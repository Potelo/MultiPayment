<?php

namespace Potelo\MultiPayment\Tests\Unity;

use Potelo\MultiPayment\Tests\TestCase;

class MultiPaymentTest extends TestCase
{

    /**
     * Test if can get the invoice by id
     *
     * @dataProvider shouldGetInvoiceDataProvider
     *
     * @param $gateway
     * @param $id
     *
     * @return void
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function testShouldGetInvoice($gateway, $id)
    {
        $multiPayment = new \Potelo\MultiPayment\MultiPayment($gateway);
        $invoice = $multiPayment->getInvoice($id);
        $this->assertEquals($id, $invoice->id);
    }

    /**
     * @return array
     */
    public function shouldGetInvoiceDataProvider(): array
    {
        return [
            'iugu' => ['iugu', '4DAF50DDAA1E461CBA9ECF813000FC0B'],
            'moip' => ['moip', 'ORD-MNPLI3PYQK0T'],
        ];
    }
}