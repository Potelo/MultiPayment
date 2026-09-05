<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Carbon\Carbon;
use Stripe\ApiRequestor;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Models\SubscriptionItem;
use Potelo\MultiPayment\Gateways\StripeGateway;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Enums\DeclineCode;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Enums\ProrationBehavior;
use Potelo\MultiPayment\Enums\SubscriptionStatus;
use Potelo\MultiPayment\Exceptions\ChargingException;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Assinatura no driver Stripe: criação sobre Price e Subscription, leitura com a fatura mais
 * recente, pausa e retomada por `pause_collection`, cancelamento imediato e ao fim do
 * período, troca de plano com política de pró-rata e prévia com linhas reais. As fixtures de
 * `tests/fixtures/stripe/subscriptions/` foram gravadas na sandbox pelo próprio driver (ver o
 * README da pasta).
 */
class StripeGatewaySubscriptionTest extends TestCase
{
    private const SUBSCRIPTION_EXPAND = ['default_payment_method', 'items.data.price.product'];

    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment.gateways.stripe.api_key' => 'sk_test_fake',
        ]));
        Facade::setFacadeApplication($app);

        RecordingStripeHttpClient::withResponses([]);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testCreateSubscriptionWithASavedCardChargesTheFirstInvoiceAndReadsIt(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::priceListResponse(),
            self::fixture('subscriptions/active'),
            self::fixture('invoices/paid'),
            self::fixture('payment_intents/paid'),
        ]);

        $subscription = self::subscriptionModel();
        $subscription->creditCard = new CreditCard();
        $subscription->creditCard->id = 'pm_fake123';

        $result = (new StripeGateway())->createSubscription($subscription);

        $this->assertSame([
            'get /v1/prices',
            'post /v1/subscriptions',
            'get /v1/invoices/in_1UBJmkPjx0CusuMrN6Yc2Ha1',
            'get /v1/payment_intents/pi_3UBHTpPjx0CusuMr1JTEiHGi',
        ], self::calledPaths($httpClient));

        $params = $httpClient->calls[1][2];
        $this->assertSame('cus_fake123', $params['customer']);
        $this->assertSame([['price' => 'price_fake1']], $params['items']);
        $this->assertSame('charge_automatically', $params['collection_method']);
        $this->assertSame('error_if_incomplete', $params['payment_behavior']);
        $this->assertSame(['payment_method_types' => ['card']], $params['payment_settings']);
        $this->assertSame('pm_fake123', $params['default_payment_method']);
        $this->assertSame(self::SUBSCRIPTION_EXPAND, $params['expand']);

        $this->assertSame('sub_1UBJmkPjx0CusuMr3KQ2wXyZ', $result->id);
        $this->assertSame(SubscriptionStatus::ACTIVE, $result->status);
        $this->assertSame('plano_mensal', $result->planId);
        $this->assertSame(10000, $result->amount);
        $this->assertSame([], $result->items);
        $this->assertSame('stripe', $result->gateway);
        $this->assertSame(InvoiceStatus::PAID, $result->latestInvoice->status);
    }

    public function testCreateSubscriptionWithTrialDaysSendsThePeriodAndReturnsTheDate(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::fixture('subscriptions/trialing'),
            self::fixture('invoices/paid'),
            self::fixture('payment_intents/paid'),
        ]);

        $subscription = self::subscriptionModel();
        $subscription->planId = 'price_fake1';
        $subscription->trialDays = 7;

        $result = (new StripeGateway())->createSubscription($subscription);

        $params = $httpClient->calls[0][2];
        $this->assertSame(7, $params['trial_period_days']);
        $this->assertArrayNotHasKey('trial_end', $params);

        $this->assertNull($result->trialDays);
        $this->assertSame(1789170793, $result->trialEndsAt->getTimestamp());
        $this->assertSame(SubscriptionStatus::TRIALING, $result->status);
    }

    public function testCreateSubscriptionWithPixLeavesTheFirstInvoiceOpen(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::fixture('subscriptions/incomplete'),
            self::fixture('invoices/open_requires_payment_method'),
        ]);

        $subscription = self::subscriptionModel();
        $subscription->planId = 'price_fake1';
        $subscription->availablePaymentMethods = [PaymentMethod::PIX];

        $result = (new StripeGateway())->createSubscription($subscription);

        $params = $httpClient->calls[0][2];
        $this->assertSame('default_incomplete', $params['payment_behavior']);
        $this->assertSame(['payment_method_types' => ['pix']], $params['payment_settings']);

        $this->assertSame(SubscriptionStatus::PENDING, $result->status);
        $this->assertSame(InvoiceStatus::PENDING, $result->latestInvoice->status);
    }

    public function testCreateSubscriptionWithExtraItemsCreatesPricesOnDemand(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::productResponse('prod_item0'),
            self::priceResponse(),
            self::productResponse('prod_item1'),
            self::fixture('subscriptions/active'),
            self::fixture('invoices/paid'),
            self::fixture('payment_intents/paid'),
        ]);

        $recurring = new SubscriptionItem();
        $recurring->description = 'Consultas extras';
        $recurring->amount = 2500;
        $recurring->quantity = 2;

        $oneTime = new SubscriptionItem();
        $oneTime->description = 'Taxa de adesão';
        $oneTime->amount = 900;
        $oneTime->recurring = false;

        $subscription = self::subscriptionModel();
        $subscription->planId = 'price_fake1';
        $subscription->items = [$recurring, $oneTime];

        (new StripeGateway())->createSubscription($subscription);

        $this->assertSame([
            'post /v1/products',
            'get /v1/prices/price_fake1',
            'post /v1/products',
            'post /v1/subscriptions',
            'get /v1/invoices/in_1UBJmkPjx0CusuMrN6Yc2Ha1',
            'get /v1/payment_intents/pi_3UBHTpPjx0CusuMr1JTEiHGi',
        ], self::calledPaths($httpClient));
        $this->assertSame(['name' => 'Consultas extras'], $httpClient->calls[0][2]);
        $this->assertSame(['name' => 'Taxa de adesão'], $httpClient->calls[2][2]);

        $params = $httpClient->calls[3][2];
        $this->assertSame([
            ['price' => 'price_fake1'],
            [
                'price_data' => [
                    'currency' => 'brl',
                    'product' => 'prod_item0',
                    'unit_amount' => 2500,
                    'recurring' => ['interval' => 'month', 'interval_count' => 1],
                ],
                'quantity' => 2,
            ],
        ], $params['items']);
        $this->assertSame([
            [
                'price_data' => ['currency' => 'brl', 'product' => 'prod_item1', 'unit_amount' => 900],
                'quantity' => 1,
            ],
        ], $params['add_invoice_items']);
    }

    public function testCreateSubscriptionWithACardThatRequiresAuthenticationIsInterrupted(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([self::fixture('setup_intents/requires_action')]);

        $subscription = self::subscriptionModel();
        $subscription->planId = 'price_fake1';
        $subscription->creditCard = new CreditCard();
        $subscription->creditCard->token = 'pm_fake123';

        try {
            (new StripeGateway())->createSubscription($subscription);
            $this->fail('Esperava ChargingException');
        } catch (ChargingException $e) {
            $this->assertSame(DeclineCode::AUTHENTICATION_REQUIRED, $e->declineCode);
            $this->assertNotNull($e->chargeResponse);
        }

        $this->assertSame(['post /v1/setup_intents'], self::calledPaths($httpClient));
    }

    public function testCreateSubscriptionSendsTrialEndAndBillingCycleAnchor(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::fixture('subscriptions/trialing'),
            self::fixture('invoices/paid'),
            self::fixture('payment_intents/paid'),
        ]);

        $subscription = self::subscriptionModel();
        $subscription->planId = 'price_fake1';
        $subscription->trialEndsAt = Carbon::createFromTimestamp(1789170793);
        $subscription->nextBillingAt = Carbon::createFromTimestamp(1791157981);

        (new StripeGateway())->createSubscription($subscription);

        $params = $httpClient->calls[0][2];
        $this->assertSame(1789170793, $params['trial_end']);
        $this->assertSame(1791157981, $params['billing_cycle_anchor']);
        $this->assertArrayNotHasKey('trial_period_days', $params);
    }

    public function testCreateSubscriptionRequiresCustomerAndPlanBeforeTheNetwork(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);
        $gateway = new StripeGateway();

        $withoutCustomer = new Subscription();
        $withoutCustomer->planId = 'plano_mensal';
        try {
            $gateway->createSubscription($withoutCustomer);
            $this->fail('Esperava ModelAttributeValidationException');
        } catch (ModelAttributeValidationException) {
        }

        $withoutPlan = new Subscription();
        $withoutPlan->customer = new Customer();
        $withoutPlan->customer->id = 'cus_fake123';
        try {
            $gateway->createSubscription($withoutPlan);
            $this->fail('Esperava ModelAttributeValidationException');
        } catch (ModelAttributeValidationException) {
        }

        $this->assertSame([], $httpClient->calls);
    }

    #[DataProvider('statusFixtureProvider')]
    public function testGetSubscriptionMapsEachStatus(string $fixture, SubscriptionStatus $expected): void
    {
        $response = self::fixture("subscriptions/{$fixture}");
        $responses = [$response];
        if (!empty($response['latest_invoice'])) {
            $responses[] = self::fixture('invoices/paid');
            $responses[] = self::fixture('payment_intents/paid');
        }
        RecordingStripeHttpClient::withResponses($responses);

        $result = $this->getSubscription($response['id']);

        $this->assertSame($expected, $result->status);
    }

    public static function statusFixtureProvider(): array
    {
        return [
            'incomplete' => ['incomplete', SubscriptionStatus::PENDING],
            'incomplete_expired' => ['incomplete_expired', SubscriptionStatus::EXPIRED],
            'trialing' => ['trialing', SubscriptionStatus::TRIALING],
            'active' => ['active', SubscriptionStatus::ACTIVE],
            'past_due' => ['past_due', SubscriptionStatus::PAST_DUE],
            'unpaid' => ['unpaid', SubscriptionStatus::PAST_DUE],
            'canceled' => ['canceled', SubscriptionStatus::CANCELED],
            'paused' => ['paused', SubscriptionStatus::PAUSED],
            'pause_collection' => ['active_pause_collection', SubscriptionStatus::PAUSED],
        ];
    }

    public function testGetSubscriptionParsesTheModelAndReadsTheLatestInvoice(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::fixture('subscriptions/active'),
            self::fixture('invoices/paid'),
            self::fixture('payment_intents/paid'),
        ]);

        $result = $this->getSubscription('sub_1UBJmkPjx0CusuMr3KQ2wXyZ');

        $this->assertSame(['expand' => self::SUBSCRIPTION_EXPAND], $httpClient->calls[0][2]);
        $this->assertSame('sub_1UBJmkPjx0CusuMr3KQ2wXyZ', $result->id);
        $this->assertSame('plano_mensal', $result->planId);
        $this->assertSame(10000, $result->amount);
        $this->assertSame('cus_VBen1v8T4Qa6XX', $result->customer->id);
        $this->assertSame(1791157981, $result->nextBillingAt->getTimestamp());
        $this->assertFalse($result->cancelAtPeriodEnd);
        $this->assertNull($result->canceledAt);
        $this->assertSame(1788565981, $result->createdAt->getTimestamp());
        $this->assertSame([], $result->metadata);
        $this->assertSame(PaymentMethod::CREDIT_CARD, $result->paymentMethod);
        $this->assertSame([PaymentMethod::CREDIT_CARD], $result->availablePaymentMethods);
        $this->assertSame(InvoiceStatus::PAID, $result->latestInvoice->status);
        $this->assertSame('stripe', $result->latestInvoice->gateway);
    }

    public function testGetSubscriptionOnACanceledSubscriptionFillsCanceledAt(): void
    {
        $response = self::fixture('subscriptions/canceled');
        RecordingStripeHttpClient::withResponses([
            $response,
            self::fixture('invoices/paid'),
            self::fixture('payment_intents/paid'),
        ]);

        $result = $this->getSubscription($response['id']);

        $this->assertSame(SubscriptionStatus::CANCELED, $result->status);
        $this->assertSame(1788565992, $result->canceledAt->getTimestamp());
    }

    public function testSuspendPausesTheCollection(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([self::fixture('subscriptions/active_pause_collection')]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1UBJmkPjx0CusuMr3KQ2wXyZ';

        $result = (new StripeGateway())->suspendSubscription($subscription);

        $this->assertSame(['post /v1/subscriptions/sub_1UBJmkPjx0CusuMr3KQ2wXyZ'], self::calledPaths($httpClient));
        $this->assertSame(['behavior' => 'void'], $httpClient->calls[0][2]['pause_collection']);
        $this->assertSame(SubscriptionStatus::PAUSED, $result->status);
    }

    public function testResumeClearsThePauseAndTheScheduledCancellation(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([self::fixture('subscriptions/active')]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1UBJmkPjx0CusuMr3KQ2wXyZ';

        $result = (new StripeGateway())->resumeSubscription($subscription);

        $params = $httpClient->calls[0][2];
        $this->assertSame('', $params['pause_collection']);
        // o encoder do stripe-php envia booleano como a string 'false'
        $this->assertSame('false', $params['cancel_at_period_end']);
        $this->assertSame(SubscriptionStatus::ACTIVE, $result->status);
    }

    public function testCancelImmediatelyDeletesTheSubscription(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([self::fixture('subscriptions/canceled')]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1UBJmkPjx0CusuMr3KQ2wXyZ';

        $result = (new StripeGateway())->cancelSubscription($subscription);

        $this->assertSame(['delete /v1/subscriptions/sub_1UBJmkPjx0CusuMr3KQ2wXyZ'], self::calledPaths($httpClient));
        $this->assertSame(SubscriptionStatus::CANCELED, $result->status);
        $this->assertSame(1788565992, $result->canceledAt->getTimestamp());
    }

    public function testCancelAtPeriodEndKeepsTheSubscriptionActiveAndFillsTheModel(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([self::fixture('subscriptions/active_cancel_at_period_end')]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1UBJmkPjx0CusuMr3KQ2wXyZ';

        $result = (new StripeGateway())->cancelSubscription($subscription, true);

        $this->assertSame(['post /v1/subscriptions/sub_1UBJmkPjx0CusuMr3KQ2wXyZ'], self::calledPaths($httpClient));
        $this->assertSame('true', $httpClient->calls[0][2]['cancel_at_period_end']);
        $this->assertSame(SubscriptionStatus::ACTIVE, $result->status);
        $this->assertTrue($result->cancelAtPeriodEnd);
        $this->assertSame(1788565991, $result->canceledAt->getTimestamp());
    }

    #[DataProvider('prorationProvider')]
    public function testChangePlanTranslatesTheProrationPolicy(ProrationBehavior $proration, string $expected, bool $readsInvoice): void
    {
        $changed = self::fixture('subscriptions/active');
        $changed['items']['data'][0]['price']['id'] = 'price_fake2';
        $changed['items']['data'][0]['price']['lookup_key'] = 'plano_anual';
        $responses = [
            self::priceListResponse('price_fake2', 'plano_anual'),
            self::fixture('subscriptions/active'),
            $changed,
        ];
        if ($readsInvoice) {
            $responses[] = self::fixture('invoices/paid');
            $responses[] = self::fixture('payment_intents/paid');
        }
        $httpClient = RecordingStripeHttpClient::withResponses($responses);

        $subscription = new Subscription();
        $subscription->id = 'sub_1UBJmkPjx0CusuMr3KQ2wXyZ';

        $result = (new StripeGateway())->changeSubscriptionPlan($subscription, 'plano_anual', $proration);

        $params = $httpClient->calls[2][2];
        $this->assertSame([['id' => 'si_UBJmkPjx0CusuMrLq0v9Yb2', 'price' => 'price_fake2']], $params['items']);
        $this->assertSame($expected, $params['proration_behavior']);
        $this->assertSame('plano_anual', $result->planId);
        $this->assertSame($readsInvoice, !is_null($result->latestInvoice));
    }

    public static function prorationProvider(): array
    {
        return [
            'CHARGE_DIFFERENCE fatura na hora' => [ProrationBehavior::CHARGE_DIFFERENCE, 'always_invoice', true],
            'NONE não gera pró-rata' => [ProrationBehavior::NONE, 'none', false],
            'CREDIT deixa a pró-rata para a próxima fatura' => [ProrationBehavior::CREDIT, 'create_prorations', false],
        ];
    }

    public function testChangePlanWithADifferentNextBillingAtIsRefusedBeforeTheNetwork(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1UBJmkPjx0CusuMr3KQ2wXyZ';
        $subscription->nextBillingAt = Carbon::parse('2030-01-01');

        try {
            (new StripeGateway())->changeSubscriptionPlan($subscription, 'plano_anual', ProrationBehavior::NONE);
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::SUBSCRIPTIONS, $e->capability);
            $this->assertSame(UnsupportedOperationException::REASON_GATEWAY_LIMITATION, $e->reason);
        }

        // outra hora do mesmo dia também é mudança: a comparação com o lido é exata
        $sameDay = new Subscription();
        $sameDay->id = 'sub_1UBJmkPjx0CusuMr3KQ2wXyZ';
        $sameDay->nextBillingAt = Carbon::createFromTimestamp(1791157981 + 3600);
        $sameDay->original = json_decode(json_encode(self::fixture('subscriptions/active')));

        try {
            (new StripeGateway())->changeSubscriptionPlan($sameDay, 'plano_anual', ProrationBehavior::NONE);
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::SUBSCRIPTIONS, $e->capability);
        }
        $this->assertSame([], $httpClient->calls);
    }

    public function testChangePlanWithTheNextBillingAtReadFromTheGatewayIsAccepted(): void
    {
        $changed = self::fixture('subscriptions/active');
        RecordingStripeHttpClient::withResponses([
            self::priceListResponse('price_fake2', 'plano_anual'),
            self::fixture('subscriptions/active'),
            $changed,
        ]);

        // um model lido do gateway traz nextBillingAt preenchido com o current_period_end
        $subscription = new Subscription();
        $subscription->id = 'sub_1UBJmkPjx0CusuMr3KQ2wXyZ';
        $subscription->nextBillingAt = Carbon::createFromTimestamp(1791157981);
        $subscription->original = json_decode(json_encode(self::fixture('subscriptions/active')));

        $result = (new StripeGateway())->changeSubscriptionPlan($subscription, 'plano_anual', ProrationBehavior::NONE);

        $this->assertSame(SubscriptionStatus::ACTIVE, $result->status);
    }

    public function testPreviewPlanChangeReturnsTheRealProrationLines(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::priceListResponse('price_fake2', 'plano_anual'),
            self::fixture('subscriptions/active'),
            self::previewInvoiceResponse(),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1UBJmkPjx0CusuMr3KQ2wXyZ';

        $preview = (new StripeGateway())->previewSubscriptionPlanChange($subscription, 'plano_anual');

        $this->assertSame([
            'get /v1/prices',
            'get /v1/subscriptions/sub_1UBJmkPjx0CusuMr3KQ2wXyZ',
            'post /v1/invoices/create_preview',
        ], self::calledPaths($httpClient));
        $params = $httpClient->calls[2][2];
        $this->assertSame('sub_1UBJmkPjx0CusuMr3KQ2wXyZ', $params['subscription']);
        $this->assertSame(
            [
                'items' => [['id' => 'si_UBJmkPjx0CusuMrLq0v9Yb2', 'price' => 'price_fake2']],
                'proration_behavior' => 'always_invoice',
            ],
            $params['subscription_details']
        );

        $this->assertSame(25005, $preview->amount);
        $this->assertCount(3, $preview->items);
        $this->assertSame('Unused time on Mensal', $preview->items[0]->description);
        $this->assertSame(-5000, $preview->items[0]->price);
        $this->assertSame('Anual', $preview->items[1]->description);
        // linha com quantidade 2: price é o valor unitário (30000 dividido por 2)
        $this->assertSame(2, $preview->items[1]->quantity);
        $this->assertSame(15000, $preview->items[1]->price);
        // valor que não divide pela quantidade vira linha de valor total, e a soma dos itens
        // continua igual a amount
        $this->assertSame(1, $preview->items[2]->quantity);
        $this->assertSame(5, $preview->items[2]->price);
        $this->assertSame(
            $preview->amount,
            array_sum(array_map(static fn ($item) => $item->price * $item->quantity, $preview->items))
        );
        $this->assertSame(1819904400, $preview->effectiveAt->getTimestamp());
        $this->assertTrue($preview->appliesImmediately);
        $this->assertSame('stripe', $preview->gateway);
    }

    public function testUpdateSubscriptionReplacesTheItemsDeclaratively(): void
    {
        $current = self::fixture('subscriptions/active');
        $current['items']['data'][] = self::extraItemResponse();
        $httpClient = RecordingStripeHttpClient::withResponses([
            $current,
            self::fixture('subscriptions/active'),
        ]);

        // lista desejada vazia: o item extra sai, o item do plano fica
        $subscription = new Subscription();
        $subscription->id = 'sub_1UBJmkPjx0CusuMr3KQ2wXyZ';
        $subscription->items = [];

        (new StripeGateway())->updateSubscription($subscription);

        $this->assertSame([
            'get /v1/subscriptions/sub_1UBJmkPjx0CusuMr3KQ2wXyZ',
            'post /v1/subscriptions/sub_1UBJmkPjx0CusuMr3KQ2wXyZ',
        ], self::calledPaths($httpClient));
        $params = $httpClient->calls[1][2];
        $this->assertSame([['id' => 'si_extra1', 'deleted' => 'true']], $params['items']);
        $this->assertSame('none', $params['proration_behavior']);
    }

    public function testUpdateSubscriptionWritesCardTrialAndMetadata(): void
    {
        Carbon::setTestNow('2026-09-04 12:00:00');
        try {
            $httpClient = RecordingStripeHttpClient::withResponses([self::fixture('subscriptions/active')]);

            $subscription = new Subscription();
            $subscription->id = 'sub_1UBJmkPjx0CusuMr3KQ2wXyZ';
            $subscription->creditCard = new CreditCard();
            $subscription->creditCard->id = 'pm_fake456';
            $subscription->trialDays = 7;
            $subscription->metadata = ['origem' => 'teste'];

            (new StripeGateway())->updateSubscription($subscription);

            $params = $httpClient->calls[0][2];
            $this->assertSame('pm_fake456', $params['default_payment_method']);
            $this->assertSame(Carbon::now()->addDays(7)->getTimestamp(), $params['trial_end']);
            $this->assertSame(['origem' => 'teste'], $params['metadata']);
            $this->assertNull($subscription->trialDays);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testUpdateSubscriptionWritesThePaymentSettingsWhenTheMethodChanged(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([self::fixture('subscriptions/active')]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1UBJmkPjx0CusuMr3KQ2wXyZ';
        $subscription->paymentMethod = PaymentMethod::PIX;

        (new StripeGateway())->updateSubscription($subscription);

        $this->assertSame(
            ['payment_method_types' => ['pix']],
            $httpClient->calls[0][2]['payment_settings']
        );
    }

    /**
     * Método e trial iguais aos lidos do gateway ficam fora do payload: um update que mexeu em
     * outra coisa não reescreve o que já está na assinatura.
     */
    public function testUpdateSubscriptionSkipsThePaymentMethodAndTrialReadFromTheGateway(): void
    {
        $original = json_decode(json_encode(self::fixture('subscriptions/trialing')));
        $httpClient = RecordingStripeHttpClient::withResponses([self::fixture('subscriptions/trialing')]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1UBJmkPjx0CusuMr3KQ2wXyZ';
        $subscription->availablePaymentMethods = [PaymentMethod::CREDIT_CARD];
        $subscription->trialEndsAt = Carbon::createFromTimestamp($original->trial_end);
        $subscription->metadata = ['origem' => 'teste'];
        $subscription->original = $original;

        (new StripeGateway())->updateSubscription($subscription);

        $params = $httpClient->calls[0][2];
        $this->assertArrayNotHasKey('payment_settings', $params);
        $this->assertArrayNotHasKey('trial_end', $params);
        $this->assertSame(['origem' => 'teste'], $params['metadata']);
    }

    public function testUpdateSubscriptionUpdatesKeptItemsAndCreatesNewOnes(): void
    {
        $current = self::fixture('subscriptions/active');
        $current['items']['data'][] = self::extraItemResponse();
        $httpClient = RecordingStripeHttpClient::withResponses([
            $current,
            self::productResponse('prod_item0'),
            self::priceResponse(),
            self::fixture('subscriptions/active'),
        ]);

        $kept = new SubscriptionItem();
        $kept->id = 'si_extra1';
        $kept->quantity = 3;

        $new = new SubscriptionItem();
        $new->description = 'Consultas extras';
        $new->amount = 2500;

        $subscription = new Subscription();
        $subscription->id = 'sub_1UBJmkPjx0CusuMr3KQ2wXyZ';
        $subscription->items = [$kept, $new];

        (new StripeGateway())->updateSubscription($subscription);

        $params = $httpClient->calls[3][2];
        $this->assertSame([
            ['id' => 'si_extra1', 'quantity' => 3],
            [
                'price_data' => [
                    'currency' => 'brl',
                    'product' => 'prod_item0',
                    'unit_amount' => 2500,
                    'recurring' => ['interval' => 'month', 'interval_count' => 1],
                ],
                'quantity' => 1,
            ],
        ], $params['items']);
        $this->assertSame('none', $params['proration_behavior']);
    }

    /**
     * A recusa do método vem antes de o cartão ser salvo: um cartão por token com uma lista
     * sem cartão não pode ficar anexado ao cliente na Stripe.
     */
    public function testUpdateSubscriptionValidatesTheMethodBeforeSavingTheCard(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1UBJmkPjx0CusuMr3KQ2wXyZ';
        $subscription->availablePaymentMethods = [PaymentMethod::PIX];
        $subscription->creditCard = new CreditCard();
        $subscription->creditCard->token = 'pm_fake123';

        try {
            (new StripeGateway())->updateSubscription($subscription);
            $this->fail('Esperava ModelAttributeValidationException');
        } catch (ModelAttributeValidationException) {
        }
        $this->assertSame([], $httpClient->calls);
    }

    /**
     * Sem `planId` no model, o item do plano é o único cujo Price tem `lookup_key`: os Prices
     * de itens extras criados pela lib não têm um, e a ordem da lista da Stripe não decide.
     */
    public function testParseElectsThePlanItemByLookupKeyWhenThePlanIdIsUnknown(): void
    {
        $response = self::fixture('subscriptions/active');
        $extra = self::extraItemResponse();
        $extra['created'] = $response['items']['data'][0]['created'];
        $extra['price']['lookup_key'] = null;
        array_unshift($response['items']['data'], $extra);
        RecordingStripeHttpClient::withResponses([
            $response,
            self::fixture('invoices/paid'),
            self::fixture('payment_intents/paid'),
        ]);

        $result = $this->getSubscription('sub_1UBJmkPjx0CusuMr3KQ2wXyZ');

        $this->assertSame('plano_mensal', $result->planId);
        $this->assertCount(1, $result->items);
        $this->assertSame('si_extra1', $result->items[0]->id);
    }

    public function testUpdateSubscriptionRefusesMethodsTheDriverDoesNotCoverBeforeTheNetwork(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);
        $gateway = new StripeGateway();

        $multi = new Subscription();
        $multi->id = 'sub_1UBJmkPjx0CusuMr3KQ2wXyZ';
        $multi->availablePaymentMethods = [PaymentMethod::PIX, PaymentMethod::CREDIT_CARD];
        try {
            $gateway->updateSubscription($multi);
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::MULTIPLE_PAYMENT_METHODS, $e->capability);
        }

        $boleto = new Subscription();
        $boleto->id = 'sub_1UBJmkPjx0CusuMr3KQ2wXyZ';
        $boleto->availablePaymentMethods = [PaymentMethod::BANK_SLIP];
        try {
            $gateway->updateSubscription($boleto);
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::BANK_SLIP, $e->capability);
        }

        $this->assertSame([], $httpClient->calls);
    }

    public function testUpdateSubscriptionWithADifferentNextBillingAtIsRefusedBeforeTheNetwork(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1UBJmkPjx0CusuMr3KQ2wXyZ';
        $subscription->nextBillingAt = Carbon::parse('2030-01-01');

        try {
            (new StripeGateway())->updateSubscription($subscription);
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::SUBSCRIPTIONS, $e->capability);
            $this->assertSame(UnsupportedOperationException::REASON_GATEWAY_LIMITATION, $e->reason);
        }
        $this->assertSame([], $httpClient->calls);
    }

    public function testListSubscriptionsValidatesInputBeforeTheNetwork(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);
        $gateway = new StripeGateway();
        $customer = new Customer();
        $customer->id = 'cus_fake123';

        foreach ([
            [new Customer(), 1, 10],
            [$customer, 0, 10],
            [$customer, 1, 0],
            [$customer, 1, 101],
        ] as [$who, $page, $limit]) {
            try {
                $gateway->listSubscriptions($who, $page, $limit);
                $this->fail('Esperava ModelAttributeValidationException');
            } catch (ModelAttributeValidationException) {
            }
        }
        $this->assertSame([], $httpClient->calls);
    }

    public function testListSubscriptionsListsEveryStatusWithoutTheLatestInvoice(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            [
                'object' => 'list',
                'url' => '/v1/subscriptions',
                'has_more' => false,
                'data' => [self::fixture('subscriptions/active'), self::fixture('subscriptions/canceled')],
            ],
        ]);

        $customer = new Customer();
        $customer->id = 'cus_fake123';

        $subscriptions = (new StripeGateway())->listSubscriptions($customer);

        $params = $httpClient->calls[0][2];
        $this->assertSame('cus_fake123', $params['customer']);
        $this->assertSame('all', $params['status']);
        $this->assertSame(100, $params['limit']);
        $this->assertCount(2, $subscriptions);
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscriptions[0]->status);
        $this->assertSame(SubscriptionStatus::CANCELED, $subscriptions[1]->status);
        $this->assertNull($subscriptions[0]->latestInvoice);
    }

    private function getSubscription(string $id): Subscription
    {
        $subscription = new Subscription();
        $subscription->id = $id;

        return (new StripeGateway())->getSubscription($subscription);
    }

    private static function subscriptionModel(): Subscription
    {
        $subscription = new Subscription();
        $subscription->customer = new Customer();
        $subscription->customer->id = 'cus_fake123';
        $subscription->planId = 'plano_mensal';

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

    private static function productResponse(string $id): array
    {
        return ['id' => $id, 'object' => 'product', 'name' => 'Produto', 'active' => true, 'created' => 1786700000, 'metadata' => []];
    }

    private static function priceResponse(string $id = 'price_fake1', string $lookupKey = 'plano_mensal'): array
    {
        return [
            'id' => $id,
            'object' => 'price',
            'active' => true,
            'currency' => 'brl',
            'lookup_key' => $lookupKey,
            'nickname' => null,
            'created' => 1786700000,
            'product' => 'prod_fake1',
            'recurring' => ['interval' => 'month', 'interval_count' => 1, 'usage_type' => 'licensed'],
            'type' => 'recurring',
            'unit_amount' => 10000,
            'unit_amount_decimal' => '10000',
        ];
    }

    private static function priceListResponse(string $id = 'price_fake1', string $lookupKey = 'plano_mensal'): array
    {
        return [
            'object' => 'list',
            'url' => '/v1/prices',
            'has_more' => false,
            'data' => [self::priceResponse($id, $lookupKey)],
        ];
    }

    private static function extraItemResponse(): array
    {
        return [
            'id' => 'si_extra1',
            'object' => 'subscription_item',
            // criado depois do item do plano: o mais antigo é lido como o do plano
            'created' => 1788566500,
            'quantity' => 1,
            'subscription' => 'sub_1UBJmkPjx0CusuMr3KQ2wXyZ',
            'price' => self::priceResponse('price_extra1', ''),
        ];
    }

    /**
     * Prévia de fatura da troca de plano: crédito do período não usado e cobrança do plano
     * novo, no formato de `invoices.create_preview`.
     */
    private static function previewInvoiceResponse(): array
    {
        return [
            'id' => 'upcoming_in_fake1',
            'object' => 'invoice',
            'total' => 25005,
            'currency' => 'brl',
            'lines' => [
                'object' => 'list',
                'has_more' => false,
                'data' => [
                    [
                        'id' => 'il_fake1',
                        'object' => 'line_item',
                        'description' => 'Unused time on Mensal',
                        'amount' => -5000,
                        'quantity' => 1,
                        'period' => ['start' => 1788368400, 'end' => 1791157981],
                    ],
                    [
                        'id' => 'il_fake2',
                        'object' => 'line_item',
                        'description' => 'Anual',
                        'amount' => 30000,
                        'quantity' => 2,
                        'period' => ['start' => 1788368400, 'end' => 1819904400],
                    ],
                    [
                        'id' => 'il_fake3',
                        'object' => 'line_item',
                        'description' => 'Ajuste',
                        'amount' => 5,
                        'quantity' => 3,
                        'period' => ['start' => 1788368400, 'end' => 1790960400],
                    ],
                ],
            ],
        ];
    }
}
