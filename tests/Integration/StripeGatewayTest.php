<?php

namespace Potelo\MultiPayment\Tests\Integration;

use Carbon\Carbon;
use Stripe\StripeClient;
use Illuminate\Support\Facades\Config;
use Potelo\MultiPayment\Tests\TestCase;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Gateways\StripeGateway;
use Potelo\MultiPayment\Enums\InvoiceOriginType;
use Potelo\MultiPayment\Facades\MultiPayment;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Exceptions\ChargingException;
use Potelo\MultiPayment\Exceptions\CardDeclinedException;
use Potelo\MultiPayment\Enums\DeclineCode;
use PHPUnit\Framework\Attributes\DataProvider;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Enums\RefundStatus;
use Potelo\MultiPayment\Exceptions\RefundNotSupportedException;
use Potelo\MultiPayment\Enums\PaymentMethod;

/**
 * Cenários específicos do gateway Stripe na sandbox real. O fluxo de cartão é token-only:
 * os PaymentMethods de teste da Stripe (pm_card_visa etc.) fazem o papel do token criado
 * client-side com Stripe.js.
 */
class StripeGatewayTest extends TestCase
{
    /**
     * Gateway data provider — mantém o padrão por gateway e dispensa o sleep da Iugu.
     *
     * @return array[]
     */
    public static function stripeGatewayDataProvider(): array
    {
        return [
            ['stripe'],
        ];
    }

