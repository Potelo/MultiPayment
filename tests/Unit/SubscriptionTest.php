<?php

namespace Potelo\MultiPayment\Tests\Unit;

use Mockery;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Potelo\MultiPayment\Models\Plan;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\MultiPayment;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\InvoiceItem;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Models\SubscriptionItem;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Contracts\SubscriptionContract;
use Potelo\MultiPayment\Models\SubscriptionDiscount;
use Potelo\MultiPayment\Builders\SubscriptionBuilder;
use Potelo\MultiPayment\Models\SubscriptionPlanChange;
use Potelo\MultiPayment\Enums\ProrationBehavior;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\ConfigurationException;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Enums\PlanInterval;
use Potelo\MultiPayment\Enums\SubscriptionStatus;

class SubscriptionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * Gateway falso que declara assinaturas e implementa `SubscriptionContract`.
     */
    private static function subscriptionGateway(): GatewayContract
    {
        $gateway = Mockery::mock(GatewayContract::class, SubscriptionContract::class);
        $gateway->shouldReceive('supports')->with(Capability::SUBSCRIPTIONS)->andReturn(true);

        return $gateway;
    }

    /**
     * Gateway falso que não declara capability alguma.
     */
    private static function gatewayWithoutCapabilities(): GatewayContract
    {
        $gateway = Mockery::mock(GatewayContract::class);
        $gateway->shouldReceive('supports')->andReturn(false);
        $gateway->shouldReceive('notYetImplemented')->andReturn([]);
        $gateway->shouldReceive('__toString')->andReturn('falso');

        return $gateway;
    }

    public function testFillBuildsNestedModelsAndParsesDates(): void
    {
        $subscription = new Subscription();
        $subscription->fill([
            'id' => 'sub_1',
            'plan_id' => 'plano_mensal',
            'customer' => ['name' => 'Fulano', 'email' => 'fulano@exemplo.com'],
            'items' => [['description' => 'Consultas', 'amount' => 1000, 'quantity' => 2]],
            'discounts' => [['description' => 'Promo', 'amount_off' => 500, 'cycles' => 1]],
            'next_billing_at' => '2026-10-01',
            'trial_ends_at' => '2026-09-15',
            'metadata' => ['origem' => 'teste'],
        ]);

        $this->assertSame('plano_mensal', $subscription->planId);
        $this->assertInstanceOf(Customer::class, $subscription->customer);
        $this->assertSame('fulano@exemplo.com', $subscription->customer->email);
        $this->assertInstanceOf(SubscriptionItem::class, $subscription->items[0]);
        $this->assertSame(1000, $subscription->items[0]->amount);
        $this->assertTrue($subscription->items[0]->recurring);
        $this->assertInstanceOf(SubscriptionDiscount::class, $subscription->discounts[0]);
        $this->assertSame(500, $subscription->discounts[0]->amountOff);
        $this->assertInstanceOf(Carbon::class, $subscription->nextBillingAt);
        $this->assertSame('2026-10-01', $subscription->nextBillingAt->format('Y-m-d'));
        $this->assertSame('2026-09-15', $subscription->trialEndsAt->format('Y-m-d'));
        $this->assertSame(['origem' => 'teste'], $subscription->metadata);
    }

    public function testFillKeepsInstancesAlreadyBuilt(): void
    {
        $item = new SubscriptionItem();
        $item->description = 'Consultas';
        $item->amount = 100;

        $subscription = new Subscription();
        $subscription->fill(['items' => [$item]]);

        $this->assertSame($item, $subscription->items[0]);
    }

    public function testFillWithoutItemsDoesNotClearTheExistingOnes(): void
    {
        $subscription = new Subscription();
        $subscription->items = [new SubscriptionItem()];

        $subscription->fill(['status' => SubscriptionStatus::ACTIVE]);

        $this->assertCount(1, $subscription->items);
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
    }

    public function testToArrayFlattensItemsDiscountsAndCustomer(): void
    {
        $subscription = new Subscription();
        $subscription->fill([
            'customer' => ['name' => 'Fulano'],
            'items' => [['description' => 'Consultas', 'amount' => 1000]],
            'discounts' => [['description' => 'Promo', 'percent_off' => 10.5]],
        ]);

        $array = $subscription->toArray();

        $this->assertSame('Consultas', $array['items'][0]['description']);
        $this->assertSame(10.5, $array['discounts'][0]['percent_off']);
        $this->assertSame('Fulano', $array['customer']['name']);
    }

    public function testSubscriptionRequiresCustomerAndPlan(): void
    {
        $subscription = new Subscription();
        $subscription->planId = 'plano';

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/`customer` attribute is required/');

        $subscription->validate();
    }

    public function testSubscriptionRequiresPlanId(): void
    {
        $subscription = new Subscription();
        $subscription->fill(['customer' => ['name' => 'Fulano']]);

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/`planId` attribute is required/');

        $subscription->validate();
    }

    public function testSubscriptionRejectsUnknownPaymentMethodOnWrite(): void
    {
        $subscription = new Subscription();
        $subscription->fill(['customer' => ['name' => 'Fulano'], 'plan_id' => 'plano']);

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/availablePaymentMethods must be one of/');

        $subscription->availablePaymentMethods = ['bitcoin'];
    }

    public function testSubscriptionValidationRejectsNonSelectablePaymentMethod(): void
    {
        $subscription = new Subscription();
        $subscription->fill(['customer' => ['name' => 'Fulano'], 'plan_id' => 'plano']);
        $subscription->availablePaymentMethods = [PaymentMethod::AUTOMATIC_PIX];

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/availablePaymentMethods must be one of/');

        $subscription->validate();
    }

    public function testSubscriptionPropagatesItemValidation(): void
    {
        $subscription = new Subscription();
        $subscription->fill([
            'customer' => ['name' => 'Fulano'],
            'plan_id' => 'plano',
            'items' => [['description' => 'Desconto', 'amount' => -100]],
        ]);

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/must not be negative/');

        $subscription->validate();
    }

    public function testSubscriptionRejectsItemsOfTheWrongType(): void
    {
        $subscription = new Subscription();
        $subscription->fill(['customer' => ['name' => 'Fulano'], 'plan_id' => 'plano']);
        $subscription->items = ['não é um item'];

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/array of SubscriptionItem/');

        $subscription->validate();
    }

    public function testValidSubscriptionPassesValidation(): void
    {
        $subscription = new Subscription();
        $subscription->fill([
            'customer' => ['name' => 'Fulano'],
            'plan_id' => 'plano',
            'items' => [['description' => 'Consultas', 'amount' => 1000, 'quantity' => 1]],
            'discounts' => [['description' => 'Promo', 'amount_off' => 500]],
            'available_payment_methods' => [PaymentMethod::CREDIT_CARD, PaymentMethod::PIX],
        ]);

        $subscription->validate();

        $this->assertSame('plano', $subscription->planId);
    }

    public function testItemRejectsNegativeAmount(): void
    {
        $item = new SubscriptionItem();
        $item->description = 'Desconto';
        $item->amount = -100;

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/Use SubscriptionDiscount/');

        $item->validate();
    }

    public function testItemRequiresDescriptionAndAmount(): void
    {
        $item = new SubscriptionItem();
        $item->amount = 100;

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/`description` attribute is required/');

        $item->validate();
    }

    /**
     * Zero é vazio para o validate() do Model, então a regra de quantity mínima não pode
     * depender de validateQuantityAttribute().
     */
    public function testItemRejectsZeroQuantity(): void
    {
        $item = new SubscriptionItem();
        $item->description = 'Consultas';
        $item->amount = 100;
        $item->quantity = 0;

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/quantity must be at least 1/');

        $item->validate();
    }

    public function testItemAcceptsZeroAmount(): void
    {
        $item = new SubscriptionItem();
        $item->description = 'Cortesia';
        $item->amount = 0;
        $item->quantity = 1;

        $item->validate();

        $this->assertSame(0, $item->amount);
    }

    public function testDiscountRequiresOneOfAmountOffOrPercentOff(): void
    {
        $discount = new SubscriptionDiscount();
        $discount->description = 'Promo';

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/`amountOff or percentOff` attribute is required/');

        $discount->validate();
    }

    public function testDiscountRejectsAmountOffAndPercentOffTogether(): void
    {
        $discount = new SubscriptionDiscount();
        $discount->description = 'Promo';
        $discount->amountOff = 500;
        $discount->percentOff = 10;

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/mutually exclusive/');

        $discount->validate();
    }

    public function testDiscountRejectsNegativeAmountOff(): void
    {
        $discount = new SubscriptionDiscount();
        $discount->description = 'Promo';
        $discount->amountOff = -500;

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/positive amount in cents/');

        $discount->validate();
    }

    public function testDiscountRejectsPercentOffAboveOneHundred(): void
    {
        $discount = new SubscriptionDiscount();
        $discount->description = 'Promo';
        $discount->percentOff = 101;

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/at most 100/');

        $discount->validate();
    }

    public function testDiscountRejectsZeroCycles(): void
    {
        $discount = new SubscriptionDiscount();
        $discount->description = 'Promo';
        $discount->amountOff = 500;
        $discount->cycles = 0;

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/cycles must be null or at least 1/');

        $discount->validate();
    }

    public function testDiscountRejectsCyclesAndValidUntilTogether(): void
    {
        $discount = new SubscriptionDiscount();
        $discount->description = 'Promo';
        $discount->amountOff = 500;
        $discount->cycles = 3;
        $discount->validUntil = Carbon::parse('2026-12-31');

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/cycles and validUntil are mutually exclusive/');

        $discount->validate();
    }

    public function testBuilderDiscountsAcceptAValidUntilDate(): void
    {
        $gateway = Mockery::mock(GatewayContract::class);

        $subscription = (new SubscriptionBuilder($gateway))
            ->addAmountDiscount('Promo', 300, null, Carbon::parse('2026-12-31'))
            ->get();

        $this->assertSame('2026-12-31', $subscription->discounts[0]->validUntil->format('Y-m-d'));
        $this->assertNull($subscription->discounts[0]->cycles);
    }

    public function testDiscountFillParsesValidUntil(): void
    {
        $discount = new SubscriptionDiscount();
        $discount->fill(['description' => 'Promo', 'amount_off' => 500, 'valid_until' => '2026-12-31']);

        $this->assertSame('2026-12-31', $discount->validUntil->format('Y-m-d'));

        $carbon = new SubscriptionDiscount();
        $carbon->fill(['valid_until' => Carbon::parse('2026-12-31 10:00:00')]);

        $this->assertSame('2026-12-31 10:00:00', $carbon->validUntil->format('Y-m-d H:i:s'));

        $empty = new SubscriptionDiscount();
        $empty->fill(['description' => 'Promo', 'valid_until' => '']);

        $this->assertNull($empty->validUntil);
    }

    public function testPlanRejectsUnknownIntervalOnWrite(): void
    {
        $plan = new Plan();
        $plan->name = 'Mensal';
        $plan->amount = 10000;

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/interval must be one of: day, week, month, year/');

        $plan->interval = 'quinzena';
    }

    public function testPlanRequiresNameAmountAndInterval(): void
    {
        $plan = new Plan();
        $plan->amount = 10000;
        $plan->interval = PlanInterval::MONTH;

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/`name` attribute is required/');

        $plan->validate();
    }

    public function testValidPlanPassesValidation(): void
    {
        $plan = new Plan();
        $plan->name = 'Mensal';
        $plan->amount = 10000;
        $plan->interval = PlanInterval::MONTH;
        $plan->intervalCount = 1;

        $plan->validate();

        $this->assertSame(PlanInterval::MONTH, $plan->interval);
    }

    /**
     * As linhas de uma simulação são linhas de fatura: crédito de período não usado vem com
     * valor negativo.
     */
    public function testPlanChangeParsesInvoiceLinesAndDate(): void
    {
        $planChange = new SubscriptionPlanChange();
        $planChange->fill([
            'amount' => 50000,
            'items' => [['description' => 'Tempo não utilizado', 'price' => -10000, 'quantity' => 1]],
            'effective_at' => '2026-10-01',
            'applies_immediately' => true,
        ]);

        $this->assertInstanceOf(InvoiceItem::class, $planChange->items[0]);
        $this->assertSame(-10000, $planChange->items[0]->price);
        $this->assertSame('2026-10-01', $planChange->effectiveAt->format('Y-m-d'));
        $this->assertTrue($planChange->appliesImmediately);
    }

    /**
     * Uma prévia recém-criada já tem lista de linhas (vazia) e não assume que o plano vale na
     * hora.
     */
    public function testPlanChangeStartsWithAnEmptyListOfLines(): void
    {
        $planChange = new SubscriptionPlanChange();

        $this->assertSame([], $planChange->items);
        $this->assertFalse($planChange->appliesImmediately);
    }

    /**
     * `items` e `applies_immediately` nulos no `fill()` mantêm o valor atual em vez de lançar
     * `TypeError`.
     */
    public function testPlanChangeFillIgnoresNullLinesAndFlag(): void
    {
        $planChange = new SubscriptionPlanChange();
        $planChange->fill(['amount' => 100, 'items' => null, 'applies_immediately' => null]);

        $this->assertSame(100, $planChange->amount);
        $this->assertSame([], $planChange->items);
        $this->assertFalse($planChange->appliesImmediately);
    }

    public function testBuilderAssemblesTheSubscription(): void
    {
        $gateway = Mockery::mock(GatewayContract::class);

        $subscription = (new SubscriptionBuilder($gateway))
            ->setPlanId('plano_mensal')
            ->setCustomerId('cus_1')
            ->addItem('Consultas', 1000, 2)
            ->addItem('Setup', 500, 1, false)
            ->addAmountDiscount('Promo', 300, 1)
            ->addPercentDiscount('Anual', 10.0)
            ->setNextBillingAt('2026-10-01')
            ->setMetadata(['origem' => 'teste'])
            ->get();

        $this->assertSame('plano_mensal', $subscription->planId);
        $this->assertSame('cus_1', $subscription->customer->id);
        $this->assertCount(2, $subscription->items);
        $this->assertTrue($subscription->items[0]->recurring);
        $this->assertFalse($subscription->items[1]->recurring);
        $this->assertCount(2, $subscription->discounts);
        $this->assertSame(300, $subscription->discounts[0]->amountOff);
        $this->assertSame(1, $subscription->discounts[0]->cycles);
        $this->assertSame(10.0, $subscription->discounts[1]->percentOff);
        $this->assertNull($subscription->discounts[1]->cycles);
        $this->assertSame('2026-10-01', $subscription->nextBillingAt->format('Y-m-d'));
        $this->assertSame(['origem' => 'teste'], $subscription->metadata);
    }

    public function testBuilderCreateDelegatesToTheGateway(): void
    {
        $gateway = self::subscriptionGateway();
        $gateway->shouldReceive('createSubscription')
            ->once()
            ->andReturnUsing(function (Subscription $subscription) {
                $subscription->id = 'sub_criada';

                return $subscription;
            });

        $subscription = (new SubscriptionBuilder($gateway))
            ->setPlanId('plano_mensal')
            ->setCustomerId('cus_1')
            ->create();

        $this->assertSame('sub_criada', $subscription->id);
    }

    public function testFillAndToArrayHandleTheLatestInvoice(): void
    {
        $subscription = new Subscription();
        $subscription->fill([
            'id' => 'sub_1',
            'latest_invoice' => ['id' => 'inv_1', 'status' => InvoiceStatus::PENDING],
        ]);

        $this->assertInstanceOf(Invoice::class, $subscription->latestInvoice);
        $this->assertSame('inv_1', $subscription->latestInvoice->id);
        $this->assertSame('inv_1', $subscription->toArray()['latest_invoice']['id']);
    }

    #[DataProvider('lifecycleProvider')]
    public function testModelDelegatesLifecycleToTheGateway(
        string $gatewayMethod,
        array $gatewayArgs,
        string $modelMethod,
        array $modelArgs
    ): void {
        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $gateway = self::subscriptionGateway();
        $gateway->shouldReceive($gatewayMethod)
            ->once()
            // o último argumento do contract é a chave de idempotência, nula por padrão
            ->with($subscription, ...array_merge($gatewayArgs, [null]))
            ->andReturn($subscription);

        $this->assertSame(
            $subscription,
            $subscription->{$modelMethod}(...array_merge($modelArgs, [$gateway]))
        );
    }

    public static function lifecycleProvider(): array
    {
        return [
            'suspend' => ['suspendSubscription', [], 'suspend', []],
            'resume' => ['resumeSubscription', [], 'resume', []],
            'cancel imediato' => ['cancelSubscription', [false], 'cancel', [false]],
            'cancel ao fim do periodo' => ['cancelSubscription', [true], 'cancel', [true]],
            'changePlan cobrando' => ['changeSubscriptionPlan', ['plano_anual', ProrationBehavior::CHARGE_DIFFERENCE], 'changePlan', ['plano_anual', ProrationBehavior::CHARGE_DIFFERENCE]],
            'changePlan sem cobrar' => ['changeSubscriptionPlan', ['plano_anual', ProrationBehavior::NONE], 'changePlan', ['plano_anual', ProrationBehavior::NONE]],
            'changePlan com crédito' => ['changeSubscriptionPlan', ['plano_anual', ProrationBehavior::CREDIT], 'changePlan', ['plano_anual', ProrationBehavior::CREDIT]],
        ];
    }

    /**
     * O booleano antigo de `changePlan()`, posicional ou pelo nome `charge`, chega ao gateway
     * traduzido para o enum, com aviso de obsolescência.
     */
    #[DataProvider('deprecatedChargeProvider')]
    #[IgnoreDeprecations]
    public function testChangePlanTranslatesTheDeprecatedBoolean(callable $call, ProrationBehavior $expected): void
    {
        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $gateway = self::subscriptionGateway();
        $gateway->shouldReceive('changeSubscriptionPlan')
            ->once()
            ->with($subscription, 'plano_anual', $expected, null)
            ->andReturn($subscription);

        $this->expectUserDeprecationMessage(
            'O booleano $charge de changePlan() está obsoleto desde 2026-09-02; passe'
            . ' ProrationBehavior::CHARGE_DIFFERENCE ou ProrationBehavior::NONE'
        );

        $this->assertSame($subscription, $call($subscription, $gateway));
    }

    public static function deprecatedChargeProvider(): array
    {
        return [
            'true posicional' => [
                fn (Subscription $s, $g) => $s->changePlan('plano_anual', true, $g),
                ProrationBehavior::CHARGE_DIFFERENCE,
            ],
            'false posicional' => [
                fn (Subscription $s, $g) => $s->changePlan('plano_anual', false, $g),
                ProrationBehavior::NONE,
            ],
            'charge nomeado' => [
                fn (Subscription $s, $g) => $s->changePlan('plano_anual', charge: false, gateway: $g),
                ProrationBehavior::NONE,
            ],
            'charge nomeado prevalece sobre o enum' => [
                fn (Subscription $s, $g) => $s->changePlan('plano_anual', ProrationBehavior::NONE, $g, charge: true),
                ProrationBehavior::CHARGE_DIFFERENCE,
            ],
        ];
    }

    public function testPreviewPlanChangeDelegatesToTheGateway(): void
    {
        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $planChange = new SubscriptionPlanChange();

        $gateway = self::subscriptionGateway();
        $gateway->shouldReceive('previewSubscriptionPlanChange')
            ->once()
            ->with($subscription, 'plano_anual')
            ->andReturn($planChange);

        $this->assertSame($planChange, $subscription->previewPlanChange('plano_anual', $gateway));
    }

    public function testCreateSavesTheCustomerBeforeTheSubscription(): void
    {
        $ordem = [];

        $gateway = self::subscriptionGateway();
        $gateway->shouldReceive('createCustomer')
            ->once()
            ->andReturnUsing(function (Customer $customer) use (&$ordem) {
                $ordem[] = 'customer';
                $customer->id = 'cus_novo';

                return $customer;
            });
        $gateway->shouldReceive('createSubscription')
            ->once()
            ->andReturnUsing(function (Subscription $subscription) use (&$ordem) {
                $ordem[] = 'subscription';
                $subscription->id = 'sub_1';

                return $subscription;
            });

        $customer = new Customer();
        $customer->name = 'Fulano';
        $customer->email = 'fulano@exemplo.com';

        $subscription = (new SubscriptionBuilder($gateway))
            ->setPlanId('plano_mensal')
            ->setCustomer($customer)
            ->create();

        $this->assertSame(['customer', 'subscription'], $ordem);
        $this->assertSame('cus_novo', $subscription->customer->id);
    }

    public function testSetItemsAndSetDiscountsReplaceInsteadOfAppending(): void
    {
        $gateway = Mockery::mock(GatewayContract::class);

        $item = new SubscriptionItem();
        $item->description = 'Único';
        $item->amount = 100;

        $subscription = (new SubscriptionBuilder($gateway))
            ->addItem('Descartado', 999, 1)
            ->setItems([$item])
            ->addAmountDiscount('Descartado', 999)
            ->setDiscounts([])
            ->setAvailablePaymentMethods([PaymentMethod::PIX])
            ->setTrialEndsAt('2026-09-15')
            ->get();

        $this->assertSame([$item], $subscription->items);
        $this->assertSame([], $subscription->discounts);
        $this->assertSame([PaymentMethod::PIX], $subscription->availablePaymentMethods);
        $this->assertSame('2026-09-15', $subscription->trialEndsAt->format('Y-m-d'));
    }

    /**
     * Com id preenchido o save() faz update, que aceita atributo parcial.
     */
    public function testUpdateDoesNotRequireCustomerOrPlanId(): void
    {
        $gateway = self::subscriptionGateway();
        $gateway->shouldReceive('updateSubscription')->once()->andReturnUsing(fn($s) => $s);
        $gateway->shouldNotReceive('createCustomer');

        $subscription = new Subscription();
        $subscription->fill([
            'id' => 'sub_1',
            'items' => [['description' => 'Consultas', 'amount' => 100, 'quantity' => 1]],
        ]);

        $subscription->save($gateway);

        $this->assertSame('sub_1', $subscription->id);
    }

    /**
     * Método de domínio recusa gateway sem a capability de assinaturas com
     * `UnsupportedOperationException`, sem chegar ao contract.
     */
    public function testDomainMethodsRejectAGatewayWithoutTheSubscriptionsCapability(): void
    {
        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        try {
            $subscription->suspend(self::gatewayWithoutCapabilities());
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::SUBSCRIPTIONS, $e->capability);
            $this->assertSame('falso', $e->gateway);
            $this->assertSame(UnsupportedOperationException::REASON_GATEWAY_LIMITATION, $e->reason);
        }
    }

    /**
     * Gateway que declara a capability sem implementar o contract é erro de driver e chega como
     * `ConfigurationException`, sem `httpStatus`.
     */
    public function testGatewayDeclaringTheCapabilityWithoutTheContractIsADriverError(): void
    {
        $gateway = Mockery::mock(GatewayContract::class);
        $gateway->shouldReceive('supports')->with(Capability::SUBSCRIPTIONS)->andReturn(true);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/declares the subscriptions capability but does not implement SubscriptionContract/');

        $subscription->suspend($gateway);
    }

    /**
     * O update dispensa cliente e plano, mas não as demais validações: item de valor negativo
     * seria relido como desconto.
     */
    public function testUpdateStillValidatesItemsAndPaymentMethods(): void
    {
        $gateway = self::subscriptionGateway();
        $gateway->shouldNotReceive('updateSubscription');

        $subscription = new Subscription();
        $subscription->fill([
            'id' => 'sub_1',
            'items' => [['description' => 'X', 'amount' => -500, 'quantity' => 1]],
        ]);

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/must not be negative/');

        $subscription->save($gateway);
    }

    public function testUpdateStillValidatesAvailablePaymentMethods(): void
    {
        $gateway = self::subscriptionGateway();
        $gateway->shouldNotReceive('updateSubscription');

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription->availablePaymentMethods = [PaymentMethod::AUTOMATIC_PIX];

        $this->expectException(ModelAttributeValidationException::class);

        $subscription->save($gateway);
    }

    /**
     * Gateway que declara a capability mas não tem o método do despacho por convenção é erro
     * de driver e chega como `ConfigurationException`, sem requisição.
     */
    public function testGatewayWithoutTheDispatchMethodIsAConfigurationError(): void
    {
        $gateway = Mockery::mock(GatewayContract::class);
        $gateway->shouldReceive('supports')->with(Capability::PLANS)->andReturn(true);

        $plan = new Plan();
        $plan->name = 'Mensal';
        $plan->amount = 10000;
        $plan->interval = PlanInterval::MONTH;

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/does not have method \[createPlan\]/');

        $plan->save($gateway);
    }

    public function testTheFacadeRejectsAGatewayDeclaringTheCapabilityWithoutTheContractAsAConfigurationError(): void
    {
        $gateway = Mockery::mock(GatewayContract::class);
        $gateway->shouldReceive('supports')->with(Capability::PLANS)->andReturn(true);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/declares the plans capability but does not implement PlanContract/');

        (new MultiPayment($gateway))->listPlans();
    }

    public function testPlanWithIdCannotBeSavedAgain(): void
    {
        $plan = new Plan();
        $plan->id = 'plan_1';
        $plan->name = 'Mensal';
        $plan->amount = 10000;
        $plan->interval = PlanInterval::MONTH;

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/cannot be updated/');

        $plan->save(Mockery::mock(GatewayContract::class));
    }

    #[DataProvider('listOperationsProvider')]
    public function testListOperationsRejectAGatewayWithoutTheCapability(string $metodo, array $args, Capability $capability): void
    {
        $multiPayment = new \Potelo\MultiPayment\MultiPayment(self::gatewayWithoutCapabilities());

        try {
            $multiPayment->{$metodo}(...$args);
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame($capability, $e->capability);
            $this->assertSame(UnsupportedOperationException::REASON_GATEWAY_LIMITATION, $e->reason);
        }
    }

    public static function listOperationsProvider(): array
    {
        return [
            'assinaturas' => ['listSubscriptions', ['cus_1'], Capability::SUBSCRIPTIONS],
            'planos' => ['listPlans', [], Capability::PLANS],
        ];
    }

    public function testBuilderSetsTheCardByIdAndImpliesTheCardPaymentMethod(): void
    {
        $subscription = (new SubscriptionBuilder(Mockery::mock(GatewayContract::class)))
            ->setPlanId('plano_mensal')
            ->setCustomerId('cus_1')
            ->setCreditCard('pm_1')
            ->setTrialDays(7)
            ->get();

        $this->assertInstanceOf(CreditCard::class, $subscription->creditCard);
        $this->assertSame('pm_1', $subscription->creditCard->id);
        $this->assertSame(PaymentMethod::CREDIT_CARD, $subscription->paymentMethod);
        $this->assertSame(7, $subscription->trialDays);
        $this->assertNull($subscription->trialEndsAt);
    }

    public function testBuilderSetsTheCardByModelAndThePaymentMethodByString(): void
    {
        $card = new CreditCard();
        $card->token = 'tok_1';

        $subscription = (new SubscriptionBuilder(Mockery::mock(GatewayContract::class)))
            ->setCreditCard($card)
            ->get();
        $this->assertSame($card, $subscription->creditCard);

        $pix = (new SubscriptionBuilder(Mockery::mock(GatewayContract::class)))
            ->setPaymentMethod('pix')
            ->get();
        $this->assertSame(PaymentMethod::PIX, $pix->paymentMethod);
        $this->assertNull($pix->creditCard);
    }

    public function testResolvedPaymentMethodFallsBackToTheCard(): void
    {
        $subscription = new Subscription();
        $this->assertNull($subscription->resolvedPaymentMethod());

        $subscription->creditCard = new CreditCard();
        $this->assertSame(PaymentMethod::CREDIT_CARD, $subscription->resolvedPaymentMethod());

        $subscription->paymentMethod = PaymentMethod::CREDIT_CARD;
        $this->assertSame(PaymentMethod::CREDIT_CARD, $subscription->resolvedPaymentMethod());
    }

    public function testFillAndToArrayHandleTheCreditCard(): void
    {
        $subscription = new Subscription();
        $subscription->fill(['credit_card' => ['id' => 'pm_1'], 'trial_days' => 3]);

        $this->assertSame('pm_1', $subscription->creditCard->id);
        $this->assertSame(3, $subscription->trialDays);
        $this->assertSame('pm_1', $subscription->toArray()['credit_card']['id']);
        $this->assertSame(3, $subscription->toArray()['trial_days']);
    }

    #[DataProvider('invalidSubscriptionProvider')]
    public function testValidationRejectsConflictingPaymentAndTrialAttributes(callable $mutate, string $message): void
    {
        $subscription = new Subscription();
        $subscription->fill(['customer' => ['name' => 'Fulano'], 'plan_id' => 'plano']);
        $mutate($subscription);

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches($message);

        $subscription->validate();
    }

    public static function invalidSubscriptionProvider(): array
    {
        return [
            'trialDays zero' => [fn (Subscription $s) => $s->trialDays = 0, '/trialDays must be at least 1/'],
            'trialDays negativo' => [fn (Subscription $s) => $s->trialDays = -1, '/trialDays must be at least 1/'],
            'trialDays com trialEndsAt' => [
                function (Subscription $s) {
                    $s->trialDays = 7;
                    $s->trialEndsAt = Carbon::parse('2026-10-01');
                },
                '/trialDays and trialEndsAt are mutually exclusive/',
            ],
            'cartao com metodo pix' => [
                function (Subscription $s) {
                    $s->creditCard = new CreditCard();
                    $s->creditCard->id = 'pm_1';
                    $s->paymentMethod = PaymentMethod::PIX;
                },
                '/creditCard was given but credit_card is not among the payment methods/',
            ],
            'cartao com lista sem cartao' => [
                function (Subscription $s) {
                    $s->creditCard = new CreditCard();
                    $s->creditCard->id = 'pm_1';
                    $s->availablePaymentMethods = [PaymentMethod::PIX];
                },
                '/creditCard was given but credit_card is not among the payment methods/',
            ],
            'metodo fora da lista' => [
                function (Subscription $s) {
                    $s->paymentMethod = PaymentMethod::CREDIT_CARD;
                    $s->availablePaymentMethods = [PaymentMethod::PIX];
                },
                '/paymentMethod \[credit_card\] must be one of availablePaymentMethods/',
            ],
            'metodo nao selecionavel' => [
                fn (Subscription $s) => $s->paymentMethod = PaymentMethod::AUTOMATIC_PIX,
                '/paymentMethod must be one of: credit_card, bank_slip, pix/',
            ],
            'cartao invalido' => [
                function (Subscription $s) {
                    $s->creditCard = new CreditCard();
                    $s->creditCard->number = '123';
                },
                '/CreditCard number/',
            ],
        ];
    }

    public function testRequiredCapabilitiesDeriveFromThePaymentMethodAndTheCard(): void
    {
        $subscription = new Subscription();
        $this->assertSame([Capability::SUBSCRIPTIONS], $subscription->requiredCapabilities());

        $subscription->paymentMethod = PaymentMethod::PIX;
        $this->assertSame([Capability::SUBSCRIPTIONS, Capability::PIX], $subscription->requiredCapabilities());

        $rawCard = new Subscription();
        $rawCard->creditCard = new CreditCard();
        $rawCard->creditCard->number = '4111111111111111';
        $this->assertSame(
            [Capability::SUBSCRIPTIONS, Capability::CREDIT_CARD, Capability::RAW_CARD_DATA],
            $rawCard->requiredCapabilities()
        );

        $tokenCard = new Subscription();
        $tokenCard->creditCard = new CreditCard();
        $tokenCard->creditCard->token = 'tok_1';
        $this->assertSame([Capability::SUBSCRIPTIONS, Capability::CREDIT_CARD], $tokenCard->requiredCapabilities());

        // a lista tem precedência sobre o método, e mais de um método exige MULTIPLE_PAYMENT_METHODS
        $list = new Subscription();
        $list->paymentMethod = PaymentMethod::PIX;
        $list->availablePaymentMethods = [PaymentMethod::PIX, PaymentMethod::BANK_SLIP];
        $this->assertSame(
            [Capability::SUBSCRIPTIONS, Capability::PIX, Capability::BANK_SLIP, Capability::MULTIPLE_PAYMENT_METHODS],
            $list->requiredCapabilities()
        );
        $this->assertSame([PaymentMethod::PIX, PaymentMethod::BANK_SLIP], $list->resolvedPaymentMethods());
    }
}
