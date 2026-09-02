<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Plan;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Gateways\IuguGateway;
use Potelo\MultiPayment\Models\SubscriptionItem;
use Potelo\MultiPayment\Models\SubscriptionDiscount;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Enums\InvoiceOriginType;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Enums\PlanInterval;
use Potelo\MultiPayment\Tests\Unit\RecordingLogger;

class IuguGatewaySubscriptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment.gateways.iugu.api_key' => 'test-api-key',
        ]));
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
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

    #[DataProvider('statusProvider')]
    public function testParseMapsIuguFlagsToGenericStatus(array $flags, ?string $expected): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse($flags)]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription = (new IuguGateway($api))->getSubscription($subscription);

        $this->assertSame($expected, $subscription->status);
    }

    public static function statusProvider(): array
    {
        return [
            'suspensa' => [['suspended' => true, 'active' => false], Subscription::STATUS_SUSPENDED],
            'suspensa tem precedencia sobre trial' => [
                ['suspended' => true, 'in_trial' => true],
                Subscription::STATUS_SUSPENDED,
            ],
            'em trial' => [['in_trial' => true], Subscription::STATUS_TRIALING],
            'ativa' => [['active' => true], Subscription::STATUS_ACTIVE],
            'inativa' => [['active' => false], Subscription::STATUS_PENDING],
            'sem flag nenhuma' => [['active' => null, 'suspended' => null, 'in_trial' => null], null],
        ];
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
        $this->assertSame(Subscription::STATUS_SUSPENDED, $suspended->status);

        $resumed = $gateway->resumeSubscription($subscription);
        $this->assertStringEndsWith('/subscriptions/sub_1/activate', $api->calls[1]['url']);
        $this->assertSame(Subscription::STATUS_ACTIVE, $resumed->status);
    }

    public function testCancelWithoutPeriodEndSuspendsTheSubscription(): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse(['suspended' => true])]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        (new IuguGateway($api))->cancelSubscription($subscription);

        $this->assertStringEndsWith('/subscriptions/sub_1/suspend', $api->calls[0]['url']);
    }

    public function testCancelAtPeriodEndIsRejected(): void
    {
        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $api = new QueuedIuguApiRequest([]);

        try {
            (new IuguGateway($api))->cancelSubscription($subscription, true);
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::CANCEL_AT_PERIOD_END, $e->capability);
            $this->assertSame('iugu', $e->gateway);
            $this->assertSame(UnsupportedOperationException::REASON_GATEWAY_LIMITATION, $e->reason);
            $this->assertFalse($e->isNotImplemented());
            $this->assertStringContainsString('Suspenda a assinatura', $e->getMessage());
        }
        $this->assertCount(0, $api->calls);
    }

    public function testChangePlanWithoutChargeSendsSkipChargeAndTheNewBillingDate(): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse()]);

        $subscription = new Subscription();
        $subscription->fill(['id' => 'sub_1', 'next_billing_at' => '2026-12-01']);

        (new IuguGateway($api))->changeSubscriptionPlan($subscription, 'plano_anual', false);

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

        $changed = (new IuguGateway($api))->changeSubscriptionPlan($subscription, 'plano_anual');

        $this->assertCount(2, $api->calls);
        $this->assertSame('POST', $api->calls[0]['method']);
        $this->assertStringEndsWith('/subscriptions/sub_1/change_plan/plano_anual', $api->calls[0]['url']);
        $this->assertSame('GET', $api->calls[1]['method']);
        $this->assertSame('plano_anual', $changed->planId);
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
     * `old_plan`, com `discount` em 0 e sem linhas. O parse lê `cost` e deixa `items` nulo.
     */
    public function testPreviewPlanChangeReadsTheSimulationResponse(): void
    {
        $api = new QueuedIuguApiRequest([
            json_decode(
                file_get_contents(__DIR__ . '/../../fixtures/iugu/change_plan_simulation.json'),
                flags: JSON_THROW_ON_ERROR
            ),
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $planChange = (new IuguGateway($api))
            ->previewSubscriptionPlanChange($subscription, 'multipayment-teste-destino');

        $this->assertStringEndsWith(
            '/subscriptions/sub_1/change_plan_simulation/multipayment-teste-destino',
            $api->calls[0]['url']
        );
        $this->assertSame(30000, $planChange->amount);
        $this->assertSame('2026-10-02', $planChange->effectiveAt->format('Y-m-d'));
        $this->assertNull($planChange->items);
        $this->assertSame(0, $planChange->original->discount);
        $this->assertSame(1, $planChange->original->cycles);
        $this->assertSame('multipayment-teste-destino', $planChange->original->new_plan);
        $this->assertSame('multipayment-teste-origem', $planChange->original->old_plan);
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

        $planChange = (new IuguGateway($api))->previewSubscriptionPlanChange($subscription, 'plano_anual');

        $this->assertSame(30000, $planChange->amount);
        $this->assertSame('2026-12-01', $planChange->effectiveAt->format('Y-m-d'));
        $this->assertCount(1, $planChange->items);
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
            $this->assertSame(Capability::NATIVE_COUPONS, $e->capability);
            $this->assertSame(UnsupportedOperationException::REASON_GATEWAY_LIMITATION, $e->reason);
            $this->assertStringContainsString('use amountOff', $e->getMessage());
        }
        $this->assertCount(0, $api->calls);
    }

    /**
     * A Iugu só expressa desconto de uma fatura ou até ser removido, então guardar cycles maior
     * que 1 devolveria um limite que o gateway não mantém.
     */
    public function testDiscountLimitedToMoreThanOneCycleIsRejected(): void
    {
        $discount = new SubscriptionDiscount();
        $discount->description = 'Promo';
        $discount->amountOff = 500;
        $discount->cycles = 3;

        $subscription = new Subscription();
        $subscription->fill(['plan_id' => 'plano', 'customer' => ['id' => 'cus_1']]);
        $subscription->discounts = [$discount];

        $api = new QueuedIuguApiRequest([]);

        try {
            (new IuguGateway($api))->createSubscription($subscription);
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::NATIVE_COUPONS, $e->capability);
            $this->assertSame(UnsupportedOperationException::REASON_GATEWAY_LIMITATION, $e->reason);
            $this->assertStringContainsString('use cycles 1 ou nulo', $e->getMessage());
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
            $this->fail('Expected GatewayException');
        } catch (GatewayException $e) {
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

        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->status);
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

        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->status);
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
            Subscription::STATUS_ACTIVE,
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
            Subscription::STATUS_ACTIVE,
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
            Subscription::STATUS_SUSPENDED,
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

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
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

        $this->expectException(GatewayException::class);
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

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches($mensagem);

        $gateway->listSubscriptions($customer, $page, $limit);
    }

    #[DataProvider('invalidPaginationProvider')]
    public function testPlanPaginationBoundsAreRejected(int $page, int $limit, string $mensagem): void
    {
        $gateway = new IuguGateway(new QueuedIuguApiRequest([]));

        $this->expectException(GatewayException::class);
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

    public function testCancelReturnsTheSuspendedSubscription(): void
    {
        $api = new QueuedIuguApiRequest([$this->subscriptionResponse(['suspended' => true])]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $canceled = (new IuguGateway($api))->cancelSubscription($subscription);

        $this->assertCount(1, $api->calls);
        $this->assertSame(Subscription::STATUS_SUSPENDED, $canceled->status);
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

        $this->expectException(GatewayException::class);
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

        $planChange = (new IuguGateway($api))->previewSubscriptionPlanChange($subscription, 'p');

        $this->assertNull($planChange->amount);
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
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);

        $subscription = $gateway->updateSubscription($subscription);

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertSame('inv_1', $subscription->latestInvoice->id);
    }

    public function testPlanChangeFallsBackWhenCostIsNotNumeric(): void
    {
        $api = new QueuedIuguApiRequest([(object) ['cost' => 'R$ 300,00', 'price_cents' => 30000]]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

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
        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->status);
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
        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->status);
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
        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->status);
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
            Subscription::STATUS_ACTIVE,
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
            Subscription::STATUS_ACTIVE,
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
        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->status);
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

        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->status);
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
        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->status);
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
        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->status);
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

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
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
        $this->assertSame(Subscription::STATUS_PAST_DUE, $subscription->status);
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
}
