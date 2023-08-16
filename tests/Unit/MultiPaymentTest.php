<?php

namespace Potelo\MultiPayment\Tests\Unit;

use Potelo\MultiPayment\Tests\TestCase;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Facades\MultiPayment;

class MultiPaymentTest extends TestCase
{

    /**
     * Test if can get the invoice by id
     *
     * @return void
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function testShouldGetInvoice()
    {
        $gateway = 'iugu';
        $invoice = MultiPayment::setGateway($gateway)->newInvoice()
            ->setPaymentMethod(Invoice::PAYMENT_METHOD_CREDIT_CARD)
            ->addCustomer('Fake Customer', 'email@exemplo.com', '20176996915')
            ->addItem('teste', 1000, 1)
            ->addCreditCardToken(self::iuguCreditCardToken())
            ->create();

        $multiPayment = new \Potelo\MultiPayment\MultiPayment($gateway);
        $invoiceFetched = $multiPayment->getInvoice($invoice->id);
        $this->assertEquals($invoiceFetched->id, $invoice->id);
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
        sleep(3);

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
        ];
    }
}