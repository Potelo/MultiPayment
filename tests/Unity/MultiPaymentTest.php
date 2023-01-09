<?php

namespace Potelo\MultiPayment\Tests\Unity;

use Potelo\MultiPayment\Tests\TestCase;
use Potelo\MultiPayment\Models\Invoice;

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
//            'iugu' => ['iugu', '4DAF50DDAA1E461CBA9ECF813000FC0B'],
            'moip' => ['moip', 'ORD-TC1BMFF78KBU'],
        ];
    }

    /**
     * Test if thorws an exception when not find the invoice
     *
     * @dataProvider shouldNotGetInvoiceDataProvider
     *
     * @param $gateway
     * @param $id
     *
     * @return void
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function testShouldNotGetInvoice($gateway, $id)
    {
        $this->expectException(\Potelo\MultiPayment\Exceptions\GatewayException::class);
        $multiPayment = new \Potelo\MultiPayment\MultiPayment($gateway);
        $multiPayment->getInvoice($id);
    }

    /**
     * @return array
     */
    public function shouldNotGetInvoiceDataProvider(): array
    {
        return [
            'iugu' => ['iugu', '4DAF50DDAA1E461CBA9ECF813111FC0B'],
            'moip' => ['moip', 'ORD-MNPLI3PYQTOK'],
        ];
    }

    /**
     * Test if can refund the invoice
     *
     * @dataProvider shouldRefundInvoiceDataProvider
     *
     * @param  string  $gateway
     * @param  array  $data
     *
     * @return void
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     */
    public function testShouldRefundInvoice(string $gateway, array $data, string $status, ?int $refundedAmount)
    {
        $multiPayment = new \Potelo\MultiPayment\MultiPayment($gateway);

        $invoiceBuilder = $multiPayment->newInvoice();
        $invoiceBuilder->addCustomer(
            $data['customer']['name'] ?? null,
            $data['customer']['email'] ?? null,
            $data['customer']['taxDocument'] ?? null,
            $data['customer']['birthDate'] ?? null,
            $data['customer']['phoneArea'] ?? null,
            $data['customer']['phoneNumber'] ?? null
        );
        $total = 0;
        foreach ($data['items'] as $item) {
            $invoiceBuilder->addItem($item['description'], $item['price'], $item['quantity']);
            $total += $item['price'] * $item['quantity'];
        }
        $invoiceBuilder->setPaymentMethod($data['paymentMethod']);
        $invoiceBuilder->addCreditCard(
            $data['creditCard']['number'] ?? null,
            $data['creditCard']['month'] ?? null,
            $data['creditCard']['year'] ?? null,
            $data['creditCard']['cvv'] ?? null,
            $data['creditCard']['firstName'] ?? null,
            $data['creditCard']['lastName'] ?? null

        );
        $invoice = $invoiceBuilder->create();
        sleep(3); // Aguarda a criação da fatura no gateway (moip)

        $refundedInvoice = $multiPayment->refundInvoice($invoice->id, $refundedAmount);

        if (is_null($refundedAmount)) {
            $refundedAmount = $total;
        }
        $this->assertEquals($status, $refundedInvoice->status);
        $this->assertEquals($refundedAmount, $refundedInvoice->refundedAmount);
        $this->assertEquals($total - $refundedAmount, $refundedInvoice->paidAmount);
    }

    /**
     * @return array
     */
    public function shouldRefundInvoiceDataProvider(): array
    {
        return [
            'iugu - credit card - full refund' => [
                'gateway' => 'iugu',
                'data' => [
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithoutAddress(),
                    'paymentMethod' => 'credit_card',
                    'creditCard' => self::creditCard(),
                ],
                'status' => Invoice::STATUS_REFUNDED,
                'refundedAmount' => null,
            ],
            'moip - credit card - full refund' => [
                'gateway' => 'moip',
                'data' => [
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithoutAddress(),
                    'paymentMethod' => 'credit_card',
                    'creditCard' => self::creditCard(),
                ],
                'status' => Invoice::STATUS_REFUNDED,
                'refundedAmount' => null,
            ],
            'iugu - credit card - partial refund' => [
                'gateway' => 'iugu',
                'data' => [
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithoutAddress(),
                    'paymentMethod' => 'credit_card',
                    'creditCard' => self::creditCard(),
                ],
                'status' => Invoice::STATUS_PARTIALLY_REFUNDED,
                'refundedAmount' => 5000,
            ],
            'moip - credit card - partial refund' => [
                'gateway' => 'moip',
                'data' => [
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithoutAddress(),
                    'paymentMethod' => 'credit_card',
                    'creditCard' => self::creditCard(),
                ],
                'status' => Invoice::STATUS_PARTIALLY_REFUNDED,
                'refundedAmount' => 5000,
            ],
        ];
    }
}