<?php

namespace Potelo\MultiPayment\Tests\Unity\Builders;

use Carbon\Carbon;
use Potelo\MultiPayment\Tests\TestCase;
use Potelo\MultiPayment\Facades\MultiPayment;

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
     * @throws \Potelo\MultiPayment\Exceptions\GatewayNotAvailableException
     */
    public function testShouldCreateInvoice(string $gateway, array $data): void
    {
        $invoiceBuilder = MultiPayment::setGateway($gateway)->newInvoice();
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
        if (isset($data['expiresAt'])) {
            $invoiceBuilder->setExpiresAt($data['expiresAt']);
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
        $this->assertEquals($gateway, $invoice->gateway);
        $this->assertInstanceOf(\Potelo\MultiPayment\Models\Invoice::class, $invoice);
        $this->assertObjectHasAttribute('id', $invoice);
    }

    /**
     * @return array[]
     */
    public function shouldCreateInvoiceDataProvider(): array
    {
        return [
            'iugu - without payment method' => [
                'gateway' => 'iugu',
                'data' => [
                    'expiresAt' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithAddress(),
                ]
            ],
            'moip - without payment method' => [
                'gateway' => 'moip',
                'data' => [
                    'expiresAt' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithAddress(),
                ]
            ],
            'iugu - company with address without payment method' => [
                'gateway' => 'iugu',
                'data' => [
                    'expiresAt' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::companyWithAddress(),
                ]
            ],
            'moip - company with address without payment method' => [
                'gateway' => 'moip',
                'data' => [
                    'expiresAt' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::companyWithAddress(),
                ]
            ],
            'moip - without payment method and without address' => [
                'gateway' => 'moip',
                'data' => [
                    'expiresAt' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithoutAddress(),
                ]
            ],
            'iugu - credit card without address' => [
                'gateway' => 'iugu',
                'data' => [
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithoutAddress(),
                    'paymentMethod' => 'credit_card',
                    'creditCard' => self::creditCard(),
                ]
            ],
            'moip - credit card without address' => [
                'gateway' => 'moip',
                'data' => [
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithoutAddress(),
                    'paymentMethod' => 'credit_card',
                    'creditCard' => self::creditCard(),
                ]
            ],
            'iugu - credit card with address' => [
                'gateway' => 'iugu',
                'data' => [
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithAddress(),
                    'paymentMethod' => 'credit_card',
                    'creditCard' => self::creditCard(),
                ]
            ],
            'moip - credit card with address' => [
                'gateway' => 'moip',
                'data' => [
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithAddress(),
                    'paymentMethod' => 'credit_card',
                    'creditCard' => self::creditCard(),
                ]
            ],
            'iugu - bank slip with address' => [
                'gateway' => 'iugu',
                'data' => [
                    'expiresAt' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithAddress(),
                    'paymentMethod' => 'bank_slip',
                ]
            ],
            'moip - bank slip with address' => [
                'gateway' => 'moip',
                'data' => [
                    'expiresAt' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithAddress(),
                    'paymentMethod' => 'bank_slip',
                ]
            ],
            'iugu - pix with address' => [
                'gateway' => 'iugu',
                'data' => [
                    'expiresAt' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithAddress(),
                    'paymentMethod' => 'pix',
                ]
            ],
            'iugu - pix without address' => [
                'gateway' => 'iugu',
                'data' => [
                    'expiresAt' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithoutAddress(),
                    'paymentMethod' => 'pix',
                ]
            ],
        ];
    }
}