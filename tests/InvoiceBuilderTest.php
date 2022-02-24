<?php

namespace Potelo\MultiPayment\Tests;

use Carbon\Carbon;
use Potelo\MultiPayment\Exceptions\MultiPaymentException;

class InvoiceBuilderTest extends TestCase
{

    /**
     * Create invoice test.
     *
     * @dataProvider shouldCreateInvoiceDataProvider
     *
     * @param  string  $gateway
     * @param  array  $data
     *
     * @return void
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     */
    public function testShouldCreateInvoice(string $gateway, array $data): void
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
        if (isset($data['customer']['address'])) {
            $invoiceBuilder->addCustomerAddress(
                $data['customer']['address']['zipCode'] ?? null,
                $data['customer']['address']['street'] ?? null,
                $data['customer']['address']['number'] ?? null,
                $data['customer']['address']['complement'] ?? null,
                $data['customer']['address']['district'] ?? null,
                $data['customer']['address']['city'] ?? null,
                $data['customer']['address']['state'] ?? null,
                $data['customer']['address']['country'] ?? null
            );
        }
        foreach ($data['items'] as $item) {
            $invoiceBuilder->addItem($item['description'], $item['quantity'], $item['price']);
        }
        if (isset($data['expirationDate'])) {
            $invoiceBuilder->setExpirationDate($data['expirationDate']);
        }
        if (isset($data['paymentMethod'])) {
            $invoiceBuilder->setPaymentMethod($data['paymentMethod']);
        }
        if (isset($data['creditCard'])) {
            $invoiceBuilder->addCreditCard(
                $data['creditCard']['number'] ?? null,
                $data['creditCard']['month'] ?? null,
                $data['creditCard']['year'] ?? null,
                $data['creditCard']['cvv'] ?? null,
                $data['creditCard']['firstName'] ?? null,
                $data['creditCard']['lastName'] ?? null

            );
        }
        $invoice = $invoiceBuilder->create();
        $this->assertInstanceOf(\Potelo\MultiPayment\Models\Invoice::class, $invoice);
        $this->assertObjectHasAttribute('id', $invoice);
    }

    /**
     * @return array[]
     */
    public function shouldCreateInvoiceDataProvider(): array
    {
        return [
//            'iugu - without payment method' => [
//                'gateway' => 'iugu',
//                'data' => [
//                    'expirationDate' => Carbon::now()->addWeekday()->format('Y-m-d'),
//                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
//                    'customer' => Faker::customerWithAddress(),
//                ]
//            ],
//            'moip - without payment method' => [
//                'gateway' => 'moip',
//                'data' => [
//                    'expirationDate' => Carbon::now()->addWeekday()->format('Y-m-d'),
//                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
//                    'customer' => Faker::customerWithAddress(),
//                ]
//            ],
//            'moip - without payment method and without address' => [
//                'gateway' => 'moip',
//                'data' => [
//                    'expirationDate' => Carbon::now()->addWeekday()->format('Y-m-d'),
//                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
//                    'customer' => Faker::customerWithoutAddress(),
//                ]
//            ],
//            'iugu - credit card without address' => [
//                'gateway' => 'iugu',
//                'data' => [
//                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
//                    'customer' => Faker::customerWithoutAddress(),
//                    'paymentMethod' => 'credit_card',
//                    'creditCard' => Faker::creditCard(),
//                ]
//            ],
//            'moip - credit card without address' => [
//                'gateway' => 'moip',
//                'data' => [
//                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
//                    'customer' => Faker::customerWithoutAddress(),
//                    'paymentMethod' => 'credit_card',
//                    'creditCard' => Faker::creditCard(),
//                ]
//            ],
//            'iugu - credit card with address' => [
//                'gateway' => 'iugu',
//                'data' => [
//                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
//                    'customer' => Faker::customerWithAddress(),
//                    'paymentMethod' => 'credit_card',
//                    'creditCard' => Faker::creditCard(),
//                ]
//            ],
//            'moip - credit card with address' => [
//                'gateway' => 'moip',
//                'data' => [
//                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
//                    'customer' => Faker::customerWithAddress(),
//                    'paymentMethod' => 'credit_card',
//                    'creditCard' => Faker::creditCard(),
//                ]
//            ],
//            'iugu - bank slip with address' => [
//                'gateway' => 'iugu',
//                'data' => [
//                    'expirationDate' => Carbon::now()->addWeekday()->format('Y-m-d'),
//                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
//                    'customer' => Faker::customerWithAddress(),
//                    'paymentMethod' => 'bank_slip',
//                ]
//            ],
//            'moip - bank slip with address' => [
//                'gateway' => 'moip',
//                'data' => [
//                    'expirationDate' => Carbon::now()->addWeekday()->format('Y-m-d'),
//                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
//                    'customer' => Faker::customerWithAddress(),
//                    'paymentMethod' => 'bank_slip',
//                ]
//            ],
//            'iugu - pix with address' => [
//                'gateway' => 'iugu',
//                'data' => [
//                    'expirationDate' => Carbon::now()->addWeekday()->format('Y-m-d'),
//                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
//                    'customer' => Faker::customerWithAddress(),
//                    'paymentMethod' => 'pix',
//                ]
//            ],
            'iugu - pix without address' => [
                'gateway' => 'iugu',
                'data' => [
                    'expirationDate' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
                    'customer' => Faker::customerWithoutAddress(),
                    'paymentMethod' => 'pix',
                ]
            ],
        ];
    }

    /**
     * Create invoice test.
     *
     * @dataProvider shouldNotCreateInvoiceDataProvider
     *
     * @param  string  $gateway
     * @param  array  $data
     *
     * @return void
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     */
    public function testShouldNotCreateInvoice(string $gateway, array $data): void
    {
        $this->expectException(MultiPaymentException::class);

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
        if (isset($data['customer']['address'])) {
            $invoiceBuilder->addCustomerAddress(
                $data['customer']['address']['zipCode'] ?? null,
                $data['customer']['address']['street'] ?? null,
                $data['customer']['address']['number'] ?? null,
                $data['customer']['address']['complement'] ?? null,
                $data['customer']['address']['district'] ?? null,
                $data['customer']['address']['city'] ?? null,
                $data['customer']['address']['state'] ?? null,
                $data['customer']['address']['country'] ?? null
            );
        }
        foreach ($data['items'] as $item) {
            $invoiceBuilder->addItem($item['description'], $item['quantity'], $item['price']);
        }
        if (isset($data['expirationDate'])) {
            $invoiceBuilder->setExpirationDate($data['expirationDate']);
        }
        if (isset($data['paymentMethod'])) {
            $invoiceBuilder->setPaymentMethod($data['paymentMethod']);
        }
        if (isset($data['creditCard'])) {
            $invoiceBuilder->addCreditCard(
                $data['creditCard']['number'] ?? null,
                $data['creditCard']['month'] ?? null,
                $data['creditCard']['year'] ?? null,
                $data['creditCard']['cvv'] ?? null,
                $data['creditCard']['firstName'] ?? null,
                $data['creditCard']['lastName'] ?? null

            );
        }
        $invoiceBuilder->create();
    }

    /**
     * @return array
     */
    public function shouldNotCreateInvoiceDataProvider(): array
    {
        return [
//            'iugu - bank_slip without address' => [
//                'gateway' => 'iugu',
//                'data' => [
//                    'expirationDate' => Carbon::now()->addWeekday()->format('Y-m-d'),
//                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
//                    'customer' => Faker::customerWithoutAddress(),
//                    'paymentMethod' => 'bank_slip',
//                ]
//            ],
//            'moip - bank_slip without address' => [
//                'gateway' => 'moip',
//                'data' => [
//                    'expirationDate' => Carbon::now()->addWeekday()->format('Y-m-d'),
//                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
//                    'customer' => Faker::customerWithoutAddress(),
//                    'paymentMethod' => 'bank_slip',
//                ]
//            ],
//            'moip - pix' => [
//                'gateway' => 'moip',
//                'data' => [
//                    'expirationDate' => Carbon::now()->addWeekday()->format('Y-m-d'),
//                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
//                    'customer' => Faker::customerWithAddress(),
//                    'paymentMethod' => 'pix',
//                ]
//            ],
            'iugu - without payment method and without address' => [
                'gateway' => 'iugu',
                'data' => [
                    'expirationDate' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
                    'customer' => Faker::customerWithoutAddress(),
                ]
            ],
        ];
    }
}