<?php

namespace Potelo\MultiPayment\Tests\Unit;

use Mockery;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\MultiPayment;
use Potelo\MultiPayment\Models\Refund;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\AutomaticPix;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Models\AutomaticPixCharge;
use Potelo\MultiPayment\Models\AutomaticPixCancellation;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Contracts\SubscriptionContract;

/**
 * A chave de idempotência informada na fachada, nos builders e nos models chega ao driver como
 * último argumento da operação de escrita; o cliente criado junto com a fatura ou a assinatura
 * recebe a chave derivada `{chave}:customer`.
 *
 * As operações da fachada que gravam o gateway no model o resolvem de novo pelo nome, com `new`
 * na classe configurada, então o gateway falso é um `overload:` do Mockery e as chamadas são
 * gravadas numa lista compartilhada entre as instâncias que ele cria.
 */
class IdempotencyKeyPropagationTest extends TestCase
{
    private const OVERLOADED_GATEWAY = 'Potelo\\MultiPayment\\Tests\\Unit\\OverloadedPropagationGateway';

    /** @var array<int, array{0: string, 1: array}> método do driver e argumentos recebidos */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->calls = [];
        $app = new Container();
        $app->instance('config', new Repository(['multi-payment' => [
            'default' => 'falso',
            'gateways' => ['falso' => ['class' => self::OVERLOADED_GATEWAY]],
        ]]));
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testFacadeInvoiceOperationsPassTheKey(): void
    {
        $gateway = $this->gateway([
            'refundInvoice' => fn () => new Refund(),
            'cancelInvoice' => fn (Invoice $i) => $i,
            'chargeInvoiceWithCreditCard' => fn (Invoice $i) => $i,
            'duplicateInvoice' => fn () => new Invoice(),
            'rescheduleAutomaticPixPayment' => fn (Invoice $i) => $i,
        ]);
        $expiresAt = Carbon::parse('2026-10-01');

        $payment = new MultiPayment($gateway);
        $payment->refundInvoice('inv_1', 500, 'k-refund');
        $payment->cancelInvoice('inv_1', 'k-cancel');
        $payment->chargeInvoiceWithCreditCard('inv_1', 'tok_1', null, 'k-charge');
        $payment->duplicateInvoice('inv_1', $expiresAt, ['x' => 1], 'k-dup');
        $payment->rescheduleAutomaticPixPayment('inv_1', 'k-resched');

        $this->assertSame([
            'refundInvoice', 'cancelInvoice', 'chargeInvoiceWithCreditCard', 'duplicateInvoice', 'rescheduleAutomaticPixPayment',
        ], array_column($this->calls, 0));
        [$refund, $cancel, $charge, $duplicate, $reschedule] = array_column($this->calls, 1);
        $this->assertSame('inv_1', $refund[0]->id);
        $this->assertSame(500, $refund[1]);
        $this->assertSame('k-refund', $refund[2]);
        $this->assertNull($refund[0]->refundedAmount, 'o valor vai como argumento; refundedAmount é só de leitura');
        $this->assertSame(['inv_1', 'k-cancel'], [$cancel[0]->id, $cancel[1]]);
        $this->assertSame(['tok_1', 'k-charge'], [$charge[0]->creditCard->token, $charge[1]]);
        $this->assertSame($expiresAt, $duplicate[1]);
        $this->assertSame([['x' => 1], 'k-dup'], [$duplicate[2], $duplicate[3]]);
        $this->assertSame(['inv_1', 'k-resched'], [$reschedule[0]->id, $reschedule[1]]);
    }

    public function testFacadeCustomerCardAndAutomaticPixOperationsPassTheKey(): void
    {
        $gateway = $this->gateway([
            'deleteCreditCard' => fn () => null,
            'setCustomerDefaultCard' => fn (Customer $c) => $c,
            'cancelAutomaticPixRecurrence' => fn () => new AutomaticPixCancellation(),
            'cancelAutomaticPixScheduledPayment' => fn () => new AutomaticPixCancellation(),
        ]);

        $payment = new MultiPayment($gateway);
        $payment->deleteCard('cus_1', 'pm_1', 'k-delete');
        $payment->setDefaultCard('cus_1', 'pm_1', 'k-default');
        $payment->cancelAutomaticPixRecurrence('rec_1', 'k-rec');
        $payment->cancelAutomaticPixScheduledPayment('pay_1', 'E1', 'k-pay');

        [$delete, $default, $recurrence, $payment] = array_column($this->calls, 1);
        $this->assertSame(['pm_1', 'cus_1', 'k-delete'], [$delete[0]->id, $delete[0]->customer->id, $delete[1]]);
        $this->assertSame(['cus_1', 'pm_1', 'k-default'], [$default[0]->id, $default[1], $default[2]]);
        $this->assertInstanceOf(AutomaticPix::class, $recurrence[0]);
        $this->assertSame('k-rec', $recurrence[1]);
        $this->assertInstanceOf(AutomaticPixCharge::class, $payment[0]);
        $this->assertSame('k-pay', $payment[1]);
    }