    /**
     * Deve cobrar uma fatura de cartão com PaymentMethod de teste e refletir em getInvoice.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldChargeCreditCardInvoice($gateway)
    {
        $customerData = self::customerWithoutAddress();
        $invoice = MultiPayment::setGateway($gateway)->newInvoice()
            ->addCustomer(
                $customerData['name'],
                $customerData['email'],
                $customerData['taxDocument'],
                $customerData['birthDate'],
                $customerData['phoneArea'],
                $customerData['phoneNumber']
            )
            ->addItem('Assinatura mensal', 12345, 1)
            ->setAvailablePaymentMethods([PaymentMethod::CREDIT_CARD])
            ->addCreditCardToken('pm_card_visa')
            ->create();

        $this->assertNotNull($invoice->id);
        $this->assertEquals(InvoiceStatus::PAID, $invoice->status);
        $this->assertEquals(12345, $invoice->amount);
        $this->assertEquals(12345, $invoice->paidAmount);
        $this->assertEquals(PaymentMethod::CREDIT_CARD, $invoice->paymentMethod);
        $this->assertEquals('4242', $invoice->creditCard->lastDigits);
        $this->assertNotNull($invoice->paidAt);
        $this->assertCount(1, $invoice->items);
        $this->assertEquals('Assinatura mensal', $invoice->items[0]->description);

        sleep(3); // a balance transaction (fee) do cartão é assíncrona logo após o confirm

        $invoiceFetched = MultiPayment::setGateway($gateway)->getInvoice($invoice->id);
        $this->assertEquals(InvoiceStatus::PAID, $invoiceFetched->status);
        $this->assertEquals(12345, $invoiceFetched->paidAmount);
        $this->assertNotNull($invoiceFetched->fee);
        $this->assertEquals($invoice->id, $invoiceFetched->id);
    }

    /**
     * Recusa de cartão deve virar ChargingException com resposta bruta e razão normalizada.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldRaiseChargingExceptionOnDeclinedCard($gateway)
    {
        $customerData = self::customerWithoutAddress();
        $invoiceBuilder = MultiPayment::setGateway($gateway)->newInvoice()
            ->addCustomer(
                $customerData['name'],
                $customerData['email'],
                $customerData['taxDocument'],
                $customerData['birthDate'],
                $customerData['phoneArea'],
                $customerData['phoneNumber']
            )
            ->addItem('Assinatura mensal', 9900, 1)
            ->setAvailablePaymentMethods([PaymentMethod::CREDIT_CARD])
            ->addCreditCardToken('pm_card_chargeDeclined');

        try {
            $invoiceBuilder->create();
            $this->fail('Expected ChargingException was not thrown');
        } catch (ChargingException $exception) {
            $this->assertInstanceOf(CardDeclinedException::class, $exception);
            $this->assertEquals('card_declined', $exception->reason);
            $this->assertSame(DeclineCode::GENERIC, $exception->declineCode);
            $this->assertSame('generic_decline', $exception->gatewayCode);
            $this->assertFalse($exception->retryable);
            $this->assertNotEmpty($exception->chargeResponse);
        }
    }

    /**
     * Recusa por saldo insuficiente chega com o decline_code da Stripe traduzido para
     * INSUFFICIENT_FUNDS e com nova tentativa permitida.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testInsufficientFundsDeclineIsTranslatedToDeclineCode($gateway)
    {
        $customerData = self::customerWithoutAddress();
        $invoiceBuilder = MultiPayment::setGateway($gateway)->newInvoice()
            ->addCustomer(
                $customerData['name'],
                $customerData['email'],
                $customerData['taxDocument'],
                $customerData['birthDate'],
                $customerData['phoneArea'],
                $customerData['phoneNumber']
            )
            ->addItem('Assinatura mensal', 9900, 1)
            ->setAvailablePaymentMethods([PaymentMethod::CREDIT_CARD])
            ->addCreditCardToken('pm_card_chargeDeclinedInsufficientFunds');

        try {
            $invoiceBuilder->create();
            $this->fail('Expected CardDeclinedException was not thrown');
        } catch (CardDeclinedException $exception) {
            $this->assertSame(DeclineCode::INSUFFICIENT_FUNDS, $exception->declineCode);
            $this->assertSame('insufficient_funds', $exception->gatewayCode);
            $this->assertTrue($exception->retryable);
            $this->assertSame('insufficient_funds', $exception->reason);
        }
    }

    /**
     * Deve salvar, buscar, definir como padrão e excluir um cartão tokenizado.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldManageCreditCardLifecycle($gateway)
    {
        $customer = $this->createCustomer($gateway, self::customerWithoutAddress());

        $creditCard = MultiPayment::setGateway($gateway)->newCreditCard()
            ->setCustomerId($customer->id)
            ->setToken('pm_card_visa')
            ->setDescription('cartão de teste')
            ->create();

        $this->assertNotNull($creditCard->id);
        $this->assertEquals('visa', $creditCard->brand);
        $this->assertEquals('4242', $creditCard->lastDigits);
        $this->assertEquals('cartão de teste', $creditCard->description);
        $this->assertEquals($gateway, $creditCard->gateway);

        $cardFetched = MultiPayment::setGateway($gateway)->getCard($customer->id, $creditCard->id);
        $this->assertEquals($creditCard->id, $cardFetched->id);
        $this->assertEquals('4242', $cardFetched->lastDigits);

        $customerUpdated = MultiPayment::setGateway($gateway)->setDefaultCard($customer->id, $creditCard->id);
        $this->assertEquals($creditCard->id, $customerUpdated->defaultCard->id);

        MultiPayment::setGateway($gateway)->deleteCard($customer->id, $creditCard->id);

        // após o detach o PaymentMethod não pertence mais ao customer
        $this->expectException(GatewayException::class);
        MultiPayment::setGateway($gateway)->getCard($customer->id, $creditCard->id);
    }

    /**
     * Deve criar fatura pix server-side com QR code e refletir o pagamento mágico da sandbox.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldCreatePixInvoiceAndReceiveMagicPayment($gateway)
    {
        $customerData = self::customerWithoutAddress();
        $invoice = MultiPayment::setGateway($gateway)->newInvoice()
            ->addCustomer(
                $customerData['name'],
                'succeed_immediately@example.com',
                $customerData['taxDocument']
            )
            ->addItem('Assinatura mensal', 12345, 1)
            ->setAvailablePaymentMethods([PaymentMethod::PIX])
            ->setPixExpiresAt(\Carbon\Carbon::now()->addHour())
            ->create();

        $this->assertNotNull($invoice->id);
        $this->assertEquals(InvoiceStatus::PENDING, $invoice->status);
        $this->assertEquals(PaymentMethod::PIX, $invoice->paymentMethod);
        $this->assertNotNull($invoice->pix);
        $this->assertNotNull($invoice->pix->qrCodeText);
        $this->assertNotNull($invoice->pix->qrCodeImageUrl);
        $this->assertNotNull($invoice->url);
        $this->assertNotNull($invoice->pixExpiresAt);
        $this->assertNull($invoice->dueDate);

        // além do pagamento mágico, espera a balance transaction (fee) materializar
        $invoiceFetched = $this->waitForInvoiceCondition($gateway, $invoice->id, function (Invoice $fetched) {
            return $fetched->status === InvoiceStatus::PAID && !is_null($fetched->fee);
        });
        $this->assertEquals(InvoiceStatus::PAID, $invoiceFetched->status);
        $this->assertEquals(12345, $invoiceFetched->paidAmount);
        $this->assertEquals(PaymentMethod::PIX, $invoiceFetched->paymentMethod);
        $this->assertNotNull($invoiceFetched->fee);
    }

    /**
     * Deve cancelar uma fatura pix pendente.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldCancelPendingPixInvoice($gateway)
    {
        $customerData = self::customerWithoutAddress();
        $invoice = MultiPayment::setGateway($gateway)->newInvoice()
            ->addCustomer($customerData['name'], $customerData['email'], $customerData['taxDocument'])
            ->addItem('Assinatura mensal', 5000, 1)
            ->setAvailablePaymentMethods([PaymentMethod::PIX])
            ->create();

        $this->assertEquals(InvoiceStatus::PENDING, $invoice->status);

        $invoiceCanceled = MultiPayment::setGateway($gateway)->cancelInvoice($invoice->id);
        $this->assertEquals(InvoiceStatus::CANCELED, $invoiceCanceled->status);
    }

    /**
     * Fatura pix expirada volta a pendente e deve aceitar cobrança com cartão.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldChargeExpiredPixInvoiceWithCreditCard($gateway)
    {
        $customerData = self::customerWithoutAddress();
        $invoice = MultiPayment::setGateway($gateway)->newInvoice()
            ->addCustomer($customerData['name'], 'expire_immediately@example.com', $customerData['taxDocument'])
            ->addItem('Assinatura mensal', 9900, 1)
            ->setAvailablePaymentMethods([PaymentMethod::PIX])
            ->create();

        $this->assertEquals(InvoiceStatus::PENDING, $invoice->status);

        // aguarda a sandbox processar a expiração mágica (o PI segue pendente e re-cobrável)
        $invoiceExpired = $this->waitForInvoiceCondition($gateway, $invoice->id, function (Invoice $fetched) {
            return empty($fetched->pix);
        });
        $this->assertEquals(InvoiceStatus::PENDING, $invoiceExpired->status);

        $invoicePaid = MultiPayment::setGateway($gateway)
            ->chargeInvoiceWithCreditCard($invoice->id, 'pm_card_visa');
        $this->assertEquals(InvoiceStatus::PAID, $invoicePaid->status);
        $this->assertEquals(PaymentMethod::CREDIT_CARD, $invoicePaid->paymentMethod);
        $this->assertEquals('4242', $invoicePaid->creditCard->lastDigits);
    }

    /**
     * Busca a fatura até a condição ser satisfeita ou o tempo limite estourar — a sandbox
     * processa os e-mails mágicos e a balance transaction de forma assíncrona.
     *
     * @param  string  $gateway
     * @param  string  $invoiceId
     * @param  callable  $condition
     * @return \Potelo\MultiPayment\Models\Invoice
     */
    private function waitForInvoiceCondition(string $gateway, string $invoiceId, callable $condition): Invoice
    {
        $invoice = MultiPayment::setGateway($gateway)->getInvoice($invoiceId);
        foreach (range(1, 10) as $attempt) {
            if ($condition($invoice)) {
                return $invoice;
            }
            sleep(3);
            $invoice = MultiPayment::setGateway($gateway)->getInvoice($invoiceId);
        }

        $this->fail("Timeout aguardando a condição da fatura [{$invoiceId}] na sandbox");
    }

