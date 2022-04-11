<?php

namespace Potelo\MultiPayment\Tests\Feature;

use Potelo\MultiPayment\Tests\TestCase;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Facades\MultiPayment;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;

class FallbackTest extends TestCase
{

    /**
     * Test if fallback is used when a gateway is not available
     *
     * @dataProvider gatewaysDataProvider
     *
     * @return void
     * @throws GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     */
    public function testShouldUseFallbackWhenWrongCredencials($data)
    {
        $this->app['config']->set('multi-payment.fallback', true);
        $this->app['config']->set("multi-payment.gateways.{$data['gateway']}.api_key", null);

        $invoiceBuilder = MultiPayment::setGateway($data['gateway'])->newInvoice();
        $invoice = $invoiceBuilder->addCustomer('João Maria', 'joaomaria@email.com', '20176996915')
            ->addCustomerAddress(
                '41820330',
                'Rua Exemplo',
                '123',
                'Apto. 123',
                'Bairro Exemplo',
                'São Paulo',
                'SP',
                'Brasil'
            )
            ->addItem('O nome do item', '10000', '1')
            ->create();
        $this->assertTrue($invoice->gateway == $data['fallback']);
    }

    /**
     * Test if fallback is used when a gateway is not available
     *
     * @dataProvider gatewaysDataProvider
     *
     * @return void
     * @throws GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     */
    public function testShouldUseFallbackWithCorrectCreditCardToken($data)
    {
        $tokens = [
            'moip' => 'CmDt2IP+4s9GDnL9xlc5TZSw8vcA3BCNrTg/7kgWtcp6TSERmRZxxlg9pMrVeoGxAVz8WtceZtKEL1GJfDcJJMYFkSqapxRThFrtT36BX5ZIy9hMl3IhvLULjDYgz6Ax3v1pUY3dudqaT9jQQJtdjasTbYgwxw11HNqBBOGZRhJgpNB1IRXCb1z40pL3VTB+Ox2fvd1MlC/ZVdgmGpcZ00+GI8MKhvHPSbIRn6Qk2BnDqis4NdrFDARZheIyAo1ABKyXaEFKgLURDEdqtplXzN7ycQ/EPJDVoPsBoOub0vlzLYk0NaWmIFPvUdd6/tYXvqSu/aEyJe3Yaj9PrZUxZQ==',
            'iugu' => self::iuguCreditCardToken(),
        ];

        $this->app['config']->set('multi-payment.fallback', true);
        $this->app['config']->set("multi-payment.gateways.{$data['gateway']}.api_key", null);

        $invoiceBuilder = MultiPayment::setGateway($data['gateway'])->newInvoice();
        $invoice = $invoiceBuilder->addCustomer('João Maria', 'joaomaria@email.com', '20176996915')
            ->addCustomerAddress(
                '41820330',
                'Rua Exemplo',
                '123',
                'Apto. 123',
                'Bairro Exemplo',
                'São Paulo',
                'SP',
                'Brasil'
            )
            ->addItem('O nome do item', '10000', '1')
            ->addCreditCardTokens($tokens)
            ->setPaymentMethod(Invoice::PAYMENT_METHOD_CREDIT_CARD)
            ->create();
        $this->assertTrue($invoice->gateway == $data['fallback']);
    }

    /**
     * Test if fallback is not used when an invalid card is used
     * @dataProvider gatewaysDataProvider
     *
     * @return void
     * @throws GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     */
    public function testShouldNotUseFallbackWhenInvalidCardNumber($data)
    {
        $this->app['config']->set('multi-payment.fallback', true);
        $this->expectException(GatewayException::class);

        MultiPayment::setGateway($data['gateway'])->newInvoice()
            ->addCustomer('João Maria', 'joaomaria@email.com', '20176996915')
            ->setPaymentMethod(Invoice::PAYMENT_METHOD_CREDIT_CARD)
            ->addCreditCard('1234567890123456', '12', '2030', '123', 'A', 'W')
            ->addCustomerAddress(
                '41820330',
                'Rua Exemplo',
                '123',
                'Apto. 123',
                'Bairro Exemplo',
                'São Paulo',
                'SP',
                'Brasil'
            )
            ->addItem('O nome do item', '10000', '1')
            ->create();
    }

    /**
     * Test if the fallback is not used when the gateway is not available
     *
     * @dataProvider gatewaysDataProvider
     *
     * @return void
     * @throws GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     */
    public function testShouldNotUseFallbackWhenDisabled($data)
    {
        $this->app['config']->set('multi-payment.fallback', false);
        $this->app['config']->set("multi-payment.gateways.{$data['gateway']}.api_key", null);

        $invoiceBuilder = MultiPayment::setGateway($data['gateway'])->newInvoice();
        $this->expectException(GatewayNotAvailableException::class);
        $invoiceBuilder->addCustomer('João Maria', 'joaomaria@email.com', '20176996915')
            ->addCustomerAddress(
                '41820330',
                'Rua Exemplo',
                '123',
                'Apto. 123',
                'Bairro Exemplo',
                'São Paulo',
                'SP',
                'Brasil'
            )
            ->addItem('O nome do item', '10000', '1')
            ->create();
    }

    public function gatewaysDataProvider() {
        return [
            'iugu to moip' => [['gateway' => 'iugu', 'fallback' => 'moip']],
            'moip to iugu' => [['gateway' => 'moip', 'fallback' => 'iugu']],
        ];
    }
}