    /**
     * `charge()` cria o cliente antes da fatura, com a chave derivada, e a fatura com a chave.
     */
    public function testChargeCreatesTheCustomerWithADerivedKeyAndTheInvoiceWithTheKey(): void
    {
        $gateway = $this->gateway([
            'createCustomer' => function (Customer $c) {
                $c->id = 'cus_novo';

                return $c;
            },
            'createInvoice' => fn (Invoice $i) => $i,
        ]);

        (new MultiPayment($gateway))->charge([
            'amount' => 10000,
            'available_payment_methods' => ['pix'],
            'customer' => ['name' => 'Cliente', 'email' => 'cliente@example.com', 'tax_document' => '20176996915'],
        ], 'k-charge');

        $this->assertSame(['createCustomer', 'createInvoice'], array_column($this->calls, 0));
        $this->assertSame('k-charge:customer', $this->calls[0][1][1]);
        $this->assertSame('cus_novo', $this->calls[1][1][0]->customer->id);
        $this->assertSame('k-charge', $this->calls[1][1][1]);
    }

    public function testChargeWithoutAKeyCreatesTheCustomerWithoutAKey(): void
    {
        $gateway = $this->gateway([
            'createCustomer' => function (Customer $c) {
                $c->id = 'cus_novo';

                return $c;
            },
            'createInvoice' => fn (Invoice $i) => $i,
        ]);

        (new MultiPayment($gateway))->charge([
            'amount' => 10000,
            'available_payment_methods' => ['pix'],
            'customer' => ['name' => 'Cliente', 'email' => 'cliente@example.com'],
        ]);

        $this->assertSame([null, null], array_map(fn (array $call) => $call[1][1], $this->calls));
    }

    public function testBuildersPassTheKeyGivenToWithIdempotencyKey(): void
    {
        $gateway = $this->gateway([
            'createInvoice' => fn (Invoice $i) => $i,
            'createCustomer' => fn (Customer $c) => $c,
            'createCreditCard' => fn (CreditCard $c) => $c,
        ]);
        $customer = new Customer();
        $customer->id = 'cus_1';
        $customer->name = 'Cliente';
        $customer->email = 'cliente@example.com';

        $payment = new MultiPayment($gateway);
        $payment->newInvoice()
            ->setCustomer($customer)
            ->addItem('Item', 10000, 1)
            ->setAvailablePaymentMethods(['pix'])
            ->withIdempotencyKey('k-invoice')
            ->create();
        $payment->newCustomer()
            ->setName('Cliente')
            ->setEmail('cliente@example.com')
            ->withIdempotencyKey('k-customer')
            ->create();
        $payment->newCreditCard()
            ->setCustomer($customer)
            ->setToken('tok_1')
            ->withIdempotencyKey('k-card')
            ->create();
        $payment->newCustomer()
            ->setName('Sem chave')
            ->setEmail('semchave@example.com')
            ->create();

        $this->assertSame([
            ['createInvoice', 'k-invoice'],
            ['createCustomer', 'k-customer'],
            ['createCreditCard', 'k-card'],
            ['createCustomer', null],
        ], array_map(fn (array $call) => [$call[0], $call[1][1]], $this->calls));
        $this->assertSame('tok_1', $this->calls[2][1][0]->token);
    }

    public function testModelSaveAndDeletePassTheKey(): void
    {
        $gateway = $this->gateway([
            'createCustomer' => fn (Customer $c) => $c,
            'updateCustomer' => fn (Customer $c) => $c,
            'deleteCreditCard' => fn () => null,
        ]);

        $customer = new Customer();
        $customer->name = 'Cliente';
        $customer->email = 'cliente@example.com';
        $customer->save($gateway, true, 'k-create');
        $customer->id = 'cus_1';
        $customer->save($gateway, true, 'k-update');

        $creditCard = new CreditCard();
        $creditCard->id = 'pm_1';
        $creditCard->delete($gateway, 'k-delete');

        $this->assertSame([
            ['createCustomer', 'k-create'],
            ['updateCustomer', 'k-update'],
            ['deleteCreditCard', 'k-delete'],
        ], array_map(fn (array $call) => [$call[0], $call[1][1]], $this->calls));
    }

    public function testInvoiceSaveCreatesTheCustomerWithADerivedKey(): void
    {
        $gateway = $this->gateway([
            'createCustomer' => function (Customer $c) {
                $c->id = 'cus_novo';

                return $c;
            },
            'createInvoice' => fn (Invoice $i) => $i,
        ]);

        $invoice = new Invoice();
        $invoice->fill([
            'amount' => 10000,
            'available_payment_methods' => ['pix'],
            'customer' => ['name' => 'Cliente', 'email' => 'cliente@example.com'],
        ]);
        $invoice->save($gateway, true, 'k-invoice');

        $this->assertSame([
            ['createCustomer', 'k-invoice:customer'],
            ['createInvoice', 'k-invoice'],
        ], array_map(fn (array $call) => [$call[0], $call[1][1]], $this->calls));
    }