    /**
     * Deve estornar integralmente uma fatura de cartão paga.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldRefundCreditCardInvoiceTotally($gateway)
    {
        $customerData = self::customerWithoutAddress();
        $invoice = MultiPayment::setGateway($gateway)->newInvoice()
            ->addCustomer($customerData['name'], $customerData['email'], $customerData['taxDocument'])
            ->addItem('Assinatura mensal', 9900, 1)
            ->setAvailablePaymentMethods([PaymentMethod::CREDIT_CARD])
            ->addCreditCardToken('pm_card_visa')
            ->create();

        $this->assertEquals(InvoiceStatus::PAID, $invoice->status);

        $refund = MultiPayment::setGateway($gateway)->refundInvoice($invoice->id);
        $this->assertStringStartsWith('re_', $refund->id);
        $this->assertEquals(9900, $refund->amount);
        $this->assertContains($refund->status, [RefundStatus::PENDING, RefundStatus::SUCCEEDED]);

        $invoiceRefunded = $refund->invoice();
        $this->assertEquals(InvoiceStatus::REFUNDED, $invoiceRefunded->status);
        $this->assertEquals(9900, $invoiceRefunded->refundedAmount);
        $this->assertCount(1, $invoiceRefunded->refunds);
        $this->assertEquals($refund->id, $invoiceRefunded->refunds[0]->id);
    }

    /**
     * Deve estornar parcialmente uma fatura pix paga.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldRefundPixInvoicePartially($gateway)
    {
        $customerData = self::customerWithoutAddress();
        $invoice = MultiPayment::setGateway($gateway)->newInvoice()
            ->addCustomer($customerData['name'], 'succeed_immediately@example.com', $customerData['taxDocument'])
            ->addItem('Assinatura mensal', 12345, 1)
            ->setAvailablePaymentMethods([PaymentMethod::PIX])
            ->create();

        $invoicePaid = $this->waitForInvoiceCondition($gateway, $invoice->id, function (Invoice $fetched) {
            return $fetched->status === InvoiceStatus::PAID;
        });
        $this->assertEquals(InvoiceStatus::PAID, $invoicePaid->status);

        $refund = MultiPayment::setGateway($gateway)->refundInvoice($invoice->id, 2345);
        $this->assertEquals(2345, $refund->amount);

        $invoiceRefunded = $refund->invoice();
        $this->assertEquals(InvoiceStatus::PARTIALLY_REFUNDED, $invoiceRefunded->status);
        $this->assertEquals(2345, $invoiceRefunded->refundedAmount);
        $this->assertEquals(12345, $invoiceRefunded->paidAmount);
        $this->assertCount(1, $invoiceRefunded->refunds);
        $this->assertEquals($refund->id, $invoiceRefunded->refunds[0]->id);

        // segundo estorno acima do restante é recusado sem chamar a Stripe
        try {
            MultiPayment::setGateway($gateway)->refundInvoice($invoice->id, 10001);
            $this->fail('Esperava RefundNotSupportedException');
        } catch (RefundNotSupportedException $e) {
            $this->assertSame(RefundNotSupportedException::REASON_AMOUNT_EXCEEDS_REFUNDABLE, $e->reason);
        }
        $this->assertEquals(2345, MultiPayment::setGateway($gateway)->getInvoice($invoice->id)->refundedAmount);
    }

    /**
     * Deve duplicar uma fatura pix pendente com nova expiração, cancelando a original.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldDuplicatePendingPixInvoice($gateway)
    {
        $customerData = self::customerWithoutAddress();
        $invoice = MultiPayment::setGateway($gateway)->newInvoice()
            ->addCustomer($customerData['name'], $customerData['email'], $customerData['taxDocument'])
            ->addItem('Assinatura mensal', 5000, 1)
            ->setAvailablePaymentMethods([PaymentMethod::PIX])
            ->setPixExpiresAt(\Carbon\Carbon::now()->addHour())
            ->create();

        $this->assertEquals(InvoiceStatus::PENDING, $invoice->status);

        $newExpiresAt = \Carbon\Carbon::now()->addDays(2);
        $invoiceDuplicated = MultiPayment::setGateway($gateway)
            ->duplicateInvoice($invoice->id, $newExpiresAt);

        $this->assertNotEquals($invoice->id, $invoiceDuplicated->id);
        $this->assertEquals(InvoiceStatus::PENDING, $invoiceDuplicated->status);
        $this->assertEquals(5000, $invoiceDuplicated->amount);
        $this->assertNotNull($invoiceDuplicated->pix->qrCodeText);
        $this->assertEqualsWithDelta(
            $newExpiresAt->getTimestamp(),
            $invoiceDuplicated->pixExpiresAt->getTimestamp(),
            60
        );
        $this->assertEquals($invoice->customer->id, $invoiceDuplicated->customer->id);

        $originalFetched = MultiPayment::setGateway($gateway)->getInvoice($invoice->id);
        $this->assertEquals(InvoiceStatus::CANCELED, $originalFetched->status);
    }

    /**
     * Fatura de assinatura (objeto Invoice da Stripe) lida por `getInvoice()` com o id `in_`,
     * recusada na duplicação e cancelada por `void`. O Invoice é criado direto no SDK porque a
     * lib ainda não cria fatura nem assinatura no Stripe.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldReadAndVoidAStripeInvoiceByItsId($gateway)
    {
        $customer = $this->createCustomer($gateway, self::customerWithoutAddress());
        $stripeInvoiceId = $this->createOpenStripeInvoice($customer->id);

        $invoice = MultiPayment::setGateway($gateway)->getInvoice($stripeInvoiceId);

        $this->assertSame($stripeInvoiceId, $invoice->id);
        $this->assertSame(InvoiceOriginType::INVOICE, $invoice->originType);
        $this->assertSame(InvoiceStatus::PENDING, $invoice->status);
        $this->assertSame(12345, $invoice->amount);
        $this->assertNull($invoice->paidAmount);
        $this->assertSame($customer->id, $invoice->customer->id);
        $this->assertSame('Assinatura mensal', $invoice->items[0]->description);
        $this->assertSame(12345, $invoice->items[0]->price);
        $this->assertStringStartsWith('https://invoice.stripe.com/', $invoice->url);
        $this->assertInstanceOf(\Stripe\Invoice::class, $invoice->original);

        try {
            MultiPayment::setGateway($gateway)->duplicateInvoice($stripeInvoiceId, Carbon::now()->addDay());
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::INVOICE_DUPLICATION, $e->capability);
        }

        $canceled = MultiPayment::setGateway($gateway)->cancelInvoice($stripeInvoiceId);
        $this->assertSame(InvoiceStatus::CANCELED, $canceled->status);
        $this->assertSame(InvoiceOriginType::INVOICE, $canceled->originType);
        $this->assertSame('void', $canceled->original->status);

        $this->assertSame(InvoiceStatus::CANCELED, MultiPayment::setGateway($gateway)->getInvoice($stripeInvoiceId)->status);
    }

    /**
     * Fatura de assinatura paga: o charge do PaymentIntent, lido num GET à parte, alimenta
     * valor pago, taxa e cartão.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldReadAPaidStripeInvoiceWithItsCharge($gateway)
    {
        $customer = $this->createCustomer($gateway, self::customerWithoutAddress());
        $stripeInvoiceId = $this->createOpenStripeInvoice($customer->id);

        $client = $this->stripeClient();
        $paymentMethod = $client->paymentMethods->attach('pm_card_visa', ['customer' => $customer->id]);
        $client->invoices->pay($stripeInvoiceId, ['payment_method' => $paymentMethod->id]);

        $invoice = MultiPayment::setGateway($gateway)->getInvoice($stripeInvoiceId);

        $this->assertSame(InvoiceStatus::PAID, $invoice->status);
        $this->assertSame(InvoiceOriginType::INVOICE, $invoice->originType);
        $this->assertSame(12345, $invoice->paidAmount);
        $this->assertSame(0, $invoice->refundedAmount);
        $this->assertSame([], $invoice->refunds);
        $this->assertNotNull($invoice->paidAt);
        $this->assertSame(PaymentMethod::CREDIT_CARD, $invoice->paymentMethod);
        $this->assertSame('visa', $invoice->creditCard->brand);
        $this->assertSame('4242', $invoice->creditCard->lastDigits);
    }

    /**
     * Cria um Invoice `open` de 12345 centavos na sandbox, direto no SDK.
     *
     * @param  string  $customerId
     * @return string  id do Invoice (`in_`)
     */
    private function createOpenStripeInvoice(string $customerId): string
    {
        $client = $this->stripeClient();
        $stripeInvoice = $client->invoices->create([
            'customer' => $customerId,
            'collection_method' => 'charge_automatically',
            'currency' => 'brl',
            'auto_advance' => false,
        ]);
        $client->invoiceItems->create([
            'customer' => $customerId,
            'invoice' => $stripeInvoice->id,
            'amount' => 12345,
            'currency' => 'brl',
            'description' => 'Assinatura mensal',
        ]);
        $client->invoices->finalizeInvoice($stripeInvoice->id, ['auto_advance' => false]);

        return $stripeInvoice->id;
    }

