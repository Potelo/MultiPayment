<?php

namespace Potelo\MultiPayment\Tests\Unit;

use Stripe\ApiRequestor;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\MultiPayment;
use Potelo\MultiPayment\Models\Plan;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\AutomaticPix;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Enums\PlanInterval;
use Potelo\MultiPayment\Enums\ProrationBehavior;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Gateways\IuguGateway;
use Potelo\MultiPayment\Gateways\StripeGateway;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;
use Potelo\MultiPayment\Tests\Unit\Gateways\QueuedIuguApiRequest;
use Potelo\MultiPayment\Tests\Unit\Gateways\RecordingStripeHttpClient;

/**
 * Guardas de entrada nos models e na fachada: operação fora das capabilities do gateway lança
 * `UnsupportedOperationException` antes de qualquer requisição, inclusive antes de criar o
 * cliente que acompanha a fatura ou a assinatura.
 */
class CapabilityGuardsTest extends TestCase
{
    private RecordingStripeHttpClient $stripeHttp;

    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment' => [
                'default' => 'iugu',
                'gateways' => [
                    'iugu' => ['api_key' => 'iugu-key', 'class' => IuguGateway::class],
                    'stripe' => ['api_key' => 'sk_test_fake', 'class' => StripeGateway::class],
                ],
            ],
        ]));
        Facade::setFacadeApplication($app);

        $this->stripeHttp = RecordingStripeHttpClient::withResponses([]);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testSubscriptionCreationOnStripeFailsBeforeCreatingTheCustomer(): void
    {
        $customer = new Customer();
        $customer->name = 'Fulano';
        $customer->email = 'fulano@exemplo.com';

        $builder = (new MultiPayment('stripe'))->newSubscription()
            ->setPlanId('plano_mensal')
            ->setCustomer($customer);

        $this->assertNotImplemented(Capability::SUBSCRIPTIONS, fn () => $builder->create());
    }

    public function testSubscriptionDomainMethodsOnStripeFailBeforeTheNetwork(): void
    {
        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        $this->assertNotImplemented(Capability::SUBSCRIPTIONS, fn () => $subscription->get('stripe'));
        $this->assertNotImplemented(Capability::SUBSCRIPTIONS, fn () => $subscription->suspend('stripe'));
        foreach (ProrationBehavior::cases() as $proration) {
            $this->assertNotImplemented(Capability::SUBSCRIPTIONS, fn () => $subscription->changePlan('plano_anual', $proration, 'stripe'));
        }
        $this->assertNotImplemented(Capability::SUBSCRIPTIONS, fn () => $subscription->previewPlanChange('plano_anual', 'stripe'));
        $this->assertNotImplemented(Capability::SUBSCRIPTIONS, fn () => (new MultiPayment('stripe'))->getSubscription('sub_1'));
        $this->assertNotImplemented(Capability::SUBSCRIPTIONS, fn () => $subscription->resume('stripe'));
        $this->assertNotImplemented(Capability::SUBSCRIPTIONS, fn () => $subscription->cancel(false, 'stripe'));
        $this->assertNotImplemented(Capability::SUBSCRIPTIONS, fn () => (new MultiPayment('stripe'))->listSubscriptions('cus_1'));

        $existing = new Subscription();
        $existing->id = 'sub_1';
        $existing->metadata = ['origem' => 'teste'];
        $this->assertNotImplemented(Capability::SUBSCRIPTIONS, fn () => $existing->save('stripe'));
    }

    /**
     * No update, o gateway gravado no model prevalece sobre o informado, como em `Model::save()`.
     */
    public function testUpdateUsesTheGatewayStoredInTheModel(): void
    {
        $api = new QueuedIuguApiRequest([]);
        $subscription = new Subscription();
        $subscription->id = 'sub_1';
        $subscription->gateway = 'stripe';

        $this->assertNotImplemented(Capability::SUBSCRIPTIONS, fn () => $subscription->save(new IuguGateway($api)));
        $this->assertCount(0, $api->calls);
    }

    /**
     * `Model::delete()` confere a capability antes de procurar o método de despacho.
     */
    public function testDeleteChecksTheCapabilityBeforeTheDispatchMethod(): void
    {
        $plan = new Plan();
        $plan->id = 'plan_1';

        $this->assertNotImplemented(Capability::PLANS, fn () => $plan->delete('stripe'));
    }

    public function testPlanOperationsOnStripeFailBeforeTheNetwork(): void
    {
        $plan = new Plan();
        $plan->name = 'Mensal';
        $plan->amount = 10000;
        $plan->interval = PlanInterval::MONTH;

        $this->assertNotImplemented(Capability::PLANS, fn () => $plan->save('stripe'));

        $existing = new Plan();
        $existing->id = 'plan_1';
        $this->assertNotImplemented(Capability::PLANS, fn () => $existing->get('stripe'));
        $this->assertNotImplemented(Capability::PLANS, fn () => (new MultiPayment('stripe'))->listPlans());
        $this->assertNotImplemented(Capability::PLANS, fn () => (new MultiPayment('stripe'))->getPlan('plano_mensal'));
    }

    public function testBankSlipChargeOnStripeFailsBeforeCreatingTheCustomer(): void
    {
        $multiPayment = new MultiPayment('stripe');

        $this->assertNotImplemented(Capability::BANK_SLIP, fn () => $multiPayment->charge([
            'items' => [['description' => 'Mensalidade', 'price' => 10000, 'quantity' => 1]],
            'available_payment_methods' => [PaymentMethod::BANK_SLIP->value],
            'customer' => ['name' => 'Fulano', 'email' => 'fulano@exemplo.com', 'tax_document' => '20176996915'],
        ]));
    }

    public function testAutomaticPixInvoiceOnStripeFailsBeforeCreatingTheCustomer(): void
    {
        $automaticPix = new AutomaticPix();
        $automaticPix->id = 'recurrence-id';

        $builder = (new MultiPayment('stripe'))->newInvoice()
            ->addCustomer('Fulano', 'fulano@exemplo.com', '20176996915')
            ->addItem('Mensalidade', 10000, 1)
            ->addAvailablePaymentMethod(PaymentMethod::PIX)
            ->setAutomaticPix($automaticPix);

        $this->assertNotImplemented(Capability::AUTOMATIC_PIX, fn () => $builder->create());
    }

    public function testRawCardInvoiceOnStripeFailsBeforeCreatingTheCustomer(): void
    {
        $builder = (new MultiPayment('stripe'))->newInvoice()
            ->addCustomer('Fulano', 'fulano@exemplo.com', '20176996915')
            ->addItem('Mensalidade', 10000, 1)
            ->addAvailablePaymentMethod(PaymentMethod::CREDIT_CARD)
            ->addCreditCard('4111111111111111', '12', '2030', '123', 'Fulano', 'Silva');

        $this->assertUnsupported(
            Capability::RAW_CARD_DATA,
            UnsupportedOperationException::REASON_GATEWAY_LIMITATION,
            'stripe',
            fn () => $builder->create()
        );
        $this->assertSame([], $this->stripeHttp->calls);
    }

    public function testPercentDiscountSubscriptionOnIuguFailsBeforeCreatingTheCustomer(): void
    {
        $api = new QueuedIuguApiRequest([]);
        $customer = new Customer();
        $customer->name = 'Fulano';
        $customer->email = 'fulano@exemplo.com';

        $builder = (new MultiPayment(new IuguGateway($api)))->newSubscription()
            ->setPlanId('plano_mensal')
            ->setCustomer($customer)
            ->addPercentDiscount('Anual', 10.0);

        $this->assertUnsupported(
            Capability::NATIVE_COUPONS,
            UnsupportedOperationException::REASON_GATEWAY_LIMITATION,
            'iugu',
            fn () => $builder->create()
        );
        $this->assertCount(0, $api->calls);
    }

    public function testMultiMethodInvoiceOnStripeFailsBeforeCreatingTheCustomer(): void
    {
        $builder = (new MultiPayment('stripe'))->newInvoice()
            ->addCustomer('Fulano', 'fulano@exemplo.com', '20176996915')
            ->addItem('Mensalidade', 10000, 1)
            ->setAvailablePaymentMethods([PaymentMethod::PIX, PaymentMethod::CREDIT_CARD]);

        $this->assertNotImplemented(Capability::MULTIPLE_PAYMENT_METHODS, fn () => $builder->create());
    }

    /**
     * A primeira capability recusada é a do método de pagamento, antes da de multi-método.
     */
    public function testTheFirstMissingCapabilityIsThePaymentMethod(): void
    {
        $builder = (new MultiPayment('stripe'))->newInvoice()
            ->addCustomer('Fulano', 'fulano@exemplo.com', '20176996915')
            ->addItem('Mensalidade', 10000, 1)
            ->setAvailablePaymentMethods([PaymentMethod::PIX, PaymentMethod::BANK_SLIP]);

        $this->assertNotImplemented(Capability::BANK_SLIP, fn () => $builder->create());
    }

    /**
     * `requiredCapabilities()` recusa método fora de `PaymentMethod::selectable()`, como
     * `AUTOMATIC_PIX` em `availablePaymentMethods` ou em `paymentMethod`, com
     * `ModelAttributeValidationException`.
     */
    public function testInvoiceRequiredCapabilitiesRejectNonSelectableMethods(): void
    {
        $invoice = new Invoice();
        $invoice->availablePaymentMethods = [PaymentMethod::AUTOMATIC_PIX];

        try {
            $invoice->requiredCapabilities();
            $this->fail('Esperava ModelAttributeValidationException');
        } catch (ModelAttributeValidationException $e) {
            $this->assertStringContainsString('availablePaymentMethods must be one of', $e->getMessage());
        }

        $byMethod = new Invoice();
        $byMethod->paymentMethod = PaymentMethod::AUTOMATIC_PIX;

        try {
            $byMethod->requiredCapabilities();
            $this->fail('Esperava ModelAttributeValidationException');
        } catch (ModelAttributeValidationException $e) {
            $this->assertStringContainsString('paymentMethod must be one of', $e->getMessage());
        }
    }

    /**
     * Com a lista vazia, `paymentMethod` decide a capability exigida, e a fatura de boleto no
     * Stripe falha pelo array de `charge()` antes de criar o cliente.
     */
    public function testInvoiceRequiredCapabilitiesDeriveFromPaymentMethodWhenTheListIsEmpty(): void
    {
        $invoice = new Invoice();
        $invoice->paymentMethod = PaymentMethod::BANK_SLIP;
        $this->assertSame([Capability::BANK_SLIP], $invoice->requiredCapabilities());

        // a lista tem precedência sobre o método, que precisa constar dela
        $invoice->availablePaymentMethods = [PaymentMethod::PIX, PaymentMethod::BANK_SLIP];
        $this->assertSame(
            [Capability::PIX, Capability::BANK_SLIP, Capability::MULTIPLE_PAYMENT_METHODS],
            $invoice->requiredCapabilities()
        );

        $multiPayment = new MultiPayment('stripe');
        $this->assertNotImplemented(Capability::BANK_SLIP, fn () => $multiPayment->charge([
            'items' => [['description' => 'Mensalidade', 'price' => 10000, 'quantity' => 1]],
            'payment_method' => 'bank_slip',
            'customer' => ['name' => 'Fulano', 'email' => 'fulano@exemplo.com'],
        ]));
        $this->assertSame([], $this->stripeHttp->calls);
    }

    public function testInvoiceRequiredCapabilitiesDeriveFromTheAttributes(): void
    {
        $invoice = new Invoice();
        $invoice->availablePaymentMethods = [PaymentMethod::PIX, PaymentMethod::BANK_SLIP];
        $invoice->automaticPix = new AutomaticPix();

        $this->assertSame(
            [Capability::PIX, Capability::BANK_SLIP, Capability::MULTIPLE_PAYMENT_METHODS, Capability::AUTOMATIC_PIX],
            $invoice->requiredCapabilities()
        );

        $cardOnly = new Invoice();
        $cardOnly->creditCard = new \Potelo\MultiPayment\Models\CreditCard();
        $cardOnly->creditCard->id = 'pm_1';
        $this->assertSame([Capability::CREDIT_CARD], $cardOnly->requiredCapabilities());

        $rawCard = new Invoice();
        $rawCard->creditCard = new \Potelo\MultiPayment\Models\CreditCard();
        $rawCard->creditCard->number = '4111111111111111';
        $this->assertSame([Capability::CREDIT_CARD, Capability::RAW_CARD_DATA], $rawCard->requiredCapabilities());

        $tokenized = new Invoice();
        $tokenized->creditCard = new \Potelo\MultiPayment\Models\CreditCard();
        $tokenized->creditCard->token = 'pm_tok';
        $this->assertSame([Capability::CREDIT_CARD], $tokenized->requiredCapabilities());

        $subscription = new Subscription();
        $this->assertSame([Capability::SUBSCRIPTIONS], $subscription->requiredCapabilities());
        $discount = new \Potelo\MultiPayment\Models\SubscriptionDiscount();
        $discount->percentOff = 10.0;
        $subscription->discounts = [$discount];
        $this->assertSame([Capability::SUBSCRIPTIONS, Capability::NATIVE_COUPONS], $subscription->requiredCapabilities());

        $existing = new Invoice();
        $existing->id = 'inv_1';
        $existing->availablePaymentMethods = [PaymentMethod::BANK_SLIP];
        $this->assertSame([], $existing->requiredCapabilities());
    }

    public function testIuguInvoiceWithEveryMethodPassesTheGuardAndCreatesTheCustomerFirst(): void
    {
        $api = (new QueuedIuguApiRequest([
            (object) [
                'id' => 'cus_novo',
                'name' => 'Fulano',
                'email' => 'fulano@exemplo.com',
                'cpf_cnpj' => '20176996915',
                'phone' => null,
                'phone_prefix' => null,
                'created_at' => '2026-09-02T09:00:00-03:00',
                'custom_variables' => [],
                'default_payment_method_id' => null,
                'errors' => null,
            ],
            (object) [
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
                'payable_with' => ['credit_card', 'bank_slip', 'pix'],
                'customer_id' => 'cus_novo',
                'customer_name' => 'Fulano',
                'email' => 'fulano@exemplo.com',
                'payer_phone' => null,
                'payer_phone_prefix' => null,
                'items' => [(object) ['description' => 'Mensalidade', 'price_cents' => 10000, 'quantity' => 1]],
                'payer_address_zip_code' => null,
                'bank_slip' => null,
                'pix' => null,
                'automatic_pix' => null,
                'credit_card_transaction' => null,
                'variables' => [],
                'errors' => null,
            ],
        ]))->installAsSdkRequester();

        try {
            $invoice = (new MultiPayment('iugu'))->newInvoice()
                ->addCustomer('Fulano', 'fulano@exemplo.com', '20176996915')
                ->addItem('Mensalidade', 10000, 1)
                ->setAvailablePaymentMethods([PaymentMethod::CREDIT_CARD, PaymentMethod::BANK_SLIP, PaymentMethod::PIX])
                ->create();
        } finally {
            QueuedIuguApiRequest::restoreSdkRequester();
        }

        $this->assertCount(2, $api->calls);
        $this->assertStringEndsWith('/customers', $api->calls[0]['url']);
        $this->assertStringEndsWith('/invoices', $api->calls[1]['url']);
        $this->assertSame('cus_novo', $invoice->customer->id);
        $this->assertSame('inv_1', $invoice->id);
    }

    /**
     * A Iugu não autentica o portador ao salvar o cartão: concluir um setup é limitação do
     * gateway, recusada antes de qualquer requisição.
     */
    public function testConfirmCreditCardSetupOnIuguIsAGatewayLimitation(): void
    {
        $this->assertUnsupported(
            Capability::CARD_SETUP_AUTHENTICATION,
            UnsupportedOperationException::REASON_GATEWAY_LIMITATION,
            'iugu',
            fn () => (new MultiPayment('iugu'))->confirmCreditCardSetup('seti_1')
        );
        $this->assertFalse((new MultiPayment('iugu'))->supports(Capability::CARD_SETUP_AUTHENTICATION));
        $this->assertTrue((new MultiPayment('stripe'))->supports(Capability::CARD_SETUP_AUTHENTICATION));
    }

    public function testFacadeExposesTheDeclarations(): void
    {
        $multiPayment = new MultiPayment('stripe');

        $iugu = new IuguGateway(new QueuedIuguApiRequest([]));
        $this->assertInstanceOf(StripeGateway::class, $multiPayment->gateway());
        $this->assertInstanceOf(IuguGateway::class, $multiPayment->gateway('iugu'));
        $this->assertSame($iugu, $multiPayment->gateway($iugu));
        $this->assertTrue($multiPayment->supports(Capability::PIX));
        $this->assertFalse($multiPayment->supports(Capability::BANK_SLIP));
        $this->assertTrue($multiPayment->supports(Capability::BANK_SLIP, 'iugu'));
        $this->assertTrue($multiPayment->gateway('iugu')->supports(Capability::INSTALLMENTS));
        $this->assertSame((new StripeGateway())->capabilities(), $multiPayment->capabilities());
        $this->assertSame((new StripeGateway())->notYetImplemented(), $multiPayment->notYetImplemented('stripe'));
        $this->assertContains(Capability::SUBSCRIPTIONS, $multiPayment->capabilities('iugu'));
        $this->assertSame([], $this->stripeHttp->calls);
    }

    public function testFacadeDocblockAnnotatesTheCapabilityMethods(): void
    {
        $docblock = (new \ReflectionClass(\Potelo\MultiPayment\Facades\MultiPayment::class))->getDocComment();

        foreach (['gateway(', 'supports(', 'capabilities(', 'notYetImplemented(', 'confirmCreditCardSetup('] as $method) {
            $this->assertMatchesRegularExpression('/@method static .*' . preg_quote($method, '/') . '/', $docblock, $method);
        }
    }

    public function testLaravelFacadeResolvesTheCapabilityMethods(): void
    {
        Facade::getFacadeApplication()->bind('multiPayment', fn () => new MultiPayment('stripe'));

        $this->assertTrue(\Potelo\MultiPayment\Facades\MultiPayment::supports(Capability::PIX));
        $this->assertFalse(\Potelo\MultiPayment\Facades\MultiPayment::supports(Capability::BANK_SLIP));
        $this->assertContains(Capability::SUBSCRIPTIONS, \Potelo\MultiPayment\Facades\MultiPayment::capabilities('iugu'));
        $this->assertInstanceOf(StripeGateway::class, \Potelo\MultiPayment\Facades\MultiPayment::gateway());
    }

    /**
     * Executa a operação esperando `UnsupportedOperationException` com `not_implemented` para
     * a capability informada, e afirma que nenhuma requisição saiu para a Stripe.
     */
    private function assertNotImplemented(Capability $capability, callable $operation): void
    {
        $this->assertUnsupported($capability, UnsupportedOperationException::REASON_NOT_IMPLEMENTED, 'stripe', $operation);
        $this->assertSame([], $this->stripeHttp->calls, 'a guarda deixou uma requisição sair');
    }

    /**
     * Executa a operação esperando `UnsupportedOperationException` com a capability, o motivo
     * e o gateway informados.
     */
    private function assertUnsupported(Capability $capability, string $reason, string $gateway, callable $operation): void
    {
        try {
            $operation();
            $this->fail("Esperava UnsupportedOperationException para {$capability->name}");
        } catch (UnsupportedOperationException $e) {
            $this->assertSame($capability, $e->capability);
            $this->assertSame($gateway, $e->gateway);
            $this->assertSame($reason, $e->reason);
        }
    }
}
