<?php

namespace Potelo\MultiPayment\Tests\Integration;

use Potelo\MultiPayment\Tests\TestCase;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Facades\MultiPayment;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\ChargingException;

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
     * @dataProvider stripeGatewayDataProvider
     *
     * @return void
     */
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
            ->setAvailablePaymentMethods([Invoice::PAYMENT_METHOD_CREDIT_CARD])
            ->addCreditCardToken('pm_card_visa')
            ->create();

        $this->assertNotNull($invoice->id);
        $this->assertEquals(Invoice::STATUS_PAID, $invoice->status);
        $this->assertEquals(12345, $invoice->amount);
        $this->assertEquals(12345, $invoice->paidAmount);
        $this->assertEquals(Invoice::PAYMENT_METHOD_CREDIT_CARD, $invoice->paymentMethod);
        $this->assertEquals('4242', $invoice->creditCard->lastDigits);
        $this->assertNotNull($invoice->paidAt);
        $this->assertCount(1, $invoice->items);
        $this->assertEquals('Assinatura mensal', $invoice->items[0]->description);

        sleep(3); // a balance transaction (fee) do cartão é assíncrona logo após o confirm

        $invoiceFetched = MultiPayment::setGateway($gateway)->getInvoice($invoice->id);
        $this->assertEquals(Invoice::STATUS_PAID, $invoiceFetched->status);
        $this->assertEquals(12345, $invoiceFetched->paidAmount);
        $this->assertNotNull($invoiceFetched->fee);
        $this->assertEquals($invoice->id, $invoiceFetched->id);
    }

    /**
     * Recusa de cartão deve virar ChargingException com resposta bruta e razão normalizada.
     *
     * @dataProvider stripeGatewayDataProvider
     *
     * @return void
     */
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
            ->setAvailablePaymentMethods([Invoice::PAYMENT_METHOD_CREDIT_CARD])
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
     * @dataProvider stripeGatewayDataProvider
     *
     * @return void
     */
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
     * Boleto está fora do escopo do gateway Stripe e deve falhar com mensagem específica.
     *
     * @dataProvider stripeGatewayDataProvider
     *
     * @return void
     */
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
            ->setAvailablePaymentMethods([Invoice::PAYMENT_METHOD_BANK_SLIP]);

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('does not support bank slip');

        $invoiceBuilder->create();
    }
}
