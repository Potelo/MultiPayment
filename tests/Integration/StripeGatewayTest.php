<?php

namespace Potelo\MultiPayment\Tests\Integration;

use Carbon\Carbon;
use Stripe\StripeClient;
use Illuminate\Support\Facades\Config;
use Potelo\MultiPayment\Tests\TestCase;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Gateways\StripeGateway;
use Potelo\MultiPayment\Enums\InvoiceOriginType;
use Potelo\MultiPayment\Facades\MultiPayment;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Exceptions\ChargingException;
use Potelo\MultiPayment\Exceptions\CardDeclinedException;
use Potelo\MultiPayment\Enums\DeclineCode;
use Potelo\MultiPayment\Enums\CaptureMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Enums\RefundStatus;
use Potelo\MultiPayment\Enums\DisputeStatus;
use Potelo\MultiPayment\Exceptions\RefundNotSupportedException;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Listing\InvoiceFilter;

/**
 * Cenários específicos do gateway Stripe na sandbox real. O fluxo de cartão é token-only:
 * os PaymentMethods de teste da Stripe (pm_card_visa etc.) fazem o papel do token criado
 * client-side com Stripe.js.
 */
class StripeGatewayTest extends TestCase
{
    /**
     * Gateway data provider — mantém o padrão por gateway e dispensa o sleep da Iugu.
     *
     * @return array[]
     */
    public static function stripeGatewayDataProvider(): array
    {
        return [
            ['stripe'],
        ];
    }

