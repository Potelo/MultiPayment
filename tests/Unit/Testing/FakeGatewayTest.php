<?php

namespace Potelo\MultiPayment\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\MultiPayment;
use Potelo\MultiPayment\Facades\MultiPayment as MultiPaymentFacade;
use Potelo\MultiPayment\Gateways\IuguGateway;
use Potelo\MultiPayment\Gateways\StripeGateway;
use Potelo\MultiPayment\Testing\FakeGateway;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Enums\CaptureMethod;
use Potelo\MultiPayment\Enums\DeclineCode;
use Potelo\MultiPayment\Enums\DisputeStatus;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Enums\SubscriptionStatus;
use Potelo\MultiPayment\Enums\WebhookEventType;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Exceptions\CardDeclinedException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;

/**
 * `MultiPayment::fake()` e o `FakeGateway`: os gateways configurados são substituídos no
 * container, as operações da lib funcionam em memória com o vocabulário real de models,
 * status e exceções, os desvios são programáveis e as asserções da facade enxergam o que
 * aconteceu.
 */
class FakeGatewayTest extends TestCase
{
    private Container $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Container();
        $this->app->instance('config', new Repository([
            'multi-payment' => [
                'default' => 'iugu',
                'gateways' => [
                    'iugu' => ['api_key' => 'chave-iugu', 'class' => IuguGateway::class],
                    'iugu_b' => ['api_key' => 'chave-iugu-b', 'class' => IuguGateway::class],
                    'stripe' => ['api_key' => 'sk_test_x', 'class' => StripeGateway::class],
                ],
            ],
        ]));
        $this->app->bind('multiPayment', fn () => new MultiPayment());
        Facade::setFacadeApplication($this->app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testFakeReplacesEveryConfiguredGatewayInTheContainer(): void
    {
        $fakes = MultiPaymentFacade::fake();

        $this->assertSame(['iugu', 'iugu_b', 'stripe'], array_keys($fakes));
        $this->assertSame($fakes['iugu'], (new MultiPayment('iugu'))->gateway());
        $this->assertSame($fakes['stripe'], (new MultiPayment('stripe'))->gateway());
    }

    public function testFakeWithNamesReplacesOnlyTheNamedGateways(): void
    {
        MultiPaymentFacade::fake(['stripe']);

        $this->assertInstanceOf(FakeGateway::class, (new MultiPayment('stripe'))->gateway());
        $this->assertInstanceOf(IuguGateway::class, (new MultiPayment('iugu'))->gateway());
    }

    public function testPixInvoiceIsCreatedPendingAndReadBackThroughTheFacade(): void
    {
        MultiPaymentFacade::fake();

        $invoice = MultiPaymentFacade::newInvoice()
            ->addCustomer('Fulano', 'fulano@exemplo.com', '20176996915')
            ->addItem('Mensalidade', 10000, 1)
            ->setPaymentMethod(PaymentMethod::PIX)
            ->create();

        $this->assertSame(InvoiceStatus::PENDING, $invoice->status);
        $this->assertSame(10000, $invoice->amount);
        $this->assertSame('iugu', $invoice->gateway);
        $this->assertNotEmpty($invoice->customer->id);

        $read = MultiPaymentFacade::getInvoice($invoice->id);
        $this->assertSame($invoice->id, $read->id);

        MultiPaymentFacade::assertInvoiceCreated(fn (Invoice $created) => $created->amount === 10000);
    }

    public function testCreditCardInvoiceIsChargedAndAssertNothingChargedFails(): void
    {
        MultiPaymentFacade::fake();

        $invoice = MultiPaymentFacade::newInvoice()
            ->addCustomer('Fulano', 'fulano@exemplo.com', '20176996915')
            ->addItem('Mensalidade', 10000, 1)
            ->addCreditCardId('cartao-salvo')
            ->create();

        $this->assertSame(InvoiceStatus::PAID, $invoice->status);
        $this->assertSame(10000, $invoice->paidAmount);

        $this->expectException(AssertionFailedError::class);
        MultiPaymentFacade::assertNothingCharged();
    }

    public function testAssertNothingChargedPassesWhenNoOperationHappened(): void
    {
        MultiPaymentFacade::fake();

        MultiPaymentFacade::assertNothingCharged();
        $this->addToAssertionCount(1);
    }

    /**
     * Fakes registrados num container anterior não valem no seguinte: as asserções exigem um
     * `fake()` no container corrente, então um teste que esquece o `fake()` falha com a
     * instrução em vez de enxergar fakes órfãos de outro teste.
     */
    public function testAssertionsRequireFakeToHaveBeenCalledOnTheCurrentContainer(): void
    {
        MultiPaymentFacade::fake();
        Facade::setFacadeApplication(new Container());

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Chame MultiPayment::fake()');
        MultiPaymentFacade::assertNothingCharged();
    }

    public function testCallingFakeAgainDiscardsThePreviousFakes(): void
    {
        MultiPaymentFacade::fake();

        MultiPaymentFacade::newInvoice()
            ->addCustomer('Fulano', 'fulano@exemplo.com', '20176996915')
            ->addItem('Mensalidade', 10000, 1)
            ->setPaymentMethod(PaymentMethod::PIX)
            ->create();

        MultiPaymentFacade::fake();

        MultiPaymentFacade::assertNothingCharged();
        $this->addToAssertionCount(1);
    }

    public function testAssertionsFailWhenNothingSatisfiesThem(): void
    {
        MultiPaymentFacade::fake();

        MultiPaymentFacade::newInvoice()
            ->addCustomer('Fulano', 'fulano@exemplo.com', '20176996915')
            ->addItem('Mensalidade', 10000, 1)
            ->setPaymentMethod(PaymentMethod::PIX)
            ->create();

        $failures = 0;
        foreach ([
            fn () => MultiPaymentFacade::assertInvoiceCreated(fn (Invoice $invoice) => $invoice->amount === 99),
            fn () => MultiPaymentFacade::assertSubscriptionCreated(),
            fn () => MultiPaymentFacade::assertRefunded('fake_inv_1'),
            fn () => MultiPaymentFacade::assertCapabilityChecked(Capability::DISPUTES),
        ] as $assertion) {
            try {
                $assertion();
            } catch (AssertionFailedError) {
                $failures++;
            }
        }

        $this->assertSame(4, $failures);
    }

    public function testWillDeclineRejectsTheNextCardChargeWithTheGivenReason(): void
    {
        $fakes = MultiPaymentFacade::fake();
        $fakes['iugu']->willDecline(DeclineCode::INSUFFICIENT_FUNDS, '51');

        try {
            MultiPaymentFacade::newInvoice()
                ->addCustomer('Fulano', 'fulano@exemplo.com', '20176996915')
                ->addItem('Mensalidade', 10000, 1)
                ->addCreditCardId('cartao-salvo')
                ->create();
            $this->fail('Esperava CardDeclinedException');
        } catch (CardDeclinedException $e) {
            $this->assertSame(DeclineCode::INSUFFICIENT_FUNDS, $e->declineCode);
            $this->assertSame('51', $e->gatewayCode);
            $this->assertTrue($e->retryable);
        }

        MultiPaymentFacade::assertNothingCharged();
    }

    public function testWillRequireActionReturnsThePendingCardAndConfirmCompletesIt(): void
    {
        $fakes = MultiPaymentFacade::fake();
        $fakes['iugu']->willRequireAction();

        $card = MultiPaymentFacade::newCreditCard()
            ->setCustomerId('fake_cus_1')
            ->setToken('tok_teste')
            ->create();

        $this->assertTrue($card->requiresAction);
        $this->assertNull($card->id);
        $this->assertNotEmpty($card->setupId);
        $this->assertNotEmpty($card->clientSecret);

        $confirmed = MultiPaymentFacade::confirmCreditCardSetup($card->setupId);
        $this->assertFalse($confirmed->requiresAction);
        $this->assertNotEmpty($confirmed->id);
    }

    public function testWillFailThrowsTheProgrammedExceptionOnTheNextOperation(): void
    {
        $fakes = MultiPaymentFacade::fake();
        $fakes['iugu']->willFail(GatewayNotAvailableException::class);

        $this->expectException(GatewayNotAvailableException::class);
        MultiPaymentFacade::getInvoice('fake_inv_1');
    }

    public function testRefundFlowUpdatesTheInvoiceAndTheRefundAssertions(): void
    {
        MultiPaymentFacade::fake();

        $invoice = MultiPaymentFacade::newInvoice()
            ->addCustomer('Fulano', 'fulano@exemplo.com', '20176996915')
            ->addItem('Mensalidade', 10000, 1)
            ->addCreditCardId('cartao-salvo')
            ->create();

        $refund = MultiPaymentFacade::refundInvoice($invoice->id, 3000);
        $this->assertSame(3000, $refund->amount);
        $this->assertSame(InvoiceStatus::PARTIALLY_REFUNDED, $refund->invoice->status);
        $this->assertSame(7000, MultiPaymentFacade::refundableAmount($invoice->id));

        MultiPaymentFacade::refundInvoice($invoice->id);
        $this->assertSame(InvoiceStatus::REFUNDED, MultiPaymentFacade::getInvoice($invoice->id)->status);

        MultiPaymentFacade::assertRefunded($invoice->id, 3000);
        MultiPaymentFacade::assertRefunded($invoice->id, 7000);
        MultiPaymentFacade::assertRefunded($invoice->id);
    }

    /**
     * O caminho antigo de estorno parcial (escrita em `refundedAmount`) vale no fake como nos
     * drivers reais: o valor escrito é o pedido daquele estorno, o razão interno do fake
     * ignora a escrita do consumidor e o acumulado sai certo.
     */
    #[IgnoreDeprecations]
    public function testRefundHonorsTheLegacyRefundedAmountPath(): void
    {
        MultiPaymentFacade::fake();

        $invoice = MultiPaymentFacade::newInvoice()
            ->addCustomer('Fulano', 'fulano@exemplo.com', '20176996915')
            ->addItem('Mensalidade', 10000, 1)
            ->addCreditCardId('cartao-salvo')
            ->create();

        $invoice->refundedAmount = 3000;
        $refund = $invoice->refund();

        $this->assertSame(3000, $refund->amount);
        $this->assertSame(InvoiceStatus::PARTIALLY_REFUNDED, $refund->invoice->status);
        MultiPaymentFacade::assertRefunded($invoice->id, 3000);
        $this->assertSame(7000, MultiPaymentFacade::refundableAmount($invoice->id));
    }

    public function testSubscriptionWithTrialIsCreatedTrialing(): void
    {
        MultiPaymentFacade::fake();

        $subscription = MultiPaymentFacade::newSubscription()
            ->setPlanId('plano-mensal')
            ->setCustomerId('fake_cus_1')
            ->setTrialDays(7)
            ->create();

        $this->assertSame(SubscriptionStatus::TRIALING, $subscription->status);
        $this->assertNotNull($subscription->trialEndsAt);

        MultiPaymentFacade::assertSubscriptionCreated(
            fn (Subscription $created) => $created->planId === 'plano-mensal'
        );
    }

    public function testDeclaredCapabilitiesAreEnforcedAndTheCheckIsRecorded(): void
    {
        $fakes = MultiPaymentFacade::fake();
        $fakes['iugu']->declareCapabilities(
            array_values(array_filter(Capability::cases(), fn (Capability $c) => $c !== Capability::SUBSCRIPTIONS))
        );

        try {
            MultiPaymentFacade::newSubscription()
                ->setPlanId('plano-mensal')
                ->setCustomerId('fake_cus_1')
                ->create();
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::SUBSCRIPTIONS, $e->capability);
        }

        MultiPaymentFacade::assertCapabilityChecked(Capability::SUBSCRIPTIONS);
    }