    private function stripeClient(): StripeClient
    {
        return new StripeClient([
            'api_key' => Config::get('multi-payment.gateways.stripe.api_key'),
            'stripe_version' => StripeGateway::STRIPE_API_VERSION,
        ]);
    }

    /**
     * Boleto está fora do escopo do gateway Stripe e deve falhar com mensagem específica.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldRejectBankSlipInvoice($gateway)
    {
        $customerData = self::customerWithoutAddress();
        $invoiceBuilder = MultiPayment::setGateway($gateway)->newInvoice()
            ->addCustomer(
                $customerData['name'],
                $customerData['email'],
                $customerData['taxDocument']
            )
            ->addItem('Assinatura mensal', 9900, 1)
            ->setAvailablePaymentMethods([PaymentMethod::BANK_SLIP]);

        try {
            $invoiceBuilder->create();
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::BANK_SLIP, $e->capability);
            $this->assertSame(UnsupportedOperationException::REASON_NOT_IMPLEMENTED, $e->reason);
        }
    }

    /**
     * Sem `pixExpiresAt`, o QR Code expira no fim do dia de `dueDate`, e a Stripe aceita um
     * vencimento de hoje.
     *
     * @param  string  $gateway
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldCreateAPixInvoiceExpiringAtTheEndOfTheDueDate(string $gateway): void
    {
        $customerData = self::customerWithoutAddress();
        $invoice = MultiPayment::setGateway($gateway)->newInvoice()
            ->addCustomer($customerData['name'], $customerData['email'], $customerData['taxDocument'])
            ->addItem('Assinatura mensal', 5000, 1)
            ->setPaymentMethod(PaymentMethod::PIX)
            ->setDueDate(\Carbon\Carbon::today())
            ->create();

        $this->assertEquals(InvoiceStatus::PENDING, $invoice->status);
        $this->assertEquals(PaymentMethod::PIX, $invoice->paymentMethod);
        $this->assertEqualsWithDelta(
            \Carbon\Carbon::today()->endOfDay()->getTimestamp(),
            $invoice->pixExpiresAt->getTimestamp(),
            60
        );
        $this->assertSame(\Carbon\Carbon::today()->format('Y-m-d'), $invoice->dueDate->format('Y-m-d'));

        MultiPayment::setGateway($gateway)->cancelInvoice($invoice->id);
    }
}