    /**
     * Deve cobrar uma fatura de cartão com PaymentMethod de teste e refletir em getInvoice.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldChargeCreditCardInvoice($gateway)
    {
        $customerData = self::customerWithoutAddress();
        $invoice = MultiPayment::setGateway($gateway)->newInvoice()
            ->addCustomer(
                $customerData['name'],
                $customerData['email'],
                $customerData['taxDocument'],
                $customerData['birthDate'],
                $customerData['phoneArea'],
                $customerData['phoneNumber']
            )
            ->addItem('Assinatura mensal', 12345, 1)
            ->setAvailablePaymentMethods([PaymentMethod::CREDIT_CARD])
            ->addCreditCardToken('pm_card_visa')
            ->create();

        $this->assertNotNull($invoice->id);
        $this->assertEquals(InvoiceStatus::PAID, $invoice->status);
        $this->assertEquals(12345, $invoice->amount);
        $this->assertEquals(12345, $invoice->paidAmount);
        $this->assertEquals(PaymentMethod::CREDIT_CARD, $invoice->paymentMethod);
        $this->assertEquals('4242', $invoice->creditCard->lastDigits);
        $this->assertNotNull($invoice->paidAt);
        $this->assertCount(1, $invoice->items);
        $this->assertEquals('Assinatura mensal', $invoice->items[0]->description);

        sleep(3); // a balance transaction (fee) do cartão é assíncrona logo após o confirm

        $invoiceFetched = MultiPayment::setGateway($gateway)->getInvoice($invoice->id);
        $this->assertEquals(InvoiceStatus::PAID, $invoiceFetched->status);
        $this->assertEquals(12345, $invoiceFetched->paidAmount);
        $this->assertNotNull($invoiceFetched->fee);
        $this->assertEquals($invoice->id, $invoiceFetched->id);
    }

    /**
     * Recusa de cartão deve virar ChargingException com resposta bruta e razão normalizada.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldRaiseChargingExceptionOnDeclinedCard($gateway)
    {
        $customerData = self::customerWithoutAddress();
        $invoiceBuilder = MultiPayment::setGateway($gateway)->newInvoice()
            ->addCustomer(
                $customerData['name'],
                $customerData['email'],
                $customerData['taxDocument'],
                $customerData['birthDate'],
                $customerData['phoneArea'],
                $customerData['phoneNumber']
            )
            ->addItem('Assinatura mensal', 9900, 1)
            ->setAvailablePaymentMethods([PaymentMethod::CREDIT_CARD])
            ->addCreditCardToken('pm_card_chargeDeclined');

        try {
            $invoiceBuilder->create();
            $this->fail('Expected ChargingException was not thrown');
        } catch (ChargingException $exception) {
            $this->assertInstanceOf(CardDeclinedException::class, $exception);
            $this->assertEquals('card_declined', $exception->reason);
            $this->assertSame(DeclineCode::GENERIC, $exception->declineCode);
            $this->assertSame('generic_decline', $exception->gatewayCode);
            // a recusa acontece no SetupIntent que salva o token, onde a Stripe envia
            // advice_code try_again_later para este cartão de teste
            $this->assertTrue($exception->retryable);
            $this->assertNotEmpty($exception->chargeResponse);
        }
    }

    /**
     * Recusa por saldo insuficiente chega com o decline_code da Stripe traduzido para
     * INSUFFICIENT_FUNDS e com nova tentativa permitida.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testInsufficientFundsDeclineIsTranslatedToDeclineCode($gateway)
    {
        $customerData = self::customerWithoutAddress();
        $invoiceBuilder = MultiPayment::setGateway($gateway)->newInvoice()
            ->addCustomer(
                $customerData['name'],
                $customerData['email'],
                $customerData['taxDocument'],
                $customerData['birthDate'],
                $customerData['phoneArea'],
                $customerData['phoneNumber']
            )
            ->addItem('Assinatura mensal', 9900, 1)
            ->setAvailablePaymentMethods([PaymentMethod::CREDIT_CARD])
            ->addCreditCardToken('pm_card_chargeDeclinedInsufficientFunds');

        try {
            $invoiceBuilder->create();
            $this->fail('Expected CardDeclinedException was not thrown');
        } catch (CardDeclinedException $exception) {
            $this->assertSame(DeclineCode::INSUFFICIENT_FUNDS, $exception->declineCode);
            $this->assertSame('insufficient_funds', $exception->gatewayCode);
            $this->assertTrue($exception->retryable);
            $this->assertSame('insufficient_funds', $exception->reason);
        }
    }

    /**
     * Deve salvar, buscar, definir como padrão e excluir um cartão tokenizado.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldManageCreditCardLifecycle($gateway)
    {
        $customer = $this->createCustomer($gateway, self::customerWithoutAddress());

        $creditCard = MultiPayment::setGateway($gateway)->newCreditCard()
            ->setCustomerId($customer->id)
            ->setToken('pm_card_visa')
            ->setDescription('cartão de teste')
            ->setAsDefault()
            ->create();

        $this->assertNotNull($creditCard->id);
        $this->assertFalse($creditCard->requiresAction);
        $this->assertStringStartsWith('seti_', $creditCard->setupId);
        $this->assertEquals('visa', $creditCard->brand);
        $this->assertEquals('4242', $creditCard->lastDigits);
        $this->assertEquals('cartão de teste', $creditCard->description);
        $this->assertEquals($gateway, $creditCard->gateway);

        // a descrição e a marcação de padrão viajam em metadata do SetupIntent e são aplicadas no setup concluído
        $cardFetched = MultiPayment::setGateway($gateway)->getCard($customer->id, $creditCard->id);
        $this->assertEquals($creditCard->id, $cardFetched->id);
        $this->assertEquals('4242', $cardFetched->lastDigits);
        $this->assertEquals('cartão de teste', $cardFetched->description);
        $this->assertEquals($creditCard->id, MultiPayment::setGateway($gateway)->getCustomer($customer->id)->defaultCard->id);

        $customerUpdated = MultiPayment::setGateway($gateway)->setDefaultCard($customer->id, $creditCard->id);
        $this->assertEquals($creditCard->id, $customerUpdated->defaultCard->id);

        MultiPayment::setGateway($gateway)->deleteCard($customer->id, $creditCard->id);

        // após o detach o PaymentMethod não pertence mais ao customer
        $this->expectException(UnsupportedOperationException::class);
        MultiPayment::setGateway($gateway)->getCard($customer->id, $creditCard->id);
    }

    /**
     * Cartão que exige autenticação do portador (`pm_card_authenticationRequired`) volta com
     * `requiresAction` e sem id, e a confirmação antes de o pagador autenticar devolve o mesmo
     * estado; nada é anexado ao cliente.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldReturnRequiresActionForACardThatNeedsAuthentication($gateway)
    {
        $customer = $this->createCustomer($gateway, self::customerWithoutAddress());

        $creditCard = MultiPayment::setGateway($gateway)->newCreditCard()
            ->setCustomerId($customer->id)
            ->setToken('pm_card_authenticationRequired')
            ->setDescription('cartão com autenticação')
            ->create();

        $this->assertTrue($creditCard->requiresAction);
        $this->assertNull($creditCard->id);
        $this->assertStringStartsWith('seti_', $creditCard->setupId);
        $this->assertNotEmpty($creditCard->clientSecret);
        $this->assertNull($creditCard->actionUrl, 'sem return_url a autenticação é pelo Stripe.js');
        $this->assertEquals('3184', $creditCard->lastDigits);
        $this->assertEquals('cartão com autenticação', $creditCard->description);

        $confirmed = MultiPayment::setGateway($gateway)->confirmCreditCardSetup($creditCard->setupId);
        $this->assertTrue($confirmed->requiresAction);
        $this->assertNull($confirmed->id);
        $this->assertEquals($creditCard->setupId, $confirmed->setupId);
        $this->assertEquals($customer->id, $confirmed->customer->id);

        $attached = $this->stripeClient()->paymentMethods->all(['customer' => $customer->id, 'type' => 'card']);
        $this->assertCount(0, $attached->data, 'o cartão só é anexado depois da autenticação');
    }

    /**
     * Deve criar fatura pix server-side com QR code e refletir o pagamento mágico da sandbox.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldCreatePixInvoiceAndReceiveMagicPayment($gateway)
    {
        $customerData = self::customerWithoutAddress();
        $invoice = MultiPayment::setGateway($gateway)->newInvoice()
            ->addCustomer(
                $customerData['name'],
                'succeed_immediately@example.com',
                $customerData['taxDocument']
            )
            ->addItem('Assinatura mensal', 12345, 1)
            ->setAvailablePaymentMethods([PaymentMethod::PIX])
            ->setPixExpiresAt(\Carbon\Carbon::now()->addHour())
            ->create();

        $this->assertNotNull($invoice->id);
        $this->assertEquals(InvoiceStatus::PENDING, $invoice->status);
        $this->assertEquals(PaymentMethod::PIX, $invoice->paymentMethod);
        $this->assertNotNull($invoice->pix);
        $this->assertNotNull($invoice->pix->qrCodeText);
        $this->assertNotNull($invoice->pix->qrCodeImageUrl);
        $this->assertNotNull($invoice->url);
        $this->assertNotNull($invoice->pixExpiresAt);
        $this->assertNull($invoice->dueDate);

        // além do pagamento mágico, espera a balance transaction (fee) materializar
        $invoiceFetched = $this->waitForInvoiceCondition($gateway, $invoice->id, function (Invoice $fetched) {
            return $fetched->status === InvoiceStatus::PAID && !is_null($fetched->fee);
        });
        $this->assertEquals(InvoiceStatus::PAID, $invoiceFetched->status);
        $this->assertEquals(12345, $invoiceFetched->paidAmount);
        $this->assertEquals(PaymentMethod::PIX, $invoiceFetched->paymentMethod);
        $this->assertNotNull($invoiceFetched->fee);
    }

    /**
     * Deve cancelar uma fatura pix pendente.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldCancelPendingPixInvoice($gateway)
    {
        $customerData = self::customerWithoutAddress();
        $invoice = MultiPayment::setGateway($gateway)->newInvoice()
            ->addCustomer($customerData['name'], $customerData['email'], $customerData['taxDocument'])
            ->addItem('Assinatura mensal', 5000, 1)
            ->setAvailablePaymentMethods([PaymentMethod::PIX])
            ->create();

        $this->assertEquals(InvoiceStatus::PENDING, $invoice->status);

        $invoiceCanceled = MultiPayment::setGateway($gateway)->cancelInvoice($invoice->id);
        $this->assertEquals(InvoiceStatus::CANCELED, $invoiceCanceled->status);
    }

    /**
     * Fatura pix expirada volta a pendente e deve aceitar cobrança com cartão.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldChargeExpiredPixInvoiceWithCreditCard($gateway)
    {
        $customerData = self::customerWithoutAddress();
        $invoice = MultiPayment::setGateway($gateway)->newInvoice()
            ->addCustomer($customerData['name'], 'expire_immediately@example.com', $customerData['taxDocument'])
            ->addItem('Assinatura mensal', 9900, 1)
            ->setAvailablePaymentMethods([PaymentMethod::PIX])
            ->create();

        $this->assertEquals(InvoiceStatus::PENDING, $invoice->status);

        // aguarda a sandbox processar a expiração mágica (o PI segue pendente e re-cobrável)
        $invoiceExpired = $this->waitForInvoiceCondition($gateway, $invoice->id, function (Invoice $fetched) {
            return empty($fetched->pix);
        });
        $this->assertEquals(InvoiceStatus::PENDING, $invoiceExpired->status);

        $invoicePaid = MultiPayment::setGateway($gateway)
            ->chargeInvoiceWithCreditCard($invoice->id, 'pm_card_visa');
        $this->assertEquals(InvoiceStatus::PAID, $invoicePaid->status);
        $this->assertEquals(PaymentMethod::CREDIT_CARD, $invoicePaid->paymentMethod);
        $this->assertEquals('4242', $invoicePaid->creditCard->lastDigits);
    }

    /**
     * Busca a fatura até a condição ser satisfeita ou o tempo limite estourar — a sandbox
     * processa os e-mails mágicos e a balance transaction de forma assíncrona.
     *
     * @param  string  $gateway
     * @param  string  $invoiceId
     * @param  callable  $condition
     * @return \Potelo\MultiPayment\Models\Invoice
     */
    private function waitForInvoiceCondition(string $gateway, string $invoiceId, callable $condition): Invoice
    {
        $invoice = MultiPayment::setGateway($gateway)->getInvoice($invoiceId);
        foreach (range(1, 10) as $attempt) {
            if ($condition($invoice)) {
                return $invoice;
            }
            sleep(3);
            $invoice = MultiPayment::setGateway($gateway)->getInvoice($invoiceId);
        }

        $this->fail("Timeout aguardando a condição da fatura [{$invoiceId}] na sandbox");
    }

