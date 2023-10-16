<?php

namespace Potelo\MultiPayment\Tests\Unit\Builders;

use Carbon\Carbon;
use Potelo\MultiPayment\Tests\TestCase;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Exceptions\ChargingException;

class InvoiceBuilderTest extends TestCase
{

    /**
     * Create a invoice with mocked data
     *
     * @param  string  $gateway
     * @param  array  $data
     *
     * @return \Potelo\MultiPayment\Models\Invoice
     */
    private function createInvoice(string $gateway, array $data): Invoice
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
            $invoiceBuilder->addItem($item['description'], $item['price'], $item['quantity']);
        }
        if (isset($data['dueAt'])) {
            $invoiceBuilder->setDueAt($data['dueAt']);
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

        if (isset($data['customVariables'])) {
            foreach ($data['customVariables'] as $key => $value) {
                $invoiceBuilder->addCustomVariable($key, $value);
            }
        }

        return $invoiceBuilder->create();
    }

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
        $invoice = $this->createInvoice($gateway, $data);
        $this->assertInstanceOf(\Potelo\MultiPayment\Models\Invoice::class, $invoice);
        $this->assertNotEmpty($invoice->id);
        $this->assertNotEmpty($invoice->status);

        $this->assertEquals($data['customer']['name'], $invoice->customer->name);
        $this->assertEquals($data['customer']['email'], $invoice->customer->email);
        $this->assertEquals($data['customer']['taxDocument'], $invoice->customer->taxDocument);
        $this->assertEquals($data['customer']['birthDate'], $invoice->customer->birthDate);
        $this->assertEquals($data['customer']['phoneArea'], $invoice->customer->phoneArea);
        $this->assertEquals($data['customer']['phoneNumber'], $invoice->customer->phoneNumber);

        if (isset($data['customer']['address'])) {
            $this->assertEquals($data['customer']['address']['zipCode'], $invoice->customer->address->zipCode);
            $this->assertEquals($data['customer']['address']['street'], $invoice->customer->address->street);
            $this->assertEquals($data['customer']['address']['number'], $invoice->customer->address->number);
            $this->assertEquals($data['customer']['address']['complement'], $invoice->customer->address->complement);
            $this->assertEquals($data['customer']['address']['district'], $invoice->customer->address->district);
            $this->assertEquals($data['customer']['address']['city'], $invoice->customer->address->city);
            $this->assertEquals($data['customer']['address']['state'], $invoice->customer->address->state);
            $this->assertEquals($data['customer']['address']['country'], $invoice->customer->address->country);
        }

        foreach ($data['items'] as $key => $item) {
            $this->assertEquals($item['description'], $invoice->items[$key]->description);
            $this->assertEquals($item['price'], $invoice->items[$key]->price);
            $this->assertEquals($item['quantity'], $invoice->items[$key]->quantity);
        }

        if (isset($data['dueAt'])) {
            $this->assertEquals($data['dueAt'], $invoice->dueAt->format('Y-m-d'));
        }

        if (isset($data['paymentMethod'])) {
            $this->assertEquals($data['paymentMethod'], $invoice->paymentMethod);
        }

        if (isset($data['creditCard'])) {
            $this->assertEquals($data['creditCard']['number'], $invoice->creditCard->number);
            $this->assertEquals($data['creditCard']['month'], $invoice->creditCard->month);
            $this->assertEquals($data['creditCard']['year'], $invoice->creditCard->year);
            $this->assertEquals($data['creditCard']['cvv'], $invoice->creditCard->cvv);
            $this->assertEquals($data['creditCard']['firstName'], $invoice->creditCard->firstName);
            $this->assertEquals($data['creditCard']['lastName'], $invoice->creditCard->lastName);
            $this->assertNotEmpty($invoice->creditCard->token);
            $this->assertNotEmpty($invoice->creditCard->id);
        }

        if (isset($data['customVariables'])) {
            foreach ($invoice->customVariables as $customVariable) {
                $this->assertArrayHasKey($customVariable->name, $data['customVariables']);
                $this->assertEquals($data['customVariables'][$customVariable->name], $customVariable->value);
            }
        }

        // Verifica se a fatura foi criada no gateway com os dados corretos
        $invoice = $invoice->get($invoice->id, $gateway);

        $this->assertNotEmpty($invoice->status);

        $this->assertEquals($data['customer']['name'], $invoice->customer->name);
        $this->assertEquals($data['customer']['email'], $invoice->customer->email);
        $this->assertEquals($data['customer']['phoneArea'], $invoice->customer->phoneArea);
        $this->assertEquals($data['customer']['phoneNumber'], $invoice->customer->phoneNumber);

        if (isset($data['customVariables'])) {
            foreach ($invoice->customVariables as $customVariable) {
                $this->assertArrayHasKey($customVariable->name, $data['customVariables']);
                $this->assertEquals($data['customVariables'][$customVariable->name], $customVariable->value);
            }
        }

        foreach ($data['items'] as $key => $item) {
            $this->assertEquals($item['description'], $invoice->items[$key]->description);
            $this->assertEquals($item['price'], $invoice->items[$key]->price);
            $this->assertEquals($item['quantity'], $invoice->items[$key]->quantity);
        }

        if (isset($data['dueAt'])) {
            $this->assertEquals($data['dueAt'], $invoice->dueAt->format('Y-m-d'));
        }

        if (isset($data['paymentMethod']) && $invoice->status === $invoice::STATUS_PAID) {
            $this->assertEquals($data['paymentMethod'], $invoice->paymentMethod);
        }

        if (isset($data['customer']['address'])) {
            $this->assertEquals($data['customer']['address']['zipCode'], $invoice->customer->address->zipCode);
            $this->assertEquals($data['customer']['address']['street'], $invoice->customer->address->street);
            $this->assertEquals($data['customer']['address']['number'], $invoice->customer->address->number);
            $this->assertEquals($data['customer']['address']['complement'], $invoice->customer->address->complement);
            $this->assertEquals($data['customer']['address']['district'], $invoice->customer->address->district);
            $this->assertEquals($data['customer']['address']['city'], $invoice->customer->address->city);
            $this->assertEquals($data['customer']['address']['state'], $invoice->customer->address->state);
            $this->assertEquals($data['customer']['address']['country'], $invoice->customer->address->country);
        }
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
                    'dueAt' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithAddress(),
                ]
            ],
            'iugu - without payment method - with custom variable' => [
                'gateway' => 'iugu',
                'data' => [
                    'dueAt' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithAddress(),
                    'customVariables' => [
                        'custom_variable_1' => 'value_1',
                        'custom_variable_2' => 'value_2',
                    ]
                ]
            ],
            'iugu - company with address without payment method' => [
                'gateway' => 'iugu',
                'data' => [
                    'dueAt' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::companyWithAddress(),
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
            'iugu - credit card with address' => [
                'gateway' => 'iugu',
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
                    'dueAt' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithAddress(),
                    'paymentMethod' => 'bank_slip',
                ]
            ],
            'iugu - pix with address' => [
                'gateway' => 'iugu',
                'data' => [
                    'dueAt' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithAddress(),
                    'paymentMethod' => 'pix',
                ]
            ],
            'iugu - pix without address' => [
                'gateway' => 'iugu',
                'data' => [
                    'dueAt' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithoutAddress(),
                    'paymentMethod' => 'pix',
                ]
            ],
        ];
    }

    /**
     * Fail to create invoice test.
     *
     * @dataProvider shouldNotCreateInvoiceDataProvider
     *
     * @param  string  $gateway
     * @param  array  $data
     *
     * @return void
     */
    public function testShouldNotCreateInvoice(string $gateway, array $data): void
    {
        $this->expectException(ChargingException::class);
        $this->createInvoice($gateway, $data);
    }

    public function shouldNotCreateInvoiceDataProvider(): array
    {
        return [
            'iugu - credit card - charge fail' => [
                'gateway' => 'iugu',
                'data' => [
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithAddress(),
                    'paymentMethod' => 'credit_card',
                    'creditCard' => array_merge(self::creditCard(), ['number' => '4012888888881881']),
                ],
            ],
        ];
    }

    public static function customerWithAddress(): array
    {
        $customer = self::customerWithoutAddress();
        $customer['address'] = self::address();
        return $customer;
    }

    public static function address(): array
    {
        $address['zipCode'] = '41820330';
        $address['street'] = 'Rua Deputado Mário Lima';
        $address['number'] = '123';
        $address['district'] = 'Caminho das Arvores';
        $address['complement'] = 'Apto. 123';
        $address['city'] = 'Salvador';
        $address['state'] = 'BA';
        $address['country'] = 'Brasil';
        return $address;
    }

    public static function creditCard(): array
    {
        $creditCard['number'] = '4111111111111111';
        $creditCard['month'] = '12';
        $creditCard['year'] = \Carbon\Carbon::now()->addYear()->format('Y');
        $creditCard['cvv'] = '123';
        $creditCard['firstName'] = 'Faker';
        $creditCard['lastName'] = 'Teste';
        return $creditCard;
    }
}