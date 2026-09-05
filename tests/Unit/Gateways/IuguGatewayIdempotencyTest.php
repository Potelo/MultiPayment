<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Potelo\MultiPayment\Models\Plan;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\InvoiceItem;
use Potelo\MultiPayment\Models\AutomaticPix;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Models\SubscriptionItem;
use Potelo\MultiPayment\Models\AutomaticPixCharge;
use Potelo\MultiPayment\Gateways\IuguGateway;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Enums\PlanInterval;
use Potelo\MultiPayment\Enums\ProrationBehavior;
use Potelo\MultiPayment\Contracts\IdempotencyStore;
use Potelo\MultiPayment\Idempotency\InMemoryIdempotencyStore;
use Potelo\MultiPayment\Exceptions\RateLimitException;
use Potelo\MultiPayment\Exceptions\ConfigurationException;
use Potelo\MultiPayment\Exceptions\IdempotencyConflictException;

/**
 * Chave de idempotência no driver da Iugu: cabeçalho `Idempotency-Key` nos quatro endpoints
 * que a Iugu aceita, `IdempotencyStore` nos demais métodos de escrita (com chave derivada nas
 * requisições secundárias), nada sem chave, e resolução da store pelo container.
 */
class IuguGatewayIdempotencyTest extends TestCase
{
    private Container $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Container();
        $this->app->instance('config', new Repository([
            'multi-payment.gateways.iugu.api_key' => 'test-api-key',
            'multi-payment.gateways.iugu.id' => 'account-id',
            'multi-payment.environment' => 'testing',
            'multi-payment.idempotency.ttl' => 3600,
        ]));
        Facade::setFacadeApplication($this->app);
        Carbon::setTestNow('2026-09-02 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        QueuedIuguApiRequest::restoreSdkRequester();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    #[DataProvider('nativeEndpointProvider')]
    public function testTheKeyGoesInTheHeaderOfTheEndpointsTheIuguSupports(\Closure $operation, array $responses, string $expectedPath): void
    {
        $api = new QueuedIuguApiRequest($responses);
        $store = new InMemoryIdempotencyStore();

        $operation(new IuguGateway($api, $store), 'chave-1');

        $this->assertSame('POST', $api->calls[0]['method']);
        $this->assertStringEndsWith($expectedPath, $api->calls[0]['url']);
        $this->assertSame(['Idempotency-Key: chave-1'], $api->calls[0]['headers']);
        $this->assertArrayNotHasKey('idempotency_key', $api->calls[0]['data']);
        // o gateway deduplica sozinho: a store não é usada
        $this->assertFalse($store->has('iugu:chave-1'));

        // nenhuma outra requisição da operação (a leitura da fatura cobrada) leva a chave
        foreach (array_slice($api->calls, 1) as $call) {
            $this->assertSame([], $call['headers']);
        }
    }

    public static function nativeEndpointProvider(): array
    {
        return [
            'createInvoice pix (POST /invoices)' => [
                fn (IuguGateway $g, string $key) => $g->createInvoice(self::pixInvoiceModel(), $key),
                [self::pendingInvoiceResponse()],
                '/invoices',
            ],
            'createInvoice com cartão salvo (POST /charge)' => [
                fn (IuguGateway $g, string $key) => $g->createInvoice(self::cardInvoiceModel(), $key),
                [(object) ['success' => true, 'invoice_id' => 'inv_1'], self::pendingInvoiceResponse()],
                '/charge',
            ],
            'chargeInvoiceWithCreditCard (POST /charge)' => [
                function (IuguGateway $g, string $key) {
                    $invoice = self::invoiceWithId();
                    $invoice->creditCard = new CreditCard();
                    $invoice->creditCard->id = 'pm_1';

                    return $g->chargeInvoiceWithCreditCard($invoice, $key);
                },
                [(object) ['success' => true, 'invoice_id' => 'inv_1'], self::pendingInvoiceResponse()],
                '/charge',
            ],
            'createCustomer (POST /customers)' => [
                fn (IuguGateway $g, string $key) => $g->createCustomer(self::customerModel(), $key),
                [self::customerResponse()],
                '/customers',
            ],
            'createSubscription (POST /subscriptions)' => [
                function (IuguGateway $g, string $key) {
                    $subscription = new Subscription();
                    $subscription->planId = 'plano_mensal';
                    $subscription->customer = self::customerWithId();

                    return $g->createSubscription($subscription, $key);
                },
                [self::subscriptionResponse()],
                '/subscriptions',
            ],
        ];
    }

    /**
     * A segunda chamada com a mesma chave devolve a resposta guardada: a requisição de escrita
     * não se repete, só as leituras que a operação faz em volta dela.
     */
    #[DataProvider('storeEndpointProvider')]
    public function testTheKeyGoesThroughTheStoreOnTheEndpointsTheIuguDoesNotSupport(
        \Closure $operation,
        array $responses,
        string $expectedMethod,
        string $expectedPath,
        array $responsesForTheRetry = []
    ): void {
        $api = new QueuedIuguApiRequest(array_merge($responses, $responsesForTheRetry));
        $store = new InMemoryIdempotencyStore();
        $gateway = new IuguGateway($api, $store);

        $operation($gateway, 'chave-1');

        $writes = array_filter(
            $api->calls,
            fn (array $call) => $call['method'] === $expectedMethod && str_ends_with(parse_url($call['url'], PHP_URL_PATH), $expectedPath)
        );
        $this->assertCount(1, $writes, "esperava uma requisição {$expectedMethod} {$expectedPath}");
        $this->assertSame([], reset($writes)['headers']);
        $this->assertArrayNotHasKey('idempotency_key', reset($writes)['data']);
        $this->assertTrue($store->has('iugu:chave-1'));

        $operation($gateway, 'chave-1');

        $writesAfterRetry = array_filter(
            $api->calls,
            fn (array $call) => $call['method'] === $expectedMethod && str_ends_with(parse_url($call['url'], PHP_URL_PATH), $expectedPath)
        );
        $this->assertCount(1, $writesAfterRetry, 'a segunda chamada com a mesma chave não pode repetir a escrita');
    }

    public static function storeEndpointProvider(): array
    {
        $paidCardInvoice = self::pendingInvoiceResponse([
            'status' => 'paid',
            'paid_at' => '2026-08-20T10:00:00-03:00',
            'paid_cents' => 10000,
            'payment_method' => 'iugu_credit_card',
            'payable_with' => 'credit_card',
        ]);
        $refundedInvoice = self::pendingInvoiceResponse([
            'status' => 'refunded',
            'paid_at' => '2026-08-20T10:00:00-03:00',
            'paid_cents' => 0,
            'refunded_cents' => 10000,
            'payment_method' => 'iugu_credit_card',
        ]);

        return [
            'cancelInvoice (PUT /cancel)' => [
                fn (IuguGateway $g, string $key) => $g->cancelInvoice(self::invoiceWithId(), $key),
                [self::pendingInvoiceResponse(['status' => 'canceled'])],
                'PUT', '/invoices/inv_1/cancel',
            ],
            'refundInvoice (operação inteira guardada: nem a leitura prévia se repete)' => [
                fn (IuguGateway $g, string $key) => $g->refundInvoice(self::invoiceWithId(), null, $key),
                [$paidCardInvoice, $refundedInvoice],
                'POST', '/invoices/inv_1/refund',
            ],
            'captureInvoice (POST /capture)' => [
                fn (IuguGateway $g, string $key) => $g->captureInvoice(self::invoiceWithId(), null, $key),
                [$paidCardInvoice],
                'POST', '/invoices/inv_1/capture',
            ],
            'duplicateInvoice (POST /duplicate)' => [
                fn (IuguGateway $g, string $key) => $g->duplicateInvoice(self::invoiceWithId(), Carbon::parse('2026-10-01'), [], $key),
                [self::pendingInvoiceResponse(['id' => 'inv_2'])],
                'POST', '/invoices/inv_1/duplicate',
            ],
            'rescheduleAutomaticPixPayment' => [
                fn (IuguGateway $g, string $key) => $g->rescheduleAutomaticPixPayment(self::invoiceWithId(), $key),
                [self::pendingInvoiceResponse()],
                'POST', '/invoices/inv_1/reschedule_automatic_pix_payment',
            ],
            'cancelAutomaticPixRecurrence' => [
                function (IuguGateway $g, string $key) {
                    $automaticPix = new AutomaticPix();
                    $automaticPix->id = 'rec_1';

                    return $g->cancelAutomaticPixRecurrence($automaticPix, $key);
                },
                [(object) ['cancellation_id' => 'can_1', 'status' => 'requested']],
                'PUT', '/automatic_pix/receiver_recurrences/rec_1/cancel',
            ],
            'cancelAutomaticPixScheduledPayment' => [
                function (IuguGateway $g, string $key) {
                    $charge = new AutomaticPixCharge();
                    $charge->id = 'pay_1';
                    $charge->endToEndId = 'E123';

                    return $g->cancelAutomaticPixScheduledPayment($charge, $key);
                },
                [(object) ['cancellation_id' => 'can_1', 'status' => 'requested']],
                'POST', '/automatic_pix/receiver_recurrence_payments/cancel',
            ],
            'updateCustomer (PUT /customers/{id})' => [
                fn (IuguGateway $g, string $key) => $g->updateCustomer(self::customerWithId(), $key),
                [self::customerResponse()],
                'PUT', '/customers/cus_1',
            ],
            'setCustomerDefaultCard (PUT /customers/{id})' => [
                fn (IuguGateway $g, string $key) => $g->setCustomerDefaultCard(self::customerWithId(), 'pm_1', $key),
                [self::customerResponse(['default_payment_method_id' => 'pm_1'])],
                'PUT', '/customers/cus_1',
            ],
            'createCreditCard com token (POST /payment_methods)' => [
                fn (IuguGateway $g, string $key) => $g->createCreditCard(self::tokenizedCardModel(), $key),
                [self::paymentMethodResponse()],
                'POST', '/customers/cus_1/payment_methods',
            ],
            'deleteCreditCard (DELETE /payment_methods/{id})' => [
                fn (IuguGateway $g, string $key) => $g->deleteCreditCard(self::savedCardModel(), $key),
                [(object) ['id' => 'pm_1']],
                'DELETE', '/customers/cus_1/payment_methods/pm_1',
            ],
            'updateSubscription sem itens (PUT /subscriptions/{id})' => [
                function (IuguGateway $g, string $key) {
                    $subscription = self::subscriptionWithId();
                    $subscription->metadata = ['origem' => 'teste'];

                    return $g->updateSubscription($subscription, $key);
                },
                [self::subscriptionResponse()],
                'PUT', '/subscriptions/sub_1',
            ],
            'suspendSubscription (POST /suspend)' => [
                fn (IuguGateway $g, string $key) => $g->suspendSubscription(self::subscriptionWithId(), $key),
                [self::subscriptionResponse(['suspended' => true])],
                'POST', '/subscriptions/sub_1/suspend',
            ],
            'resumeSubscription (POST /activate)' => [
                fn (IuguGateway $g, string $key) => $g->resumeSubscription(self::subscriptionWithId(), $key),
                [self::subscriptionResponse()],
                'POST', '/subscriptions/sub_1/activate',
            ],
            'cancelSubscription (POST /suspend, mais o PUT da marca de cancelamento)' => [
                fn (IuguGateway $g, string $key) => $g->cancelSubscription(self::subscriptionWithId(), false, $key),
                [
                    self::subscriptionResponse(['suspended' => true]),
                    self::subscriptionResponse([
                        'suspended' => true,
                        'custom_variables' => [(object) ['name' => 'mp_canceled_at', 'value' => '2026-09-02T10:00:00-03:00']],
                    ]),
                ],
                'POST', '/subscriptions/sub_1/suspend',
            ],
            'cancelSubscription (PUT da marca de cancelamento, chave derivada)' => [
                fn (IuguGateway $g, string $key) => $g->cancelSubscription(self::subscriptionWithId(), false, $key),
                [
                    self::subscriptionResponse(['suspended' => true]),
                    self::subscriptionResponse([
                        'suspended' => true,
                        'custom_variables' => [(object) ['name' => 'mp_canceled_at', 'value' => '2026-09-02T10:00:00-03:00']],
                    ]),
                ],
                'PUT', '/subscriptions/sub_1',
            ],
            'cancelSubscription agendado (PUT das variáveis de agendamento)' => [
                function (IuguGateway $g, string $key) {
                    $subscription = self::subscriptionWithId();
                    $subscription->nextBillingAt = Carbon::parse('2026-12-01');

                    return $g->cancelSubscription($subscription, true, $key);
                },
                [self::subscriptionResponse(['custom_variables' => [
                    (object) ['name' => 'mp_cancel_at_period_end', 'value' => '1'],
                    (object) ['name' => 'mp_cancel_scheduled_for', 'value' => '2026-12-01'],
                ]])],
                'PUT', '/subscriptions/sub_1',
            ],
            'changeSubscriptionPlan com cobrança (POST /change_plan, com a releitura repetida)' => [
                fn (IuguGateway $g, string $key) => $g->changeSubscriptionPlan(self::subscriptionWithId(), 'plano_anual', ProrationBehavior::CHARGE_DIFFERENCE, $key),
                [(object) ['success' => true], self::subscriptionResponse(['plan_identifier' => 'plano_anual'])],
                'POST', '/subscriptions/sub_1/change_plan/plano_anual',
                [self::subscriptionResponse(['plan_identifier' => 'plano_anual'])],
            ],
            'changeSubscriptionPlan sem cobrança (PUT /subscriptions/{id})' => [
                fn (IuguGateway $g, string $key) => $g->changeSubscriptionPlan(self::subscriptionWithId(), 'plano_anual', ProrationBehavior::NONE, $key),
                [self::subscriptionResponse(['plan_identifier' => 'plano_anual'])],
                'PUT', '/subscriptions/sub_1',
            ],
            'createPlan (POST /plans)' => [
                function (IuguGateway $g, string $key) {
                    $plan = new Plan();
                    $plan->name = 'Mensal';
                    $plan->identifier = 'plano_mensal';
                    $plan->amount = 10000;
                    $plan->interval = PlanInterval::MONTH;

                    return $g->createPlan($plan, $key);
                },
                [self::planResponse()],
                'POST', '/plans',
            ],
        ];
    }

    /**
     * O retry do estorno com a mesma chave devolve o `Refund` da primeira execução sem nenhuma
     * requisição: a leitura prévia encontraria a fatura já estornada e a guarda recusaria.
     */
    public function testRefundRetryReturnsTheStoredRefundWithoutReadingTheInvoiceAgain(): void
    {
        $paid = self::pendingInvoiceResponse([
            'status' => 'paid',
            'paid_at' => '2026-08-20T10:00:00-03:00',
            'paid_cents' => 10000,
            'payment_method' => 'iugu_credit_card',
        ]);
        $refunded = self::pendingInvoiceResponse([
            'status' => 'refunded',
            'paid_at' => '2026-08-20T10:00:00-03:00',
            'paid_cents' => 0,
            'refunded_cents' => 10000,
            'payment_method' => 'iugu_credit_card',
        ]);
        $api = new QueuedIuguApiRequest([$paid, $refunded]);
        $gateway = new IuguGateway($api, new InMemoryIdempotencyStore());

        $first = $gateway->refundInvoice(self::invoiceWithId(), null, 'chave-1');
        $second = $gateway->refundInvoice(self::invoiceWithId(), null, 'chave-1');

        $this->assertCount(2, $api->calls);
        $this->assertSame(10000, $first->amount);
        $this->assertSame($first, $second);
    }

    /**
     * O caminho antigo (valor escrito em `refundedAmount`) também vale com chave: o valor é
     * resolvido antes de a operação entrar na store, e o retry devolve o mesmo `Refund`.
     */
    #[IgnoreDeprecations]
    public function testRefundWithAKeyStillHonoursTheLegacyRefundedAmount(): void
    {
        $paid = self::pendingInvoiceResponse([
            'status' => 'paid',
            'paid_at' => '2026-08-20T10:00:00-03:00',
            'paid_cents' => 10000,
            'payment_method' => 'iugu_credit_card',
        ]);
        $partiallyRefunded = self::pendingInvoiceResponse([
            'status' => 'partially_refunded',
            'paid_at' => '2026-08-20T10:00:00-03:00',
            'paid_cents' => 7500,
            'refunded_cents' => 2500,
            'payment_method' => 'iugu_credit_card',
        ]);
        $api = new QueuedIuguApiRequest([$paid, $partiallyRefunded]);
        $gateway = new IuguGateway($api, new InMemoryIdempotencyStore());
        $invoice = self::invoiceWithId();
        $invoice->refundedAmount = 2500;

        $first = $gateway->refundInvoice($invoice, null, 'chave-legada');
        $second = $gateway->refundInvoice(self::invoiceWithId(), null, 'chave-legada');

        $this->assertCount(2, $api->calls);
        $this->assertSame(['partial_value_refund_cents' => 2500], $api->calls[1]['data']);
        $this->assertSame(2500, $first->amount);
        $this->assertSame($first, $second);
    }

    public function testTheSameKeyOnAnotherOperationIsAConflict(): void
    {
        $api = new QueuedIuguApiRequest([
            self::pendingInvoiceResponse(['status' => 'canceled']),
            self::pendingInvoiceResponse(['id' => 'inv_2']),
        ]);
        $gateway = new IuguGateway($api, new InMemoryIdempotencyStore());
        $gateway->cancelInvoice(self::invoiceWithId(), 'chave-1');

        try {
            $gateway->duplicateInvoice(self::invoiceWithId(), Carbon::parse('2026-10-01'), [], 'chave-1');
            $this->fail('Esperava IdempotencyConflictException');
        } catch (IdempotencyConflictException $e) {
            $this->assertStringContainsString('[chave-1]', $e->getMessage());
            $this->assertStringContainsString('outra operação', $e->getMessage());
        }

        $this->assertCount(1, $api->calls);
    }

    #[DataProvider('conflictWithoutResourceIdProvider')]
    public function testAReusedKeyWithoutResourceIdOnAnInvoiceWriteSurfacesTheConflict(\Closure $operation): void
    {
        $api = new QueuedIuguApiRequest([self::iuguConflictResponse('processing')]);

        try {
            $operation(new IuguGateway($api), 'chave-1');
            $this->fail('Esperava IdempotencyConflictException');
        } catch (IdempotencyConflictException $e) {
            $this->assertNull($e->resourceId);
        }

        $this->assertCount(1, $api->calls);
    }

    public static function conflictWithoutResourceIdProvider(): array
    {
        return [
            'POST /invoices' => [fn (IuguGateway $g, string $key) => $g->createInvoice(self::pixInvoiceModel(), $key)],
            'POST /charge' => [fn (IuguGateway $g, string $key) => $g->createInvoice(self::cardInvoiceModel(), $key)],
        ];
    }

    /**
     * A chave antiga em `gatewayOptions` fica fora do corpo em toda operação, e as demais
     * opções continuam entrando.
     */
    #[DataProvider('legacyKeyProvider')]
    #[IgnoreDeprecations]
    public function testTheLegacyKeyStaysOutOfTheBodyOnEveryOperation(\Closure $operation, array $responses, int $writeCall, ?string $expectedStoreKey): void
    {
        $api = new QueuedIuguApiRequest($responses);
        $store = new InMemoryIdempotencyStore();

        $operation(new IuguGateway($api, $store));

        $data = $api->calls[$writeCall]['data'];
        $this->assertArrayNotHasKey('idempotency_key', $data);
        $this->assertSame(1, $data['outra']);
        if (!is_null($expectedStoreKey)) {
            $this->assertTrue($store->has($expectedStoreKey));
        } else {
            $this->assertSame(['Idempotency-Key: chave-antiga'], $api->calls[$writeCall]['headers']);
        }
    }

    public static function legacyKeyProvider(): array
    {
        $legacy = ['idempotency_key' => 'chave-antiga', 'outra' => 1];

        return [
            'createCustomer' => [
                function (IuguGateway $g) use ($legacy) {
                    $customer = self::customerModel();
                    $customer->gatewayOptions = $legacy;

                    return $g->createCustomer($customer);
                },
                [self::customerResponse()], 0, null,
            ],
            'updateCustomer' => [
                function (IuguGateway $g) use ($legacy) {
                    $customer = self::customerWithId();
                    $customer->gatewayOptions = $legacy;

                    return $g->updateCustomer($customer);
                },
                [self::customerResponse()], 0, 'iugu:chave-antiga',
            ],
            'createSubscription' => [
                function (IuguGateway $g) use ($legacy) {
                    $subscription = new Subscription();
                    $subscription->planId = 'plano_mensal';
                    $subscription->customer = self::customerWithId();
                    $subscription->gatewayOptions = $legacy;

                    return $g->createSubscription($subscription);
                },
                [self::subscriptionResponse()], 0, null,
            ],
            'updateSubscription' => [
                function (IuguGateway $g) use ($legacy) {
                    $subscription = self::subscriptionWithId();
                    $subscription->gatewayOptions = $legacy;

                    return $g->updateSubscription($subscription);
                },
                [self::subscriptionResponse()], 0, 'iugu:chave-antiga',
            ],
            'createPlan' => [
                function (IuguGateway $g) use ($legacy) {
                    $plan = new Plan();
                    $plan->name = 'Mensal';
                    $plan->identifier = 'plano_mensal';
                    $plan->amount = 10000;
                    $plan->interval = PlanInterval::MONTH;
                    $plan->gatewayOptions = $legacy;

                    return $g->createPlan($plan);
                },
                [self::planResponse()], 0, 'iugu:chave-antiga',
            ],
            'duplicateInvoice (chave nas opções do argumento)' => [
                fn (IuguGateway $g) => $g->duplicateInvoice(self::invoiceWithId(), Carbon::parse('2026-10-01'), $legacy),
                [self::pendingInvoiceResponse(['id' => 'inv_2'])], 0, 'iugu:chave-antiga',
            ],
        ];
    }

    public function testTheCardSavedBeforeAChargeUsesADerivedKeyInTheStore(): void
    {
        $api = new QueuedIuguApiRequest([
            self::paymentMethodResponse(),
            (object) ['success' => true, 'invoice_id' => 'inv_1'],
            self::pendingInvoiceResponse(),
        ]);
        $store = new InMemoryIdempotencyStore();

        $invoice = self::cardInvoiceModel();
        $invoice->creditCard = new CreditCard();
        $invoice->creditCard->token = 'tok_1';
        (new IuguGateway($api, $store))->createInvoice($invoice, 'chave-1');

        $this->assertStringEndsWith('/customers/cus_1/payment_methods', $api->calls[0]['url']);
        $this->assertSame([], $api->calls[0]['headers']);
        $this->assertTrue($store->has('iugu:chave-1:card'));
        $this->assertStringEndsWith('/charge', $api->calls[1]['url']);
        $this->assertSame(['Idempotency-Key: chave-1'], $api->calls[1]['headers']);
        $this->assertFalse($store->has('iugu:chave-1'));
    }

    public function testTokenizationOfRawCardDataUsesADerivedKeyInTheStore(): void
    {
        $api = new QueuedIuguApiRequest([
            (object) ['id' => 'tok_1', 'method' => 'credit_card'],
            self::paymentMethodResponse(),
        ]);
        $store = new InMemoryIdempotencyStore();

        $creditCard = self::savedCardModel();
        $creditCard->id = null;
        $creditCard->number = '4111111111111111';
        $creditCard->cvv = '123';
        $creditCard->firstName = 'Cliente';
        $creditCard->lastName = 'Teste';
        $creditCard->month = '12';
        $creditCard->year = '2030';
        (new IuguGateway($api, $store))->createCreditCard($creditCard, 'chave-1');

        $this->assertStringEndsWith('/payment_token', $api->calls[0]['url']);
        $this->assertStringEndsWith('/customers/cus_1/payment_methods', $api->calls[1]['url']);
        $this->assertTrue($store->has('iugu:chave-1:token'));
        $this->assertTrue($store->has('iugu:chave-1'));
    }

    public function testRemovingSubscriptionItemsBeforeTheUpdateUsesADerivedKey(): void
    {
        $api = new QueuedIuguApiRequest([
            self::subscriptionResponse(['subitems' => [
                (object) ['id' => 'sub_item_velho', 'description' => 'Antigo', 'price_cents' => 1000, 'quantity' => 1, 'recurrent' => true],
            ]]),
            self::subscriptionResponse(),
            self::subscriptionResponse(),
        ]);
        $store = new InMemoryIdempotencyStore();

        $subscription = self::subscriptionWithId();
        $item = new SubscriptionItem();
        $item->description = 'Novo';
        $item->amount = 2500;
        $subscription->items = [$item];
        (new IuguGateway($api, $store))->updateSubscription($subscription, 'chave-1');

        $this->assertSame(['GET', 'PUT', 'PUT'], array_column($api->calls, 'method'));
        $this->assertTrue($store->has('iugu:chave-1:remove'));
        $this->assertTrue($store->has('iugu:chave-1'));
    }

    public function testWithoutAKeyNoHeaderIsSentAndTheStoreIsNotTouched(): void
    {
        $api = new QueuedIuguApiRequest([self::pendingInvoiceResponse(), self::pendingInvoiceResponse(['status' => 'canceled'])]);
        $store = new class implements IdempotencyStore {
            public function remember(string $key, callable $operation, int $ttlSeconds): mixed
            {
                throw new \LogicException('a store não pode ser usada sem chave');
            }

            public function has(string $key): bool
            {
                return false;
            }

            public function forget(string $key): void
            {
                throw new \LogicException('a store não pode ser usada sem chave');
            }
        };
        $gateway = new IuguGateway($api, $store);

        $gateway->createInvoice(self::pixInvoiceModel());
        $gateway->cancelInvoice(self::invoiceWithId());

        $this->assertSame([[], []], array_column($api->calls, 'headers'));
    }

    public function testAStoreEndpointWithAKeyButNoStoreConfiguredIsAConfigurationError(): void
    {
        $api = new QueuedIuguApiRequest([self::pendingInvoiceResponse()]);

        try {
            (new IuguGateway($api))->cancelInvoice(self::invoiceWithId(), 'chave-1');
            $this->fail('Esperava ConfigurationException');
        } catch (ConfigurationException $e) {
            $this->assertStringContainsString('IdempotencyStore', $e->getMessage());
        }

        $this->assertSame([], $api->calls);
    }

    public function testANativeEndpointWithAKeyWorksWithoutAStore(): void
    {
        $api = new QueuedIuguApiRequest([self::pendingInvoiceResponse()]);

        $invoice = (new IuguGateway($api))->createInvoice(self::pixInvoiceModel(), 'chave-1');

        $this->assertSame('inv_1', $invoice->id);
        $this->assertSame(['Idempotency-Key: chave-1'], $api->calls[0]['headers']);
    }

    public function testTheStoreIsResolvedFromTheContainerWhenNotInjected(): void
    {
        $store = new InMemoryIdempotencyStore();
        $this->app->instance(IdempotencyStore::class, $store);
        $api = new QueuedIuguApiRequest([self::pendingInvoiceResponse(['status' => 'canceled'])]);

        (new IuguGateway($api))->cancelInvoice(self::invoiceWithId(), 'chave-1');

        $this->assertTrue($store->has('iugu:chave-1'));
    }

    public function testAConflictInTheStoreIsAnIdempotencyConflictExceptionWithoutARequest(): void
    {
        $api = new QueuedIuguApiRequest([self::pendingInvoiceResponse()]);
        $store = new class implements IdempotencyStore {
            public function remember(string $key, callable $operation, int $ttlSeconds): mixed
            {
                throw IdempotencyConflictException::concurrent($key);
            }

            public function has(string $key): bool
            {
                return false;
            }

            public function forget(string $key): void
            {
            }
        };

        try {
            (new IuguGateway($api, $store))->cancelInvoice(self::invoiceWithId(), 'chave-1');
            $this->fail('Esperava IdempotencyConflictException');
        } catch (IdempotencyConflictException $e) {
            $this->assertStringContainsString('[iugu:chave-1]', $e->getMessage());
        }

        $this->assertSame([], $api->calls);
    }

    public function testTheStoreTtlComesFromTheConfiguration(): void
    {
        $api = new QueuedIuguApiRequest([self::pendingInvoiceResponse(['status' => 'canceled'])]);
        $store = new InMemoryIdempotencyStore();

        (new IuguGateway($api, $store))->cancelInvoice(self::invoiceWithId(), 'chave-1');

        Carbon::setTestNow('2026-09-02 12:59:59');
        $this->assertTrue($store->has('iugu:chave-1'));
        Carbon::setTestNow('2026-09-02 13:00:00');
        $this->assertFalse($store->has('iugu:chave-1'));
    }

    #[IgnoreDeprecations]
    public function testTheLegacyGatewayOptionStillWorksWithADeprecationAndStaysOutOfTheBody(): void
    {
        $api = new QueuedIuguApiRequest([self::pendingInvoiceResponse()]);

        $invoice = self::pixInvoiceModel();
        $invoice->gatewayOptions = ['idempotency_key' => 'chave-antiga', 'expires_in' => 3];

        $this->expectUserDeprecationMessage("gateway_options['idempotency_key'] está obsoleto desde 2026-09-02; passe idempotencyKey como argumento da operação");

        (new IuguGateway($api))->createInvoice($invoice);

        $this->assertSame(['Idempotency-Key: chave-antiga'], $api->calls[0]['headers']);
        $this->assertArrayNotHasKey('idempotency_key', $api->calls[0]['data']);
        $this->assertSame(3, $api->calls[0]['data']['expires_in']);
    }

    #[IgnoreDeprecations]
    public function testTheLegacyGatewayOptionAlsoReachesTheStoreEndpoints(): void
    {
        $api = new QueuedIuguApiRequest([self::pendingInvoiceResponse(['status' => 'canceled'])]);
        $store = new InMemoryIdempotencyStore();

        $invoice = self::invoiceWithId();
        $invoice->gatewayOptions = ['idempotency_key' => 'chave-antiga'];

        $this->expectUserDeprecationMessage("gateway_options['idempotency_key'] está obsoleto desde 2026-09-02; passe idempotencyKey como argumento da operação");

        (new IuguGateway($api, $store))->cancelInvoice($invoice);

        $this->assertTrue($store->has('iugu:chave-antiga'));
    }

    #[IgnoreDeprecations]
    public function testTheArgumentWinsOverTheLegacyGatewayOption(): void
    {
        $api = new QueuedIuguApiRequest([self::pendingInvoiceResponse()]);

        $invoice = self::pixInvoiceModel();
        $invoice->gatewayOptions = ['idempotency_key' => 'chave-antiga'];

        (new IuguGateway($api))->createInvoice($invoice, 'chave-nova');

        $this->assertSame(['Idempotency-Key: chave-nova'], $api->calls[0]['headers']);
    }

    /**
     * Na reutilização da chave a Iugu responde 409 com o id da fatura original; o driver a lê e
     * devolve, sem cabeçalho na leitura.
     */
    public function testAReusedKeyOnInvoiceCreationReturnsTheOriginalInvoice(): void
    {
        $api = new QueuedIuguApiRequest([
            self::iuguConflictResponse('F53DE68D632E48618DB238F2C1E5D531'),
            self::pendingInvoiceResponse(['id' => 'F53DE68D632E48618DB238F2C1E5D531']),
        ]);

        $invoice = (new IuguGateway($api))->createInvoice(self::pixInvoiceModel(), 'chave-1');

        $this->assertSame('F53DE68D632E48618DB238F2C1E5D531', $invoice->id);
        $this->assertSame(InvoiceStatus::PENDING, $invoice->status);
        $this->assertSame('GET', $api->calls[1]['method']);
        $this->assertStringEndsWith('/invoices/F53DE68D632E48618DB238F2C1E5D531', $api->calls[1]['url']);
        $this->assertSame([], $api->calls[1]['headers']);
    }

    public function testAReusedKeyOnACardChargeReturnsTheOriginalInvoice(): void
    {
        $api = new QueuedIuguApiRequest([
            self::iuguConflictResponse('495CD9FE89EB496FB3230F97AB857AA9'),
            self::pendingInvoiceResponse(['id' => '495CD9FE89EB496FB3230F97AB857AA9', 'status' => 'paid']),
        ]);

        $invoice = (new IuguGateway($api))->createInvoice(self::cardInvoiceModel(), 'chave-1');

        $this->assertSame('495CD9FE89EB496FB3230F97AB857AA9', $invoice->id);
        $this->assertStringEndsWith('/charge', $api->calls[0]['url']);
        $this->assertStringEndsWith('/invoices/495CD9FE89EB496FB3230F97AB857AA9', $api->calls[1]['url']);
    }

    /**
     * Para cliente e assinatura a Iugu responde `resource_id: processing`, sem o id do recurso,
     * então não há o que ler e a exceção sobe com `resourceId` nulo.
     */
    public function testAReusedKeyOnCustomerCreationIsAConflictWithoutResourceId(): void
    {
        $api = new QueuedIuguApiRequest([self::iuguConflictResponse('processing')]);

        try {
            (new IuguGateway($api))->createCustomer(self::customerModel(), 'chave-1');
            $this->fail('Esperava IdempotencyConflictException');
        } catch (IdempotencyConflictException $e) {
            $this->assertSame(409, $e->httpStatus);
            $this->assertNull($e->resourceId);
            $this->assertStringContainsString('já esta em uso', $e->getMessage());
        }

        $this->assertCount(1, $api->calls);
    }

    public function testTheConflictExceptionCarriesTheResourceIdWhenTheIuguInformsIt(): void
    {
        $api = new QueuedIuguApiRequest([self::iuguConflictResponse('D51E2917CDFE409FAE08EDCA52D8E8FC')]);

        $subscription = new Subscription();
        $subscription->planId = 'plano_mensal';
        $subscription->customer = self::customerWithId();

        try {
            (new IuguGateway($api))->createSubscription($subscription, 'chave-1');
            $this->fail('Esperava IdempotencyConflictException');
        } catch (IdempotencyConflictException $e) {
            $this->assertSame('D51E2917CDFE409FAE08EDCA52D8E8FC', $e->resourceId);
        }
    }

    public function testRetryAfterHeaderOfA429FillsTheRateLimitException(): void
    {
        $api = new QueuedIuguApiRequest([
            new QueuedIuguResponse((object) ['errors' => 'Too many requests'], 429, ['retry-after' => '7']),
        ]);

        try {
            (new IuguGateway($api))->getInvoice(self::invoiceWithId());
            $this->fail('Esperava RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(429, $e->httpStatus);
            $this->assertSame(7, $e->retryAfter);
        }
    }

    #[DataProvider('retryAfterProvider')]
    public function testRetryAfterReadsTheFirstNumericValueOnly(mixed $header, ?int $expected): void
    {
        $api = new QueuedIuguApiRequest([
            new QueuedIuguResponse((object) ['errors' => 'Too many requests'], 429, ['retry-after' => $header]),
        ]);

        try {
            (new IuguGateway($api))->getInvoice(self::invoiceWithId());
            $this->fail('Esperava RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame($expected, $e->retryAfter);
        }
    }

    public static function retryAfterProvider(): array
    {
        return [
            'cabeçalho repetido vira lista: vale o primeiro' => [['7', '9'], 7],
            'data HTTP não é interpretada' => ['Wed, 02 Sep 2026 12:00:00 GMT', null],
        ];
    }

    public function testRetryAfterIsNullWhenTheIuguDoesNotSendIt(): void
    {
        $api = new QueuedIuguApiRequest([
            new QueuedIuguResponse((object) ['errors' => 'Too many requests'], 429),
        ]);

        try {
            (new IuguGateway($api))->getInvoice(self::invoiceWithId());
            $this->fail('Esperava RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertNull($e->retryAfter);
        }
    }

    /**
     * Resposta 409 da Iugu para chave reutilizada, como observada na sandbox.
     */
    private static function iuguConflictResponse(string $resourceId): QueuedIuguResponse
    {
        return new QueuedIuguResponse((object) ['errors' => [
            "Essa chave de idempotência já esta em uso: idempotency_key: chave-1, resource_id: {$resourceId}",
        ]], 409);
    }

    private static function invoiceWithId(): Invoice
    {
        $invoice = new Invoice();
        $invoice->id = 'inv_1';

        return $invoice;
    }

    private static function customerWithId(): Customer
    {
        $customer = self::customerModel();
        $customer->id = 'cus_1';

        return $customer;
    }

    private static function customerModel(): Customer
    {
        $customer = new Customer();
        $customer->name = 'Cliente';
        $customer->email = 'cliente@example.com';
        $customer->taxDocument = '20176996915';

        return $customer;
    }

    private static function pixInvoiceModel(): Invoice
    {
        $invoice = new Invoice();
        $invoice->customer = self::customerWithId();
        $invoice->availablePaymentMethods = [PaymentMethod::PIX];
        $invoice->dueDate = Carbon::parse('2026-10-01');
        $item = new InvoiceItem();
        $item->description = 'Item';
        $item->price = 10000;
        $item->quantity = 1;
        $invoice->items = [$item];

        return $invoice;
    }

    private static function cardInvoiceModel(): Invoice
    {
        $invoice = self::pixInvoiceModel();
        $invoice->availablePaymentMethods = [PaymentMethod::CREDIT_CARD];
        $invoice->creditCard = new CreditCard();
        $invoice->creditCard->id = 'pm_1';

        return $invoice;
    }

    private static function savedCardModel(): CreditCard
    {
        $creditCard = new CreditCard();
        $creditCard->id = 'pm_1';
        $creditCard->customer = self::customerWithId();

        return $creditCard;
    }

    private static function tokenizedCardModel(): CreditCard
    {
        $creditCard = self::savedCardModel();
        $creditCard->id = null;
        $creditCard->token = 'tok_1';

        return $creditCard;
    }

    private static function subscriptionWithId(): Subscription
    {
        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        return $subscription;
    }

    private static function pendingInvoiceResponse(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 'inv_1',
            'status' => 'pending',
            'total_cents' => 10000,
            'paid_at' => null,
            'secure_url' => 'https://faturas.iugu.com/inv_1',
            'taxes_paid_cents' => null,
            'created_at_iso' => '2026-09-02T09:00:00-03:00',
            'paid_cents' => 0,
            'refunded_cents' => 0,
            'due_date' => '2026-10-01',
            'payment_method' => null,
            'payable_with' => 'pix',
            'customer_id' => 'cus_1',
            'customer_name' => 'Cliente',
            'email' => 'cliente@example.com',
            'payer_phone' => null,
            'payer_phone_prefix' => null,
            'items' => [
                (object) ['description' => 'Item', 'price_cents' => 10000, 'quantity' => 1],
            ],
            'payer_address_zip_code' => null,
            'bank_slip' => null,
            'pix' => null,
            'automatic_pix' => null,
            'credit_card_transaction' => null,
            'variables' => [],
        ], $overrides);
    }

    private static function customerResponse(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 'cus_1',
            'name' => 'Cliente',
            'email' => 'cliente@example.com',
            'cpf_cnpj' => '20176996915',
            'phone' => null,
            'phone_prefix' => null,
            'created_at' => '2026-09-02T09:00:00-03:00',
            'custom_variables' => [],
            'default_payment_method_id' => null,
        ], $overrides);
    }

    private static function paymentMethodResponse(): object
    {
        return (object) [
            'id' => 'pm_1',
            'description' => 'CREDIT CARD',
            'created_at_iso' => '2026-09-02T09:00:00-03:00',
            'data' => (object) ['brand' => 'VISA', 'display_number' => 'XXXX-XXXX-XXXX-4242', 'month' => 12, 'year' => 2030, 'holder_name' => 'Cliente Teste'],
        ];
    }

    private static function subscriptionResponse(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 'sub_1',
            'customer_id' => 'cus_1',
            'plan_identifier' => 'plano_mensal',
            'price_cents' => 10000,
            'expires_at' => '2026-10-01',
            'created_at' => '2026-09-01T10:00:00-03:00',
            'active' => true,
            'suspended' => false,
            'in_trial' => false,
        ], $overrides);
    }

    private static function planResponse(): object
    {
        return (object) [
            'id' => 'plan_1',
            'identifier' => 'plano_mensal',
            'name' => 'Mensal',
            'interval' => 1,
            'interval_type' => 'months',
            'prices' => [(object) ['value_cents' => 10000, 'currency' => 'BRL']],
        ];
    }
}
