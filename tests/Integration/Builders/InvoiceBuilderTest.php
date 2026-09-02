<?php

namespace Potelo\MultiPayment\Tests\Integration\Builders;

use Carbon\Carbon;
use Potelo\MultiPayment\Tests\TestCase;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\AutomaticPix;
use Potelo\MultiPayment\Exceptions\ChargingException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

class InvoiceBuilderTest extends TestCase
{

    /**
     * A sandbox da Iugu rejeita a criação de faturas com Pix Automático. O
     * cenário permanece completo para ser reativado quando o recurso estiver
     * disponível no ambiente de testes.
     */
    #[Group('iugu-sandbox-limitation')]
    public function testShouldCreateAutomaticPixInvoice(): void
    {
        $this->markTestSkipped(
            'A sandbox da Iugu retorna que Pix Automático não está disponível no modo de teste.'
        );

        $reference = 'multipayment-' . Carbon::now()->format('YmdHis');
        $invoice = (new \Potelo\MultiPayment\MultiPayment('iugu'))->newInvoice()
            ->addAvailablePaymentMethod(Invoice::PAYMENT_METHOD_PIX)
            ->addCustomer(
                'Automatic Pix Sandbox',
                "{$reference}@example.com",
                '20176996915',
                null,
                '71',
                '982345678'
            )
            ->addItem('Automatic Pix sandbox test', 100, 1)
            ->setExpiresAt(Carbon::now()->addDays(2))
            ->addAutomaticPix(
                AutomaticPix::AUTHORIZATION_TYPE_QR_CODE_WITH_PAYMENT,
                AutomaticPix::FREQUENCY_MONTHLY,
                Carbon::now()->addDays(3),
                $reference,
                Carbon::now()->addYear(),
                AutomaticPix::RETRY_POLICY_ALLOWED
            )
            ->addAutomaticPixCharge('Automatic Pix sandbox test')
            ->create();

        $this->assertNotEmpty($invoice->id);
        $this->assertInstanceOf(AutomaticPix::class, $invoice->automaticPix);
        $this->assertNotEmpty($invoice->automaticPix->id);
        $this->assertSame($reference, $invoice->automaticPix->contractReference);
        $this->assertSame('iugu', $invoice->automaticPix->gateway);
        $this->assertNotNull($invoice->automaticPix->original);
    }

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
        if (isset($data['expiresAt'])) {
            $invoiceBuilder->setExpiresAt($data['expiresAt']);
        }
        if (isset($data['availablePaymentMethods'])) {
            $invoiceBuilder->setAvailablePaymentMethods($data['availablePaymentMethods']);
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

        if (isset($data['gatewayOptions'])) {
            $invoiceBuilder->setGatewayOptions($data['gatewayOptions']);
        }

        return $invoiceBuilder->create();
    }

    /**
     * Create invoice test.
     *
     * @param  string  $gateway
     * @param  array  $data
     *
     * @return void
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     */
    #[DataProvider('shouldCreateInvoiceDataProvider')]
    public function testShouldCreateInvoice(string $gateway, array $data): void
    {
        $invoice = $this->createInvoice($gateway, $data);
        $this->assertInstanceOf(Invoice::class, $invoice);
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
            // acentos podem ter sido removidos na criação
            $this->assertEquals(
                preg_replace('/[^p{L}p{N}s]/u', '', $data['customer']['address']['district']),
                preg_replace('/[^p{L}p{N}s]/u', '', $invoice->customer->address->district),
            );
            $this->assertEquals($data['customer']['address']['city'], $invoice->customer->address->city);
            $this->assertEquals($data['customer']['address']['state'], $invoice->customer->address->state);
            $this->assertEquals($data['customer']['address']['country'], $invoice->customer->address->country);
        }

        foreach ($data['items'] as $key => $item) {
            $this->assertEquals($item['description'], $invoice->items[$key]->description);
            $this->assertEquals($item['price'], $invoice->items[$key]->price);
            $this->assertEquals($item['quantity'], $invoice->items[$key]->quantity);
        }

        if (isset($data['expiresAt'])) {
            $this->assertEquals($data['expiresAt'], $invoice->expiresAt->format('Y-m-d'));
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
            $this->assertEquals(substr($data['creditCard']['number'], -4), $invoice->creditCard->lastDigits);
            $this->assertNotEmpty($invoice->creditCard->token);
            $this->assertNotEmpty($invoice->creditCard->id);
        }

        if ((isset($data['availablePaymentMethods']) && in_array('bank_slip', $data['availablePaymentMethods'])) || (isset($data['paymentMethod']) && $data['paymentMethod'] === 'bank_slip') || (isset($data['gatewayOptions']) && in_array('payable_with', $data['gatewayOptions']) && in_array('bank_slip', $data['gatewayOptions']['payable_with']))) {
            $this->assertNotEmpty($invoice->bankSlip);
            $this->assertNotEmpty($invoice->bankSlip->url);
            $this->assertNotEmpty($invoice->bankSlip->number);
            $this->assertNotEmpty($invoice->bankSlip->barcodeData);
            $this->assertNotEmpty($invoice->bankSlip->barcodeImage);
        }

        if ((isset($data['availablePaymentMethods']) && in_array('pix', $data['availablePaymentMethods'])) || (isset($data['paymentMethod']) && $data['paymentMethod'] === 'pix') || (isset($data['gatewayOptions']) && in_array('payable_with', $data['gatewayOptions']) && in_array('pix', $data['gatewayOptions']['payable_with']))) {

            $this->assertNotEmpty($invoice->pix);
            $this->assertNotEmpty($invoice->pix->qrCodeImageUrl);
            $this->assertNotEmpty($invoice->pix->qrCodeText);
        }