    /**
     * Deve estornar integralmente uma fatura de cartão paga.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldRefundCreditCardInvoiceTotally($gateway)
    {
        $customerData = self::customerWithoutAddress();
        $invoice = MultiPayment::setGateway($gateway)->newInvoice()
            ->addCustomer($customerData['name'], $customerData['email'], $customerData['taxDocument'])
            ->addItem('Assinatura mensal', 9900, 1)
            ->setAvailablePaymentMethods([PaymentMethod::CREDIT_CARD])
            ->addCreditCardToken('pm_card_visa')
            ->create();

        $this->assertEquals(InvoiceStatus::PAID, $invoice->status);

        $refund = MultiPayment::setGateway($gateway)->refundInvoice($invoice->id);
        $this->assertStringStartsWith('re_', $refund->id);
        $this->assertEquals(9900, $refund->amount);
        $this->assertContains($refund->status, [RefundStatus::PENDING, RefundStatus::SUCCEEDED]);

        $invoiceRefunded = $refund->invoice();
        $this->assertEquals(InvoiceStatus::REFUNDED, $invoiceRefunded->status);
        $this->assertEquals(9900, $invoiceRefunded->refundedAmount);
        $this->assertCount(1, $invoiceRefunded->refunds);
        $this->assertEquals($refund->id, $invoiceRefunded->refunds[0]->id);
    }

    /**
     * Deve estornar parcialmente uma fatura pix paga.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldRefundPixInvoicePartially($gateway)
    {
        $customerData = self::customerWithoutAddress();
        $invoice = MultiPayment::setGateway($gateway)->newInvoice()
            ->addCustomer($customerData['name'], 'succeed_immediately@example.com', $customerData['taxDocument'])
            ->addItem('Assinatura mensal', 12345, 1)
            ->setAvailablePaymentMethods([PaymentMethod::PIX])
            ->create();

        $invoicePaid = $this->waitForInvoiceCondition($gateway, $invoice->id, function (Invoice $fetched) {
            return $fetched->status === InvoiceStatus::PAID;
        });
        $this->assertEquals(InvoiceStatus::PAID, $invoicePaid->status);

        $refund = MultiPayment::setGateway($gateway)->refundInvoice($invoice->id, 2345);
        $this->assertEquals(2345, $refund->amount);

        $invoiceRefunded = $refund->invoice();
        $this->assertEquals(InvoiceStatus::PARTIALLY_REFUNDED, $invoiceRefunded->status);
        $this->assertEquals(2345, $invoiceRefunded->refundedAmount);
        $this->assertEquals(12345, $invoiceRefunded->paidAmount);
        $this->assertCount(1, $invoiceRefunded->refunds);
        $this->assertEquals($refund->id, $invoiceRefunded->refunds[0]->id);

        // segundo estorno acima do restante é recusado sem chamar a Stripe
        try {
            MultiPayment::setGateway($gateway)->refundInvoice($invoice->id, 10001);
            $this->fail('Esperava RefundNotSupportedException');
        } catch (RefundNotSupportedException $e) {
            $this->assertSame(RefundNotSupportedException::REASON_AMOUNT_EXCEEDS_REFUNDABLE, $e->reason);
        }
        $this->assertEquals(2345, MultiPayment::setGateway($gateway)->getInvoice($invoice->id)->refundedAmount);
    }

    /**
     * Deve duplicar uma fatura pix pendente com nova expiração, cancelando a original.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldDuplicatePendingPixInvoice($gateway)
    {
        $customerData = self::customerWithoutAddress();
        $invoice = MultiPayment::setGateway($gateway)->newInvoice()
            ->addCustomer($customerData['name'], $customerData['email'], $customerData['taxDocument'])
            ->addItem('Assinatura mensal', 5000, 1)
            ->setAvailablePaymentMethods([PaymentMethod::PIX])
            ->setPixExpiresAt(\Carbon\Carbon::now()->addHour())
            ->create();

        $this->assertEquals(InvoiceStatus::PENDING, $invoice->status);

        $newExpiresAt = \Carbon\Carbon::now()->addDays(2);
        $invoiceDuplicated = MultiPayment::setGateway($gateway)
            ->duplicateInvoice($invoice->id, $newExpiresAt);

        $this->assertNotEquals($invoice->id, $invoiceDuplicated->id);
        $this->assertEquals(InvoiceStatus::PENDING, $invoiceDuplicated->status);
        $this->assertEquals(5000, $invoiceDuplicated->amount);
        $this->assertNotNull($invoiceDuplicated->pix->qrCodeText);
        $this->assertEqualsWithDelta(
            $newExpiresAt->getTimestamp(),
            $invoiceDuplicated->pixExpiresAt->getTimestamp(),
            60
        );
        $this->assertEquals($invoice->customer->id, $invoiceDuplicated->customer->id);

        $originalFetched = MultiPayment::setGateway($gateway)->getInvoice($invoice->id);
        $this->assertEquals(InvoiceStatus::CANCELED, $originalFetched->status);
    }

    /**
     * Fatura de assinatura (objeto Invoice da Stripe) lida por `getInvoice()` com o id `in_`,
     * recusada na duplicação e cancelada por `void`. O Invoice é criado direto no SDK porque a
     * lib ainda não cria fatura nem assinatura no Stripe.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldReadAndVoidAStripeInvoiceByItsId($gateway)
    {
        $customer = $this->createCustomer($gateway, self::customerWithoutAddress());
        $stripeInvoiceId = $this->createOpenStripeInvoice($customer->id);

        $invoice = MultiPayment::setGateway($gateway)->getInvoice($stripeInvoiceId);

        $this->assertSame($stripeInvoiceId, $invoice->id);
        $this->assertSame(InvoiceOriginType::INVOICE, $invoice->originType);
        $this->assertSame(InvoiceStatus::PENDING, $invoice->status);
        $this->assertSame(12345, $invoice->amount);
        $this->assertNull($invoice->paidAmount);
        $this->assertSame($customer->id, $invoice->customer->id);
        $this->assertSame('Assinatura mensal', $invoice->items[0]->description);
        $this->assertSame(12345, $invoice->items[0]->price);
        $this->assertStringStartsWith('https://invoice.stripe.com/', $invoice->url);
        $this->assertInstanceOf(\Stripe\Invoice::class, $invoice->original);

        try {
            MultiPayment::setGateway($gateway)->duplicateInvoice($stripeInvoiceId, Carbon::now()->addDay());
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::INVOICE_DUPLICATION, $e->capability);
        }

        $canceled = MultiPayment::setGateway($gateway)->cancelInvoice($stripeInvoiceId);
        $this->assertSame(InvoiceStatus::CANCELED, $canceled->status);
        $this->assertSame(InvoiceOriginType::INVOICE, $canceled->originType);
        $this->assertSame('void', $canceled->original->status);

        $this->assertSame(InvoiceStatus::CANCELED, MultiPayment::setGateway($gateway)->getInvoice($stripeInvoiceId)->status);
    }

    /**
     * Fatura de assinatura paga: o charge do PaymentIntent, lido num GET à parte, alimenta
     * valor pago, taxa e cartão.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldReadAPaidStripeInvoiceWithItsCharge($gateway)
    {
        $customer = $this->createCustomer($gateway, self::customerWithoutAddress());
        $stripeInvoiceId = $this->createOpenStripeInvoice($customer->id);

        $client = $this->stripeClient();
        $paymentMethod = $client->paymentMethods->attach('pm_card_visa', ['customer' => $customer->id]);
        $client->invoices->pay($stripeInvoiceId, ['payment_method' => $paymentMethod->id]);

        $invoice = MultiPayment::setGateway($gateway)->getInvoice($stripeInvoiceId);

        $this->assertSame(InvoiceStatus::PAID, $invoice->status);
        $this->assertSame(InvoiceOriginType::INVOICE, $invoice->originType);
        $this->assertSame(12345, $invoice->paidAmount);
        $this->assertSame(0, $invoice->refundedAmount);
        $this->assertSame([], $invoice->refunds);
        $this->assertNotNull($invoice->paidAt);
        $this->assertSame(PaymentMethod::CREDIT_CARD, $invoice->paymentMethod);
        $this->assertSame('visa', $invoice->creditCard->brand);
        $this->assertSame('4242', $invoice->creditCard->lastDigits);
    }

    /**
     * Cria um Invoice `open` de 12345 centavos na sandbox, direto no SDK.
     *
     * @param  string  $customerId
     * @return string  id do Invoice (`in_`)
     */
    private function createOpenStripeInvoice(string $customerId): string
    {
        $client = $this->stripeClient();
        $stripeInvoice = $client->invoices->create([
            'customer' => $customerId,
            'collection_method' => 'charge_automatically',
            'currency' => 'brl',
            'auto_advance' => false,
        ]);
        $client->invoiceItems->create([
            'customer' => $customerId,
            'invoice' => $stripeInvoice->id,
            'amount' => 12345,
            'currency' => 'brl',
            'description' => 'Assinatura mensal',
        ]);
        $client->invoices->finalizeInvoice($stripeInvoice->id, ['auto_advance' => false]);

        return $stripeInvoice->id;
    }