    public function testFakeWebhookHydratesFromTheFakeState(): void
    {
        $fakes = MultiPaymentFacade::fake();

        $invoice = MultiPaymentFacade::newInvoice()
            ->addCustomer('Fulano', 'fulano@exemplo.com', '20176996915')
            ->addItem('Mensalidade', 10000, 1)
            ->setPaymentMethod(PaymentMethod::PIX)
            ->create();

        $event = $fakes['iugu']->fakeWebhook(WebhookEventType::INVOICE_PAID, ['invoice_id' => $invoice->id]);

        $this->assertSame(WebhookEventType::INVOICE_PAID, $event->type);
        $this->assertSame('iugu', $event->gateway);
        $this->assertNotEmpty($event->id);
        $this->assertSame($invoice->id, $event->invoice()->id);
    }

    public function testParseWebhookAcceptsAJsonPayloadWithTheCommonType(): void
    {
        $fakes = MultiPaymentFacade::fake();

        $event = $fakes['iugu']->parseWebhook(
            json_encode(['type' => 'invoice.payment_failed', 'invoice_id' => 'inv_x', 'decline_code' => 'insufficient_funds']),
            []
        );

        $this->assertSame(WebhookEventType::INVOICE_PAYMENT_FAILED, $event->type);
        $this->assertSame('inv_x', $event->invoiceId);
        $this->assertSame(DeclineCode::INSUFFICIENT_FUNDS, $event->declineCode);
    }