        if (isset($data['gatewayOptions'])) {
            if (in_array('payable_with', $data['gatewayOptions']) && $gateway == 'iugu') {
                foreach ($invoice->original->payable_with as $value) {
                    $this->assertContains($value, $data['gatewayOptions']['payable_with']);
                }
            }
            if (in_array('expires_in', $data['gatewayOptions'])) {
                $this->assertEquals($data['gatewayOptions'], $invoice->gatewayOptions);
                if ($gateway == 'iugu') {
                    foreach ($invoice->gatewayOptions as $key => $value) {
                        $this->assertNotEmpty(array_filter($invoice->original->variables, function ($variable) use ($key, $value) {
                            return $variable->variable == $key && $variable->value == $value;
                        }));
                    }
                }
            }
        }

        // Verifica se a fatura foi criada no gateway com os dados corretos
        $invoice = $invoice->refresh();

        $this->assertNotEmpty($invoice->status);

        $this->assertEquals($data['customer']['name'], $invoice->customer->name);
        $this->assertEquals($data['customer']['email'], $invoice->customer->email);
        $this->assertEquals($data['customer']['phoneArea'], $invoice->customer->phoneArea);
        $this->assertEquals($data['customer']['phoneNumber'], $invoice->customer->phoneNumber);

        foreach ($data['items'] as $key => $item) {
            $this->assertEquals($item['description'], $invoice->items[$key]->description);
            $this->assertEquals($item['price'], $invoice->items[$key]->price);
            $this->assertEquals($item['quantity'], $invoice->items[$key]->quantity);
        }

        if (isset($data['expiresAt'])) {
            $this->assertEquals($data['expiresAt'], $invoice->expiresAt->format('Y-m-d'));
        }

        if (isset($data['paymentMethod']) && $invoice->status === $invoice::STATUS_PAID) {
            $this->assertEquals($data['paymentMethod'], $invoice->paymentMethod);
        }

        if (isset($data['customer']['address'])) {
            $this->assertEquals($data['customer']['address']['zipCode'], $invoice->customer->address->zipCode);
            $this->assertEquals($data['customer']['address']['street'], $invoice->customer->address->street);
            $this->assertEquals($data['customer']['address']['number'], $invoice->customer->address->number);
            $this->assertEquals($data['customer']['address']['complement'], $invoice->customer->address->complement);
            // acentos podem ter sido removidos na criação
            $this->assertEquals(
                preg_replace('/[^p{L}p{N}s]/u', '', $data['customer']['address']['district']),
                preg_replace('/[^p{L}p{N}s]/u', '', $invoice->customer->address->district),
            );
            $this->assertEquals($data['customer']['address']['city'], $invoice->customer->address->city);
            $this->assertEquals($data['customer']['address']['state'], $invoice->customer->address->state);
            $this->assertEquals($data['customer']['address']['country'], $invoice->customer->address->country);
        }
    }

    /**
     * @return array[]
     */
    public static function shouldCreateInvoiceDataProvider(): array
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
            'iugu - without payment method - with adicional options' => [
                'gateway' => 'iugu',
                'data' => [
                    'expiresAt' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithAddress(),
                    'gatewayOptions' => [
                        'expires_in' => 5,
                    ]
                ]
            ],
            'iugu - without payment method - with payable_with' => [
                'gateway' => 'iugu',
                'data' => [
                    'expiresAt' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithAddress(),
                    'gatewayOptions' => [
                        'payable_with' => ['bank_slip', 'pix'],
                    ]
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
            'iugu - credit card without address' => [
                'gateway' => 'iugu',
                'data' => [
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithoutAddress(),
                    'availablePaymentMethods' => ['credit_card'],
                    'creditCard' => self::creditCard(),
                ]
            ],
            'iugu - credit card with address' => [
                'gateway' => 'iugu',
                'data' => [
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithAddress(),
                    'availablePaymentMethods' => ['credit_card'],
                    'creditCard' => self::creditCard(),
                ]
            ],
            'iugu - bank slip with address' => [
                'gateway' => 'iugu',
                'data' => [
                    'expiresAt' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithAddress(),
                    'availablePaymentMethods' => ['bank_slip'],
                ]
            ],
            'iugu - pix with address' => [
                'gateway' => 'iugu',
                'data' => [
                    'expiresAt' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithAddress(),
                    'availablePaymentMethods' => ['pix'],
                ]
            ],
            'iugu - pix without address' => [
                'gateway' => 'iugu',
                'data' => [
                    'expiresAt' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithoutAddress(),
                    'availablePaymentMethods' => ['pix'],
                ]
            ],
        ];
    }

    /**
     * Fail to create invoice test.
     *
     * @param  string  $gateway
     * @param  array  $data
     *
     * @return void
     */
    #[DataProvider('shouldNotCreateInvoiceDataProvider')]
    public function testShouldNotCreateInvoice(string $gateway, array $data): void
    {
        $this->expectException(ChargingException::class);
        $this->createInvoice($gateway, $data);
    }

    public static function shouldNotCreateInvoiceDataProvider(): array
    {
        return [
            'iugu - credit card - charge fail' => [
                'gateway' => 'iugu',
                'data' => [
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithAddress(),
                    'availablePaymentMethods' => ['credit_card'],
                    'creditCard' => array_merge(self::creditCard(), ['number' => '4012888888881881']),
                ],
            ],
        ];
    }
}