    public function testModelCreateAndCustomerDeleteCreditCardPassTheKey(): void
    {
        $gateway = $this->gateway([
            'createCustomer' => fn (Customer $c) => $c,
            'deleteCreditCard' => fn () => null,
        ]);

        $customer = new Customer();
        $customer->create(['name' => 'Cliente', 'email' => 'cliente@example.com'], $gateway, 'k-create');
        $customer->id = 'cus_1';
        $customer->deleteCreditCard('pm_1', $gateway, 'k-delete');

        $this->assertSame([
            ['createCustomer', 'k-create'],
            ['deleteCreditCard', 'k-delete'],
        ], array_map(fn (array $call) => [$call[0], $call[1][1]], $this->calls));
        $this->assertSame('cus_1', $this->calls[1][1][0]->customer->id);
    }

    public function testSubscriptionSaveCreatesTheCustomerWithADerivedKeyAndPlanSavePassesTheKey(): void
    {
        $gateway = Mockery::mock(GatewayContract::class, SubscriptionContract::class, \Potelo\MultiPayment\Contracts\PlanContract::class);
        $gateway->shouldReceive('supports')->andReturn(true);
        $gateway->shouldReceive('__toString')->andReturn('falso');
        $gateway->shouldReceive('createCustomer')->once()
            ->with(Mockery::type(Customer::class), 'k-sub:customer')
            ->andReturnUsing(function (Customer $c) {
                $c->id = 'cus_novo';

                return $c;
            });
        $gateway->shouldReceive('createSubscription')->once()
            ->with(Mockery::type(Subscription::class), 'k-sub')
            ->andReturnUsing(fn (Subscription $s) => $s);
        $gateway->shouldReceive('createPlan')->once()
            ->with(Mockery::type(\Potelo\MultiPayment\Models\Plan::class), 'k-plan')
            ->andReturnUsing(fn ($p) => $p);

        $subscription = new Subscription();
        $subscription->fill(['plan_id' => 'plano_mensal', 'customer' => ['name' => 'Cliente', 'email' => 'cliente@example.com']]);
        $subscription->save($gateway, true, 'k-sub');
        $this->assertSame('cus_novo', $subscription->customer->id);

        $plan = new \Potelo\MultiPayment\Models\Plan();
        $plan->name = 'Mensal';
        $plan->amount = 10000;
        $plan->interval = \Potelo\MultiPayment\Enums\PlanInterval::MONTH;
        $plan->save($gateway, true, 'k-plan');
    }

    public function testSubscriptionDomainMethodsPassTheKey(): void
    {
        $gateway = Mockery::mock(GatewayContract::class, SubscriptionContract::class);
        $gateway->shouldReceive('supports')->andReturn(true);
        $gateway->shouldReceive('__toString')->andReturn('falso');
        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $gateway->shouldReceive('suspendSubscription')->once()->with($subscription, 'k-suspend')->andReturn($subscription);
        $gateway->shouldReceive('resumeSubscription')->once()->with($subscription, 'k-resume')->andReturn($subscription);
        $gateway->shouldReceive('cancelSubscription')->once()->with($subscription, true, 'k-cancel')->andReturn($subscription);
        $gateway->shouldReceive('changeSubscriptionPlan')->once()->with($subscription, 'plano_anual', \Potelo\MultiPayment\Enums\ProrationBehavior::NONE, 'k-change')->andReturn($subscription);
        $gateway->shouldReceive('updateSubscription')->once()->with($subscription, 'k-update')->andReturn($subscription);

        $this->assertSame($subscription, $subscription->suspend($gateway, 'k-suspend'));
        $this->assertSame($subscription, $subscription->resume($gateway, 'k-resume'));
        $this->assertSame($subscription, $subscription->cancel(true, $gateway, 'k-cancel'));
        $this->assertSame($subscription, $subscription->changePlan('plano_anual', \Potelo\MultiPayment\Enums\ProrationBehavior::NONE, $gateway, 'k-change'));
        $subscription->save($gateway, true, 'k-update');
        $this->assertSame('sub_1', $subscription->id);
    }

    /**
     * Gateway falso que grava cada chamada em `$this->calls` e responde com o retorno informado
     * por método.
     *
     * @param  array<string, callable>  $returns  método do driver e o que ele devolve
     * @return GatewayContract
     */
    private function gateway(array $returns): GatewayContract
    {
        $gateway = Mockery::mock('overload:' . self::OVERLOADED_GATEWAY, GatewayContract::class);
        $gateway->shouldReceive('supports')->andReturn(true);
        $gateway->shouldReceive('__toString')->andReturn('falso');

        foreach ($returns as $method => $return) {
            $gateway->shouldReceive($method)->andReturnUsing(function (...$args) use ($method, $return) {
                $this->calls[] = [$method, $args];

                return $return(...$args);
            });
        }

        return $gateway;
    }
}
