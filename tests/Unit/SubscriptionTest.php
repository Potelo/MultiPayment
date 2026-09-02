<?php

namespace Potelo\MultiPayment\Tests\Unit;

use Mockery;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Potelo\MultiPayment\Models\Plan;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\InvoiceItem;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Models\SubscriptionItem;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Contracts\SubscriptionContract;
use Potelo\MultiPayment\Models\SubscriptionDiscount;
use Potelo\MultiPayment\Builders\SubscriptionBuilder;
use Potelo\MultiPayment\Models\SubscriptionPlanChange;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

class SubscriptionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
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

        $subscription->fill(['status' => Subscription::STATUS_ACTIVE]);

        $this->assertCount(1, $subscription->items);
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

    public function testSubscriptionRejectsUnknownPaymentMethod(): void
    {
        $subscription = new Subscription();
        $subscription->fill(['customer' => ['name' => 'Fulano'], 'plan_id' => 'plano']);
        $subscription->availablePaymentMethods = ['bitcoin'];

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
            'available_payment_methods' => [Invoice::PAYMENT_METHOD_CREDIT_CARD, Invoice::PAYMENT_METHOD_PIX],
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

    public function testPlanRejectsUnknownInterval(): void
    {
        $plan = new Plan();
        $plan->name = 'Mensal';
        $plan->amount = 10000;
        $plan->interval = 'day';

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/interval must be one of/');

        $plan->validate();
    }

    public function testPlanRequiresNameAmountAndInterval(): void
    {
        $plan = new Plan();
        $plan->amount = 10000;
        $plan->interval = Plan::INTERVAL_MONTH;

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/`name` attribute is required/');

        $plan->validate();
    }

    public function testValidPlanPassesValidation(): void
    {
        $plan = new Plan();
        $plan->name = 'Mensal';
        $plan->amount = 10000;
        $plan->interval = Plan::INTERVAL_MONTH;
        $plan->intervalCount = 1;

        $plan->validate();

        $this->assertSame(Plan::INTERVAL_MONTH, $plan->interval);
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
        ]);

        $this->assertInstanceOf(InvoiceItem::class, $planChange->items[0]);
        $this->assertSame(-10000, $planChange->items[0]->price);
        $this->assertSame('2026-10-01', $planChange->effectiveAt->format('Y-m-d'));
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
        $gateway = Mockery::mock(GatewayContract::class, SubscriptionContract::class);
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
            'latest_invoice' => ['id' => 'inv_1', 'status' => Invoice::STATUS_PENDING],
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

        $gateway = Mockery::mock(GatewayContract::class, SubscriptionContract::class);
        $gateway->shouldReceive($gatewayMethod)
            ->once()
            ->with($subscription, ...$gatewayArgs)
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
            'changePlan cobrando' => ['changeSubscriptionPlan', ['plano_anual', true], 'changePlan', ['plano_anual', true]],
            'changePlan sem cobrar' => ['changeSubscriptionPlan', ['plano_anual', false], 'changePlan', ['plano_anual', false]],
        ];
    }

    public function testPreviewPlanChangeDelegatesToTheGateway(): void
    {
        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $planChange = new SubscriptionPlanChange();

        $gateway = Mockery::mock(GatewayContract::class, SubscriptionContract::class);
        $gateway->shouldReceive('previewSubscriptionPlanChange')
            ->once()
            ->with($subscription, 'plano_anual')
            ->andReturn($planChange);

        $this->assertSame($planChange, $subscription->previewPlanChange('plano_anual', $gateway));
    }

    public function testCreateSavesTheCustomerBeforeTheSubscription(): void
    {
        $ordem = [];

        $gateway = Mockery::mock(GatewayContract::class, SubscriptionContract::class);
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
            ->setAvailablePaymentMethods([Invoice::PAYMENT_METHOD_PIX])
            ->setTrialEndsAt('2026-09-15')
            ->get();

        $this->assertSame([$item], $subscription->items);
        $this->assertSame([], $subscription->discounts);
        $this->assertSame([Invoice::PAYMENT_METHOD_PIX], $subscription->availablePaymentMethods);
        $this->assertSame('2026-09-15', $subscription->trialEndsAt->format('Y-m-d'));
    }

    /**
     * Com id preenchido o save() faz update, que aceita atributo parcial.
     */
    public function testUpdateDoesNotRequireCustomerOrPlanId(): void
    {
        $gateway = Mockery::mock(GatewayContract::class, SubscriptionContract::class);
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
     * Método de domínio recusa gateway sem SubscriptionContract com GatewayException, e não com
     * Error do PHP.
     */
    public function testDomainMethodsRejectAGatewayWithoutTheSubscriptionContract(): void
    {
        $gateway = Mockery::mock(GatewayContract::class);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/does not implement SubscriptionContract; subscriptions are not yet implemented in this library/');

        $subscription->suspend($gateway);
    }

    /**
     * O update dispensa cliente e plano, mas não as demais validações: item de valor negativo
     * seria relido como desconto.
     */
    public function testUpdateStillValidatesItemsAndPaymentMethods(): void
    {
        $gateway = Mockery::mock(GatewayContract::class, SubscriptionContract::class);
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
        $gateway = Mockery::mock(GatewayContract::class, SubscriptionContract::class);
        $gateway->shouldNotReceive('updateSubscription');

        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription->availablePaymentMethods = ['bitcoin'];

        $this->expectException(ModelAttributeValidationException::class);

        $subscription->save($gateway);
    }

    public function testPlanWithIdCannotBeSavedAgain(): void
    {
        $plan = new Plan();
        $plan->id = 'plan_1';
        $plan->name = 'Mensal';
        $plan->amount = 10000;
        $plan->interval = Plan::INTERVAL_MONTH;

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/cannot be updated/');

        $plan->save(Mockery::mock(GatewayContract::class));
    }

    #[DataProvider('listOperationsProvider')]
    public function testListOperationsRejectAGatewayWithoutTheContract(string $metodo, array $args, string $contract): void
    {
        $multiPayment = new \Potelo\MultiPayment\MultiPayment(Mockery::mock(GatewayContract::class));

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches("/does not implement {$contract}; the operations of that contract are not yet implemented in this library/");

        $multiPayment->{$metodo}(...$args);
    }

    public static function listOperationsProvider(): array
    {
        return [
            'assinaturas' => ['listSubscriptions', ['cus_1'], 'SubscriptionContract'],
            'planos' => ['listPlans', [], 'PlanContract'],
        ];
    }
}