    public function testWillFailThrowsTheGivenExceptionInstance(): void
    {
        $fakes = MultiPaymentFacade::fake();
        $failure = new GatewayNotAvailableException('fora do ar');
        $fakes['iugu']->willFail($failure);

        try {
            MultiPaymentFacade::getInvoice('fake_inv_1');
            $this->fail('Esperava GatewayNotAvailableException');
        } catch (GatewayNotAvailableException $e) {
            $this->assertSame($failure, $e);
        }
    }

    /**
     * `declareCapabilities()` alimenta as três listas: capability em `notYetImplemented`
     * recusa com o reason `not_implemented`, e capability em `emulated` responde a
     * `supports()` e a `isEmulated()` como num driver real.
     */
    public function testDeclaredListsDriveTheReasonAndTheEmulationAnswers(): void
    {
        $fakes = MultiPaymentFacade::fake();
        $fakes['iugu']->declareCapabilities(
            array_values(array_filter(
                Capability::cases(),
                fn (Capability $c) => $c !== Capability::SUBSCRIPTIONS && $c !== Capability::COUPONS
            )),
            [Capability::SUBSCRIPTIONS],
            [Capability::COUPONS]
        );

        try {
            MultiPaymentFacade::newSubscription()
                ->setPlanId('plano-mensal')
                ->setCustomerId('fake_cus_1')
                ->create();
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertTrue($e->isNotImplemented());
        }

        $this->assertTrue($fakes['iugu']->supports(Capability::COUPONS));
        $this->assertTrue($fakes['iugu']->isEmulated(Capability::COUPONS));
    }

