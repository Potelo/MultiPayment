<?php

namespace Potelo\MultiPayment\Tests\Integration;

use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Tests\TestCase;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\AutomaticPix;
use Potelo\MultiPayment\Facades\MultiPayment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Potelo\MultiPayment\Exceptions\RefundNotSupportedException;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Enums\RefundStatus;
use Potelo\MultiPayment\Models\Refund;
use Potelo\MultiPayment\Enums\PaymentMethod;

class MultiPaymentTest extends TestCase
{

    /**
     * A consulta depende de uma fatura com Pix Automático criada no próprio
     * teste, mas a sandbox da Iugu ainda rejeita essa criação.
     */
    #[Group('iugu-sandbox-limitation')]
    public function testShouldGetAutomaticPixInvoice(): void
    {
        $this->markTestSkipped(
            'A sandbox da Iugu não permite criar a fatura de Pix Automático necessária para a consulta.'
        );

        $reference = 'multipayment-' . now()->format('YmdHis');
        $invoice = MultiPayment::setGateway('iugu')->newInvoice()
            ->addAvailablePaymentMethod(PaymentMethod::PIX)
            ->addCustomer(
                'Automatic Pix Sandbox',
                "{$reference}@example.com",
                '20176996915',
                null,
                '71',
                '982345678'
            )
            ->addItem('Automatic Pix sandbox test', 100, 1)
            ->setExpiresAt(now()->addDays(2))
            ->addAutomaticPix(
                AutomaticPix::AUTHORIZATION_TYPE_QR_CODE_WITH_PAYMENT,
                AutomaticPix::FREQUENCY_MONTHLY,
                now()->addDays(3),
                $reference,
                now()->addYear(),
                AutomaticPix::RETRY_POLICY_ALLOWED
            )
            ->addAutomaticPixCharge('Automatic Pix sandbox test')
            ->create();

        $invoiceFetched = MultiPayment::setGateway('iugu')->getInvoice($invoice->id);

        $this->assertSame($invoice->id, $invoiceFetched->id);
        $this->assertInstanceOf(AutomaticPix::class, $invoiceFetched->automaticPix);
        $this->assertSame($invoice->automaticPix->id, $invoiceFetched->automaticPix->id);
        $this->assertSame($reference, $invoiceFetched->automaticPix->contractReference);
        $this->assertSame('iugu', $invoiceFetched->automaticPix->gateway);
        $this->assertNotNull($invoiceFetched->automaticPix->original);
    }

    /**
     * A retentativa exige uma fatura expirada após falha de débito de uma
     * recorrência autorizada, estado que não pode ser criado na sandbox.
     */
    #[Group('iugu-sandbox-limitation')]
    public function testShouldRescheduleAutomaticPixPayment(): void
    {
        $this->markTestSkipped(
            'A sandbox da Iugu não permite criar a recorrência e a fatura expirada necessárias para a retentativa.'
        );
    }

    /**
     * O cancelamento exige uma recorrência autorizada criada durante o teste,
     * mas a sandbox não oferece suporte à criação de Pix Automático.
     */
    #[Group('iugu-sandbox-limitation')]
    public function testShouldCancelAutomaticPixRecurrence(): void
    {
        $this->markTestSkipped(
            'A sandbox da Iugu não permite criar a recorrência ativa necessária para testar o cancelamento.'
        );
    }

    /**
     * O cancelamento de agendamento exige um débito agendado e seu end-to-end
     * ID, que não podem ser produzidos pela sandbox no fluxo do teste.
     */
    #[Group('iugu-sandbox-limitation')]
    public function testShouldCancelAutomaticPixScheduledPayment(): void
    {
        $this->markTestSkipped(
            'A sandbox da Iugu não permite criar o pagamento agendado necessário para testar o cancelamento.'
        );
    }

    /**
     * A consulta exige que uma recorrência seja criada e cancelada no próprio
     * teste; a sandbox bloqueia a etapa inicial desse fluxo.
     */
    #[Group('iugu-sandbox-limitation')]
    public function testShouldGetAutomaticPixCancellation(): void
    {
        $this->markTestSkipped(
            'A sandbox da Iugu não permite criar o cancelamento de Pix Automático necessário para a consulta.'
        );
    }

    /**
     * A listagem exige uma recorrência com cancelamentos criados durante o
     * teste; a sandbox bloqueia a criação dessa recorrência.
     */
    #[Group('iugu-sandbox-limitation')]
    public function testShouldListAutomaticPixCancellations(): void
    {
        $this->markTestSkipped(
            'A sandbox da Iugu não permite criar o histórico de cancelamentos necessário para a listagem.'
        );
    }

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
            ->addAvailablePaymentMethod(PaymentMethod::CREDIT_CARD)
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

        $this->expectException(\Potelo\MultiPayment\Exceptions\NotFoundException::class);
        $this->expectExceptionMessageMatches('/not found/i');
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
            ->addAvailablePaymentMethod(PaymentMethod::PIX)
            ->addCustomer('Fake Customer', 'email@exemplo.com', '20176996915')
            ->addItem('teste', 1000, 1)
            ->create();

