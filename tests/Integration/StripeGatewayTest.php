<?php

namespace Potelo\MultiPayment\Tests\Integration;

use Potelo\MultiPayment\Tests\TestCase;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Facades\MultiPayment;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\ChargingException;
use PHPUnit\Framework\Attributes\DataProvider;
use Potelo\MultiPayment\Enums\InvoiceStatus;
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
            $this->assertEquals('card_declined', $exception->reason);
            $this->assertNotEmpty($exception->chargeResponse);
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
            ->setExpiresAt(\Carbon\Carbon::now()->addHour())
            ->create();

        $this->assertNotNull($invoice->id);
        $this->assertEquals(InvoiceStatus::PENDING, $invoice->status);
        $this->assertEquals(PaymentMethod::PIX, $invoice->paymentMethod);
        $this->assertNotNull($invoice->pix);
        $this->assertNotNull($invoice->pix->qrCodeText);
        $this->assertNotNull($invoice->pix->qrCodeImageUrl);
        $this->assertNotNull($invoice->url);
        $this->assertNotNull($invoice->expiresAt);

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

        $invoiceRefunded = MultiPayment::setGateway($gateway)->refundInvoice($invoice->id);
        $this->assertEquals(InvoiceStatus::REFUNDED, $invoiceRefunded->status);
        $this->assertEquals(9900, $invoiceRefunded->refundedAmount);
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

        $invoiceRefunded = MultiPayment::setGateway($gateway)->refundInvoice($invoice->id, 2345);
        $this->assertEquals(InvoiceStatus::PARTIALLY_REFUNDED, $invoiceRefunded->status);
        $this->assertEquals(2345, $invoiceRefunded->refundedAmount);
        $this->assertEquals(12345, $invoiceRefunded->paidAmount);
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
            ->setExpiresAt(\Carbon\Carbon::now()->addHour())
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
            $invoiceDuplicated->expiresAt->getTimestamp(),
            60
        );
        $this->assertEquals($invoice->customer->id, $invoiceDuplicated->customer->id);

        $originalFetched = MultiPayment::setGateway($gateway)->getInvoice($invoice->id);
        $this->assertEquals(InvoiceStatus::CANCELED, $originalFetched->status);
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

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('[createInvoice com boleto] no Stripe ainda não está implementada nesta lib');

        $invoiceBuilder->create();
    }
}
