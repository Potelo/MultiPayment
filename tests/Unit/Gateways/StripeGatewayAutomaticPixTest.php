<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Carbon\Carbon;
use Stripe\ApiRequestor;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\AutomaticPix;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Models\SubscriptionItem;
use Potelo\MultiPayment\Models\AutomaticPixCharge;
use Potelo\MultiPayment\Models\SubscriptionDiscount;
use Potelo\MultiPayment\Models\AutomaticPixCancellation;
use Potelo\MultiPayment\Gateways\StripeGateway;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Enums\SubscriptionStatus;
use Potelo\MultiPayment\Exceptions\NotFoundException;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Pix Automático no driver Stripe: a assinatura com `paymentMethod` `AUTOMATIC_PIX` registra
 * o mandato em `payment_method_options.pix.mandate_options`, a leitura devolve o estado em
 * `Subscription::$automaticPix`, as operações de agendamento respondem `managed_by_gateway` e
 * a consulta de cancelamentos lê o Mandate. As fixtures de assinatura com mandato e de
 * Mandate são montadas a partir da documentação, porque a conta de sandbox ainda não tem o
 * recurso liberado (ver o README da pasta de fixtures).
 */
class StripeGatewayAutomaticPixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // relógio congelado: o start_date derivado usa o início do dia de hoje mais 3 dias
        Carbon::setTestNow(Carbon::parse('2026-09-05 10:00:00'));

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment.gateways.stripe.api_key' => 'sk_test_fake',
            'multi-payment.gateways.stripe.pix_mandate_reference' => 'Empresa Exemplo',
        ]));
        Facade::setFacadeApplication($app);

        RecordingStripeHttpClient::withResponses([]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        ApiRequestor::setHttpClient(null);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testCreateSubscriptionWithAutomaticPixRegistersTheMandate(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::priceResponse(),
            self::fixture('subscriptions/incomplete_automatic_pix'),
            self::fixture('invoices/open_requires_payment_method'),
        ]);

        $subscription = self::subscriptionModel();
        $subscription->paymentMethod = PaymentMethod::AUTOMATIC_PIX;

        $result = (new StripeGateway())->createSubscription($subscription);

        $this->assertSame([
            'get /v1/prices/price_fake1',
            'post /v1/subscriptions',
            'get /v1/invoices/in_1UBJmkPjx0CusuMrN6Yc2Ha1',
        ], self::calledPaths($httpClient));

        $params = $httpClient->calls[1][2];
        $this->assertSame('charge_automatically', $params['collection_method']);
        $this->assertSame('default_incomplete', $params['payment_behavior']);
        $this->assertSame(['pix'], $params['payment_settings']['payment_method_types']);

        $this->assertSame([
            'amount' => 10000,
            'amount_type' => 'fixed',
            'payment_schedule' => 'monthly',
            'start_date' => Carbon::parse('2026-09-08 00:00:00')->getTimestamp(),
            'reference' => 'Empresa Exemplo',
        ], $params['payment_settings']['payment_method_options']['pix']['mandate_options']);

        $this->assertSame(SubscriptionStatus::PENDING, $result->status);
        $this->assertSame(PaymentMethod::AUTOMATIC_PIX, $result->paymentMethod);
        $this->assertNull($result->availablePaymentMethods);
        $this->assertInstanceOf(AutomaticPix::class, $result->automaticPix);
        $this->assertSame(AutomaticPix::FREQUENCY_MONTHLY, $result->automaticPix->frequency);
        $this->assertSame(1788825600, $result->automaticPix->startsAt->getTimestamp());
        $this->assertSame(1791157981, $result->automaticPix->preDebitNotificationAt->getTimestamp());
        $this->assertSame(
            Carbon::createFromTimestamp(1791157981)->addDays(3)->getTimestamp(),
            $result->automaticPix->nextDebitAt->getTimestamp()
        );
        $this->assertSame('stripe', $result->automaticPix->gateway);
    }

    /**
     * A frequência e as datas informadas em `automaticPix` prevalecem sobre as derivadas do
     * plano, e um desconto na assinatura faz o valor do mandato virar um teto (`maximum`).
     */
    public function testCreateSubscriptionWithAutomaticPixHonorsTheModelAndTheDiscounts(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::priceResponse(),
            self::fixture('subscriptions/incomplete_automatic_pix'),
            self::fixture('invoices/open_requires_payment_method'),
        ]);

        $startsAt = Carbon::now()->addDays(10)->startOfDay();
        $endsAt = Carbon::now()->addYear()->startOfDay();

        $subscription = self::subscriptionModel();
        $subscription->paymentMethod = PaymentMethod::AUTOMATIC_PIX;
        $subscription->automaticPix = new AutomaticPix();
        $subscription->automaticPix->frequency = AutomaticPix::FREQUENCY_WEEKLY;
        $subscription->automaticPix->startsAt = $startsAt;
        $subscription->automaticPix->endsAt = $endsAt;
        $discount = new SubscriptionDiscount();
        $discount->id = 'coupon_fake1';
        $subscription->discounts = [$discount];

        (new StripeGateway())->createSubscription($subscription);

        $mandateOptions = $httpClient->calls[1][2]['payment_settings']['payment_method_options']['pix']['mandate_options'];
        $this->assertSame('weekly', $mandateOptions['payment_schedule']);
        $this->assertSame('maximum', $mandateOptions['amount_type']);
        $this->assertSame($startsAt->getTimestamp(), $mandateOptions['start_date']);
        $this->assertSame($endsAt->getTimestamp(), $mandateOptions['end_date']);
    }

    /**
     * O valor do mandato soma o plano com os itens recorrentes, a data derivada anterior ao
     * mínimo de três dias é elevada a ele, e sem `pix_mandate_reference` na configuração o
     * `reference` fica de fora.
     */
    public function testCreateSubscriptionWithAutomaticPixSumsTheItemsAndFloorsTheDerivedStart(): void
    {
        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment.gateways.stripe.api_key' => 'sk_test_fake',
        ]));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $httpClient = RecordingStripeHttpClient::withResponses([
            self::priceResponse(),
            ['id' => 'prod_item0', 'object' => 'product', 'name' => 'Consultas', 'active' => true, 'created' => 1786700000, 'metadata' => []],
            self::priceResponse(),
            self::fixture('subscriptions/incomplete_automatic_pix'),
            self::fixture('invoices/open_requires_payment_method'),
        ]);

        $subscription = self::subscriptionModel();
        $subscription->paymentMethod = PaymentMethod::AUTOMATIC_PIX;
        $subscription->trialEndsAt = Carbon::now()->addDay();
        $item = new SubscriptionItem();
        $item->description = 'Consultas';
        $item->amount = 2500;
        $item->quantity = 2;
        $subscription->items = [$item];

        (new StripeGateway())->createSubscription($subscription);

        $mandateOptions = $httpClient->calls[3][2]['payment_settings']['payment_method_options']['pix']['mandate_options'];
        $this->assertSame(15000, $mandateOptions['amount']);
        $this->assertSame(Carbon::parse('2026-09-08 00:00:00')->getTimestamp(), $mandateOptions['start_date']);
        $this->assertArrayNotHasKey('reference', $mandateOptions);
    }

    public function testCreateSubscriptionWithAutomaticPixRejectsAStartDateEarlierThanThreeDays(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::priceResponse(),
        ]);

        $subscription = self::subscriptionModel();
        $subscription->paymentMethod = PaymentMethod::AUTOMATIC_PIX;
        $subscription->automaticPix = new AutomaticPix();
        $subscription->automaticPix->startsAt = Carbon::now()->addDay();

        try {
            (new StripeGateway())->createSubscription($subscription);
            $this->fail('Esperava ModelAttributeValidationException');
        } catch (ModelAttributeValidationException $e) {
            $this->assertStringContainsString('startsAt must be at least 3 days', $e->getMessage());
        }

        $this->assertSame(['get /v1/prices/price_fake1'], self::calledPaths($httpClient));
    }

    /**
     * O mandato precisa do valor por ciclo: um Price sem `unit_amount` fixo (por camadas ou
     * por uso) é recusado antes da criação da assinatura.
     */
    public function testCreateSubscriptionWithAutomaticPixRejectsAPlanWithoutAFixedUnitAmount(): void
    {
        $price = self::priceResponse();
        $price['unit_amount'] = null;
        $httpClient = RecordingStripeHttpClient::withResponses([$price]);

        $subscription = self::subscriptionModel();
        $subscription->paymentMethod = PaymentMethod::AUTOMATIC_PIX;

        try {
            (new StripeGateway())->createSubscription($subscription);
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::AUTOMATIC_PIX, $e->capability);
            $this->assertStringContainsString('unit_amount', $e->getMessage());
        }

        $this->assertSame(['get /v1/prices/price_fake1'], self::calledPaths($httpClient));
    }

    /**
     * Trocar o método de uma assinatura que já tem mandato é recusado antes da rede: sem a
     * recusa, a troca seria engolida em silêncio e o mandato continuaria valendo.
     */
    public function testUpdateSubscriptionRefusesLeavingTheMandateForAnotherMethod(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1UBJmkPjx0CusuMr3KQ2wXyZ';
        $subscription->paymentMethod = PaymentMethod::PIX;
        $subscription->original = json_decode(json_encode(self::fixture('subscriptions/active_automatic_pix')));

        try {
            (new StripeGateway())->updateSubscription($subscription);
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::AUTOMATIC_PIX, $e->capability);
            $this->assertSame(UnsupportedOperationException::REASON_NOT_IMPLEMENTED, $e->reason);
        }

        $this->assertSame([], $httpClient->calls);
    }

    public function testCreateSubscriptionWithAutomaticPixRejectsAPlanIntervalWithoutASchedule(): void
    {
        $price = self::priceResponse();
        $price['recurring'] = ['interval' => 'week', 'interval_count' => 2, 'usage_type' => 'licensed'];
        $httpClient = RecordingStripeHttpClient::withResponses([$price]);

        $subscription = self::subscriptionModel();
        $subscription->paymentMethod = PaymentMethod::AUTOMATIC_PIX;

        try {
            (new StripeGateway())->createSubscription($subscription);
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::AUTOMATIC_PIX, $e->capability);
            $this->assertSame(UnsupportedOperationException::REASON_GATEWAY_LIMITATION, $e->reason);
            $this->assertStringContainsString('[week:2]', $e->getMessage());
        }

        $this->assertSame(['get /v1/prices/price_fake1'], self::calledPaths($httpClient));
    }

    public function testGetSubscriptionReadsTheMandateIntoAutomaticPix(): void
    {
        RecordingStripeHttpClient::withResponses([
            self::fixture('subscriptions/active_automatic_pix'),
            self::fixture('invoices/open_requires_payment_method'),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1UBJmkPjx0CusuMr3KQ2wXyZ';

        $result = (new StripeGateway())->getSubscription($subscription);

        $this->assertSame(SubscriptionStatus::ACTIVE, $result->status);
        $this->assertSame(PaymentMethod::AUTOMATIC_PIX, $result->paymentMethod);
        $this->assertNull($result->availablePaymentMethods);
        $this->assertSame(AutomaticPix::FREQUENCY_MONTHLY, $result->automaticPix->frequency);
        $this->assertSame(1791157981, $result->automaticPix->preDebitNotificationAt->getTimestamp());
        $this->assertNull($result->automaticPix->mandateId);
        $this->assertNull($result->automaticPix->mandateStatus);
    }

    /**
     * Sem `current_period_end` na leitura, as datas derivadas do ciclo ficam nulas; o restante
     * do mandato é lido normalmente.
     */
    public function testGetSubscriptionWithoutAPeriodEndLeavesTheDerivedDatesNull(): void
    {
        $fixture = self::fixture('subscriptions/active_automatic_pix');
        unset($fixture['items']['data'][0]['current_period_end']);
        RecordingStripeHttpClient::withResponses([
            $fixture,
            self::fixture('invoices/open_requires_payment_method'),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1UBJmkPjx0CusuMr3KQ2wXyZ';

        $result = (new StripeGateway())->getSubscription($subscription);

        $this->assertSame(AutomaticPix::FREQUENCY_MONTHLY, $result->automaticPix->frequency);
        $this->assertNull($result->automaticPix->nextDebitAt);
        $this->assertNull($result->automaticPix->preDebitNotificationAt);
    }

    /**
     * As três operações de agendamento respondem `managed_by_gateway` sem nenhuma requisição;
     * o encerramento da recorrência orienta o cancelamento da assinatura.
     */
    public function testSchedulingOperationsAreManagedByTheGateway(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);
        $gateway = new StripeGateway();

        $charge = new AutomaticPixCharge();
        $charge->id = 'pay_1';
        $charge->endToEndId = 'E123';
        $automaticPix = new AutomaticPix();
        $automaticPix->id = 'mandate_1UBJmkPjx0CusuMr7PxMnd41';

        foreach (
            [
                fn () => $gateway->rescheduleAutomaticPixPayment(new \Potelo\MultiPayment\Models\Invoice()),
                fn () => $gateway->cancelAutomaticPixScheduledPayment($charge),
                fn () => $gateway->cancelAutomaticPixRecurrence($automaticPix),
            ] as $operation
        ) {
            try {
                $operation();
                $this->fail('Esperava UnsupportedOperationException');
            } catch (UnsupportedOperationException $e) {
                $this->assertSame(Capability::AUTOMATIC_PIX, $e->capability);
                $this->assertSame(UnsupportedOperationException::REASON_MANAGED_BY_GATEWAY, $e->reason);
            }
        }

        $this->assertSame([], $httpClient->calls);
    }

    public function testListAutomaticPixCancellationsReadsTheMandate(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::fixture('mandates/active'),
        ]);

        $automaticPix = new AutomaticPix();
        $automaticPix->id = 'mandate_1UBJmkPjx0CusuMr7PxMnd41';

        $cancellations = (new StripeGateway())->listAutomaticPixCancellations($automaticPix);

        $this->assertSame(
            ['get /v1/mandates/mandate_1UBJmkPjx0CusuMr7PxMnd41'],
            self::calledPaths($httpClient)
        );
        $this->assertSame([], $cancellations);
        $this->assertSame('mandate_1UBJmkPjx0CusuMr7PxMnd41', $automaticPix->mandateId);
        $this->assertSame('active', $automaticPix->mandateStatus);
    }

    public function testListAutomaticPixCancellationsReturnsTheCompletedOneForAnInactiveMandate(): void
    {
        RecordingStripeHttpClient::withResponses([
            self::fixture('mandates/inactive'),
        ]);

        $automaticPix = new AutomaticPix();
        $automaticPix->id = 'mandate_1UBJmkPjx0CusuMr7PxMnd41';

        $cancellations = (new StripeGateway())->listAutomaticPixCancellations($automaticPix);

        $this->assertCount(1, $cancellations);
        $this->assertSame(AutomaticPixCancellation::STATUS_COMPLETED, $cancellations[0]->status);
        $this->assertSame('mandate_1UBJmkPjx0CusuMr7PxMnd41', $cancellations[0]->recurrenceId);
        $this->assertSame('inactive', $automaticPix->mandateStatus);
    }

    public function testGetAutomaticPixCancellationReadsTheMandate(): void
    {
        RecordingStripeHttpClient::withResponses([
            self::fixture('mandates/inactive'),
        ]);

        $cancellation = new AutomaticPixCancellation();
        $cancellation->recurrenceId = 'mandate_1UBJmkPjx0CusuMr7PxMnd41';

        $result = (new StripeGateway())->getAutomaticPixCancellation($cancellation);

        $this->assertSame(AutomaticPixCancellation::STATUS_COMPLETED, $result->status);
        $this->assertSame('mandate_1UBJmkPjx0CusuMr7PxMnd41', $result->id);
        $this->assertSame('stripe', $result->gateway);
    }

    public function testGetAutomaticPixCancellationOnAnActiveMandateIsNotFound(): void
    {
        RecordingStripeHttpClient::withResponses([
            self::fixture('mandates/active'),
        ]);

        $cancellation = new AutomaticPixCancellation();
        $cancellation->recurrenceId = 'mandate_1UBJmkPjx0CusuMr7PxMnd41';

        $this->expectException(NotFoundException::class);

        (new StripeGateway())->getAutomaticPixCancellation($cancellation);
    }

    /**
     * Num model lido do gateway o mandato já existe e o método não é uma troca: o update passa
     * sem reescrever `payment_settings`.
     */
    public function testUpdateSubscriptionKeepsAnExistingMandateUntouched(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::fixture('subscriptions/active_automatic_pix'),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1UBJmkPjx0CusuMr3KQ2wXyZ';
        $subscription->paymentMethod = PaymentMethod::AUTOMATIC_PIX;
        $subscription->metadata = ['origem' => 'teste'];
        $subscription->original = json_decode(json_encode(self::fixture('subscriptions/active_automatic_pix')));

        (new StripeGateway())->updateSubscription($subscription);

        $params = $httpClient->calls[0][2];
        $this->assertArrayNotHasKey('payment_settings', $params);
        $this->assertSame(['origem' => 'teste'], $params['metadata']);
    }

    private static function subscriptionModel(): Subscription
    {
        $subscription = new Subscription();
        $subscription->customer = new Customer();
        $subscription->customer->id = 'cus_fake123';
        $subscription->planId = 'price_fake1';

        return $subscription;
    }

    /**
     * @return string[]  `método caminho` de cada chamada gravada
     */
    private static function calledPaths(RecordingStripeHttpClient $httpClient): array
    {
        return array_map(
            static fn (array $call) => $call[0] . ' ' . parse_url($call[1], PHP_URL_PATH),
            $httpClient->calls
        );
    }

    private static function fixture(string $path): array
    {
        return json_decode(file_get_contents(__DIR__ . "/../../fixtures/stripe/{$path}.json"), true);
    }

    private static function priceResponse(): array
    {
        return [
            'id' => 'price_fake1',
            'object' => 'price',
            'active' => true,
            'currency' => 'brl',
            'lookup_key' => 'plano_mensal',
            'nickname' => null,
            'created' => 1786700000,
            'product' => 'prod_fake1',
            'recurring' => ['interval' => 'month', 'interval_count' => 1, 'usage_type' => 'licensed'],
            'type' => 'recurring',
            'unit_amount' => 10000,
            'unit_amount_decimal' => '10000',
        ];
    }
}