        $multiPayment = new \Potelo\MultiPayment\MultiPayment($gateway);
        $new = $multiPayment->duplicateInvoice($invoice->id, now()->addDays(7));
        $this->assertNotEquals($new->id, $invoice->id);
        $this->assertEquals($new->status, InvoiceStatus::PENDING);
        $this->assertTrue($new->expiresAt->isSameDay((now()->addDays(7))));

    }

    /**
     * Test if thorws an exception when not find the invoice
     *
     * @param $gateway
     * @param $id
     *
     * @return void
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    #[DataProvider('shouldNotGetInvoiceDataProvider')]
    public function testShouldNotGetInvoice($gateway, $id)
    {
        $this->expectException(\Potelo\MultiPayment\Exceptions\NotFoundException::class);
        $multiPayment = new \Potelo\MultiPayment\MultiPayment($gateway);
        $multiPayment->getInvoice($id);
    }

    /**
     * @return array
     */
    public static function shouldNotGetInvoiceDataProvider(): array
    {
        return [
            'iugu' => ['iugu', '4DAF50DDAA1E461CBA9ECF813111FC0B'],
        ];
    }

    /**
     * Test if can refund the invoice
     *
     * @param  string  $gateway
     * @param  array  $data
     *
     * @return void
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     */
    #[DataProvider('shouldRefundInvoiceDataProvider')]
    public function testShouldRefundInvoice(string $gateway, array $data, InvoiceStatus $status, ?int $refundedAmount)
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

        $refund = $multiPayment->refundInvoice($invoice->id, $refundedAmount);

        if (is_null($refundedAmount)) {
            $refundedAmount = $total;
        }
        $this->assertInstanceOf(Refund::class, $refund);
        $this->assertSame($refundedAmount, $refund->amount);
        $this->assertSame(RefundStatus::SUCCEEDED, $refund->status);
        $this->assertSame($invoice->id, $refund->invoiceId);

        $refundedInvoice = $refund->invoice();
        $this->assertSame($status, $refundedInvoice->status);
        $this->assertEquals($refundedAmount, $refundedInvoice->refundedAmount);
        $this->assertEquals($total - $refundedAmount, $refundedInvoice->paidAmount);
        $this->assertCount(1, $refundedInvoice->refunds);
        $this->assertEquals($refundedAmount, $refundedInvoice->refunds[0]->amount);

        // na Iugu a guarda lê a fatura real antes: já estornada é recusada sem novo POST
        if ($gateway === 'iugu' && $status === InvoiceStatus::REFUNDED) {
            try {
                $multiPayment->refundInvoice($invoice->id);
                $this->fail('Esperava RefundNotSupportedException');
            } catch (RefundNotSupportedException $e) {
                $this->assertSame(RefundNotSupportedException::REASON_ALREADY_REFUNDED, $e->reason);
                $this->assertFalse($e->manualRefundRequired);
            }
        }
    }

    /**
     * @return array
     */
    public static function shouldRefundInvoiceDataProvider(): array
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
                'status' => InvoiceStatus::REFUNDED,
                'refundedAmount' => null,
            ],
        ];
    }


    /**
     * Test if can refund the invoice
     *
     * @param  string  $gateway
     * @param  array  $data
     * @param  InvoiceStatus  $status
     * @param  string  $creditCardDataMethod
     * @return void
     * @throws \Potelo\MultiPayment\Exceptions\ChargingException
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     * @throws \Potelo\MultiPayment\Exceptions\MultiPaymentException
     */
    #[DataProvider('shouldChargeInvoiceWithCreditCard')]
    public function testShouldChargeInvoiceWithCreditCard(string $gateway, array $data, InvoiceStatus $status, string $creditCardDataMethod)
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

        $this->assertSame($status, $invoice->status);
    }

    /**
     * @return array
     */
    public static function shouldChargeInvoiceWithCreditCard(): array
    {
        return [
            'iugu - credit card object' => [
                'gateway' => 'iugu',
                'data' => [
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithoutAddress(),
                    'paymentMethod' => 'credit_card',
                ],
                'status' => InvoiceStatus::PAID,
                'creditCardDataMethod' => 'creditCard',
            ],
            'iugu - credit card token' => [
                'gateway' => 'iugu',
                'data' => [
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithoutAddress(),
                    'paymentMethod' => 'credit_card',
                ],
                'status' => InvoiceStatus::PAID,
                'creditCardDataMethod' => 'token',
            ],
            'iugu - credit card id' => [
                'gateway' => 'iugu',
                'data' => [
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 10000,]],
                    'customer' => self::customerWithoutAddress(),
                    'paymentMethod' => 'credit_card',
                ],
                'status' => InvoiceStatus::PAID,
                'creditCardDataMethod' => 'id',
            ],
        ];
    }
}
