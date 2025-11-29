<?php

namespace Potelo\MultiPayment\Tests\Unit;

use Potelo\MultiPayment\Models\CreditCard;
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
            ->addAvailablePaymentMethod(Invoice::PAYMENT_METHOD_CREDIT_CARD)
            ->addCustomer('Fake Customer', 'email@exemplo.com', '20176996915')
            ->addItem('teste', 1000, 1)
            ->addCreditCardToken(self::iuguCreditCardToken())
            ->create();

        $multiPayment = new \Potelo\MultiPayment\MultiPayment($gateway);
        $invoiceFetched = $multiPayment->getInvoice($invoice->id);
        $this->assertEquals($invoiceFetched->id, $invoice->id);
    }

    /**
     * Test if can get the card by id
     *
     * @return void
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function testShouldGetCard()
    {
        $gateway = 'iugu';
        $data = $this->creditCard();
        $customer = $this->createCustomer($gateway, $this->customerWithoutAddress());

        $creditCard = MultiPayment::setGateway($gateway)->newCreditCard()
            ->setDescription($data['description'])
            ->setNumber($data['number'])
            ->setCustomerId($customer->id)
            ->setFirstName($data['firstName'])
            ->setLastName($data['lastName'])
            ->setMonth($data['month'])
            ->setYear($data['year'])
            ->setCvv($data['cvv'])
            ->setDescription($data['description'])
            ->setAsDefault($data['default'])
            ->create();

        $multiPayment = new \Potelo\MultiPayment\MultiPayment($gateway);
        $card = $multiPayment->getCard($customer->id, $creditCard->id);

        $this->assertEquals($creditCard->id, $card->id);
        $this->assertEquals(substr($data['number'], -4), $card->lastDigits);
        $this->assertEquals($data['description'], $card->description);
        $this->assertEquals($data['firstName'], $card->firstName);
        $this->assertEquals($data['lastName'], $card->lastName);
        $this->assertEquals($data['month'], $card->month);
        $this->assertEquals($data['year'], $card->year);
    }

    /**
     * Test if can delete the card by id
     *
     * @return void
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function testShouldDeleteCard()
    {
        $gateway = 'iugu';
        $data = $this->creditCard();
        $customer = $this->createCustomer($gateway, $this->customerWithoutAddress());

        $creditCard = MultiPayment::setGateway($gateway)->newCreditCard()
            ->setDescription($data['description'])
            ->setNumber($data['number'])
            ->setCustomerId($customer->id)
            ->setFirstName($data['firstName'])
            ->setLastName($data['lastName'])
            ->setMonth($data['month'])
            ->setYear($data['year'])
            ->setCvv($data['cvv'])
            ->setDescription($data['description'])
            ->setAsDefault($data['default'])
            ->create();

        $multiPayment = new \Potelo\MultiPayment\MultiPayment($gateway);
        $multiPayment->deleteCard($customer->id, $creditCard->id);

        $this->expectException(\Potelo\MultiPayment\Exceptions\GatewayException::class);
        $this->expectExceptionMessage('payment_method: not found');
        $multiPayment->getCard($customer->id, $creditCard->id);
    }

    /**
     * Test if can set the card as default
     *
     * @return void
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function testShouldSetCardAsDefault()
    {
        $gateway = 'iugu';
        $data = $this->creditCard();
        $customer = $this->createCustomer($gateway, $this->customerWithoutAddress());

        $creditCardOne = MultiPayment::setGateway($gateway)->newCreditCard()
            ->setNumber($data['number'])
            ->setCustomerId($customer->id)
            ->setFirstName($data['firstName'])
            ->setLastName($data['lastName'])
            ->setMonth($data['month'])
            ->setYear($data['year'])
            ->setCvv($data['cvv'])
            ->setDescription($data['description'])
            ->setAsDefault()
            ->create();

        $customer = $customer->refresh();
        $this->assertEquals($creditCardOne->id, $customer->defaultCard->id);

        $creditCardTwo = MultiPayment::setGateway($gateway)->newCreditCard()
            ->setDescription($data['description'])
            ->setNumber($data['number'])
            ->setCustomerId($customer->id)
            ->setFirstName($data['firstName'])
            ->setLastName($data['lastName'])
            ->setMonth($data['month'])
            ->setYear($data['year'])
            ->setCvv($data['cvv'])
            ->setDescription($data['description'])
            ->setAsDefault(false)
            ->create();

        $customer = $customer->refresh();
        $this->assertEquals($creditCardOne->id, $customer->defaultCard->id);

        $multiPayment = new \Potelo\MultiPayment\MultiPayment($gateway);
        $multiPayment->setGateway($gateway);
        $multiPayment->setDefaultCard($customer->id, $creditCardTwo->id);

        $customer = $customer->refresh();
        $this->assertEquals($creditCardTwo->id, $customer->defaultCard->id);
    }

    /**
     * Test if can duplicate the invoice
     *
     * @return void
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function testShouldDuplicateInvoice()
    {
        $gateway = 'iugu';
        $invoice = MultiPayment::setGateway($gateway)->newInvoice()
            ->addAvailablePaymentMethod(Invoice::PAYMENT_METHOD_PIX)
            ->addCustomer('Fake Customer', 'email@exemplo.com', '20176996915')
            ->addItem('teste', 1000, 1)
            ->create();

        $multiPayment = new \Potelo\MultiPayment\MultiPayment($gateway);
        $new = $multiPayment->duplicateInvoice($invoice->id, now()->addDays(7));
        $this->assertNotEquals($new->id, $invoice->id);
        $this->assertEquals($new->status, Invoice::STATUS_PENDING);
        $this->assertTrue($new->expiresAt->isSameDay((now()->addDays(7))));

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
        $invoiceBuilder->addAvailablePaymentMethod($data['paymentMethod']);
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
        ];
    }


    /**
     * Test if can refund the invoice
     *
     * @dataProvider shouldChargeInvoiceWithCreditCard
     *
     * @param  string  $gateway
     * @param  array  $data
     * @param  string  $status
     * @param  string  $creditCardDataMethod
     * @return void
     * @throws \Potelo\MultiPayment\Exceptions\ChargingException
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     * @throws \Potelo\MultiPayment\Exceptions\MultiPaymentException
     */
    public function testShouldChargeInvoiceWithCreditCard(string $gateway, array $data, string $status, string $creditCardDataMethod)
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
        foreach ($data['items'] as $item) {
            $invoiceBuilder->addItem($item['description'], $item['price'], $item['quantity']);
        }
        $invoice = $invoiceBuilder->create();
        sleep(3);

        if ($creditCardDataMethod == 'creditCard') {
            $invoice->creditCard = new CreditCard();
            $invoice->creditCard->fill(self::creditCard());
            $invoice->creditCard->customer = $invoice->customer;
            $invoice->creditCard->save();

            $invoice = $multiPayment->chargeInvoiceWithCreditCard($invoice);
        } elseif ($creditCardDataMethod == 'token') {
            $creditCardToken = self::iuguCreditCardToken();
            $invoice = $multiPayment->chargeInvoiceWithCreditCard($invoice->id, $creditCardToken);
        } elseif ($creditCardDataMethod == 'id') {
            $creditCard = new CreditCard();
            $creditCard->fill(self::creditCard());
            $creditCard->customer = $invoice->customer;
            $creditCard->save();
            $invoice = $multiPayment->chargeInvoiceWithCreditCard($invoice->id, null, $creditCard->id);
        }

        $this->assertEquals($status, $invoice->status);
    }

    /**
     * @return array
     */
    public function shouldChargeInvoiceWithCreditCard(): array
    {
        return [
            'iugu - credit card object' => [
                'gateway' => 'iugu',
                'data' => [
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithoutAddress(),
                    'paymentMethod' => 'credit_card',
                ],
                'status' => Invoice::STATUS_PAID,
                'creditCardDataMethod' => 'creditCard',
            ],
            'iugu - credit card token' => [
                'gateway' => 'iugu',
                'data' => [
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithoutAddress(),
                    'paymentMethod' => 'credit_card',
                ],
                'status' => Invoice::STATUS_PAID,
                'creditCardDataMethod' => 'token',
            ],
            'iugu - credit card id' => [
                'gateway' => 'iugu',
                'data' => [
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithoutAddress(),
                    'paymentMethod' => 'credit_card',
                ],
                'status' => Invoice::STATUS_PAID,
                'creditCardDataMethod' => 'id',
            ],
        ];
    }
}