<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Plan;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Builders\SubscriptionBuilder;
use Potelo\MultiPayment\Idempotency\InMemoryIdempotencyStore;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\InvoiceItem;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Gateways\IuguGateway;
use Potelo\MultiPayment\Models\SubscriptionItem;
use Potelo\MultiPayment\Models\SubscriptionDiscount;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Enums\InvoiceOriginType;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Enums\PlanInterval;
use Potelo\MultiPayment\Enums\ProrationBehavior;
use Potelo\MultiPayment\Tests\Unit\RecordingLogger;
use Potelo\MultiPayment\Enums\SubscriptionStatus;

class IuguGatewaySubscriptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment' => [
                'default' => 'iugu',
                'gateways' => ['iugu' => ['api_key' => 'test-api-key', 'class' => IuguGateway::class]],
            ],
        ]));
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        QueuedIuguApiRequest::restoreSdkRequester();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    private function subscriptionResponse(array $overrides = []): object
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

    public function testCreateSubscriptionMapsGenericFieldsToIuguPayload(): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse()]);
        $gateway = new IuguGateway($api);

        $subscription = new Subscription();
        $subscription->fill([
            'plan_id' => 'plano_mensal',
            'customer' => ['id' => 'cus_1'],
            'next_billing_at' => '2026-10-01',
            'available_payment_methods' => [PaymentMethod::PIX],
            'metadata' => ['origem' => 'teste'],
            'items' => [['description' => 'Consultas', 'amount' => 2500, 'quantity' => 2]],
            'discounts' => [['description' => 'Promo', 'amount_off' => 500]],
        ]);

        $gateway->createSubscription($subscription);

        $this->assertCount(1, $api->calls);
        $call = $api->calls[0];
        $this->assertSame('POST', $call['method']);
        $this->assertStringEndsWith('/subscriptions', $call['url']);
        $this->assertSame([
            'customer_id' => 'cus_1',
            'plan_identifier' => 'plano_mensal',
            'expires_at' => '2026-10-01',
            'payable_with' => ['pix'],
            'custom_variables' => [['name' => 'origem', 'value' => 'teste']],
            'subitems' => [
                ['description' => 'Consultas', 'price_cents' => 2500, 'quantity' => 2, 'recurrent' => 1],
                ['description' => 'Promo', 'price_cents' => -500, 'quantity' => 1, 'recurrent' => 1],
            ],
        ], $call['data']);
    }

    /**
     * Na Iugu a recorrência de Pix Automático nasce na fatura: a assinatura com o método é
     * recusada pela falta de `MANAGES_RECURRENCE`, a mesma capability do guard do model, sem
     * nenhuma requisição.
     */
    public function testCreateSubscriptionRejectsAutomaticPixAsThePaymentMethod(): void
    {
        $api = new QueuedIuguApiRequest([]);
        $gateway = new IuguGateway($api);

        $subscription = new Subscription();
        $subscription->fill(['plan_id' => 'plano_mensal', 'customer' => ['id' => 'cus_1']]);
        $subscription->paymentMethod = PaymentMethod::AUTOMATIC_PIX;

        try {
            $gateway->createSubscription($subscription);
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::MANAGES_RECURRENCE, $e->capability);
            $this->assertSame(UnsupportedOperationException::REASON_GATEWAY_LIMITATION, $e->reason);
            $this->assertStringContainsString('nasce na fatura', $e->getMessage());
        }

        $this->assertCount(0, $api->calls);
    }

    public function testGatewayOptionsOverrideTheGeneratedPayload(): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse()]);

        $subscription = new Subscription();
        $subscription->fill(['plan_id' => 'plano_mensal', 'customer' => ['id' => 'cus_1']]);
        $subscription->gatewayOptions = [
            'only_on_charge_success' => true,
            'plan_identifier' => 'outro_plano',
        ];

        (new IuguGateway($api))->createSubscription($subscription);

        $this->assertSame([
            'customer_id' => 'cus_1',
            'plan_identifier' => 'outro_plano',
            'only_on_charge_success' => true,
        ], $api->calls[0]['data']);
    }

    public function testParseSplitsNegativeSubitemsIntoDiscounts(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'subitems' => [
                    (object) ['id' => 'si_1', 'description' => 'Consultas', 'price_cents' => 2500, 'quantity' => 2, 'recurrent' => true],
                    (object) ['id' => 'si_2', 'description' => 'Promo', 'price_cents' => -500, 'quantity' => 1, 'recurrent' => true],
                    (object) ['id' => 'si_3', 'description' => 'Bonus', 'price_cents' => -300, 'quantity' => 1, 'recurrent' => false],
                ],
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertCount(1, $subscription->items);
        $this->assertInstanceOf(SubscriptionItem::class, $subscription->items[0]);
        $this->assertSame('si_1', $subscription->items[0]->id);
        $this->assertSame(2500, $subscription->items[0]->amount);

        $this->assertCount(2, $subscription->discounts);
        $this->assertInstanceOf(SubscriptionDiscount::class, $subscription->discounts[0]);
        $this->assertSame(500, $subscription->discounts[0]->amountOff);
        $this->assertNull($subscription->discounts[0]->cycles);
        $this->assertSame(300, $subscription->discounts[1]->amountOff);
        $this->assertSame(1, $subscription->discounts[1]->cycles);
        $this->assertTrue($subscription->items[0]->recurring);
    }

    public function testParseReadsRecurrentFalseAsANonRecurringItem(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'subitems' => [
                    (object) ['id' => 'si_1', 'description' => 'Setup', 'price_cents' => 500, 'quantity' => 1, 'recurrent' => false],
                ],
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $this->assertFalse(
            (new IuguGateway($api))->getSubscription($subscription)->items[0]->recurring
        );
    }

    public function testParseReadsNextBillingFromExpiresAt(): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse()]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertSame('2026-10-01', $subscription->nextBillingAt->format('Y-m-d'));
        $this->assertNull($subscription->trialEndsAt);
        $this->assertSame(10000, $subscription->amount);
        $this->assertSame('cus_1', $subscription->customer->id);
        $this->assertSame('iugu', $subscription->gateway);
    }

    /**
     * Derivação do status a partir das flags da Iugu, um caso por estado genérico que o driver
     * produz, com a data de hoje fixada em 2026-09-15 (a resposta padrão vence em 2026-10-01).
     */
    #[DataProvider('statusProvider')]
    public function testParseMapsIuguFlagsToGenericStatus(array $flags, ?SubscriptionStatus $expected): void
    {
        Carbon::setTestNow('2026-09-15 12:00:00');
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse($flags)]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertSame($expected, $subscription->status);
    }

    public static function statusProvider(): array
    {
        $canceledMark = [(object) ['name' => 'mp_canceled_at', 'value' => '2026-09-10T10:00:00-03:00']];
        $openInvoice = [(object) ['id' => 'inv_1', 'status' => 'pending', 'due_date' => '2026-09-01']];

        return [
            'suspensa' => [['suspended' => true, 'active' => false], SubscriptionStatus::SUSPENDED],
            'suspensa tem precedencia sobre trial' => [
                ['suspended' => true, 'in_trial' => true],
                SubscriptionStatus::SUSPENDED,
            ],
            'suspensa com a marca de cancelamento' => [
                ['suspended' => true, 'active' => false, 'custom_variables' => $canceledMark],
                SubscriptionStatus::CANCELED,
            ],
            'ativa com a marca de cancelamento continua ativa' => [
                ['suspended' => false, 'active' => true, 'custom_variables' => $canceledMark],
                SubscriptionStatus::ACTIVE,
            ],
            'suspensa com a marca vazia' => [
                ['suspended' => true, 'custom_variables' => [(object) ['name' => 'mp_canceled_at', 'value' => '']]],
                SubscriptionStatus::SUSPENDED,
            ],
            'em trial' => [['in_trial' => true], SubscriptionStatus::TRIALING],
            'ativa' => [['active' => true], SubscriptionStatus::ACTIVE],
            'ativa com cobranca vencida e fatura em aberto' => [
                ['active' => true, 'expires_at' => '2026-09-01', 'recent_invoices' => $openInvoice],
                SubscriptionStatus::PAST_DUE,
            ],
            'ativa com cobranca vencida e sem fatura em aberto' => [
                ['active' => true, 'expires_at' => '2026-09-01', 'recent_invoices' => []],
                SubscriptionStatus::ACTIVE,
            ],
            'inativa com cobranca futura' => [['active' => false], SubscriptionStatus::PENDING],
            'inativa sem data de cobranca' => [['active' => false, 'expires_at' => null], SubscriptionStatus::PENDING],
            'inativa com cobranca vencida no dia' => [
                ['active' => false, 'expires_at' => '2026-09-15'],
                SubscriptionStatus::PENDING,
            ],
            'inativa com cobranca vencida e sem fatura em aberto' => [
                ['active' => false, 'expires_at' => '2026-09-01', 'recent_invoices' => []],
                SubscriptionStatus::EXPIRED,
            ],
            'inativa com cobranca vencida e fatura em aberto' => [
                ['active' => false, 'expires_at' => '2026-09-01', 'recent_invoices' => $openInvoice],
                SubscriptionStatus::PAST_DUE,
            ],
            'sem flag nenhuma' => [['active' => null, 'suspended' => null, 'in_trial' => null], null],
        ];
    }

    public function testParseReadsTheCancellationMarkIntoCanceledAtAndKeepsItOutOfMetadata(): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse([
            'suspended' => true,
            'custom_variables' => [
                (object) ['name' => 'origem', 'value' => 'teste'],
                (object) ['name' => 'mp_canceled_at', 'value' => '2026-09-10T10:00:00-03:00'],
            ],
        ])]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertSame(SubscriptionStatus::CANCELED, $subscription->status);
        $this->assertSame('2026-09-10T10:00:00-03:00', $subscription->canceledAt->toIso8601String());
        $this->assertSame(['origem' => 'teste'], $subscription->metadata);
        $this->assertFalse($subscription->cancelAtPeriodEnd);
    }

    /**
     * `custom_variables` vazia na resposta zera `canceledAt` e `metadata`: é o que a Iugu
     * devolve depois de remover a última variável.
     */
    public function testParseClearsCanceledAtAndMetadataWhenTheResponseHasNoVariablesLeft(): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse(['custom_variables' => []])]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription->canceledAt = Carbon::parse('2026-09-10T10:00:00-03:00');
        $subscription->metadata = ['mp_canceled_at' => '2026-09-10T10:00:00-03:00'];
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertNull($subscription->canceledAt);
        $this->assertSame([], $subscription->metadata);
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
    }

    public function testParseKeepsCanceledAtAndMetadataWhenTheResponseOmitsCustomVariables(): void
    {
        $response = $this->subscriptionResponse(['suspended' => true]);
        unset($response->custom_variables);
        $api = new QueuedIuguApiRequest([$response]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription->canceledAt = Carbon::parse('2026-09-10T10:00:00-03:00');
        $subscription->metadata = ['origem' => 'teste'];
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertSame('2026-09-10T10:00:00-03:00', $subscription->canceledAt->toIso8601String());
        $this->assertSame(['origem' => 'teste'], $subscription->metadata);
    }

    public function testParseFillsTrialEndsAtWhileInTrial(): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse(['in_trial' => true])]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertSame('2026-10-01', $subscription->trialEndsAt->format('Y-m-d'));
    }

    /**
     * A Iugu recusa remover e adicionar subitens na mesma requisição.
     */
    public function testUpdateRemovesItemsInAnEarlierRequest(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'subitems' => [
                    (object) ['id' => 'si_antigo', 'description' => 'Antigo', 'price_cents' => 100, 'quantity' => 1, 'recurrent' => true],
                    (object) ['id' => 'si_mantido', 'description' => 'Mantido', 'price_cents' => 200, 'quantity' => 1, 'recurrent' => true],
                ],
            ]),
            $this->subscriptionResponse(),
            $this->subscriptionResponse(),
        ]);

        $subscription = new Subscription();
        $subscription->fill([
            'id' => 'sub_1',
            'items' => [
                ['id' => 'si_mantido', 'description' => 'Mantido', 'amount' => 200, 'quantity' => 1],
                ['description' => 'Novo', 'amount' => 300, 'quantity' => 1],
            ],
        ]);

        (new IuguGateway($api))->updateSubscription($subscription);

        $this->assertCount(3, $api->calls);

        $this->assertSame('GET', $api->calls[0]['method']);

        $this->assertSame('PUT', $api->calls[1]['method']);
        $this->assertSame(
            [['id' => 'si_antigo', '_destroy' => true]],
            $api->calls[1]['data']['subitems']
        );

        $this->assertSame('PUT', $api->calls[2]['method']);
        $this->assertSame([
            ['description' => 'Mantido', 'price_cents' => 200, 'quantity' => 1, 'recurrent' => 1, 'id' => 'si_mantido'],
            ['description' => 'Novo', 'price_cents' => 300, 'quantity' => 1, 'recurrent' => 1],
        ], $api->calls[2]['data']['subitems']);
    }

    public function testUpdateWithoutItemsToRemoveSkipsTheRemovalRequest(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse(['subitems' => []]),
            $this->subscriptionResponse(),
        ]);

        $subscription = new Subscription();
        $subscription->fill([
            'id' => 'sub_1',
            'items' => [['description' => 'Novo', 'amount' => 300, 'quantity' => 1]],
        ]);

        (new IuguGateway($api))->updateSubscription($subscription);

        $this->assertCount(2, $api->calls);
        $this->assertSame('GET', $api->calls[0]['method']);
        $this->assertSame('PUT', $api->calls[1]['method']);
    }

    public function testUpdateWithoutItemsAtAllDoesNotReadTheCurrentState(): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse()]);

        $subscription = new Subscription();
        $subscription->fill(['id' => 'sub_1', 'next_billing_at' => '2026-11-01']);

        (new IuguGateway($api))->updateSubscription($subscription);

        $this->assertCount(1, $api->calls);
        $this->assertSame('PUT', $api->calls[0]['method']);
        $this->assertSame('2026-11-01', $api->calls[0]['data']['expires_at']);
    }

    public function testSuspendAndResumeHitTheirOwnEndpoints(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse(['suspended' => true]),
            $this->subscriptionResponse(),
        ]);
        $gateway = new IuguGateway($api);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $suspended = $gateway->suspendSubscription($subscription);
        $this->assertStringEndsWith('/subscriptions/sub_1/suspend', $api->calls[0]['url']);
        $this->assertSame(SubscriptionStatus::SUSPENDED, $suspended->status);

        $resumed = $gateway->resumeSubscription($subscription);
        $this->assertStringEndsWith('/subscriptions/sub_1/activate', $api->calls[1]['url']);
        $this->assertSame(SubscriptionStatus::ACTIVE, $resumed->status);
    }

    /**
     * Cancelar na Iugu é suspender e gravar `mp_canceled_at` em `custom_variables`, nesta
     * ordem; a leitura da resposta do `PUT` devolve `CANCELED` com `canceledAt` preenchido.
     */
    public function testCancelWithoutPeriodEndSuspendsAndMarksTheSubscriptionAsCanceled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02T10:00:00-03:00'));
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse(['suspended' => true]),
            $this->subscriptionResponse([
                'suspended' => true,
                'custom_variables' => [(object) ['name' => 'mp_canceled_at', 'value' => '2026-09-02T10:00:00-03:00']],
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $canceled = (new IuguGateway($api))->cancelSubscription($subscription);

        $this->assertCount(2, $api->calls);
        $this->assertSame('POST', $api->calls[0]['method']);
        $this->assertStringEndsWith('/subscriptions/sub_1/suspend', $api->calls[0]['url']);
        $this->assertSame('PUT', $api->calls[1]['method']);
        $this->assertStringEndsWith('/subscriptions/sub_1', $api->calls[1]['url']);
        $this->assertSame(
            ['custom_variables' => [['name' => 'mp_canceled_at', 'value' => Carbon::now()->toIso8601String()]]],
            $api->calls[1]['data'],
            'a marca de cancelamento leva o instante da chamada em ISO 8601'
        );

        $this->assertSame($subscription, $canceled);
        $this->assertSame(SubscriptionStatus::CANCELED, $canceled->status);
        $this->assertSame('2026-09-02T10:00:00-03:00', $canceled->canceledAt->toIso8601String());
    }

    /**
     * Segundo `cancel()` numa assinatura já marcada só repete a suspensão: a data original da
     * marca fica, e o `PUT` não sai.
     */
    public function testCancelOfAnAlreadyCanceledSubscriptionKeepsTheOriginalDate(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'suspended' => true,
                'custom_variables' => [(object) ['name' => 'mp_canceled_at', 'value' => '2026-09-01T10:00:00-03:00']],
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $canceled = (new IuguGateway($api))->cancelSubscription($subscription);

        $this->assertCount(1, $api->calls);
        $this->assertStringEndsWith('/subscriptions/sub_1/suspend', $api->calls[0]['url']);
        $this->assertSame(SubscriptionStatus::CANCELED, $canceled->status);
        $this->assertSame('2026-09-01T10:00:00-03:00', $canceled->canceledAt->toIso8601String());
    }

    /**
     * Falha no `PUT` da marca deixa o model com a resposta da suspensão: `SUSPENDED`, sem
     * `canceledAt`, e a exceção sobe.
     */
    public function testCancelLeavesTheSubscriptionSuspendedWhenTheMarkFails(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse(['suspended' => true]),
            new \IuguObjectNotFound('not found'),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        try {
            (new IuguGateway($api))->cancelSubscription($subscription);
            $this->fail('esperava NotFoundException');
        } catch (\Potelo\MultiPayment\Exceptions\NotFoundException) {
        }

        $this->assertCount(2, $api->calls);
        $this->assertSame(SubscriptionStatus::SUSPENDED, $subscription->status);
        $this->assertNull($subscription->canceledAt);
    }

    /**
     * Marca `mp_canceled_at` que não é uma data lê como ausente: a assinatura suspensa fica
     * `SUSPENDED`, `canceledAt` nulo, e um aviso vai para o log.
     */
    public function testAnUnreadableCancellationMarkIsIgnoredWithAWarning(): void
    {
        $app = \Illuminate\Support\Facades\Facade::getFacadeApplication();
        $app->instance('log', $logger = new RecordingLogger());

        $api = new QueuedIuguApiRequest([$this->subscriptionResponse([
            'suspended' => true,
            'custom_variables' => [(object) ['name' => 'mp_canceled_at', 'value' => 'sim']],
        ])]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertSame(SubscriptionStatus::SUSPENDED, $subscription->status);
        $this->assertNull($subscription->canceledAt);
        $this->assertSame([], $subscription->metadata);
        $this->assertCount(2, $logger->records, 'um aviso por leitura da marca (status e canceledAt)');
        $this->assertSame('warning', $logger->records[0]['level']);
        $this->assertStringContainsString('mp_canceled_at', $logger->records[0]['message']);
        $this->assertSame(['subscription' => 'sub_1', 'value' => 'sim', 'gateway' => 'iugu'], $logger->records[0]['context']);
    }

    public function testCancelDoesNotMarkTheSubscriptionWhenTheSuspensionFails(): void
    {
        $api = new QueuedIuguApiRequest([new \IuguObjectNotFound('not found')]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        try {
            (new IuguGateway($api))->cancelSubscription($subscription);
            $this->fail('esperava NotFoundException');
        } catch (\Potelo\MultiPayment\Exceptions\NotFoundException) {
        }

        $this->assertCount(1, $api->calls);
        $this->assertNull($subscription->canceledAt);
    }

    /**
     * Reativar uma assinatura cancelada remove a marca `mp_canceled_at` (`PUT` com `_destroy`)
     * depois do `activate`, e o status volta a `ACTIVE`.
     */
    public function testResumeClearsTheCancellationMark(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'custom_variables' => [(object) ['name' => 'mp_canceled_at', 'value' => '2026-09-02T10:00:00-03:00']],
            ]),
            $this->subscriptionResponse(['custom_variables' => []]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $resumed = (new IuguGateway($api))->resumeSubscription($subscription);

        $this->assertCount(2, $api->calls);
        $this->assertStringEndsWith('/subscriptions/sub_1/activate', $api->calls[0]['url']);
        $this->assertSame('PUT', $api->calls[1]['method']);
        $this->assertStringEndsWith('/subscriptions/sub_1', $api->calls[1]['url']);
        $this->assertSame(
            ['custom_variables' => [
                ['name' => 'mp_canceled_at', '_destroy' => true],
                ['name' => 'mp_cancel_at_period_end', '_destroy' => true],
                ['name' => 'mp_cancel_scheduled_for', '_destroy' => true],
            ]],
            $api->calls[1]['data']
        );
        $this->assertSame(SubscriptionStatus::ACTIVE, $resumed->status);
        $this->assertNull($resumed->canceledAt);
    }

    /**
     * Com chave, o `PUT` que remove a marca recebe `{chave}:uncancel`, e o retry inteiro sai
     * da store sem nova requisição.
     */
    public function testResumeStoresTheActivationAndTheUnmarkUnderTheirOwnKeys(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'custom_variables' => [(object) ['name' => 'mp_canceled_at', 'value' => '2026-09-02T10:00:00-03:00']],
            ]),
            $this->subscriptionResponse(['custom_variables' => []]),
        ]);
        $store = new \Potelo\MultiPayment\Idempotency\InMemoryIdempotencyStore();
        $gateway = new IuguGateway($api, $store);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $gateway->resumeSubscription($subscription, 'chave-1');
        $this->assertTrue($store->has('iugu:chave-1'));
        $this->assertTrue($store->has('iugu:chave-1:uncancel'));
        $this->assertSame([], $api->calls[1]['headers']);

        $again = new Subscription();
        $again->id = 'sub_1';
        $retried = $gateway->resumeSubscription($again, 'chave-1');

        $this->assertCount(2, $api->calls);
        $this->assertSame(SubscriptionStatus::ACTIVE, $retried->status);
        $this->assertNull($retried->canceledAt);
    }

    /**
     * Quando a resposta do `activate` não traz `custom_variables`, a marca conhecida pelo
     * model decide o `PUT` de remoção.
     */
    public function testResumeUsesTheMarkKnownByTheModelWhenTheResponseOmitsCustomVariables(): void
    {
        $activate = $this->subscriptionResponse();
        unset($activate->custom_variables);
        $api = new QueuedIuguApiRequest([$activate, $this->subscriptionResponse(['custom_variables' => []])]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription->canceledAt = Carbon::parse('2026-09-02T10:00:00-03:00');

        $resumed = (new IuguGateway($api))->resumeSubscription($subscription);

        $this->assertCount(2, $api->calls);
        $this->assertSame('PUT', $api->calls[1]['method']);
        $this->assertNull($resumed->canceledAt);
    }

    public function testResumeOfASuspendedSubscriptionDoesNotTouchCustomVariables(): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse(['custom_variables' => [
            (object) ['name' => 'origem', 'value' => 'teste'],
        ]])]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $resumed = (new IuguGateway($api))->resumeSubscription($subscription);

        $this->assertCount(1, $api->calls);
        $this->assertSame(SubscriptionStatus::ACTIVE, $resumed->status);
        $this->assertSame(['origem' => 'teste'], $resumed->metadata);
    }

    public function testCancelAtPeriodEndSchedulesTheCancellationWithoutSuspending(): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse([
            'custom_variables' => [
                (object) ['name' => 'mp_cancel_at_period_end', 'value' => '1'],
                (object) ['name' => 'mp_cancel_scheduled_for', 'value' => '2026-12-01'],
            ],
        ])]);

        $subscription = new Subscription();
        $subscription->fill(['id' => 'sub_1', 'next_billing_at' => '2026-12-01']);

        $canceled = (new IuguGateway($api))->cancelSubscription($subscription, true);

        $this->assertCount(1, $api->calls);
        $this->assertSame('PUT', $api->calls[0]['method']);
        $this->assertStringEndsWith('/subscriptions/sub_1', $api->calls[0]['url']);
        $this->assertSame(
            ['custom_variables' => [
                ['name' => 'mp_cancel_at_period_end', 'value' => '1'],
                ['name' => 'mp_cancel_scheduled_for', 'value' => '2026-12-01'],
            ]],
            $api->calls[0]['data']
        );
        $this->assertSame(SubscriptionStatus::ACTIVE, $canceled->status);
        $this->assertTrue($canceled->cancelAtPeriodEnd);
        $this->assertNull($canceled->canceledAt);
    }

    /**
     * Sem `nextBillingAt` no model, a data programada vem de uma leitura da assinatura; sem
     * data de cobrança na resposta, não há fim de período e a operação é recusada antes de
     * qualquer escrita.
     */
    public function testCancelAtPeriodEndReadsTheBillingDateWhenTheModelDoesNotHaveIt(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse(['expires_at' => '2026-11-15']),
            $this->subscriptionResponse(),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        (new IuguGateway($api))->cancelSubscription($subscription, true);

        $this->assertCount(2, $api->calls);
        $this->assertSame('GET', $api->calls[0]['method']);
        $this->assertSame(
            ['name' => 'mp_cancel_scheduled_for', 'value' => '2026-11-15'],
            $api->calls[1]['data']['custom_variables'][1]
        );
    }

    public function testCancelAtPeriodEndWithoutABillingDateIsRejected(): void
    {
        $response = $this->subscriptionResponse();
        unset($response->expires_at);
        $api = new QueuedIuguApiRequest([$response]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        try {
            (new IuguGateway($api))->cancelSubscription($subscription, true);
            $this->fail('Esperava ModelAttributeValidationException');
        } catch (ModelAttributeValidationException $e) {
            $this->assertStringContainsString('no billing date', $e->getMessage());
        }
        $this->assertCount(1, $api->calls);
        $this->assertSame('GET', $api->calls[0]['method']);
    }

    public function testChangePlanWithoutChargeSendsSkipChargeAndTheNewBillingDate(): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse()]);

        $subscription = new Subscription();
        $subscription->fill(['id' => 'sub_1', 'next_billing_at' => '2026-12-01']);

        (new IuguGateway($api))->changeSubscriptionPlan($subscription, 'plano_anual', ProrationBehavior::NONE);

        $this->assertCount(1, $api->calls);
        $this->assertSame('PUT', $api->calls[0]['method']);
        $this->assertSame('plano_anual', $api->calls[0]['data']['plan_identifier']);
        $this->assertTrue($api->calls[0]['data']['skip_charge']);
        $this->assertSame('2026-12-01', $api->calls[0]['data']['expires_at']);
    }

    public function testChangePlanWithChargeUsesTheChangePlanEndpointThenReloads(): void
    {
        $api = new QueuedIuguApiRequest([
            (object) ['success' => true],
            $this->subscriptionResponse(['plan_identifier' => 'plano_anual']),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $changed = (new IuguGateway($api))
            ->changeSubscriptionPlan($subscription, 'plano_anual', ProrationBehavior::CHARGE_DIFFERENCE);

        $this->assertCount(2, $api->calls);
        $this->assertSame('POST', $api->calls[0]['method']);
        $this->assertStringEndsWith('/subscriptions/sub_1/change_plan/plano_anual', $api->calls[0]['url']);
        $this->assertSame('GET', $api->calls[1]['method']);
        $this->assertSame('plano_anual', $changed->planId);
    }

    /**
     * `CREDIT` é limitação da Iugu: a recusa aponta `PLAN_CHANGE_PRORATION` e nenhuma
     * requisição sai.
     */
    public function testChangePlanWithCreditIsRefusedBeforeTheNetwork(): void
    {
        $api = new QueuedIuguApiRequest([]);
        $store = new InMemoryIdempotencyStore();

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        try {
            (new IuguGateway($api, $store))
                ->changeSubscriptionPlan($subscription, 'plano_anual', ProrationBehavior::CREDIT, 'chave-1');
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::PLAN_CHANGE_PRORATION, $e->capability);
            $this->assertSame('iugu', $e->gateway);
            $this->assertSame(UnsupportedOperationException::REASON_GATEWAY_LIMITATION, $e->reason);
            $this->assertStringContainsString('ProrationBehavior::CHARGE_DIFFERENCE', $e->getMessage());
        }
        $this->assertCount(0, $api->calls);
        // a recusa vem antes da chave entrar na store
        $this->assertFalse($store->has('iugu:chave-1'));
    }

    /**
     * O booleano antigo continua aceito pelo driver: `true` segue para `change_plan` e `false`
     * para o `PUT` com `skip_charge`, com aviso de obsolescência.
     */
    #[DataProvider('deprecatedChargeProvider')]
    #[IgnoreDeprecations]
    public function testChangePlanTranslatesTheDeprecatedBoolean(bool $charge, string $method, array $responses): void
    {
        $api = new QueuedIuguApiRequest($responses);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $this->expectUserDeprecationMessage(
            'O booleano $charge de changePlan() está obsoleto desde 2026-09-02; passe'
            . ' ProrationBehavior::CHARGE_DIFFERENCE ou ProrationBehavior::NONE'
        );

        (new IuguGateway($api))->changeSubscriptionPlan($subscription, 'plano_anual', $charge);

        $this->assertSame($method, $api->calls[0]['method']);
        if ($charge) {
            $this->assertStringEndsWith('/subscriptions/sub_1/change_plan/plano_anual', $api->calls[0]['url']);
        } else {
            $this->assertStringEndsWith('/subscriptions/sub_1', $api->calls[0]['url']);
            $this->assertTrue($api->calls[0]['data']['skip_charge']);
        }
    }

    public static function deprecatedChargeProvider(): array
    {
        $reloaded = (object) ['id' => 'sub_1', 'active' => true, 'plan_identifier' => 'plano_anual'];

        return [
            'true cobra pelo change_plan' => [true, 'POST', [(object) ['success' => true], $reloaded]],
            'false troca pelo PUT com skip_charge' => [false, 'PUT', [$reloaded]],
        ];
    }

    /**
     * Do model ao driver, o booleano antigo dispara um único aviso: o model o traduz e o driver
     * recebe o enum.
     */
    public function testTheDeprecatedBooleanTriggersASingleNoticeFromTheModelToTheDriver(): void
    {
        $api = new QueuedIuguApiRequest([
            (object) ['id' => 'sub_1', 'active' => true, 'plan_identifier' => 'plano_anual'],
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $notices = [];
        set_error_handler(function (int $level, string $message) use (&$notices): bool {
            $notices[] = $message;

            return true;
        }, E_USER_DEPRECATED);

        try {
            $subscription->changePlan('plano_anual', false, new IuguGateway($api));
        } finally {
            restore_error_handler();
        }

        $this->assertCount(1, $notices);
        $this->assertSame('PUT', $api->calls[0]['method']);
    }

    /**
     * O argumento nomeado `charge` antigo continua aceito pelo driver e prevalece sobre a
     * política, com aviso de obsolescência.
     */
    #[IgnoreDeprecations]
    public function testChangePlanAcceptsTheDeprecatedNamedChargeArgument(): void
    {
        $api = new QueuedIuguApiRequest([
            (object) ['id' => 'sub_1', 'active' => true, 'plan_identifier' => 'plano_anual'],
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $this->expectUserDeprecationMessage(
            'O booleano $charge de changePlan() está obsoleto desde 2026-09-02; passe'
            . ' ProrationBehavior::CHARGE_DIFFERENCE ou ProrationBehavior::NONE'
        );

        (new IuguGateway($api))->changeSubscriptionPlan($subscription, 'plano_anual', charge: false);

        $this->assertCount(1, $api->calls);
        $this->assertSame('PUT', $api->calls[0]['method']);
        $this->assertTrue($api->calls[0]['data']['skip_charge']);
    }

    /**
     * O plano da assinatura devolvida vem do gateway; o plano pedido só é mantido quando a
     * resposta não traz `plan_identifier`.
     */
    public function testChangePlanKeepsTheRequestedPlanWhenTheReloadOmitsIt(): void
    {
        $api = new QueuedIuguApiRequest([
            (object) ['success' => true],
            (object) ['id' => 'sub_1', 'active' => true],
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $changed = (new IuguGateway($api))->changeSubscriptionPlan($subscription, 'plano_anual');

        $this->assertSame('plano_anual', $changed->planId);
    }

    /**
     * Resposta real de `change_plan_simulation` gravada na sandbox, de uma assinatura com
     * subitem e desconto ativos: só `cost`, `discount`, `cycles`, `expires_at`, `new_plan` e
     * `old_plan`, com `discount` em 0 e sem linhas. O parse lê `cost` e monta uma única linha,
     * a de cobrança do plano novo; o model só com o id faz o driver ler a assinatura antes da
     * simulação, e a assinatura paga por Pix não aplica o plano na hora.
     */
    public function testPreviewPlanChangeReadsTheSimulationResponse(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse(['payable_with' => 'pix']),
            json_decode(
                file_get_contents(__DIR__ . '/../../fixtures/iugu/change_plan_simulation.json'),
                flags: JSON_THROW_ON_ERROR
            ),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $planChange = (new IuguGateway($api))
            ->previewSubscriptionPlanChange($subscription, 'multipayment-teste-destino');

        $this->assertCount(2, $api->calls);
        $this->assertSame('GET', $api->calls[0]['method']);
        $this->assertStringEndsWith('/subscriptions/sub_1', $api->calls[0]['url']);
        $this->assertStringEndsWith(
            '/subscriptions/sub_1/change_plan_simulation/multipayment-teste-destino',
            $api->calls[1]['url']
        );
        $this->assertSame(30000, $planChange->amount);
        $this->assertSame('2026-10-02', $planChange->effectiveAt->format('Y-m-d'));
        $this->assertFalse($planChange->appliesImmediately);
        $this->assertCount(1, $planChange->items);
        $this->assertSame('Plano multipayment-teste-destino', $planChange->items[0]->description);
        $this->assertSame(30000, $planChange->items[0]->price);
        $this->assertSame(1, $planChange->items[0]->quantity);
        $this->assertSame(0, $planChange->original->discount);
        $this->assertSame(1, $planChange->original->cycles);
        $this->assertSame('multipayment-teste-destino', $planChange->original->new_plan);
        $this->assertSame('multipayment-teste-origem', $planChange->original->old_plan);
        // a leitura prévia não altera o model do chamador
        $this->assertNull($subscription->availablePaymentMethods);
    }

    /**
     * A leitura prévia usa um model à parte: o cliente do model do chamador não é sobrescrito
     * com o que veio do gateway.
     */
    public function testPreviewReadsTheSubscriptionWithoutTouchingTheCallersCustomer(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'payable_with' => 'pix',
                'customer_name' => 'Nome do gateway',
                'customer_email' => 'gateway@exemplo.com',
            ]),
            (object) ['cost' => 30000, 'discount' => 0, 'expires_at' => '2026-10-02', 'new_plan' => 'p', 'old_plan' => 'o'],
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription->customer = new Customer();
        $subscription->customer->id = 'cus_1';
        $subscription->customer->name = 'Nome local';

        (new IuguGateway($api))->previewSubscriptionPlanChange($subscription, 'p');

        $this->assertCount(2, $api->calls);
        $this->assertSame('Nome local', $subscription->customer->name);
        $this->assertNull($subscription->customer->email);
    }

    /**
     * A prévia com `ProrationBehavior::CREDIT` é recusada antes de qualquer requisição, como
     * em `changeSubscriptionPlan()`: a Iugu não gera crédito ao trocar de plano.
     */
    public function testPreviewWithCreditIsRefusedBeforeTheNetwork(): void
    {
        $api = new QueuedIuguApiRequest([]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription->paymentMethod = PaymentMethod::CREDIT_CARD;

        try {
            (new IuguGateway($api))->previewSubscriptionPlanChange($subscription, 'p', ProrationBehavior::CREDIT);
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::PLAN_CHANGE_PRORATION, $e->capability);
        }

        $this->assertSame([], $api->calls);
    }

    /**
     * A Iugu tem um único endpoint de simulação, então a prévia com `NONE` devolve a mesma
     * simulação de `CHARGE_DIFFERENCE`.
     */
    public function testPreviewWithNoneReturnsTheSameSimulation(): void
    {
        $api = new QueuedIuguApiRequest([
            (object) ['cost' => 30000, 'discount' => 0, 'expires_at' => '2026-10-02', 'new_plan' => 'p', 'old_plan' => 'o'],
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription->paymentMethod = PaymentMethod::CREDIT_CARD;

        $planChange = (new IuguGateway($api))->previewSubscriptionPlanChange($subscription, 'p', ProrationBehavior::NONE);

        $this->assertCount(1, $api->calls);
        $this->assertStringContainsString('/change_plan_simulation/p', $api->calls[0]['url']);
        $this->assertSame(30000, $planChange->amount);
    }

    /**
     * A leitura preenche `currency`; a resposta da Iugu não traz o campo e vale `BRL`, a única
     * moeda que ela opera.
     */
    public function testGetSubscriptionFillsTheCurrency(): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse()]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $this->assertSame('BRL', (new IuguGateway($api))->getSubscription($subscription)->currency);
    }

    /**
     * `creditCard` é atributo de escrita e não diz como a assinatura é paga no gateway: com
     * ele sozinho o driver ainda lê a assinatura, e `payable_with: all` responde falso.
     */
    public function testPreviewReadsTheSubscriptionWhenTheModelOnlyHasACreditCard(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse(['payable_with' => 'all']),
            (object) ['cost' => 30000, 'discount' => 0, 'expires_at' => '2026-10-02', 'new_plan' => 'p', 'old_plan' => 'o'],
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription->creditCard = new CreditCard();
        $subscription->creditCard->id = 'pm_1';

        $planChange = (new IuguGateway($api))->previewSubscriptionPlanChange($subscription, 'p');

        $this->assertCount(2, $api->calls);
        $this->assertFalse($planChange->appliesImmediately);
    }

    /**
     * Com `discount` maior que zero, a lib monta a linha de crédito do plano antigo com valor
     * negativo, e a soma das linhas continua igual a `cost`.
     */
    public function testPreviewSynthesizesACreditLineWhenTheSimulationHasADiscount(): void
    {
        $api = new QueuedIuguApiRequest([
            (object) [
                'cost' => 25000,
                'discount' => 5000,
                'cycles' => 1,
                'expires_at' => '2026-10-02',
                'new_plan' => 'plano_anual',
                'old_plan' => 'plano_mensal',
            ],
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription->paymentMethod = PaymentMethod::PIX;

        $planChange = (new IuguGateway($api))->previewSubscriptionPlanChange($subscription, 'plano_anual');

        $this->assertSame(25000, $planChange->amount);
        $this->assertCount(2, $planChange->items);
        $this->assertSame('Plano plano_anual', $planChange->items[0]->description);
        $this->assertSame(30000, $planChange->items[0]->price);
        $this->assertSame('Crédito do plano plano_mensal', $planChange->items[1]->description);
        $this->assertSame(-5000, $planChange->items[1]->price);
        $this->assertSame(1, $planChange->items[1]->quantity);
        $this->assertSame(
            $planChange->amount,
            array_sum(array_map(fn (InvoiceItem $item) => $item->price * $item->quantity, $planChange->items))
        );
    }

    /**
     * Assinatura paga só com cartão aplica o plano novo na hora; o model que já traz o método
     * dispensa a leitura prévia.
     */
    public function testPreviewAppliesImmediatelyWhenTheSubscriptionIsPaidOnlyByCard(): void
    {
        $api = new QueuedIuguApiRequest([
            (object) ['cost' => 30000, 'discount' => 0, 'expires_at' => '2026-10-02', 'new_plan' => 'p', 'old_plan' => 'o'],
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription->paymentMethod = PaymentMethod::CREDIT_CARD;

        $planChange = (new IuguGateway($api))->previewSubscriptionPlanChange($subscription, 'p');

        $this->assertCount(1, $api->calls);
        $this->assertTrue($planChange->appliesImmediately);
    }

    /**
     * Model lido do gateway com `payable_with: credit_card` já traz a lista: sem leitura extra,
     * e o plano novo vale na hora.
     */
    public function testPreviewAppliesImmediatelyForASubscriptionReadWithCardOnly(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse(['payable_with' => 'credit_card']),
            (object) ['cost' => 30000, 'discount' => 0, 'expires_at' => '2026-10-02', 'new_plan' => 'p', 'old_plan' => 'o'],
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $planChange = (new IuguGateway($api))->previewSubscriptionPlanChange($subscription, 'p');

        $this->assertCount(2, $api->calls);
        $this->assertTrue($planChange->appliesImmediately);
    }

    /**
     * As linhas sintetizadas toleram totais fora do esperado: `discount` não numérico ou
     * negativo vale zero, e plano sem identificador ganha descrição genérica.
     */
    #[DataProvider('unusualSimulationTotalsProvider')]
    public function testPreviewSynthesizedLinesTolerateUnusualTotals(object $simulation, array $expectedItems): void
    {
        $api = new QueuedIuguApiRequest([$simulation]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription->paymentMethod = PaymentMethod::PIX;

        $planChange = (new IuguGateway($api))->previewSubscriptionPlanChange($subscription, 'p');

        $this->assertSame(30000, $planChange->amount);
        $this->assertSame(
            $expectedItems,
            array_map(fn (InvoiceItem $item) => $item->toArray(), $planChange->items)
        );
    }

    public static function unusualSimulationTotalsProvider(): array
    {
        return [
            'discount formatado' => [
                (object) ['cost' => 30000, 'discount' => 'R$ 50,00', 'new_plan' => 'p', 'old_plan' => 'o'],
                [['description' => 'Plano p', 'price' => 30000, 'quantity' => 1]],
            ],
            'discount negativo' => [
                (object) ['cost' => 30000, 'discount' => -5000, 'new_plan' => 'p', 'old_plan' => 'o'],
                [['description' => 'Plano p', 'price' => 30000, 'quantity' => 1]],
            ],
            'planos sem identificador' => [
                (object) ['cost' => 30000, 'discount' => 5000],
                [
                    ['description' => 'Plano novo', 'price' => 35000, 'quantity' => 1],
                    ['description' => 'Crédito do plano anterior', 'price' => -5000, 'quantity' => 1],
                ],
            ],
        ];
    }

    /**
     * Assinatura aberta a mais de um método (`payable_with: all`) não aplica na hora: o driver
     * não sabe se o cartão padrão será cobrado.
     */
    public function testPreviewDoesNotApplyImmediatelyWhenTheSubscriptionAcceptsSeveralMethods(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse(['payable_with' => 'all']),
            (object) ['cost' => 30000, 'discount' => 0, 'expires_at' => '2026-10-02', 'new_plan' => 'p', 'old_plan' => 'o'],
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $planChange = (new IuguGateway($api))->previewSubscriptionPlanChange($subscription, 'p');

        $this->assertCount(2, $api->calls);
        $this->assertFalse($planChange->appliesImmediately);
    }

    public function testPreviewPlanChangeFallsBackToPriceCentsAndSubitems(): void
    {
        $api = new QueuedIuguApiRequest([
            (object) [
                'price_cents' => 30000,
                'expires_at' => '2026-12-01',
                'subitems' => [
                    (object) ['description' => 'Plano anual', 'price_cents' => 30000, 'quantity' => 1],
                ],
            ],
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription->paymentMethod = PaymentMethod::PIX;

        $planChange = (new IuguGateway($api))->previewSubscriptionPlanChange($subscription, 'plano_anual');

        $this->assertSame(30000, $planChange->amount);
        $this->assertSame('2026-12-01', $planChange->effectiveAt->format('Y-m-d'));
        $this->assertCount(1, $planChange->items);
        $this->assertSame('Plano anual', $planChange->items[0]->description);
        $this->assertSame(30000, $planChange->items[0]->price);
    }

    public function testListSubscriptionsPaginatesByCustomer(): void
    {
        $api = new QueuedIuguApiRequest([
            (object) ['items' => [$this->subscriptionResponse(), $this->subscriptionResponse(['id' => 'sub_2'])]],
        ]);

        $customer = new Customer();
        $customer->id = 'cus_1';

        $subscriptions = (new IuguGateway($api))->listSubscriptions($customer, 2, 50);

        $this->assertStringContainsString('customer_id=cus_1', $api->calls[0]['url']);
        $this->assertStringContainsString('limit=50', $api->calls[0]['url']);
        $this->assertStringContainsString('start=50', $api->calls[0]['url']);
        $this->assertCount(2, $subscriptions);
        $this->assertSame('sub_2', $subscriptions[1]->id);
    }

    public function testPercentageDiscountIsRejected(): void
    {
        $discount = new SubscriptionDiscount();
        $discount->description = 'Promo';
        $discount->percentOff = 10;

        $subscription = new Subscription();
        $subscription->fill(['plan_id' => 'plano', 'customer' => ['id' => 'cus_1']]);
        $subscription->discounts = [$discount];

        $api = new QueuedIuguApiRequest([]);

        try {
            (new IuguGateway($api))->createSubscription($subscription);
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::PERCENT_DISCOUNT, $e->capability);
            $this->assertSame(UnsupportedOperationException::REASON_GATEWAY_LIMITATION, $e->reason);
            $this->assertStringContainsString('use amountOff', $e->getMessage());
        }
        $this->assertCount(0, $api->calls);
    }

    public function testDiscountWithoutAmountOffIsRejectedByTheMapper(): void
    {
        $discount = new SubscriptionDiscount();
        $discount->description = 'Promo';

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription->discounts = [$discount];

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/`amountOff` attribute is required/');

        (new IuguGateway(new QueuedIuguApiRequest([])))->updateSubscription($subscription);
    }

    public function testItemWithoutAmountIsRejectedByTheMapper(): void
    {
        $item = new SubscriptionItem();
        $item->description = 'X';

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription->items = [$item];

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/`amount` attribute is required/');

        (new IuguGateway(new QueuedIuguApiRequest([])))->updateSubscription($subscription);
    }

    public function testCreatePlanMapsIntervalToIuguIntervalType(): void
    {
        $api = new QueuedIuguApiRequest([
            (object) [
                'id' => 'plan_1',
                'identifier' => 'mensal',
                'name' => 'Mensal',
                'interval' => 1,
                'interval_type' => 'months',
                'prices' => [(object) ['value_cents' => 10000, 'currency' => 'BRL']],
            ],
        ]);

        $plan = new Plan();
        $plan->name = 'Mensal';
        $plan->identifier = 'mensal';
        $plan->amount = 10000;
        $plan->interval = PlanInterval::MONTH;
        $plan->intervalCount = 1;

        $created = (new IuguGateway($api))->createPlan($plan);

        $this->assertSame('months', $api->calls[0]['data']['interval_type']);
        $this->assertSame(1, $api->calls[0]['data']['interval']);
        $this->assertSame(10000, $api->calls[0]['data']['value_cents']);
        $this->assertSame(PlanInterval::MONTH, $created->interval);
        $this->assertSame(10000, $created->amount);
        $this->assertSame('BRL', $created->currency);
    }

    /**
     * A Iugu não tem intervalo anual: `year` vai como múltiplo de 12 meses e volta como `year`.
     */
    #[DataProvider('intervalRoundTripProvider')]
    public function testCreatePlanTranslatesTheIntervalBothWays(
        PlanInterval $interval,
        int $intervalCount,
        int $iuguInterval,
        string $iuguIntervalType
    ): void {
        $api = new QueuedIuguApiRequest([
            (object) [
                'id' => 'plan_1',
                'identifier' => 'plano',
                'name' => 'Plano',
                'interval' => $iuguInterval,
                'interval_type' => $iuguIntervalType,
                'prices' => [(object) ['value_cents' => 100000, 'currency' => 'BRL']],
            ],
        ]);

        $plan = new Plan();
        $plan->name = 'Plano';
        $plan->identifier = 'plano';
        $plan->amount = 100000;
        $plan->interval = $interval;
        $plan->intervalCount = $intervalCount;

        $created = (new IuguGateway($api))->createPlan($plan);

        $this->assertSame([
            'name' => 'Plano',
            'identifier' => 'plano',
            'interval' => $iuguInterval,
            'interval_type' => $iuguIntervalType,
            'value_cents' => 100000,
        ], $api->calls[0]['data']);
        $this->assertSame($interval, $created->interval);
        $this->assertSame($intervalCount, $created->intervalCount);
    }

    public static function intervalRoundTripProvider(): array
    {
        return [
            'anual' => [PlanInterval::YEAR, 1, 12, 'months'],
            'bianual' => [PlanInterval::YEAR, 2, 24, 'months'],
            'mensal' => [PlanInterval::MONTH, 1, 1, 'months'],
            'semestral' => [PlanInterval::MONTH, 6, 6, 'months'],
            'quinzenal' => [PlanInterval::WEEK, 2, 2, 'weeks'],
        ];
    }

    public function testYearlyPlanWithoutIntervalCountIsSentAsTwelveMonths(): void
    {
        $api = new QueuedIuguApiRequest([
            (object) ['id' => 'plan_1', 'identifier' => 'anual', 'name' => 'Anual', 'interval' => 12, 'interval_type' => 'months', 'value_cents' => 100000],
        ]);

        $plan = new Plan();
        $plan->name = 'Anual';
        $plan->identifier = 'anual';
        $plan->amount = 100000;
        $plan->interval = PlanInterval::YEAR;

        $created = (new IuguGateway($api))->createPlan($plan);

        $this->assertSame(12, $api->calls[0]['data']['interval']);
        $this->assertSame('months', $api->calls[0]['data']['interval_type']);
        $this->assertSame(PlanInterval::YEAR, $created->interval);
        $this->assertSame(1, $created->intervalCount);
    }

    /**
     * A heurística de leitura vale também para o model que o chamador mandou: um plano criado
     * como 12 meses sai do createPlan como 1 ano.
     */
    public function testTwelveMonthPlanIsReadBackAsYearly(): void
    {
        $api = new QueuedIuguApiRequest([
            (object) ['id' => 'plan_1', 'identifier' => 'doze', 'name' => 'Doze', 'interval' => 12, 'interval_type' => 'months', 'value_cents' => 100000],
        ]);

        $plan = new Plan();
        $plan->name = 'Doze';
        $plan->identifier = 'doze';
        $plan->amount = 100000;
        $plan->interval = PlanInterval::MONTH;
        $plan->intervalCount = 12;

        $created = (new IuguGateway($api))->createPlan($plan);

        $this->assertSame(12, $api->calls[0]['data']['interval']);
        $this->assertSame(PlanInterval::YEAR, $created->interval);
        $this->assertSame(1, $created->intervalCount);
        $this->assertSame(12, $created->original->interval);
    }

    #[DataProvider('invalidIntervalProvider')]
    public function testInvalidIntervalIsRejectedBeforeTheRequest(PlanInterval|string|null $interval, int $intervalCount, string $message): void
    {
        $api = new QueuedIuguApiRequest([]);

        $plan = new Plan();
        $plan->name = 'Plano';
        $plan->amount = 100000;
        $plan->interval = $interval;
        $plan->intervalCount = $intervalCount;

        try {
            (new IuguGateway($api))->createPlan($plan);
            $this->fail('Expected ModelAttributeValidationException');
        } catch (ModelAttributeValidationException $e) {
            $this->assertMatchesRegularExpression($message, $e->getMessage());
        }

        $this->assertCount(0, $api->calls);
    }

    public static function invalidIntervalProvider(): array
    {
        return [
            'diário como string' => ['day', 1, '/does not support the `day` plan interval/'],
            'diário como enum' => [PlanInterval::DAY, 1, '/does not support the `day` plan interval/'],
            'intervalo ausente' => [null, 1, '/does not support the `null` plan interval/'],
            'anual acima do teto da Iugu' => [PlanInterval::YEAR, 50, '/from 1 to 599 months, 600 given/'],
            'mensal acima do teto da Iugu' => [PlanInterval::MONTH, 600, '/from 1 to 599 months, 600 given/'],
        ];
    }

    public function testGetPlanKeepsTheLocalIntervalWhenTheResponseOmitsIt(): void
    {
        $api = new QueuedIuguApiRequest([
            (object) ['id' => 'plan_1', 'name' => 'Plano', 'interval_type' => 'days', 'value_cents' => 10000],
        ]);

        $plan = new Plan();
        $plan->id = 'plan_1';
        $plan->interval = PlanInterval::YEAR;
        $plan->intervalCount = 1;

        $found = (new IuguGateway($api))->getPlan($plan);

        $this->assertSame(PlanInterval::YEAR, $found->interval);
        $this->assertSame(1, $found->intervalCount);
    }

    /**
     * Na leitura, múltiplo de 12 meses vira `year`; o resto mantém o tipo da Iugu.
     */
    #[DataProvider('iuguIntervalParseProvider')]
    public function testGetPlanParsesTheIuguInterval(
        int|string $iuguInterval,
        string $iuguIntervalType,
        PlanInterval $interval,
        int $intervalCount
    ): void {
        $api = new QueuedIuguApiRequest([
            (object) ['id' => 'plan_1', 'identifier' => 'plano', 'name' => 'Plano', 'interval' => $iuguInterval, 'interval_type' => $iuguIntervalType, 'value_cents' => 10000],
        ]);

        $plan = new Plan();
        $plan->id = 'plan_1';

        $found = (new IuguGateway($api))->getPlan($plan);

        $this->assertSame($interval, $found->interval);
        $this->assertSame($intervalCount, $found->intervalCount);
    }

    public static function iuguIntervalParseProvider(): array
    {
        return [
            '12 meses vira 1 ano' => [12, 'months', PlanInterval::YEAR, 1],
            '24 meses vira 2 anos' => [24, 'months', PlanInterval::YEAR, 2],
            '6 meses continua mensal' => [6, 'months', PlanInterval::MONTH, 6],
            '1 mês continua mensal' => [1, 'months', PlanInterval::MONTH, 1],
            '12 semanas continua semanal' => [12, 'weeks', PlanInterval::WEEK, 12],
            '12 como string vira 1 ano' => ['12', 'months', PlanInterval::YEAR, 1],
        ];
    }

    public function testDeactivatePlanIsRejected(): void
    {
        $plan = new Plan();
        $plan->id = 'plan_1';

        $api = new QueuedIuguApiRequest([]);

        try {
            (new IuguGateway($api))->deactivatePlan($plan);
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::PLAN_DEACTIVATION, $e->capability);
            $this->assertSame(UnsupportedOperationException::REASON_GATEWAY_LIMITATION, $e->reason);
            $this->assertStringContainsString('flag de ativo', $e->getMessage());
        }
        $this->assertCount(0, $api->calls);
    }

    public function testGatewayErrorsBecomeGatewayException(): void
    {
        $api = new QueuedIuguApiRequest([(object) ['errors' => ['plan_identifier' => 'não encontrado']]]);

        $subscription = new Subscription();
        $subscription->fill(['plan_id' => 'inexistente', 'customer' => ['id' => 'cus_1']]);

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/creating subscription/');

        (new IuguGateway($api))->createSubscription($subscription);
    }

    /**
     * A Iugu não tem estado de inadimplência: fatura vencida em aberto deixa a assinatura
     * `active` com `expires_at` no passado.
     */
    public function testDerivesPastDueFromOverdueDateAndUnpaidInvoice(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'expires_at' => '2026-08-01',
                'recent_invoices' => [
                    (object) ['id' => 'inv_1', 'status' => 'pending', 'due_date' => '2026-08-01', 'secure_url' => 'https://iugu/inv_1'],
                ],
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->status);
        $this->assertInstanceOf(Invoice::class, $subscription->latestInvoice);
        $this->assertSame('inv_1', $subscription->latestInvoice->id);
        $this->assertSame('https://iugu/inv_1', $subscription->latestInvoice->url);
    }

    /**
     * A fatura resumida de `recent_invoices` também vem do objeto de fatura da Iugu:
     * `originType` é `INVOICE`.
     */
    public function testLatestInvoiceMarksTheOriginAsInvoice(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'recent_invoices' => [(object) ['id' => 'inv_1', 'status' => 'paid']],
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertSame(InvoiceOriginType::INVOICE, $subscription->latestInvoice->originType);
    }

    /**
     * `expired` conta como dívida, mas o status genérico da fatura é canceled.
     */
    public function testExpiredInvoiceAlsoCountsAsPastDue(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'expires_at' => '2026-08-01',
                'recent_invoices' => [(object) ['id' => 'inv_1', 'status' => 'expired']],
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->status);
        $this->assertSame(InvoiceStatus::EXPIRED, $subscription->latestInvoice->status);
    }

    public function testPaidInvoiceWithOverdueDateIsNotPastDue(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'expires_at' => '2026-08-01',
                'recent_invoices' => [(object) ['id' => 'inv_1', 'status' => 'paid']],
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $this->assertSame(
            SubscriptionStatus::ACTIVE,
            (new IuguGateway($api))->getSubscription($subscription)->status
        );
    }

    public function testUnpaidInvoiceWithFutureBillingDateIsNotPastDue(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'expires_at' => '2099-01-01',
                'recent_invoices' => [(object) ['id' => 'inv_1', 'status' => 'pending']],
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $this->assertSame(
            SubscriptionStatus::ACTIVE,
            (new IuguGateway($api))->getSubscription($subscription)->status
        );
    }

    public function testSuspendedTakesPrecedenceOverPastDue(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'suspended' => true,
                'expires_at' => '2026-08-01',
                'recent_invoices' => [(object) ['id' => 'inv_1', 'status' => 'pending']],
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $this->assertSame(
            SubscriptionStatus::SUSPENDED,
            (new IuguGateway($api))->getSubscription($subscription)->status
        );
    }

    /**
     * `recent_invoices` é um resumo: status fora do mapa vira `UNKNOWN` com aviso no log, e
     * a leitura da assinatura segue.
     */
    public function testUnknownInvoiceStatusDoesNotBreakTheSubscriptionRead(): void
    {
        Facade::getFacadeApplication()->instance('log', $logger = new RecordingLogger());
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'recent_invoices' => [(object) ['id' => 'inv_1', 'status' => 'status_novo_da_iugu']],
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertSame('inv_1', $subscription->latestInvoice->id);
        $this->assertSame(InvoiceStatus::UNKNOWN, $subscription->latestInvoice->status);
        $this->assertSame('status_novo_da_iugu', $subscription->latestInvoice->original->status);
        $this->assertCount(1, $logger->records);
        $this->assertSame(['status' => 'status_novo_da_iugu', 'gateway' => 'iugu'], $logger->records[0]['context']);
    }

    public function testSubscriptionWithoutRecentInvoicesHasNoLatestInvoice(): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse()]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $this->assertNull((new IuguGateway($api))->getSubscription($subscription)->latestInvoice);
    }

    /**
     * Lista não informada mantém os subitens daquele tipo.
     */
    public function testUpdatingOnlyItemsDoesNotDestroyDiscounts(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'subitems' => [
                    (object) ['id' => 'si_item_antigo', 'description' => 'Antigo', 'price_cents' => 100, 'quantity' => 1, 'recurrent' => true],
                    (object) ['id' => 'si_desconto', 'description' => 'Promo', 'price_cents' => -500, 'quantity' => 1, 'recurrent' => true],
                ],
            ]),
            $this->subscriptionResponse(),
            $this->subscriptionResponse(),
        ]);

        $subscription = new Subscription();
        $subscription->fill([
            'id' => 'sub_1',
            'items' => [['description' => 'Novo', 'amount' => 300, 'quantity' => 1]],
        ]);

        (new IuguGateway($api))->updateSubscription($subscription);

        $this->assertCount(3, $api->calls);
        $this->assertSame(
            [['id' => 'si_item_antigo', '_destroy' => true]],
            $api->calls[1]['data']['subitems']
        );
        $this->assertSame(
            [['description' => 'Novo', 'price_cents' => 300, 'quantity' => 1, 'recurrent' => 1]],
            $api->calls[2]['data']['subitems']
        );
    }

    public function testUpdatingOnlyDiscountsDoesNotDestroyItems(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'subitems' => [
                    (object) ['id' => 'si_item', 'description' => 'Consultas', 'price_cents' => 100, 'quantity' => 1, 'recurrent' => true],
                    (object) ['id' => 'si_desconto_antigo', 'description' => 'Promo', 'price_cents' => -500, 'quantity' => 1, 'recurrent' => true],
                ],
            ]),
            $this->subscriptionResponse(),
            $this->subscriptionResponse(),
        ]);

        $subscription = new Subscription();
        $subscription->fill([
            'id' => 'sub_1',
            'discounts' => [['description' => 'Nova promo', 'amount_off' => 300]],
        ]);

        (new IuguGateway($api))->updateSubscription($subscription);

        $this->assertCount(3, $api->calls);
        $this->assertSame(
            [['id' => 'si_desconto_antigo', '_destroy' => true]],
            $api->calls[1]['data']['subitems']
        );
        $this->assertSame(
            [['description' => 'Nova promo', 'price_cents' => -300, 'quantity' => 1, 'recurrent' => 1]],
            $api->calls[2]['data']['subitems']
        );
    }

    public function testEmptyItemListRemovesEveryItemAndKeepsDiscounts(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'subitems' => [
                    (object) ['id' => 'si_a', 'price_cents' => 100],
                    (object) ['id' => 'si_b', 'price_cents' => 200],
                    (object) ['id' => 'si_desconto', 'price_cents' => -50],
                ],
            ]),
            $this->subscriptionResponse(),
            $this->subscriptionResponse(),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription->items = [];

        (new IuguGateway($api))->updateSubscription($subscription);

        $this->assertSame([
            ['id' => 'si_a', '_destroy' => true],
            ['id' => 'si_b', '_destroy' => true],
        ], $api->calls[1]['data']['subitems']);
        $this->assertArrayNotHasKey('subitems', $api->calls[2]['data']);
    }

    public function testSubitemsFromTheGatewayAreAcceptedAsAssociativeArrays(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse(['subitems' => [['id' => 'si_a', 'price_cents' => 100]]]),
            $this->subscriptionResponse(),
            $this->subscriptionResponse(),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription->items = [];

        (new IuguGateway($api))->updateSubscription($subscription);

        $this->assertSame([['id' => 'si_a', '_destroy' => true]], $api->calls[1]['data']['subitems']);
    }

    public function testGatewayOptionsAlsoOverrideTheUpdatePayload(): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse()]);

        $subscription = new Subscription();
        $subscription->fill(['id' => 'sub_1', 'next_billing_at' => '2026-11-01']);
        $subscription->gatewayOptions = ['expires_at' => '2026-12-25', 'ignore_due_email' => true];

        (new IuguGateway($api))->updateSubscription($subscription);

        $this->assertSame(
            ['expires_at' => '2026-12-25', 'ignore_due_email' => true],
            $api->calls[0]['data']
        );
    }

    /**
     * Na Iugu o fim do trial e a próxima cobrança são o mesmo campo.
     */
    public function testTrialEndsAtIsSentAsExpiresAt(): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse()]);

        $subscription = new Subscription();
        $subscription->fill([
            'plan_id' => 'plano_mensal',
            'customer' => ['id' => 'cus_1'],
            'trial_ends_at' => '2026-09-15',
        ]);

        (new IuguGateway($api))->createSubscription($subscription);

        $this->assertSame('2026-09-15', $api->calls[0]['data']['expires_at']);
    }

    public function testNextBillingAtAndTrialEndsAtTogetherAreRejected(): void
    {
        $subscription = new Subscription();
        $subscription->fill([
            'plan_id' => 'plano_mensal',
            'customer' => ['id' => 'cus_1'],
            'next_billing_at' => '2026-10-01',
            'trial_ends_at' => '2026-09-15',
        ]);

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/same field/');

        (new IuguGateway(new QueuedIuguApiRequest([])))->createSubscription($subscription);
    }

    #[DataProvider('payableWithProvider')]
    public function testParseMapsPayableWithBackToGenericMethods($payableWith, array $expected): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse(['payable_with' => $payableWith])]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $this->assertSame(
            $expected,
            (new IuguGateway($api))->getSubscription($subscription)->availablePaymentMethods
        );
    }

    public static function payableWithProvider(): array
    {
        return [
            'lista' => [
                ['credit_card', 'pix'],
                [PaymentMethod::CREDIT_CARD, PaymentMethod::PIX],
            ],
            'metodo desconhecido e ignorado' => [
                ['pix', 'crypto'],
                [PaymentMethod::PIX],
            ],
            'all expande nos tres' => [
                'all',
                [
                    PaymentMethod::CREDIT_CARD,
                    PaymentMethod::BANK_SLIP,
                    PaymentMethod::PIX,
                ],
            ],
        ];
    }

    public function testParseReadsMetadataAndCreatedAt(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'custom_variables' => [(object) ['name' => 'origem', 'value' => 'teste']],
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertSame(['origem' => 'teste'], $subscription->metadata);
        $this->assertSame('2026-09-01', $subscription->createdAt->format('Y-m-d'));
    }

    public function testGetPlanUsesTheIdentifierEndpointWhenThereIsNoId(): void
    {
        $api = new QueuedIuguApiRequest([
            (object) ['id' => 'plan_1', 'identifier' => 'mensal', 'name' => 'Mensal', 'interval' => 1, 'interval_type' => 'months', 'value_cents' => 10000],
        ]);

        $plan = new Plan();
        $plan->identifier = 'mensal';

        $found = (new IuguGateway($api))->getPlan($plan);

        $this->assertStringEndsWith('/plans/identifier/mensal', $api->calls[0]['url']);
        $this->assertSame(10000, $found->amount);
        $this->assertSame(PlanInterval::MONTH, $found->interval);
    }

    public function testGetPlanRequiresIdOrIdentifier(): void
    {
        $this->expectException(ModelAttributeValidationException::class);

        (new IuguGateway(new QueuedIuguApiRequest([])))->getPlan(new Plan());
    }

    public function testListPlansPaginates(): void
    {
        $api = new QueuedIuguApiRequest([
            (object) ['items' => [(object) ['id' => 'plan_1', 'name' => 'Mensal', 'interval_type' => 'months']]],
        ]);

        $plans = (new IuguGateway($api))->listPlans(3, 10);

        $this->assertStringContainsString('limit=10', $api->calls[0]['url']);
        $this->assertStringContainsString('start=20', $api->calls[0]['url']);
        $this->assertCount(1, $plans);
        $this->assertSame(PlanInterval::MONTH, $plans[0]->interval);
        $this->assertNull($plans[0]->intervalCount);
    }

    #[DataProvider('methodsThatRequireSubscriptionIdProvider')]
    public function testMethodsRequireTheSubscriptionId(callable $call): void
    {
        $this->expectException(ModelAttributeValidationException::class);

        $call(new IuguGateway(new QueuedIuguApiRequest([])), new Subscription());
    }

    public static function methodsThatRequireSubscriptionIdProvider(): array
    {
        return [
            'get' => [fn(IuguGateway $g, Subscription $s) => $g->getSubscription($s)],
            'update' => [fn(IuguGateway $g, Subscription $s) => $g->updateSubscription($s)],
            'suspend' => [fn(IuguGateway $g, Subscription $s) => $g->suspendSubscription($s)],
            'resume' => [fn(IuguGateway $g, Subscription $s) => $g->resumeSubscription($s)],
            'cancel' => [fn(IuguGateway $g, Subscription $s) => $g->cancelSubscription($s)],
            'changePlan' => [fn(IuguGateway $g, Subscription $s) => $g->changeSubscriptionPlan($s, 'p')],
            'preview' => [fn(IuguGateway $g, Subscription $s) => $g->previewSubscriptionPlanChange($s, 'p')],
        ];
    }

    public function testCreateRequiresACustomerWithId(): void
    {
        $subscription = new Subscription();
        $subscription->planId = 'plano';

        $this->expectException(ModelAttributeValidationException::class);

        (new IuguGateway(new QueuedIuguApiRequest([])))->createSubscription($subscription);
    }

    public function testListSubscriptionsRequiresTheCustomerId(): void
    {
        $this->expectException(ModelAttributeValidationException::class);

        (new IuguGateway(new QueuedIuguApiRequest([])))->listSubscriptions(new Customer());
    }

    #[DataProvider('invalidPaginationProvider')]
    public function testPaginationBoundsAreRejected(int $page, int $limit, string $mensagem): void
    {
        $customer = new Customer();
        $customer->id = 'cus_1';
        $gateway = new IuguGateway(new QueuedIuguApiRequest([]));

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches($mensagem);

        $gateway->listSubscriptions($customer, $page, $limit);
    }

    #[DataProvider('invalidPaginationProvider')]
    public function testPlanPaginationBoundsAreRejected(int $page, int $limit, string $mensagem): void
    {
        $gateway = new IuguGateway(new QueuedIuguApiRequest([]));

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches(str_replace('Subscription', 'Plan', $mensagem));

        $gateway->listPlans($page, $limit);
    }

    public static function invalidPaginationProvider(): array
    {
        return [
            'pagina zero' => [0, 100, '/Subscription page must be at least 1/'],
            'limite zero' => [1, 0, '/Subscription limit must be between 1 and 100/'],
            'limite acima de 100' => [1, 101, '/Subscription limit must be between 1 and 100/'],
        ];
    }

    /**
     * Com chave de idempotência, o `PUT` da marca de cancelamento recebe a chave derivada
     * `{chave}:cancel`, e o retry inteiro sai da store sem nova requisição.
     */
    public function testCancelStoresTheSuspensionAndTheMarkUnderTheirOwnKeys(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse(['suspended' => true]),
            $this->subscriptionResponse([
                'suspended' => true,
                'custom_variables' => [(object) ['name' => 'mp_canceled_at', 'value' => '2026-09-02T10:00:00-03:00']],
            ]),
        ]);
        $store = new \Potelo\MultiPayment\Idempotency\InMemoryIdempotencyStore();
        $gateway = new IuguGateway($api, $store);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $gateway->cancelSubscription($subscription, false, 'chave-1');
        $this->assertTrue($store->has('iugu:chave-1'));
        $this->assertTrue($store->has('iugu:chave-1:cancel'));

        $again = new Subscription();
        $again->id = 'sub_1';
        $retried = $gateway->cancelSubscription($again, false, 'chave-1');

        $this->assertCount(2, $api->calls);
        $this->assertSame(SubscriptionStatus::CANCELED, $retried->status);
    }

    public function testListSubscriptionsAcceptsAPlainArrayResponse(): void
    {
        $api = new QueuedIuguApiRequest([[$this->subscriptionResponse()]]);

        $customer = new Customer();
        $customer->id = 'cus_1';

        $this->assertCount(1, (new IuguGateway($api))->listSubscriptions($customer));
    }

    /**
     * O parse preenche nextBillingAt e trialEndsAt do mesmo `expires_at`, então ler e regravar
     * uma assinatura em trial não pode ser recusado por conflito.
     */
    public function testSubscriptionReadWhileInTrialCanBeUpdatedBack(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse(['in_trial' => true]),
            $this->subscriptionResponse(['in_trial' => true]),
        ]);
        $gateway = new IuguGateway($api);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = $gateway->getSubscription($subscription);

        $this->assertEquals($subscription->nextBillingAt, $subscription->trialEndsAt);
        $this->assertNotSame($subscription->nextBillingAt, $subscription->trialEndsAt);

        $subscription->metadata = ['origem' => 'teste'];
        $gateway->updateSubscription($subscription);

        $this->assertArrayNotHasKey('expires_at', $api->calls[1]['data']);
    }

    /**
     * Update reafirmando o que veio da leitura rebobinaria a data de cobrança quando o model
     * está velho.
     */
    public function testUpdateDoesNotResendTheBillingDateItJustRead(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse(),
            $this->subscriptionResponse(),
        ]);
        $gateway = new IuguGateway($api);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = $gateway->getSubscription($subscription);

        $subscription->metadata = ['origem' => 'teste'];
        $gateway->updateSubscription($subscription);

        $this->assertArrayNotHasKey('expires_at', $api->calls[1]['data']);
        $this->assertSame(
            [['name' => 'origem', 'value' => 'teste']],
            $api->calls[1]['data']['custom_variables']
        );
    }

    public function testUpdateSendsTheBillingDateWhenItActuallyChanged(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse(),
            $this->subscriptionResponse(),
        ]);
        $gateway = new IuguGateway($api);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = $gateway->getSubscription($subscription);

        $subscription->nextBillingAt = \Carbon\Carbon::parse('2026-12-01');
        $subscription->trialEndsAt = null;
        $gateway->updateSubscription($subscription);

        $this->assertSame('2026-12-01', $api->calls[1]['data']['expires_at']);
    }

    /**
     * `all` na Iugu significa os métodos habilitados na conta; reenviá-lo como lista fixa
     * trocaria a configuração da assinatura sem que ninguém pedisse.
     */
    public function testUpdateDoesNotTurnPayableWithAllIntoAFixedList(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse(['payable_with' => 'all']),
            $this->subscriptionResponse(),
        ]);
        $gateway = new IuguGateway($api);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = $gateway->getSubscription($subscription);

        $this->assertCount(3, $subscription->availablePaymentMethods);

        $subscription->metadata = ['origem' => 'teste'];
        $gateway->updateSubscription($subscription);

        $this->assertArrayNotHasKey('payable_with', $api->calls[1]['data']);
    }

    public function testUpdateSendsPaymentMethodsWhenTheyActuallyChanged(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse(['payable_with' => 'all']),
            $this->subscriptionResponse(),
        ]);
        $gateway = new IuguGateway($api);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = $gateway->getSubscription($subscription);

        $subscription->availablePaymentMethods = [PaymentMethod::PIX];
        $gateway->updateSubscription($subscription);

        $this->assertSame(['pix'], $api->calls[1]['data']['payable_with']);
    }

    public function testDivergingNextBillingAndTrialEndAreStillRejected(): void
    {
        $subscription = new Subscription();
        $subscription->fill([
            'plan_id' => 'p',
            'customer' => ['id' => 'cus_1'],
            'next_billing_at' => '2026-10-01',
            'trial_ends_at' => '2026-09-15',
        ]);

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/different dates/');

        (new IuguGateway(new QueuedIuguApiRequest([])))->createSubscription($subscription);
    }

    /**
     * `price_cents` do subitem é unitário, então o desconto abatido é o valor vezes a
     * quantidade.
     */
    public function testDiscountAmountAccountsForTheSubitemQuantity(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'subitems' => [
                    (object) ['id' => 'si_d', 'description' => 'Promo', 'price_cents' => -500, 'quantity' => 3, 'recurrent' => true],
                ],
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertSame(1500, $subscription->discounts[0]->amountOff);
    }

    /**
     * `false` vira string vazia no encoder do SDK.
     */
    public function testRecurrentGoesAsIntegerInThePayload(): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse()]);

        $subscription = new Subscription();
        $subscription->fill([
            'plan_id' => 'p',
            'customer' => ['id' => 'cus_1'],
            'items' => [['description' => 'Setup', 'amount' => 500, 'quantity' => 1, 'recurring' => false]],
            'discounts' => [['description' => 'Promo', 'amount_off' => 300, 'cycles' => 1]],
        ]);

        (new IuguGateway($api))->createSubscription($subscription);

        $this->assertSame(0, $api->calls[0]['data']['subitems'][0]['recurrent']);
        $this->assertSame(0, $api->calls[0]['data']['subitems'][1]['recurrent']);
    }

    public function testSubitemIdComparisonDoesNotDependOnTheJsonType(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse(['subitems' => [(object) ['id' => 123, 'price_cents' => 100]]]),
            $this->subscriptionResponse(),
        ]);

        $subscription = new Subscription();
        $subscription->fill([
            'id' => 'sub_1',
            'items' => [['id' => '123', 'description' => 'Mantido', 'amount' => 100, 'quantity' => 1]],
        ]);

        (new IuguGateway($api))->updateSubscription($subscription);

        // nada a destruir: o id 123 do gateway é o mesmo '123' da lista desejada
        $this->assertCount(2, $api->calls);
        $this->assertSame('PUT', $api->calls[1]['method']);
    }

    /**
     * O `total_cents` da Iugu pode vir formatado, e valor não numérico não vira amount.
     */
    public function testPlanChangeIgnoresNonNumericAmountFields(): void
    {
        $api = new QueuedIuguApiRequest([(object) ['total_cents' => 'R$ 300,00']]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription->paymentMethod = PaymentMethod::PIX;

        $planChange = (new IuguGateway($api))->previewSubscriptionPlanChange($subscription, 'p');

        $this->assertNull($planChange->amount);
        // sem total não há linha a sintetizar
        $this->assertSame([], $planChange->items);
    }

    public function testStatusKeepsThePreviousValueWhenTheResponseHasNoFlags(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'recent_invoices' => [(object) ['id' => 'inv_1', 'status' => 'paid']],
            ]),
            (object) ['id' => 'sub_1'],
        ]);
        $gateway = new IuguGateway($api);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = $gateway->getSubscription($subscription);
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);

        $subscription = $gateway->updateSubscription($subscription);

        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertSame('inv_1', $subscription->latestInvoice->id);
    }

    public function testPlanChangeFallsBackWhenCostIsNotNumeric(): void
    {
        $api = new QueuedIuguApiRequest([(object) ['cost' => 'R$ 300,00', 'price_cents' => 30000]]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription->paymentMethod = PaymentMethod::PIX;

        $this->assertSame(
            30000,
            (new IuguGateway($api))->previewSubscriptionPlanChange($subscription, 'p')->amount
        );
    }

    /**
     * Entre faturas do mesmo estado, vence a de maior vencimento, em qualquer ordem de resposta.
     */
    #[DataProvider('ordemProvider')]
    public function testLatestInvoiceDoesNotDependOnTheOrderIuguReturns(array $recentInvoices): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'expires_at' => '2026-08-01',
                'recent_invoices' => $recentInvoices,
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertSame('inv_recente', $subscription->latestInvoice->id);
        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->status);
    }

    public static function ordemProvider(): array
    {
        $antiga = (object) ['id' => 'inv_antiga', 'status' => 'pending', 'due_date' => '2026-06-01'];
        $recente = (object) ['id' => 'inv_recente', 'status' => 'pending', 'due_date' => '2026-08-01'];

        return [
            'antiga primeiro' => [[$antiga, $recente]],
            'recente primeiro' => [[$recente, $antiga]],
        ];
    }

    /**
     * Vencimento igual é desempatado pelo menor id, qualquer que seja o estado das faturas.
     */
    #[DataProvider('tieProvider')]
    public function testSameDueDateIsBrokenByTheSmallestId(array $recentInvoices): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'expires_at' => '2026-08-01',
                'recent_invoices' => $recentInvoices,
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertSame('inv_a_paga', $subscription->latestInvoice->id);
        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->status);
    }

    public static function tieProvider(): array
    {
        return [
            'paga primeiro' => [[
                (object) ['id' => 'inv_a_paga', 'status' => 'paid', 'due_date' => '2026-08-01'],
                (object) ['id' => 'inv_z_aberta', 'status' => 'pending', 'due_date' => '2026-08-01'],
            ]],
            'aberta primeiro' => [[
                (object) ['id' => 'inv_z_aberta', 'status' => 'pending', 'due_date' => '2026-08-01'],
                (object) ['id' => 'inv_a_paga', 'status' => 'paid', 'due_date' => '2026-08-01'],
            ]],
            'paga como externally_paid' => [[
                (object) ['id' => 'inv_a_paga', 'status' => 'externally_paid', 'due_date' => '2026-08-01'],
                (object) ['id' => 'inv_z_aberta', 'status' => 'expired', 'due_date' => '2026-08-01'],
            ]],
        ];
    }

    /**
     * Cliente de outro id não é o mesmo cliente: o parse troca o objeto em vez de misturar os
     * atributos.
     */
    public function testParseReplacesTheCustomerWhenTheGatewayReturnsAnotherId(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse(['customer_id' => 'cus_outro', 'customer_name' => 'Beltrano']),
        ]);

        $subscription = new Subscription();
        $subscription->fill([
            'id' => 'sub_1',
            'customer' => ['id' => 'cus_1', 'tax_document' => '20176996915'],
        ]);

        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertSame('cus_outro', $subscription->customer->id);
        $this->assertSame('Beltrano', $subscription->customer->name);
        $this->assertNull($subscription->customer->taxDocument);
    }

    /**
     * O parse preenche o cliente existente em vez de trocá-lo por um só com id.
     */
    public function testParseKeepsTheCustomerItAlreadyHad(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'customer_name' => 'Fulano da Silva',
                'customer_email' => 'fulano@exemplo.com',
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->fill(['id' => 'sub_1', 'customer' => ['id' => 'cus_1', 'tax_document' => '20176996915']]);
        $original = $subscription->customer;

        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertSame($original, $subscription->customer);
        $this->assertSame('20176996915', $subscription->customer->taxDocument);
        $this->assertSame('Fulano da Silva', $subscription->customer->name);
        $this->assertSame('fulano@exemplo.com', $subscription->customer->email);
    }

    /**
     * Empate de vencimento e de estado é resolvido pelo menor id, em qualquer ordem.
     */
    #[DataProvider('mesmaSituacaoProvider')]
    public function testLatestInvoiceIsStableWhenDueDateAndStateTie(array $recentInvoices): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse(['recent_invoices' => $recentInvoices]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $this->assertSame(
            'inv_a',
            (new IuguGateway($api))->getSubscription($subscription)->latestInvoice->id
        );
    }

    public static function mesmaSituacaoProvider(): array
    {
        return [
            'ordem crescente' => [[
                (object) ['id' => 'inv_a', 'status' => 'pending', 'due_date' => '2026-08-01'],
                (object) ['id' => 'inv_b', 'status' => 'pending', 'due_date' => '2026-08-01'],
            ]],
            'ordem inversa' => [[
                (object) ['id' => 'inv_b', 'status' => 'pending', 'due_date' => '2026-08-01'],
                (object) ['id' => 'inv_a', 'status' => 'pending', 'due_date' => '2026-08-01'],
            ]],
        ];
    }

    public function testParseKeepsTheLocalNameWhenTheResponseOmitsIt(): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse()]);

        $subscription = new Subscription();
        $subscription->fill([
            'id' => 'sub_1',
            'customer' => ['id' => 'cus_1', 'name' => 'Fulano', 'email' => 'fulano@exemplo.com'],
        ]);

        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertSame('Fulano', $subscription->customer->name);
        $this->assertSame('fulano@exemplo.com', $subscription->customer->email);
    }

    /**
     * Entrada sem id não serve como latestInvoice, porque não dá para buscá-la, mas continua
     * valendo como fatura em aberto para a inadimplência.
     */
    public function testRecentInvoiceWithoutIdIsNotSelectableButStillCountsAsDebt(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'expires_at' => '2026-08-01',
                'recent_invoices' => [(object) ['status' => 'pending', 'due_date' => '2026-08-01']],
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertNull($subscription->latestInvoice);
        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->status);
    }

    /**
     * Vencimento hoje ainda não é inadimplência: a carência vai até o fim do dia.
     */
    public function testSubscriptionDueTodayWithAnOpenInvoiceIsNotPastDueYet(): void
    {
        $hoje = Carbon::today()->toDateString();
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'expires_at' => $hoje,
                'recent_invoices' => [(object) ['id' => 'inv_1', 'status' => 'pending', 'due_date' => $hoje]],
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $this->assertSame(
            SubscriptionStatus::ACTIVE,
            (new IuguGateway($api))->getSubscription($subscription)->status
        );
    }

    /**
     * Fatura cancelada não é dívida.
     */
    public function testCanceledInvoiceDoesNotMakeTheSubscriptionPastDue(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'expires_at' => '2026-08-01',
                'recent_invoices' => [(object) ['id' => 'inv_1', 'status' => 'canceled', 'due_date' => '2026-08-01']],
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $this->assertSame(
            SubscriptionStatus::ACTIVE,
            (new IuguGateway($api))->getSubscription($subscription)->status
        );
    }

    /**
     * Cliente local sem id não é o mesmo cliente: o parse devolve a visão do gateway em vez de
     * misturar os atributos.
     */
    public function testParseReplacesTheCustomerWhenTheLocalOneHasNoId(): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse(['customer_name' => 'Beltrano'])]);

        $subscription = new Subscription();
        $subscription->fill(['id' => 'sub_1', 'customer' => ['tax_document' => '20176996915']]);

        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertSame('cus_1', $subscription->customer->id);
        $this->assertSame('Beltrano', $subscription->customer->name);
        $this->assertNull($subscription->customer->taxDocument);
    }

    /**
     * Fatura cancelada de vencimento posterior não esconde a pendente anterior, nem na
     * inadimplência nem na escolha da fatura que representa a assinatura.
     */
    public function testPastDueLooksAtEveryInvoiceNotOnlyTheChosenOne(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'expires_at' => '2026-08-01',
                'recent_invoices' => [
                    (object) ['id' => 'inv_pendente', 'status' => 'pending', 'due_date' => '2026-08-01'],
                    (object) ['id' => 'inv_cancelada', 'status' => 'canceled', 'due_date' => '2026-08-05'],
                ],
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertSame('inv_cancelada', $subscription->latestInvoice->id);
        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->status);
    }

    /**
     * `latestInvoice` é a mais recente, não a que gerou a inadimplência: em `past_due` ela pode
     * estar quitada, e quem precisa da fatura a pagar tem de buscá-la pelo id.
     */
    public function testPastDueCanPointAtAnInvoiceThatIsAlreadyPaid(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'expires_at' => '2026-08-01',
                'recent_invoices' => [
                    (object) ['id' => 'inv_aberta', 'status' => 'pending', 'due_date' => '2026-07-01'],
                    (object) ['id' => 'inv_paga', 'status' => 'paid', 'due_date' => '2026-08-01'],
                ],
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->status);
        $this->assertSame('inv_paga', $subscription->latestInvoice->id);
        $this->assertSame(InvoiceStatus::PAID, $subscription->latestInvoice->status);
    }

    /**
     * Sem fatura em aberto, a escolhida é a de maior vencimento.
     */
    public function testWithoutAnyOpenInvoiceTheLatestOneIsChosen(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'recent_invoices' => [
                    (object) ['id' => 'inv_antiga', 'status' => 'paid', 'due_date' => '2026-07-01'],
                    (object) ['id' => 'inv_recente', 'status' => 'paid', 'due_date' => '2026-08-01'],
                ],
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $this->assertSame(
            'inv_recente',
            (new IuguGateway($api))->getSubscription($subscription)->latestInvoice->id
        );
    }

    /**
     * Fatura paga pela metade continua com valor a receber.
     */
    public function testPartiallyPaidInvoiceCountsAsOpen(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'expires_at' => '2026-08-01',
                'recent_invoices' => [
                    (object) ['id' => 'inv_parcial', 'status' => 'partially_paid', 'due_date' => '2026-08-01'],
                ],
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertSame(InvoiceStatus::PARTIALLY_PAID, $subscription->latestInvoice->status);
        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->status);
    }

    /**
     * Entrada sem id não vence a escolha, mesmo com vencimento maior.
     */
    public function testRecentInvoiceWithoutIdDoesNotHijackTheChoice(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'expires_at' => '2026-08-01',
                'recent_invoices' => [
                    (object) ['id' => 'inv_1', 'status' => 'pending', 'due_date' => '2026-08-01'],
                    (object) ['status' => 'pending', 'due_date' => '2026-08-02'],
                ],
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertSame('inv_1', $subscription->latestInvoice->id);
        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->status);
    }
    /**
     * Entrada sem vencimento perde para qualquer uma com data, em qualquer ordem.
     */
    #[DataProvider('semDataProvider')]
    public function testEntryWithoutDueDateLosesToOneWithIt(array $recentInvoices): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse(['recent_invoices' => $recentInvoices]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $this->assertSame(
            'inv_com_data',
            (new IuguGateway($api))->getSubscription($subscription)->latestInvoice->id
        );
    }

    public static function semDataProvider(): array
    {
        $semData = (object) ['id' => 'inv_a_sem_data', 'status' => 'pending'];
        $comData = (object) ['id' => 'inv_com_data', 'status' => 'pending', 'due_date' => '2026-08-01'];

        return [
            'sem data primeiro' => [[$semData, $comData]],
            'com data primeiro' => [[$comData, $semData]],
        ];
    }

    public function testEntryWithoutStatusIsNotOpen(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'expires_at' => '2026-08-01',
                'recent_invoices' => [(object) ['id' => 'inv_1', 'due_date' => '2026-08-01']],
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertNull($subscription->latestInvoice->status);
    }

    public function testRecentInvoicesFromTheGatewayAreAcceptedAsAssociativeArrays(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'expires_at' => '2026-08-01',
                'recent_invoices' => [
                    ['id' => 'inv_1', 'status' => 'pending', 'due_date' => '2026-08-01'],
                ],
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertSame('inv_1', $subscription->latestInvoice->id);
        $this->assertSame('2026-08-01', $subscription->latestInvoice->dueDate->format('Y-m-d'));
        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->status);
    }

    /**
     * Resposta que traz `recent_invoices` sem entrada utilizável zera a fatura, em vez de manter
     * a da leitura anterior, que pode nem estar mais na resposta.
     */
    public function testAResponseListingNoUsableInvoiceClearsTheStoredOne(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse([
                'recent_invoices' => [(object) ['id' => 'inv_1', 'status' => 'paid', 'due_date' => '2026-08-01']],
            ]),
            $this->subscriptionResponse([
                'recent_invoices' => [(object) ['status' => 'pending', 'due_date' => '2026-09-01']],
            ]),
        ]);
        $gateway = new IuguGateway($api);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = $gateway->getSubscription($subscription);
        $this->assertSame('inv_1', $subscription->latestInvoice->id);

        $this->assertNull($gateway->getSubscription($subscription)->latestInvoice);
    }

    private function cardResponse(string $id = 'pm_1'): object
    {
        return (object) [
            'id' => $id,
            'description' => 'CREDIT CARD',
            'created_at_iso' => '2026-09-02T09:00:00-03:00',
            'data' => (object) ['brand' => 'VISA', 'display_number' => 'XXXX-XXXX-XXXX-4242', 'month' => 12, 'year' => 2030, 'holder_name' => 'Cliente'],
        ];
    }

    /**
     * A assinatura da Iugu cobra o cartão padrão do cliente: o cartão salvo informado vira o
     * padrão por um `PUT` no cliente antes de `POST /subscriptions`, e `payable_with` sai só
     * com cartão.
     */
    public function testCreateSubscriptionWithASavedCardMakesItTheCustomerDefaultAndPaysWithCard(): void
    {
        $api = new QueuedIuguApiRequest([(object) ['id' => 'cus_1'], $this->subscriptionResponse(['payable_with' => 'credit_card'])]);

        $subscription = (new SubscriptionBuilder(new IuguGateway($api)))
            ->setPlanId('plano_mensal')
            ->setCustomerId('cus_1')
            ->setCreditCard('pm_1')
            ->create();

        $this->assertCount(2, $api->calls);
        $this->assertSame('PUT', $api->calls[0]['method']);
        $this->assertStringEndsWith('/customers/cus_1', $api->calls[0]['url']);
        $this->assertSame(['default_payment_method_id' => 'pm_1'], $api->calls[0]['data']);
        $this->assertSame('POST', $api->calls[1]['method']);
        $this->assertStringEndsWith('/subscriptions', $api->calls[1]['url']);
        $this->assertSame(['credit_card'], $api->calls[1]['data']['payable_with']);
        $this->assertSame(PaymentMethod::CREDIT_CARD, $subscription->paymentMethod);
        $this->assertSame('pm_1', $subscription->creditCard->id);
        $this->assertTrue($subscription->creditCard->default);
    }

    /**
     * Cartão sem id é salvo no cliente já como padrão (`set_as_default`) antes da assinatura.
     */
    public function testCreateSubscriptionWithATokenizedCardSavesItAsTheDefaultFirst(): void
    {
        $api = new QueuedIuguApiRequest([$this->cardResponse('pm_novo'), $this->subscriptionResponse()]);

        $card = new CreditCard();
        $card->token = 'tok_1';
        $subscription = (new SubscriptionBuilder(new IuguGateway($api)))
            ->setPlanId('plano_mensal')
            ->setCustomerId('cus_1')
            ->setCreditCard($card)
            ->create();

        $this->assertCount(2, $api->calls);
        $this->assertSame('POST', $api->calls[0]['method']);
        $this->assertStringEndsWith('/customers/cus_1/payment_methods', $api->calls[0]['url']);
        $this->assertSame('tok_1', $api->calls[0]['data']['token']);
        $this->assertTrue($api->calls[0]['data']['set_as_default']);
        $this->assertSame(['credit_card'], $api->calls[1]['data']['payable_with']);
        $this->assertSame('pm_novo', $subscription->creditCard->id);
    }

    public function testCreateSubscriptionWithATokenizedCardUsesTheDerivedCardKey(): void
    {
        $api = new QueuedIuguApiRequest([$this->cardResponse('pm_novo'), $this->subscriptionResponse()]);
        $store = new InMemoryIdempotencyStore();

        $card = new CreditCard();
        $card->token = 'tok_1';
        (new SubscriptionBuilder(new IuguGateway($api, $store)))
            ->setPlanId('plano_mensal')
            ->setCustomerId('cus_1')
            ->setCreditCard($card)
            ->withIdempotencyKey('sub-1')
            ->create();

        $this->assertTrue($store->has('iugu:sub-1:card'));
        $this->assertSame([], $api->calls[0]['headers']);
        $this->assertSame(['Idempotency-Key: sub-1'], $api->calls[1]['headers']);
    }

    public function testCreateSubscriptionWithOnlyAPaymentMethodDerivesPayableWithWithoutTouchingTheCustomer(): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse(['payable_with' => 'pix'])]);

        $subscription = (new SubscriptionBuilder(new IuguGateway($api)))
            ->setPlanId('plano_mensal')
            ->setCustomerId('cus_1')
            ->setPaymentMethod(PaymentMethod::PIX)
            ->create();

        $this->assertCount(1, $api->calls);
        $this->assertSame(['pix'], $api->calls[0]['data']['payable_with']);
        $this->assertSame(PaymentMethod::PIX, $subscription->paymentMethod);
    }

    public function testAvailablePaymentMethodsTakePrecedenceOverPaymentMethod(): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse()]);

        $subscription = new Subscription();
        $subscription->fill(['plan_id' => 'plano_mensal', 'customer' => ['id' => 'cus_1'], 'payment_method' => 'pix']);
        $subscription->availablePaymentMethods = [PaymentMethod::BANK_SLIP, PaymentMethod::PIX];

        (new IuguGateway($api))->createSubscription($subscription);

        $this->assertSame(['bank_slip', 'pix'], $api->calls[0]['data']['payable_with']);
    }

    /**
     * `trialDays` vira `expires_at` contado do momento da requisição, com
     * `only_charge_on_due_date` para a Iugu não cobrar o cartão na criação, e a chave de
     * idempotência vai intacta no cabeçalho de `POST /subscriptions`; o cartão padrão usa a
     * chave derivada `{chave}:default` pela store.
     */
    public function testTrialDaysBecomesExpiresAtCountedFromNowWithoutChangingTheIdempotencyKey(): void
    {
        Carbon::setTestNow('2026-09-15 12:00:00');
        $api = new QueuedIuguApiRequest([(object) ['id' => 'cus_1'], $this->subscriptionResponse(['in_trial' => true, 'expires_at' => '2026-09-22'])]);
        $store = new InMemoryIdempotencyStore();

        $subscription = (new SubscriptionBuilder(new IuguGateway($api, $store)))
            ->setPlanId('plano_mensal')
            ->setCustomerId('cus_1')
            ->setCreditCard('pm_1')
            ->setTrialDays(7)
            ->withIdempotencyKey('sub-1')
            ->create();

        $this->assertSame('2026-09-22', $api->calls[1]['data']['expires_at']);
        $this->assertTrue($api->calls[1]['data']['only_charge_on_due_date']);
        $this->assertSame(['Idempotency-Key: sub-1'], $api->calls[1]['headers']);
        $this->assertSame([], $api->calls[0]['headers']);
        $this->assertTrue($store->has('iugu:sub-1:default'));
        $this->assertSame('2026-09-22', $subscription->trialEndsAt->format('Y-m-d'));
        $this->assertNull($subscription->trialDays);
    }

    /**
     * Um model criado com `trialDays` pode ser salvo de novo: os dias viraram a data.
     */
    public function testAModelCreatedWithTrialDaysCanBeSavedAgain(): void
    {
        Carbon::setTestNow('2026-09-15 12:00:00');
        // o save() seguinte resolve o gateway pelo nome gravado no model, então o fake vai no
        // requester compartilhado do SDK
        $api = (new QueuedIuguApiRequest([
            $this->subscriptionResponse(['in_trial' => true, 'expires_at' => '2026-09-22']),
            $this->subscriptionResponse(['in_trial' => true, 'expires_at' => '2026-09-22']),
        ]))->installAsSdkRequester();

        $subscription = (new SubscriptionBuilder(new IuguGateway($api)))
            ->setPlanId('plano_mensal')
            ->setCustomerId('cus_1')
            ->setTrialDays(7)
            ->create();
        $subscription->metadata = ['origem' => 'teste'];
        $subscription->save();

        $this->assertCount(2, $api->calls);
        $this->assertSame('PUT', $api->calls[1]['method']);
        $this->assertSame(['custom_variables' => [['name' => 'origem', 'value' => 'teste']]], $api->calls[1]['data']);
    }

    /**
     * Num model lido do gateway a lista de métodos vem preenchida, então trocar só
     * `paymentMethod` ou informar um cartão fora dela é recusado antes de qualquer requisição.
     */
    public function testChangingThePaymentMethodOfAReadModelRequiresChangingTheList(): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse(['payable_with' => 'pix'])]);
        $gateway = new IuguGateway($api);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = $gateway->getSubscription($subscription);
        $this->assertSame([PaymentMethod::PIX], $subscription->availablePaymentMethods);

        $subscription->paymentMethod = PaymentMethod::CREDIT_CARD;
        try {
            $subscription->save($gateway);
            $this->fail('Esperava ModelAttributeValidationException');
        } catch (ModelAttributeValidationException $e) {
            $this->assertStringContainsString('paymentMethod [credit_card] must be one of availablePaymentMethods', $e->getMessage());
        }

        $subscription->paymentMethod = null;
        $subscription->creditCard = new CreditCard();
        $subscription->creditCard->id = 'pm_9';
        try {
            $subscription->save($gateway);
            $this->fail('Esperava ModelAttributeValidationException');
        } catch (ModelAttributeValidationException $e) {
            $this->assertStringContainsString('creditCard was given but credit_card is not among the payment methods', $e->getMessage());
        }
        $this->assertCount(1, $api->calls);
    }

    public function testTrialEndsAtAlsoPostponesTheFirstChargeWhileNextBillingAtAloneDoesNot(): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse(), $this->subscriptionResponse(), $this->subscriptionResponse()]);
        $gateway = new IuguGateway($api);

        $trial = new Subscription();
        $trial->fill(['plan_id' => 'plano_mensal', 'customer' => ['id' => 'cus_1'], 'trial_ends_at' => '2026-10-01']);
        $gateway->createSubscription($trial);
        $this->assertSame('2026-10-01', $api->calls[0]['data']['expires_at']);
        $this->assertTrue($api->calls[0]['data']['only_charge_on_due_date']);

        $billing = new Subscription();
        $billing->fill(['plan_id' => 'plano_mensal', 'customer' => ['id' => 'cus_1'], 'next_billing_at' => '2026-10-01']);
        $gateway->createSubscription($billing);
        $this->assertSame('2026-10-01', $api->calls[1]['data']['expires_at']);
        $this->assertArrayNotHasKey('only_charge_on_due_date', $api->calls[1]['data']);

        $overridden = new Subscription();
        $overridden->fill(['plan_id' => 'plano_mensal', 'customer' => ['id' => 'cus_1'], 'trial_days' => 7]);
        $overridden->gatewayOptions = ['only_charge_on_due_date' => false];
        $gateway->createSubscription($overridden);
        $this->assertFalse($api->calls[2]['data']['only_charge_on_due_date']);
    }

    public function testTrialDaysConflictingWithNextBillingAtIsRejectedBeforeTheNetwork(): void
    {
        Carbon::setTestNow('2026-09-15 12:00:00');
        $api = new QueuedIuguApiRequest([]);

        $subscription = new Subscription();
        $subscription->fill(['plan_id' => 'plano_mensal', 'customer' => ['id' => 'cus_1'], 'trial_days' => 7, 'next_billing_at' => '2026-10-01']);

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/trialEndsAt \(or trialDays\)/');

        (new IuguGateway($api))->createSubscription($subscription);
    }

    public function testUpdateSubscriptionWithACardMakesItTheCustomerDefaultBeforeTheUpdate(): void
    {
        $api = new QueuedIuguApiRequest([(object) ['id' => 'cus_1'], $this->subscriptionResponse()]);

        $subscription = new Subscription();
        $subscription->fill(['id' => 'sub_1', 'customer' => ['id' => 'cus_1'], 'credit_card' => ['id' => 'pm_2']]);

        (new IuguGateway($api))->updateSubscription($subscription);

        $this->assertCount(2, $api->calls);
        $this->assertStringEndsWith('/customers/cus_1', $api->calls[0]['url']);
        $this->assertSame(['default_payment_method_id' => 'pm_2'], $api->calls[0]['data']);
        $this->assertStringEndsWith('/subscriptions/sub_1', $api->calls[1]['url']);
        $this->assertSame(['payable_with' => ['credit_card']], $api->calls[1]['data']);
    }

    public function testUpdateSubscriptionWithATokenizedCardSavesItAsTheDefaultBeforeTheUpdate(): void
    {
        $api = new QueuedIuguApiRequest([$this->cardResponse('pm_novo'), $this->subscriptionResponse()]);

        $subscription = new Subscription();
        $subscription->fill(['id' => 'sub_1', 'customer' => ['id' => 'cus_1'], 'credit_card' => ['token' => 'tok_1']]);

        $subscription = (new IuguGateway($api))->updateSubscription($subscription);

        $this->assertCount(2, $api->calls);
        $this->assertSame('POST', $api->calls[0]['method']);
        $this->assertStringEndsWith('/customers/cus_1/payment_methods', $api->calls[0]['url']);
        $this->assertTrue($api->calls[0]['data']['set_as_default']);
        $this->assertStringEndsWith('/subscriptions/sub_1', $api->calls[1]['url']);
        $this->assertSame(['payable_with' => ['credit_card']], $api->calls[1]['data']);
        $this->assertSame('pm_novo', $subscription->creditCard->id);
    }

    public function testCardWithoutACustomerIdIsRejectedBeforeTheNetwork(): void
    {
        $api = new QueuedIuguApiRequest([]);

        $subscription = new Subscription();
        $subscription->fill(['id' => 'sub_1', 'credit_card' => ['id' => 'pm_2']]);

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/`customer` attribute is required/');

        (new IuguGateway($api))->updateSubscription($subscription);
    }

    /**
     * A Iugu não informa com qual método a assinatura é cobrada; o driver só preenche
     * `paymentMethod` quando ela aceita um único método.
     */
    #[DataProvider('parsedPaymentMethodProvider')]
    public function testParseFillsPaymentMethodOnlyWhenTheSubscriptionAcceptsASingleMethod(mixed $payableWith, ?PaymentMethod $expected): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse(['payable_with' => $payableWith])]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription->paymentMethod = PaymentMethod::CREDIT_CARD;
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertSame($expected, $subscription->paymentMethod);
    }

    public static function parsedPaymentMethodProvider(): array
    {
        return [
            'string unica' => ['pix', PaymentMethod::PIX],
            'lista de um' => [['credit_card'], PaymentMethod::CREDIT_CARD],
            'lista de dois' => [['pix', 'bank_slip'], null],
            'all' => ['all', null],
        ];
    }

    private function discountSubitem(string $id = 'si_d1', int $priceCents = -500, bool $recurrent = true): object
    {
        return (object) [
            'id' => $id,
            'description' => 'Promo',
            'price_cents' => $priceCents,
            'quantity' => 1,
            'recurrent' => $recurrent,
        ];
    }

    public function testDiscountWithValidUntilWritesTheVariableAfterTheCreation(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse(['subitems' => [$this->discountSubitem()]]),
            $this->subscriptionResponse([
                'subitems' => [$this->discountSubitem()],
                'custom_variables' => [(object) ['name' => 'mp_discount_si_d1_until', 'value' => '2026-12-31']],
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->fill([
            'plan_id' => 'plano_mensal',
            'customer' => ['id' => 'cus_1'],
            'discounts' => [['description' => 'Promo', 'amount_off' => 500, 'valid_until' => '2026-12-31']],
        ]);

        $created = (new IuguGateway($api))->createSubscription($subscription);

        $this->assertCount(2, $api->calls);
        $this->assertSame(
            [['description' => 'Promo', 'price_cents' => -500, 'quantity' => 1, 'recurrent' => 1]],
            $api->calls[0]['data']['subitems']
        );
        $this->assertSame('PUT', $api->calls[1]['method']);
        $this->assertStringEndsWith('/subscriptions/sub_1', $api->calls[1]['url']);
        $this->assertSame(
            ['custom_variables' => [['name' => 'mp_discount_si_d1_until', 'value' => '2026-12-31']]],
            $api->calls[1]['data']
        );
        $this->assertSame('2026-12-31', $created->discounts[0]->validUntil->format('Y-m-d'));
    }

    /**
     * `cycles` acima de 1 vira a data da fatura de número `cycles`. Na criação sem trial a
     * primeira fatura é cobrada na hora e a segunda sai na próxima cobrança da resposta, então
     * três ciclos terminam um intervalo do plano (lido por `getPlan()`) depois dela.
     */
    public function testDiscountCyclesBecomeAValidUntilComputedFromThePlanInterval(): void
    {
        Carbon::setTestNow('2026-09-04 12:00:00');

        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse(['subitems' => [$this->discountSubitem()]]),
            (object) ['identifier' => 'plano_mensal', 'interval_type' => 'months', 'interval' => 1, 'value_cents' => 10000],
            $this->subscriptionResponse([
                'subitems' => [$this->discountSubitem()],
                'custom_variables' => [(object) ['name' => 'mp_discount_si_d1_until', 'value' => '2026-11-01']],
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->fill([
            'plan_id' => 'plano_mensal',
            'customer' => ['id' => 'cus_1'],
            'discounts' => [['description' => 'Promo', 'amount_off' => 500, 'cycles' => 3]],
        ]);

        $created = (new IuguGateway($api))->createSubscription($subscription);

        $this->assertCount(3, $api->calls);
        $this->assertStringEndsWith('/plans/identifier/plano_mensal', $api->calls[1]['url']);
        $this->assertSame(
            ['custom_variables' => [['name' => 'mp_discount_si_d1_until', 'value' => '2026-11-01']]],
            $api->calls[2]['data']
        );
        $this->assertSame('2026-11-01', $created->discounts[0]->validUntil->format('Y-m-d'));
    }

    /**
     * Substituir a lista de descontos por uma vazia remove o subitem e a variável de validade
     * que ficou sem desconto correspondente.
     */
    public function testUpdateWithAnEmptyDiscountListRemovesTheStaleValidityVariable(): void
    {
        $current = $this->subscriptionResponse([
            'subitems' => [$this->discountSubitem()],
            'custom_variables' => [(object) ['name' => 'mp_discount_si_d1_until', 'value' => '2026-12-31']],
        ]);
        $api = new QueuedIuguApiRequest([
            $current,
            $this->subscriptionResponse(['custom_variables' => [
                (object) ['name' => 'mp_discount_si_d1_until', 'value' => '2026-12-31'],
            ]]),
            $this->subscriptionResponse(['custom_variables' => [
                (object) ['name' => 'mp_discount_si_d1_until', 'value' => '2026-12-31'],
            ]]),
            $this->subscriptionResponse(['custom_variables' => []]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription->discounts = [];

        $updated = (new IuguGateway($api))->updateSubscription($subscription);

        $this->assertCount(4, $api->calls);
        $this->assertSame('GET', $api->calls[0]['method']);
        $this->assertSame(
            ['subitems' => [['id' => 'si_d1', '_destroy' => true]]],
            $api->calls[1]['data']
        );
        $this->assertSame(
            ['custom_variables' => [['name' => 'mp_discount_si_d1_until', '_destroy' => true]]],
            $api->calls[3]['data']
        );
        $this->assertNull($updated->canceledAt);
        $this->assertSame([], $updated->metadata);
    }

    public function testParseReadsTheDiscountValidityAndTheScheduledCancellation(): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse([
            'subitems' => [$this->discountSubitem()],
            'custom_variables' => [
                (object) ['name' => 'origem', 'value' => 'teste'],
                (object) ['name' => 'mp_discount_si_d1_until', 'value' => '2026-12-31'],
                (object) ['name' => 'mp_cancel_at_period_end', 'value' => '1'],
                (object) ['name' => 'mp_cancel_scheduled_for', 'value' => '2026-10-01'],
            ],
        ])]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertTrue($subscription->cancelAtPeriodEnd);
        $this->assertNull($subscription->canceledAt);
        $this->assertSame('2026-12-31', $subscription->discounts[0]->validUntil->format('Y-m-d'));
        $this->assertNull($subscription->discounts[0]->cycles);
        $this->assertSame(['origem' => 'teste'], $subscription->metadata);
    }

    /**
     * Com chave, o `PUT` da validade recebe `{chave}:discounts` pela store, e o retry só
     * repete o `POST` (endpoint nativo, deduplicado pela própria Iugu).
     */
    public function testCreateWithDiscountStoresTheValidityWriteUnderItsOwnKey(): void
    {
        $created = $this->subscriptionResponse(['subitems' => [$this->discountSubitem()]]);
        $withVariable = $this->subscriptionResponse([
            'subitems' => [$this->discountSubitem()],
            'custom_variables' => [(object) ['name' => 'mp_discount_si_d1_until', 'value' => '2026-12-31']],
        ]);
        $api = new QueuedIuguApiRequest([$created, $withVariable, $created]);
        $store = new \Potelo\MultiPayment\Idempotency\InMemoryIdempotencyStore();
        $gateway = new IuguGateway($api, $store);

        $subscription = new Subscription();
        $subscription->fill([
            'plan_id' => 'plano_mensal',
            'customer' => ['id' => 'cus_1'],
            'discounts' => [['description' => 'Promo', 'amount_off' => 500, 'valid_until' => '2026-12-31']],
        ]);

        $gateway->createSubscription($subscription, 'chave-1');

        $this->assertCount(2, $api->calls);
        $this->assertSame(['Idempotency-Key: chave-1'], $api->calls[0]['headers']);
        $this->assertSame([], $api->calls[1]['headers']);
        $this->assertTrue($store->has('iugu:chave-1:discounts'));

        $again = new Subscription();
        $again->fill([
            'plan_id' => 'plano_mensal',
            'customer' => ['id' => 'cus_1'],
            'discounts' => [['description' => 'Promo', 'amount_off' => 500, 'valid_until' => '2026-12-31']],
        ]);
        $gateway->createSubscription($again, 'chave-1');

        $this->assertCount(3, $api->calls, 'o retry repete só o POST; o PUT da validade sai da store');
        $this->assertSame('POST', $api->calls[2]['method']);
    }

    /**
     * No update, o desconto novo casa com o subitem da resposta e ganha a variável de
     * validade; a variável do desconto que saiu da lista é removida no mesmo `PUT`.
     */
    public function testUpdateReplacesTheDiscountAndRewritesItsValidityVariable(): void
    {
        $old = $this->discountSubitem('si_old');
        $oldVariable = (object) ['name' => 'mp_discount_si_old_until', 'value' => '2026-10-01'];
        $new = $this->discountSubitem('si_new');

        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse(['subitems' => [$old], 'custom_variables' => [$oldVariable]]),
            $this->subscriptionResponse(['subitems' => [], 'custom_variables' => [$oldVariable]]),
            $this->subscriptionResponse(['subitems' => [$new], 'custom_variables' => [$oldVariable]]),
            $this->subscriptionResponse([
                'subitems' => [$new],
                'custom_variables' => [(object) ['name' => 'mp_discount_si_new_until', 'value' => '2026-12-31']],
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->fill([
            'id' => 'sub_1',
            'discounts' => [['description' => 'Promo', 'amount_off' => 500, 'valid_until' => '2026-12-31']],
        ]);

        $updated = (new IuguGateway($api))->updateSubscription($subscription);

        $this->assertCount(4, $api->calls);
        $this->assertSame(
            ['subitems' => [['id' => 'si_old', '_destroy' => true]]],
            $api->calls[1]['data']
        );
        $this->assertSame(
            ['custom_variables' => [
                ['name' => 'mp_discount_si_new_until', 'value' => '2026-12-31'],
                ['name' => 'mp_discount_si_old_until', '_destroy' => true],
            ]],
            $api->calls[3]['data']
        );
        $this->assertSame('2026-12-31', $updated->discounts[0]->validUntil->format('Y-m-d'));
    }

    /**
     * Update com o mesmo desconto e a mesma validade não escreve a variável de novo: a
     * operação termina no `PUT` da atualização.
     */
    public function testUpdateWithTheSameDiscountValidityDoesNotWriteTheVariableAgain(): void
    {
        $withVariable = $this->subscriptionResponse([
            'subitems' => [$this->discountSubitem()],
            'custom_variables' => [(object) ['name' => 'mp_discount_si_d1_until', 'value' => '2026-12-31']],
        ]);
        $api = new QueuedIuguApiRequest([$withVariable, $withVariable]);

        $subscription = new Subscription();
        $subscription->fill([
            'id' => 'sub_1',
            'discounts' => [[
                'id' => 'si_d1',
                'description' => 'Promo',
                'amount_off' => 500,
                'valid_until' => '2026-12-31',
            ]],
        ]);

        (new IuguGateway($api))->updateSubscription($subscription);

        $this->assertCount(2, $api->calls);
        $this->assertSame('GET', $api->calls[0]['method']);
        $this->assertSame('PUT', $api->calls[1]['method']);
    }

    /**
     * Na criação com trial, a contagem de `cycles` parte do fim do teste, que é quando a
     * primeira fatura é cobrada.
     */
    public function testDiscountCyclesCountFromTheTrialEndWhenCreatingWithATrial(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse(['subitems' => [$this->discountSubitem()], 'expires_at' => '2026-09-11']),
            (object) ['identifier' => 'plano_mensal', 'interval_type' => 'months', 'interval' => 1, 'value_cents' => 10000],
            $this->subscriptionResponse([
                'subitems' => [$this->discountSubitem()],
                'custom_variables' => [(object) ['name' => 'mp_discount_si_d1_until', 'value' => '2026-10-11']],
            ]),
        ]);

        $subscription = new Subscription();
        $subscription->fill([
            'plan_id' => 'plano_mensal',
            'customer' => ['id' => 'cus_1'],
            'trial_ends_at' => '2026-09-11',
            'discounts' => [['description' => 'Promo', 'amount_off' => 500, 'cycles' => 2]],
        ]);

        (new IuguGateway($api))->createSubscription($subscription);

        $this->assertSame(
            ['name' => 'mp_discount_si_d1_until', 'value' => '2026-10-11'],
            $api->calls[2]['data']['custom_variables'][0]
        );
    }

    public function testAnUnreadableDiscountValidityIsIgnoredWithAWarning(): void
    {
        $app = \Illuminate\Support\Facades\Facade::getFacadeApplication();
        $app->instance('log', $logger = new RecordingLogger());

        $api = new QueuedIuguApiRequest([$this->subscriptionResponse([
            'subitems' => [$this->discountSubitem()],
            'custom_variables' => [(object) ['name' => 'mp_discount_si_d1_until', 'value' => 'sim']],
        ])]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertNull($subscription->discounts[0]->validUntil);
        $this->assertCount(1, $logger->records);
        $this->assertSame('warning', $logger->records[0]['level']);
        $this->assertStringContainsString('mp_discount_si_d1_until', $logger->records[0]['message']);
    }

    /**
     * `resume()` de uma assinatura ativa com cancelamento agendado remove as variáveis do
     * agendamento, mesmo sem a marca `mp_canceled_at`.
     */
    public function testResumeClearsAScheduledCancellation(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->subscriptionResponse(['custom_variables' => [
                (object) ['name' => 'mp_cancel_at_period_end', 'value' => '1'],
                (object) ['name' => 'mp_cancel_scheduled_for', 'value' => '2026-10-01'],
            ]]),
            $this->subscriptionResponse(['custom_variables' => []]),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $resumed = (new IuguGateway($api))->resumeSubscription($subscription);

        $this->assertCount(2, $api->calls);
        $this->assertSame('PUT', $api->calls[1]['method']);
        $this->assertSame(
            [
                ['name' => 'mp_canceled_at', '_destroy' => true],
                ['name' => 'mp_cancel_at_period_end', '_destroy' => true],
                ['name' => 'mp_cancel_scheduled_for', '_destroy' => true],
            ],
            $api->calls[1]['data']['custom_variables']
        );
        $this->assertFalse($resumed->cancelAtPeriodEnd);
    }

    /**
     * O prefixo `mp_` de `custom_variables` guarda o estado da emulação, então `metadata` com
     * uma chave assim é recusado antes de qualquer requisição.
     */
    public function testMetadataWithTheReservedPrefixIsRejected(): void
    {
        $api = new QueuedIuguApiRequest([]);

        $subscription = new Subscription();
        $subscription->fill(['plan_id' => 'plano_mensal', 'customer' => ['id' => 'cus_1']]);
        $subscription->metadata = ['mp_canceled_at' => '2026-09-04'];

        try {
            (new IuguGateway($api))->createSubscription($subscription);
            $this->fail('Esperava ModelAttributeValidationException');
        } catch (ModelAttributeValidationException $e) {
            $this->assertStringContainsString('mp_ prefix', $e->getMessage());
        }
        $this->assertCount(0, $api->calls);
    }
}