    private function stripeClient(): StripeClient
    {
        return new StripeClient([
            'api_key' => Config::get('multi-payment.gateways.stripe.api_key'),
            'stripe_version' => StripeGateway::STRIPE_API_VERSION,
        ]);
    }

    /**
     * Deve criar uma fatura de boleto pendente com o voucher hospedado, a linha digitável e o
     * PDF; o voucher em aberto não pode ser cancelado, e o estorno de boleto é recusado antes
     * da rede.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldCreateBankSlipInvoiceWithHostedVoucher($gateway)
    {
        $customerData = self::customerWithoutAddress();
        $addressData = self::address();
        $invoice = MultiPayment::setGateway($gateway)->newInvoice()
            ->addCustomer(
                $customerData['name'],
                $customerData['email'],
                $customerData['taxDocument']
            )
            ->addCustomerAddress(
                $addressData['zipCode'],
                $addressData['street'],
                $addressData['number'],
                $addressData['complement'],
                $addressData['district'],
                $addressData['city'],
                $addressData['state'],
                $addressData['country']
            )
            ->addItem('Assinatura mensal', 12345, 1)
            ->setAvailablePaymentMethods([PaymentMethod::BANK_SLIP])
            ->setDueDate(Carbon::today()->addDays(3))
            ->create();

        $this->assertNotNull($invoice->id);
        $this->assertEquals(InvoiceStatus::PENDING, $invoice->status);
        $this->assertEquals(PaymentMethod::BANK_SLIP, $invoice->paymentMethod);
        $this->assertStringContainsString('boleto/voucher', $invoice->url);
        $this->assertNotEmpty($invoice->bankSlip->number);
        $this->assertStringEndsWith('/pdf', $invoice->bankSlip->url);
        $this->assertSame(Carbon::today()->addDays(3)->format('Y-m-d'), $invoice->dueDate->format('Y-m-d'));

        $invoiceFetched = MultiPayment::setGateway($gateway)->getInvoice($invoice->id);
        $this->assertEquals(InvoiceStatus::PENDING, $invoiceFetched->status);
        $this->assertEquals(PaymentMethod::BANK_SLIP, $invoiceFetched->paymentMethod);
        $this->assertEquals($invoice->bankSlip->number, $invoiceFetched->bankSlip->number);

        try {
            $invoiceFetched->cancel($gateway);
            $this->fail('Esperava UnsupportedOperationException ao cancelar boleto pendente');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::INVOICE_CANCELLATION, $e->capability);
            $this->assertSame(UnsupportedOperationException::REASON_GATEWAY_LIMITATION, $e->reason);
        }

        try {
            MultiPayment::setGateway($gateway)->refundInvoice($invoice->id);
            $this->fail('Esperava RefundNotSupportedException para estorno de boleto');
        } catch (RefundNotSupportedException $e) {
            $this->assertSame(RefundNotSupportedException::REASON_BOLETO_NO_REFUND, $e->reason);
            $this->assertTrue($e->manualRefundRequired);
        }
    }

    /**
     * Sem `pixExpiresAt`, o QR Code expira no fim do dia de `dueDate`, e a Stripe aceita um
     * vencimento de hoje.
     *
     * @param  string  $gateway
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldCreateAPixInvoiceExpiringAtTheEndOfTheDueDate(string $gateway): void
    {
        $customerData = self::customerWithoutAddress();
        $invoice = MultiPayment::setGateway($gateway)->newInvoice()
            ->addCustomer($customerData['name'], $customerData['email'], $customerData['taxDocument'])
            ->addItem('Assinatura mensal', 5000, 1)
            ->setPaymentMethod(PaymentMethod::PIX)
            ->setDueDate(\Carbon\Carbon::today())
            ->create();

        $this->assertEquals(InvoiceStatus::PENDING, $invoice->status);
        $this->assertEquals(PaymentMethod::PIX, $invoice->paymentMethod);
        $this->assertEqualsWithDelta(
            \Carbon\Carbon::today()->endOfDay()->getTimestamp(),
            $invoice->pixExpiresAt->getTimestamp(),
            60
        );
        $this->assertSame(\Carbon\Carbon::today()->format('Y-m-d'), $invoice->dueDate->format('Y-m-d'));

        MultiPayment::setGateway($gateway)->cancelInvoice($invoice->id);
    }

    /**
     * Fatura de cartão com captura manual: o confirm só reserva o valor (`AUTHORIZED`) e a
     * captura parcial recebe o valor, com a Stripe liberando o restante da reserva.
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldAuthorizeAndCaptureAManualCaptureInvoice($gateway)
    {
        $invoice = $this->createManualCaptureInvoice($gateway);

        $this->assertSame(InvoiceStatus::AUTHORIZED, $invoice->status);
        $this->assertSame(CaptureMethod::MANUAL, $invoice->captureMethod);
        $this->assertNull($invoice->paidAmount);
        $this->assertNull($invoice->paidAt);

        $fetched = MultiPayment::setGateway($gateway)->getInvoice($invoice->id);
        $this->assertSame(InvoiceStatus::AUTHORIZED, $fetched->status);
        $this->assertSame(CaptureMethod::MANUAL, $fetched->captureMethod);

        $captured = MultiPayment::setGateway($gateway)->captureInvoice($invoice->id, 10000);

        $this->assertSame(InvoiceStatus::PAID, $captured->status);
        $this->assertSame(10000, $captured->paidAmount);
        $this->assertSame(PaymentMethod::CREDIT_CARD, $captured->paymentMethod);
    }

    /**
     * O cancelamento de uma fatura autorizada sem captura libera a reserva no cartão.
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldReleaseTheReservationWhenCancelingAnAuthorizedInvoice($gateway)
    {
        $invoice = $this->createManualCaptureInvoice($gateway);

        $canceled = MultiPayment::setGateway($gateway)->cancelInvoice($invoice->id);

        $this->assertSame(InvoiceStatus::CANCELED, $canceled->status);
    }

    /**
     * O cartão de teste de contestação (`pm_card_createDispute`) cria a dispute logo após o
     * pagamento: a fatura relida vem `DISPUTED` com `disputes` preenchido, a contestação é
     * legível por id e a resposta com a evidência mágica `winning_evidence` a encerra ganha.
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldReadContestAndWinADisputedInvoice($gateway)
    {
        $customerData = self::customerWithoutAddress();
        $invoice = MultiPayment::setGateway($gateway)->newInvoice()
            ->addCustomer(
                $customerData['name'],
                $customerData['email'],
                $customerData['taxDocument'],
                $customerData['birthDate'],
                $customerData['phoneArea'],
                $customerData['phoneNumber']
            )
            ->addItem('Compra contestada', 12345, 1)
            ->setAvailablePaymentMethods([PaymentMethod::CREDIT_CARD])
            ->addCreditCardToken('pm_card_createDispute')
            ->create();

        sleep(3); // a dispute de teste é criada logo após o pagamento

        $fetched = MultiPayment::setGateway($gateway)->getInvoice($invoice->id);
        $this->assertSame(InvoiceStatus::DISPUTED, $fetched->status);
        $this->assertNotEmpty($fetched->disputes);
        $this->assertSame(DisputeStatus::OPEN, $fetched->disputes[0]->status);
        $this->assertSame(12345, $fetched->disputes[0]->amount);
        $this->assertSame($invoice->id, $fetched->disputes[0]->invoiceId);
        $this->assertNotNull($fetched->disputes[0]->dueBy);

        $dispute = MultiPayment::setGateway($gateway)->getDispute($fetched->disputes[0]->id);
        $this->assertSame($fetched->disputes[0]->id, $dispute->id);
        $this->assertSame(DisputeStatus::OPEN, $dispute->status);

        // o encerramento da dispute de teste como ganha é assíncrono: logo após o submit a
        // resposta vem em análise, e o desfecho chega depois pelo webhook charge.dispute.closed
        $contested = MultiPayment::setGateway($gateway)
            ->contestDispute($dispute->id, ['uncategorized_text' => 'winning_evidence']);
        $this->assertContains($contested->status, [DisputeStatus::UNDER_REVIEW, DisputeStatus::WON]);
        $this->assertFalse($contested->status->isLost());
    }

    /**
     * A listagem de venda avulsa (origem `PAYMENT_INTENT`) filtra por cliente e devolve a
     * fatura criada; o filtro sem `originType` é recusado antes da rede.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldListPaymentIntentInvoicesByCustomer($gateway)
    {
        $customerData = self::customerWithoutAddress();
        $invoice = MultiPayment::setGateway($gateway)->newInvoice()
            ->addCustomer($customerData['name'], $customerData['email'], $customerData['taxDocument'])
            ->addItem('Listagem avulsa', 5000, 1)
            ->setAvailablePaymentMethods([PaymentMethod::PIX])
            ->create();

        $lista = MultiPayment::setGateway($gateway)->listInvoices(new InvoiceFilter(
            customerId: $invoice->customer->id,
            originType: InvoiceOriginType::PAYMENT_INTENT,
            limit: 10
        ));

        $this->assertCount(1, $lista);
        $this->assertSame($invoice->id, $lista[0]->id);
        $this->assertSame(InvoiceOriginType::PAYMENT_INTENT, $lista[0]->originType);
        $this->assertSame(InvoiceStatus::PENDING, $lista[0]->status);
        $this->assertNull($lista->total);
        $this->assertFalse($lista->hasMore);

        try {
            MultiPayment::setGateway($gateway)->listInvoices(new InvoiceFilter(customerId: $invoice->customer->id));
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::INVOICE_LISTING, $e->capability);
        }

        MultiPayment::setGateway($gateway)->cancelInvoice($invoice->id);
    }

    /**
     * A listagem da origem `INVOICE` filtra por cliente e devolve a fatura de assinatura com
     * o mesmo parse de `getInvoice()`: origem, status e vínculo pelo cliente preenchidos.
     *
     * @return void
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldListStripeInvoicesOfTheCustomer($gateway)
    {
        $customer = $this->createCustomer($gateway, self::customerWithoutAddress());
        $stripeInvoiceId = $this->createOpenStripeInvoice($customer->id);

        $lista = MultiPayment::setGateway($gateway)->listInvoices(new InvoiceFilter(
            customerId: $customer->id,
            originType: InvoiceOriginType::INVOICE,
            limit: 10
        ));

        $this->assertCount(1, $lista);
        $this->assertSame($stripeInvoiceId, $lista[0]->id);
        $this->assertSame(InvoiceOriginType::INVOICE, $lista[0]->originType);
        $this->assertSame(InvoiceStatus::PENDING, $lista[0]->status);
        $this->assertSame($customer->id, $lista[0]->customer->id);
        $this->assertFalse($lista->hasMore);

        MultiPayment::setGateway($gateway)->cancelInvoice($stripeInvoiceId);
    }

    /**
     * Cria uma fatura de cartão com `CaptureMethod::MANUAL` no cartão de teste.
     */
    private function createManualCaptureInvoice(string $gateway): Invoice
    {
        $customerData = self::customerWithoutAddress();

        return MultiPayment::setGateway($gateway)->newInvoice()
            ->addCustomer(
                $customerData['name'],
                $customerData['email'],
                $customerData['taxDocument'],
                $customerData['birthDate'],
                $customerData['phoneArea'],
                $customerData['phoneNumber']
            )
            ->addItem('Captura em duas etapas', 12345, 1)
            ->setAvailablePaymentMethods([PaymentMethod::CREDIT_CARD])
            ->addCreditCardToken('pm_card_visa')
            ->setCaptureMethod(CaptureMethod::MANUAL)
            ->create();
    }
}