    public function testManualCaptureAuthorizesAndCaptureInvoiceCompletesIt(): void
    {
        MultiPaymentFacade::fake();

        $invoice = MultiPaymentFacade::newInvoice()
            ->addCustomer('Fulano', 'fulano@exemplo.com', '20176996915')
            ->addItem('Mensalidade', 10000, 1)
            ->addCreditCardId('cartao-salvo')
            ->setCaptureMethod(CaptureMethod::MANUAL)
            ->create();

        $this->assertSame(InvoiceStatus::AUTHORIZED, $invoice->status);
        $this->assertNull($invoice->paidAmount);

        $captured = MultiPaymentFacade::captureInvoice($invoice->id, 7000);
        $this->assertSame(InvoiceStatus::PAID, $captured->status);
        $this->assertSame(7000, $captured->paidAmount);

        $this->expectException(ModelAttributeValidationException::class);
        MultiPaymentFacade::captureInvoice($invoice->id);
    }

    public function testOpenDisputeMarksTheInvoiceAndTheDisputeFlowWorks(): void
    {
        $fakes = MultiPaymentFacade::fake();

        $invoice = MultiPaymentFacade::newInvoice()
            ->addCustomer('Fulano', 'fulano@exemplo.com', '20176996915')
            ->addItem('Mensalidade', 10000, 1)
            ->addCreditCardId('cartao-salvo')
            ->create();

        $dispute = $fakes['iugu']->openDispute($invoice->id);

        $this->assertSame(InvoiceStatus::DISPUTED, MultiPaymentFacade::getInvoice($invoice->id)->status);
        $this->assertSame($invoice->id, $dispute->invoiceId);
        $this->assertSame(10000, $dispute->amount);
        $this->assertSame(DisputeStatus::OPEN, $dispute->status);

        $contested = MultiPaymentFacade::contestDispute($dispute->id, ['file_1' => 'evidencia']);
        $this->assertSame(DisputeStatus::UNDER_REVIEW, $contested->status);

        $accepted = MultiPaymentFacade::acceptDispute($dispute->id);
        $this->assertSame(DisputeStatus::ACCEPTED, $accepted->status);
        $this->assertNotNull($accepted->closedAt);
    }

    public function testTwoAccountsOfTheSameGatewayClassKeepIsolatedState(): void
    {
        $fakes = MultiPaymentFacade::fake(['iugu', 'iugu_b']);

        (new MultiPayment('iugu'))->newInvoice()
            ->addCustomer('Fulano', 'fulano@exemplo.com', '20176996915')
            ->addItem('Mensalidade', 10000, 1)
            ->setPaymentMethod(PaymentMethod::PIX)
            ->create();

        $this->assertCount(1, $fakes['iugu']->createdInvoices());
        $this->assertCount(0, $fakes['iugu_b']->createdInvoices());
    }
}
