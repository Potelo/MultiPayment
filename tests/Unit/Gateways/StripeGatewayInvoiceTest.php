<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Carbon\Carbon;
use Stripe\ApiRequestor;
use Stripe\Exception\InvalidRequestException;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Refund;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\InvoiceItem;
use Potelo\MultiPayment\Gateways\StripeGateway;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\ValidationException;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Exceptions\ChargingException;
use Potelo\MultiPayment\Exceptions\RefundNotSupportedException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Enums\RefundStatus;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Tests\Unit\RecordingLogger;

class StripeGatewayInvoiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment.gateways.stripe.api_key' => 'sk_test_fake',
        ]));
        Facade::setFacadeApplication($app);

        // fake vazio por padrão: teste que esquecer withResponses() estoura em vez de ir à rede
        RecordingStripeHttpClient::withResponses([]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        ApiRequestor::setHttpClient(null);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testCreatesCreditCardInvoiceChargingSavedCard(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->paidCardPaymentIntentResponse()]);

        $result = (new StripeGateway())->createInvoice($this->creditCardInvoiceModel());

        $this->assertCount(1, $httpClient->calls);
        [$method, $url, $params] = $httpClient->calls[0];
        $this->assertSame('post', $method);
        $this->assertSame('/v1/payment_intents', parse_url($url, PHP_URL_PATH));
        $this->assertSame([
            'amount' => 12345,
            'currency' => 'brl',
            'customer' => 'cus_fake123',
            'metadata' => [
                'item_0_description' => 'Assinatura mensal',
                'item_0_price' => 12345,
                'item_0_quantity' => 1,
            ],
            'payment_method_types' => ['card'],
            'payment_method' => 'pm_fake123',
            // o encoder do stripe-php serializa booleanos como string antes da camada HTTP
            'confirm' => 'true',
            'off_session' => 'true',
            'expand' => ['latest_charge.balance_transaction', 'latest_charge.refunds'],
        ], $params);

        $this->assertSame('pi_fake123', $result->id);
        $this->assertSame(InvoiceStatus::PAID, $result->status);
        $this->assertSame(12345, $result->amount);
        $this->assertSame(12345, $result->paidAmount);
        $this->assertSame(0, $result->refundedAmount);
        $this->assertSame(425, $result->fee);
        $this->assertInstanceOf(Carbon::class, $result->paidAt);
        $this->assertSame(PaymentMethod::CREDIT_CARD, $result->paymentMethod);
        $this->assertSame('visa', $result->creditCard->brand);
        $this->assertSame('4242', $result->creditCard->lastDigits);
        $this->assertCount(1, $result->items);
        $this->assertSame('Assinatura mensal', $result->items[0]->description);
        $this->assertSame(12345, $result->items[0]->price);
        $this->assertSame('stripe', $result->gateway);
    }

    public function testCreatesCreditCardInvoiceSavingTokenizedCardFirst(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            $this->paymentMethodResponse(),
            $this->paidCardPaymentIntentResponse(),
        ]);

        $invoice = $this->creditCardInvoiceModel();
        $invoice->creditCard = new CreditCard();
        $invoice->creditCard->token = 'pm_fake123';
        (new StripeGateway())->createInvoice($invoice);

        $paths = array_map(static fn ($call) => $call[0] . ' ' . parse_url($call[1], PHP_URL_PATH), $httpClient->calls);
        $this->assertSame([
            'post /v1/payment_methods/pm_fake123/attach',
            'post /v1/payment_intents',
        ], $paths);
        $this->assertSame('cus_fake123', $httpClient->calls[0][2]['customer']);
        $this->assertSame('pm_fake123', $httpClient->calls[1][2]['payment_method']);
    }

    public function testRejectsInvoiceWithMultiplePaymentMethods(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);
        $invoice = $this->creditCardInvoiceModel();
        $invoice->availablePaymentMethods = [PaymentMethod::CREDIT_CARD, PaymentMethod::PIX];

        try {
            (new StripeGateway())->createInvoice($invoice);
            $this->fail('Fatura multi-método no Stripe deveria lançar UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::MULTIPLE_PAYMENT_METHODS, $e->capability);
            $this->assertSame(UnsupportedOperationException::REASON_NOT_IMPLEMENTED, $e->reason);
        }
        $this->assertSame([], $httpClient->calls);
    }

    public function testRejectsBankSlipInvoiceAttributingTheLimitationToTheLibrary(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);
        $invoice = $this->creditCardInvoiceModel();
        $invoice->creditCard = null;
        $invoice->availablePaymentMethods = [PaymentMethod::BANK_SLIP];

        try {
            (new StripeGateway())->createInvoice($invoice);
            $this->fail('Boleto no Stripe deveria lançar UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::BANK_SLIP, $e->capability);
            $this->assertSame('stripe', $e->gateway);
            $this->assertSame(UnsupportedOperationException::REASON_NOT_IMPLEMENTED, $e->reason);
            $this->assertStringContainsString('ainda não está implementada nesta lib', $e->getMessage());
            $this->assertStringNotContainsStringIgnoringCase('não oferece', $e->getMessage());
        }
        $this->assertSame([], $httpClient->calls);
    }

    public function testRejectsUnknownPaymentMethodStringOnWriteWithoutHittingTheApi(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);
        $invoice = $this->creditCardInvoiceModel();

        try {
            $invoice->availablePaymentMethods = ['foo'];
            $this->fail('Método de pagamento desconhecido deveria lançar ModelAttributeValidationException');
        } catch (ModelAttributeValidationException $e) {
            $this->assertStringContainsString('availablePaymentMethods must be one of', $e->getMessage());
        }
        $this->assertSame([], $httpClient->calls);
    }

    public function testRejectsNonSelectablePaymentMethodWithoutHittingTheApi(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);
        $invoice = $this->creditCardInvoiceModel();
        $invoice->availablePaymentMethods = [PaymentMethod::AUTOMATIC_PIX];

        try {
            (new StripeGateway())->createInvoice($invoice);
            $this->fail('Método de pagamento não selecionável deveria lançar ModelAttributeValidationException');
        } catch (ModelAttributeValidationException $e) {
            $this->assertStringContainsString('availablePaymentMethods must be one of: credit_card, bank_slip, pix', $e->getMessage());
        }
        $this->assertSame([], $httpClient->calls);
    }

    /**
     * `availablePaymentMethods[] = 'pix'` entra no array sem conversão; o driver normaliza
     * antes de escolher o método.
     */
    public function testStringAppendedToAvailablePaymentMethodsIsNormalizedBeforeUse(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->pendingPixPaymentIntentResponse()]);
        $invoice = $this->pixInvoiceModel();
        $invoice->availablePaymentMethods = [];
        $invoice->availablePaymentMethods[] = 'pix';

        $result = (new StripeGateway())->createInvoice($invoice);

        $this->assertSame(['pix'], $httpClient->calls[0][2]['payment_method_types']);
        $this->assertSame(PaymentMethod::PIX, $result->paymentMethod);
    }

    public function testCreatesPixInvoiceFullyServerSideAndParsesQrCode(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->pendingPixPaymentIntentResponse()]);

        $invoice = $this->pixInvoiceModel();
        // o parse sobrescreve pixExpiresAt com o valor devolvido pela Stripe: captura antes
        $requestedExpiresAt = $invoice->pixExpiresAt->getTimestamp();
        $result = (new StripeGateway())->createInvoice($invoice);

        $this->assertCount(1, $httpClient->calls);
        [$method, $url, $params] = $httpClient->calls[0];
        $this->assertSame('post', $method);
        $this->assertSame('/v1/payment_intents', parse_url($url, PHP_URL_PATH));
        $this->assertSame([
            'amount' => 12345,
            'currency' => 'brl',
            'customer' => 'cus_fake123',
            'metadata' => [
                'item_0_description' => 'Assinatura mensal',
                'item_0_price' => 12345,
                'item_0_quantity' => 1,
            ],
            'payment_method_types' => ['pix'],
            'payment_method_data' => [
                'type' => 'pix',
                'billing_details' => [
                    'name' => 'Fake Customer',
                    'email' => 'email@exemplo.com',
                    'tax_id' => '20176996915',
                ],
            ],
            'confirm' => 'true',
            'payment_method_options' => ['pix' => ['expires_at' => $requestedExpiresAt]],
            'expand' => ['latest_charge.balance_transaction', 'latest_charge.refunds'],
        ], $params);

        $this->assertSame(InvoiceStatus::PENDING, $result->status);
        $this->assertSame(PaymentMethod::PIX, $result->paymentMethod);
        $this->assertSame('00020126pixcopiaecola', $result->pix->qrCodeText);
        $this->assertSame('https://qr.stripe.com/test.png', $result->pix->qrCodeImageUrl);
        $this->assertSame('https://payments.stripe.com/qr/instructions/test', $result->url);
        $this->assertSame(1786800000, $result->pixExpiresAt->getTimestamp());
        $this->assertNull($result->dueDate);
        $this->assertNull($result->paidAmount);
    }

    public function testRejectsInvoiceWithAutomaticPixUntilSupported(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);
        $invoice = $this->pixInvoiceModel();
        $invoice->automaticPix = new \Potelo\MultiPayment\Models\AutomaticPix();

        try {
            (new StripeGateway())->createInvoice($invoice);
            $this->fail('Pix Automático no Stripe deveria lançar UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::AUTOMATIC_PIX, $e->capability);
            $this->assertSame(UnsupportedOperationException::REASON_NOT_IMPLEMENTED, $e->reason);
        }
        $this->assertSame([], $httpClient->calls);
    }

    public function testPixInvoiceRequiresCustomerTaxDocument(): void
    {
        $invoice = $this->pixInvoiceModel();
        $invoice->customer->taxDocument = null;

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessage('taxDocument');

        (new StripeGateway())->createInvoice($invoice);
    }

    public function testPixInvoiceRequiresCustomer(): void
    {
        $invoice = $this->pixInvoiceModel();
        $invoice->customer = null;

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessage('taxDocument');

        (new StripeGateway())->createInvoice($invoice);
    }

    public function testPixInvoiceWithoutPixExpiresAtOrDueDateOmitsPaymentMethodOptions(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->pendingPixPaymentIntentResponse()]);

        $invoice = $this->pixInvoiceModel();
        $invoice->pixExpiresAt = null;
        (new StripeGateway())->createInvoice($invoice);

        $this->assertArrayNotHasKey('payment_method_options', $httpClient->calls[0][2]);
    }

    public function testPixInvoiceRejectsPixExpiresAtOutsideStripeWindow(): void
    {
        $invoice = $this->pixInvoiceModel();
        $invoice->pixExpiresAt = Carbon::now()->subMinute();

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessage('pixExpiresAt must be more than 10 seconds and less than 14 days');

        (new StripeGateway())->createInvoice($invoice);
    }

    /**
     * Sem `pixExpiresAt`, o QR Code expira no fim do dia de `dueDate`: um vencimento de hoje
     * cabe na janela e `dueDate` permanece no model.
     */
    public function testPixInvoiceDerivesTheQrCodeExpiryFromTheEndOfTheDueDate(): void
    {
        Carbon::setTestNow('2026-09-02 10:00:00');
        $httpClient = RecordingStripeHttpClient::withResponses([$this->pendingPixPaymentIntentResponse()]);

        $invoice = $this->pixInvoiceModel();
        $invoice->pixExpiresAt = null;
        $invoice->dueDate = Carbon::parse('2026-09-02');
        (new StripeGateway())->createInvoice($invoice);

        $this->assertSame(
            Carbon::parse('2026-09-02 23:59:59')->getTimestamp(),
            $httpClient->calls[0][2]['payment_method_options']['pix']['expires_at']
        );
        $this->assertSame('2026-09-02', $invoice->dueDate->format('Y-m-d'));
    }

    public function testPixInvoiceGivesPixExpiresAtPrecedenceOverDueDate(): void
    {
        Carbon::setTestNow('2026-09-02 10:00:00');
        $httpClient = RecordingStripeHttpClient::withResponses([$this->pendingPixPaymentIntentResponse()]);

        $invoice = $this->pixInvoiceModel();
        $invoice->dueDate = Carbon::parse('2026-09-05');
        $invoice->pixExpiresAt = Carbon::parse('2026-09-02 14:00:00');
        (new StripeGateway())->createInvoice($invoice);

        $this->assertSame(
            Carbon::parse('2026-09-02 14:00:00')->getTimestamp(),
            $httpClient->calls[0][2]['payment_method_options']['pix']['expires_at']
        );
    }

    #[DataProvider('dueDateOutsideWindowProvider')]
    public function testPixInvoiceRejectsDueDateWhoseEndOfDayIsOutsideStripeWindow(string $dueDate): void
    {
        Carbon::setTestNow('2026-09-02 10:00:00');
        $invoice = $this->pixInvoiceModel();
        $invoice->pixExpiresAt = null;
        $invoice->dueDate = Carbon::parse($dueDate);

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessage('dueDate must be more than 10 seconds and less than 14 days');

        (new StripeGateway())->createInvoice($invoice);
    }

    public static function dueDateOutsideWindowProvider(): array
    {
        return [
            'vencida ontem' => ['2026-09-01'],
            'fim do dia alem de 14 dias' => ['2026-09-16'],
        ];
    }

    /**
     * `paymentMethod` com a lista vazia escolhe o método da fatura, como a lista faria.
     */
    #[DataProvider('paymentMethodOnlyProvider')]
    public function testPaymentMethodAloneSelectsTheStripePaymentMethodType(PaymentMethod $method, string $stripeType, bool $withCard): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            $withCard ? $this->paidCardPaymentIntentResponse() : $this->pendingPixPaymentIntentResponse(),
        ]);
        $invoice = $withCard ? $this->creditCardInvoiceModel() : $this->pixInvoiceModel();
        $invoice->availablePaymentMethods = null;
        $invoice->paymentMethod = $method;

        $result = (new StripeGateway())->createInvoice($invoice);

        $this->assertSame([$stripeType], $httpClient->calls[0][2]['payment_method_types']);
        $this->assertSame($method, $result->paymentMethod);
    }

    public static function paymentMethodOnlyProvider(): array
    {
        return [
            'cartao' => [PaymentMethod::CREDIT_CARD, 'card', true],
            'pix' => [PaymentMethod::PIX, 'pix', false],
        ];
    }

    public function testInvoiceWithoutAnyPaymentMethodIsRejectedBeforeTheNetwork(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);
        $invoice = $this->pixInvoiceModel();
        $invoice->availablePaymentMethods = null;

        try {
            (new StripeGateway())->createInvoice($invoice);
            $this->fail('Fatura sem método deveria lançar ModelAttributeValidationException');
        } catch (ModelAttributeValidationException $e) {
            $this->assertStringContainsString('paymentMethod or availablePaymentMethods', $e->getMessage());
        }
        $this->assertSame([], $httpClient->calls);
    }

    public function testPixInvoiceBillingDetailsOmitsMissingNameAndEmail(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->pendingPixPaymentIntentResponse()]);

        $invoice = $this->pixInvoiceModel();
        $invoice->customer->name = null;
        $invoice->customer->email = null;
        (new StripeGateway())->createInvoice($invoice);

        $this->assertSame(
            ['tax_id' => '20176996915'],
            $httpClient->calls[0][2]['payment_method_data']['billing_details']
        );
    }

    public function testIdempotencyKeyArgumentBecomesRequestHeader(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->pendingPixPaymentIntentResponse()]);

        (new StripeGateway())->createInvoice($this->pixInvoiceModel(), 'chave-unica-123');

        $this->assertSame('chave-unica-123', $httpClient->header(0, 'Idempotency-Key'));
        $this->assertArrayNotHasKey('idempotency_key', $httpClient->calls[0][2]);
    }

    public function testWithoutIdempotencyKeyNoHeaderIsSent(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->pendingPixPaymentIntentResponse()]);

        (new StripeGateway())->createInvoice($this->pixInvoiceModel());

        $this->assertNull($httpClient->header(0, 'Idempotency-Key'));
    }

    /**
     * A chave antiga em `gatewayOptions` ainda vira o cabeçalho, com aviso de deprecação, e
     * não vaza como parâmetro do payload (a API a rejeitaria).
     */
    #[IgnoreDeprecations]
    public function testLegacyIdempotencyKeyInGatewayOptionsStillBecomesTheHeaderWithADeprecation(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->pendingPixPaymentIntentResponse()]);

        $invoice = $this->pixInvoiceModel();
        $invoice->gatewayOptions = ['idempotency_key' => 'chave-antiga'];

        $this->expectUserDeprecationMessage("gateway_options['idempotency_key'] está obsoleto desde 2026-09-02; passe idempotencyKey como argumento da operação");

        (new StripeGateway())->createInvoice($invoice);

        $this->assertSame('chave-antiga', $httpClient->header(0, 'Idempotency-Key'));
        $this->assertArrayNotHasKey('idempotency_key', $httpClient->calls[0][2]);
    }

    /**
     * O argumento tem precedência sobre a chave antiga em `gatewayOptions`.
     */
    #[IgnoreDeprecations]
    public function testIdempotencyKeyArgumentWinsOverTheLegacyGatewayOption(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->pendingPixPaymentIntentResponse()]);

        $invoice = $this->pixInvoiceModel();
        $invoice->gatewayOptions = ['idempotency_key' => 'chave-antiga'];

        (new StripeGateway())->createInvoice($invoice, 'chave-nova');

        $this->assertSame('chave-nova', $httpClient->header(0, 'Idempotency-Key'));
    }

    public function testCancelsPendingInvoice(): void
    {
        $response = $this->paidCardPaymentIntentResponse(status: 'canceled');
        $response['latest_charge'] = null;
        $httpClient = RecordingStripeHttpClient::withResponses([$response]);

        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';
        $result = (new StripeGateway())->cancelInvoice($invoice);

        [$method, $url, $params] = $httpClient->calls[0];
        $this->assertSame('post', $method);
        $this->assertSame('/v1/payment_intents/pi_fake123/cancel', parse_url($url, PHP_URL_PATH));
        $this->assertSame(['expand' => ['latest_charge.balance_transaction', 'latest_charge.refunds']], $params);
        $this->assertSame(InvoiceStatus::CANCELED, $result->status);
    }

    public function testCancelPaidInvoiceBecomesValidationException(): void
    {
        RecordingStripeHttpClient::withResponses([
            [['error' => [
                'type' => 'invalid_request_error',
                'code' => 'payment_intent_unexpected_state',
                'message' => 'This PaymentIntent could not be canceled because it has a status of succeeded.',
            ]], 400],
        ]);

        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';

        try {
            (new StripeGateway())->cancelInvoice($invoice);
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $exception) {
            $this->assertSame('payment_intent_unexpected_state', $exception->getErrors()['code']);
            $this->assertSame(
                ['base' => ['This PaymentIntent could not be canceled because it has a status of succeeded.']],
                $exception->fieldErrors
            );
        }
    }

    public function testCancelInvoiceRequiresId(): void
    {
        $this->expectException(ModelAttributeValidationException::class);

        (new StripeGateway())->cancelInvoice(new Invoice());
    }

    public function testCardDeclineBecomesChargingExceptionWithNormalizedReason(): void
    {
        RecordingStripeHttpClient::withResponses([
            [['error' => [
                'type' => 'card_error',
                'code' => 'card_declined',
                'decline_code' => 'generic_decline',
                'message' => 'Your card was declined.',
                'payment_intent' => ['id' => 'pi_fake123', 'object' => 'payment_intent', 'status' => 'requires_payment_method'],
            ]], 402],
        ]);

        try {
            (new StripeGateway())->createInvoice($this->creditCardInvoiceModel());
            $this->fail('Expected ChargingException was not thrown');
        } catch (ChargingException $exception) {
            $this->assertSame('card_declined', $exception->reason);
            $this->assertNotEmpty($exception->chargeResponse);
            $this->assertSame('pi_fake123', $exception->chargeResponse['payment_intent']['id']);
        }
    }

    public function testDeclineDuringCardAttachAlsoBecomesChargingException(): void
    {
        // a Stripe valida o cartão já no attach — recusa nesse ponto precisa manter a
        // semântica de falha de cobrança
        RecordingStripeHttpClient::withResponses([
            [['error' => [
                'type' => 'card_error',
                'code' => 'card_declined',
                'decline_code' => 'generic_decline',
                'message' => 'Your card was declined.',
            ]], 402],
        ]);

        $invoice = $this->creditCardInvoiceModel();
        $invoice->creditCard = new CreditCard();
        $invoice->creditCard->token = 'pm_fake123';

        try {
            (new StripeGateway())->createInvoice($invoice);
            $this->fail('Expected ChargingException was not thrown');
        } catch (ChargingException $exception) {
            $this->assertSame('card_declined', $exception->reason);
            $this->assertSame('card_error', $exception->chargeResponse['type']);
        }
    }

    public function testAuthenticationRequiredDeclineIsNormalized(): void
    {
        RecordingStripeHttpClient::withResponses([
            [['error' => [
                'type' => 'card_error',
                'code' => 'authentication_required',
                'decline_code' => 'authentication_required',
                'message' => 'This transaction requires authentication.',
            ]], 402],
        ]);

        try {
            (new StripeGateway())->createInvoice($this->creditCardInvoiceModel());
            $this->fail('Expected ChargingException was not thrown');
        } catch (ChargingException $exception) {
            $this->assertSame('authentication_required', $exception->reason);
        }
    }

    public function testBrandNotSupportedDeclineIsNormalized(): void
    {
        RecordingStripeHttpClient::withResponses([
            [['error' => [
                'type' => 'card_error',
                'code' => 'card_declined',
                'decline_code' => 'card_not_supported',
                'message' => 'Your card is not supported.',
            ]], 402],
        ]);

        try {
            (new StripeGateway())->createInvoice($this->creditCardInvoiceModel());
            $this->fail('Expected ChargingException was not thrown');
        } catch (ChargingException $exception) {
            $this->assertSame('brand_not_supported', $exception->reason);
        }
    }

    public function testGetInvoiceParsesFullRefund(): void
    {
        $response = $this->paidCardPaymentIntentResponse();
        $response['latest_charge']['amount_refunded'] = 12345;
        $response['latest_charge']['refunded'] = true;
        RecordingStripeHttpClient::withResponses([$response]);

        $result = $this->getInvoice();

        $this->assertSame(InvoiceStatus::REFUNDED, $result->status);
        $this->assertSame(12345, $result->refundedAmount);
    }

    public function testGetInvoiceParsesPartialRefund(): void
    {
        $response = $this->paidCardPaymentIntentResponse();
        $response['latest_charge']['amount_refunded'] = 2345;
        RecordingStripeHttpClient::withResponses([$response]);

        $result = $this->getInvoice();

        $this->assertSame(InvoiceStatus::PARTIALLY_REFUNDED, $result->status);
        $this->assertSame(2345, $result->refundedAmount);
    }

    public function testGetInvoiceReportsExpiredPixAsPendingIgnoringFailedCharge(): void
    {
        // pix expirado: o PI volta a requires_payment_method com o charge em failed
        $response = $this->paidCardPaymentIntentResponse();
        $response['status'] = 'requires_payment_method';
        $response['payment_method_types'] = ['pix'];
        $response['latest_charge']['status'] = 'failed';
        $response['latest_charge']['paid'] = false;
        $response['latest_charge']['amount_captured'] = 0;
        $response['latest_charge']['payment_method_details'] = ['type' => 'pix', 'pix' => []];
        $response['latest_charge']['balance_transaction'] = null;
        RecordingStripeHttpClient::withResponses([$response]);

        $result = $this->getInvoice();

        $this->assertSame(InvoiceStatus::PENDING, $result->status);
        $this->assertNull($result->paidAmount);
        $this->assertNull($result->paidAt);
        $this->assertSame(PaymentMethod::PIX, $result->paymentMethod);
    }

    public function testGetInvoiceReadsAnUnexpectedStatusAsUnknownWithAWarning(): void
    {
        Facade::getFacadeApplication()->instance('log', $logger = new RecordingLogger());
        $response = $this->paidCardPaymentIntentResponse();
        $response['status'] = 'partially_funded';
        $response['latest_charge'] = null;
        RecordingStripeHttpClient::withResponses([$response]);

        $result = $this->getInvoice();

        $this->assertSame(InvoiceStatus::UNKNOWN, $result->status);
        $this->assertSame('partially_funded', $result->original->status);
        $this->assertCount(1, $logger->records);
        $this->assertSame('warning', $logger->records[0]['level']);
        $this->assertSame(['status' => 'partially_funded', 'gateway' => 'stripe'], $logger->records[0]['context']);
    }

    /**
     * Cada tipo de PaymentMethod da Stripe em `payment_method_details.type` chega como o caso
     * do enum; tipo fora do mapa fica nulo.
     */
    public static function stripePaymentMethodTypeProvider(): array
    {
        return [
            'card' => ['card', PaymentMethod::CREDIT_CARD],
            'pix' => ['pix', PaymentMethod::PIX],
            'boleto' => ['boleto', PaymentMethod::BANK_SLIP],
            'tipo fora do mapa' => ['card_present', null],
        ];
    }

    #[DataProvider('stripePaymentMethodTypeProvider')]
    public function testGetInvoiceParsesTheStripePaymentMethodType(string $type, ?PaymentMethod $expected): void
    {
        $response = $this->paidCardPaymentIntentResponse();
        $response['payment_method_types'] = [$type];
        $response['latest_charge']['payment_method_details'] = ['type' => $type];
        RecordingStripeHttpClient::withResponses([$response]);

        $result = $this->getInvoice();

        $this->assertSame($expected, $result->paymentMethod);
        $this->assertSame($expected ? [$expected] : null, $result->availablePaymentMethods);
    }

    public function testRequiresCaptureReadsAsAuthorizedAndProcessingAsProcessing(): void
    {
        RecordingStripeHttpClient::withResponses([$this->paidCardPaymentIntentResponse(status: 'requires_capture')]);
        $authorized = $this->getInvoice()->status;

        RecordingStripeHttpClient::withResponses([$this->paidCardPaymentIntentResponse(status: 'processing')]);
        $processing = $this->getInvoice()->status;

        $this->assertSame(InvoiceStatus::AUTHORIZED, $authorized);
        $this->assertTrue($authorized->isOpen());
        $this->assertFalse($authorized->isSettled());
        $this->assertSame(InvoiceStatus::PROCESSING, $processing);
        $this->assertTrue($processing->isOpen());
        $this->assertFalse($processing->isSettled());
    }

    /**
     * Status de dispute em aberto, conforme docs.stripe.com/api/disputes/object.
     */
    public static function openDisputeStatusProvider(): array
    {
        return [
            ['warning_needs_response'],
            ['warning_under_review'],
            ['needs_response'],
            ['under_review'],
        ];
    }

    #[DataProvider('openDisputeStatusProvider')]
    public function testGetInvoiceReportsOpenDisputeAsDisputed(string $disputeStatus): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            $this->disputedCardPaymentIntentResponse(),
            $this->disputeListResponse([$disputeStatus]),
        ]);

        $result = $this->getInvoice();

        $this->assertSame(InvoiceStatus::DISPUTED, $result->status);
        $this->assertNotSame(InvoiceStatus::PAID, $result->status);
        // o dinheiro continua contabilizado no charge até a resolução
        $this->assertSame(12345, $result->paidAmount);

        $this->assertCount(2, $httpClient->calls);
        [$method, $url, $params] = $httpClient->calls[1];
        $this->assertSame('get', $method);
        $this->assertSame('/v1/disputes', parse_url($url, PHP_URL_PATH));
        $this->assertSame(['charge' => 'ch_fake123', 'limit' => 100], $params);
    }

    public function testGetInvoiceReportsLostDisputeAsChargeback(): void
    {
        RecordingStripeHttpClient::withResponses([
            $this->disputedCardPaymentIntentResponse(),
            $this->disputeListResponse(['lost']),
        ]);

        $result = $this->getInvoice();

        $this->assertSame(InvoiceStatus::CHARGEBACK, $result->status);
        $this->assertNotSame(InvoiceStatus::REFUNDED, $result->status);
        $this->assertTrue($result->status->isContested());
        $this->assertFalse($result->status->isSettled());
    }

    /**
     * Status de dispute encerrada sem devolução ao cliente: a fatura volta ao status normal.
     */
    public static function closedDisputeStatusProvider(): array
    {
        return [
            ['won'],
            ['warning_closed'],
            ['prevented'],
        ];
    }

    #[DataProvider('closedDisputeStatusProvider')]
    public function testGetInvoiceKeepsDerivedStatusWhenDisputeWasWonOrClosed(string $disputeStatus): void
    {
        RecordingStripeHttpClient::withResponses([
            $this->disputedCardPaymentIntentResponse(),
            $this->disputeListResponse([$disputeStatus]),
        ]);

        $this->assertSame(InvoiceStatus::PAID, $this->getInvoice()->status);
    }

    public static function disputeOverRefundProvider(): array
    {
        return [
            'aberta sobre estorno parcial' => ['needs_response', 2345, false, InvoiceStatus::DISPUTED],
            'perdida sobre estorno total' => ['lost', 12345, true, InvoiceStatus::CHARGEBACK],
        ];
    }

    #[DataProvider('disputeOverRefundProvider')]
    public function testGetInvoiceDisputeTakesPrecedenceOverRefund(string $disputeStatus, int $refunded, bool $fully, InvoiceStatus $expected): void
    {
        $response = $this->disputedCardPaymentIntentResponse();
        $response['latest_charge']['amount_refunded'] = $refunded;
        $response['latest_charge']['refunded'] = $fully;
        RecordingStripeHttpClient::withResponses([
            $response,
            $this->disputeListResponse([$disputeStatus]),
        ]);

        $result = $this->getInvoice();

        $this->assertSame($expected, $result->status);
        $this->assertSame($refunded, $result->refundedAmount);
    }

    public function testGetInvoiceFallsBackToDerivedStatusWhenDisputedChargeHasNoDisputes(): void
    {
        // a flag pode chegar antes da dispute aparecer na listagem; sem dispute a fatura
        // segue a derivação normal
        $httpClient = RecordingStripeHttpClient::withResponses([
            $this->disputedCardPaymentIntentResponse(),
            $this->disputeListResponse([]),
        ]);

        $this->assertSame(InvoiceStatus::PAID, $this->getInvoice()->status);
        $this->assertCount(2, $httpClient->calls);
    }

    public function testGetInvoiceOpenDisputeWinsOverAnEarlierWonOne(): void
    {
        RecordingStripeHttpClient::withResponses([
            $this->disputedCardPaymentIntentResponse(),
            $this->disputeListResponse(['won', 'needs_response']),
        ]);

        $this->assertSame(InvoiceStatus::DISPUTED, $this->getInvoice()->status);
    }

    public function testGetInvoiceDoesNotListDisputesWhenChargeIsNotDisputed(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->paidCardPaymentIntentResponse()]);

        $this->assertSame(InvoiceStatus::PAID, $this->getInvoice()->status);
        $this->assertCount(1, $httpClient->calls);
    }

    public function testGetInvoiceDoesNotListDisputesForFailedCharge(): void
    {
        // charge failed marcado como disputed não existe na prática, mas o parse só consulta
        // disputes de charge pago
        $response = $this->disputedCardPaymentIntentResponse();
        $response['status'] = 'requires_payment_method';
        $response['latest_charge']['status'] = 'failed';
        $httpClient = RecordingStripeHttpClient::withResponses([$response]);

        $this->assertSame(InvoiceStatus::PENDING, $this->getInvoice()->status);
        $this->assertCount(1, $httpClient->calls);
    }

    public function testChargeInvoiceWithCreditCardUpdatesIntentBeforeConfirming(): void
    {
        // PI sem customer + PaymentMethod salvo: o customer do dono do cartão é vinculado
        $pendingIntent = $this->paidCardPaymentIntentResponse(status: 'requires_payment_method');
        $pendingIntent['customer'] = null;
        $httpClient = RecordingStripeHttpClient::withResponses([
            $this->paymentMethodResponse(customer: 'cus_fake123'),
            $pendingIntent,
            $this->paidCardPaymentIntentResponse(),
            $this->paidCardPaymentIntentResponse(),
        ]);

        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';
        $invoice->creditCard = new CreditCard();
        $invoice->creditCard->id = 'pm_fake123';
        $result = (new StripeGateway())->chargeInvoiceWithCreditCard($invoice);

        $paths = array_map(static fn ($call) => $call[0] . ' ' . parse_url($call[1], PHP_URL_PATH), $httpClient->calls);
        $this->assertSame([
            'get /v1/payment_methods/pm_fake123',
            'get /v1/payment_intents/pi_fake123',
            'post /v1/payment_intents/pi_fake123',
            'post /v1/payment_intents/pi_fake123/confirm',
        ], $paths);
        $this->assertSame(
            ['payment_method_types' => ['card'], 'customer' => 'cus_fake123'],
            $httpClient->calls[2][2]
        );
        $this->assertSame('pm_fake123', $httpClient->calls[3][2]['payment_method']);
        $this->assertSame('true', $httpClient->calls[3][2]['off_session']);
        $this->assertSame(InvoiceStatus::PAID, $result->status);
    }

    public function testChargeInvoiceWithMatchingCustomerResendsItInTheUpdate(): void
    {
        // PI e PaymentMethod do mesmo customer: o update repete o customer, sem efeito na Stripe,
        // para o payload ser o mesmo num retry com a mesma chave de idempotência
        $httpClient = RecordingStripeHttpClient::withResponses([
            $this->paymentMethodResponse(customer: 'cus_fake123'),
            $this->paidCardPaymentIntentResponse(status: 'requires_payment_method'),
            $this->paidCardPaymentIntentResponse(),
            $this->paidCardPaymentIntentResponse(),
        ]);

        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';
        $invoice->creditCard = new CreditCard();
        $invoice->creditCard->id = 'pm_fake123';
        (new StripeGateway())->chargeInvoiceWithCreditCard($invoice);

        $this->assertSame(['payment_method_types' => ['card'], 'customer' => 'cus_fake123'], $httpClient->calls[2][2]);
    }

    public function testChargeInvoiceRejectsCardFromAnotherCustomer(): void
    {
        RecordingStripeHttpClient::withResponses([
            $this->paymentMethodResponse(customer: 'cus_other'),
            $this->paidCardPaymentIntentResponse(status: 'requires_payment_method'),
        ]);

        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';
        $invoice->creditCard = new CreditCard();
        $invoice->creditCard->id = 'pm_fake123';

        try {
            (new StripeGateway())->chargeInvoiceWithCreditCard($invoice);
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertStringContainsString('does not belong to customer', $e->getMessage());
            $this->assertSame(Capability::CREDIT_CARD, $e->capability);
            $this->assertSame(UnsupportedOperationException::REASON_GATEWAY_LIMITATION, $e->reason);
            $this->assertNull($e->httpStatus);
        }
    }

    public function testChargeInvoiceWithLegacyTokenConvertsItIntoPaymentMethod(): void
    {
        $pendingIntent = $this->paidCardPaymentIntentResponse(status: 'requires_payment_method');
        $pendingIntent['customer'] = null;
        $httpClient = RecordingStripeHttpClient::withResponses([
            $this->paymentMethodResponse(),
            $this->paymentMethodResponse(),
            $pendingIntent,
            $this->paidCardPaymentIntentResponse(),
            $this->paidCardPaymentIntentResponse(),
        ]);

        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';
        $invoice->creditCard = new CreditCard();
        $invoice->creditCard->token = 'tok_fake123';
        (new StripeGateway())->chargeInvoiceWithCreditCard($invoice);

        $paths = array_map(static fn ($call) => $call[0] . ' ' . parse_url($call[1], PHP_URL_PATH), $httpClient->calls);
        $this->assertSame([
            'post /v1/payment_methods',
            'get /v1/payment_methods/pm_fake123',
            'get /v1/payment_intents/pi_fake123',
            'post /v1/payment_intents/pi_fake123',
            'post /v1/payment_intents/pi_fake123/confirm',
        ], $paths);
        $this->assertSame('pm_fake123', $httpClient->calls[4][2]['payment_method']);
    }

    public function testGatewayOptionsOverrideAndExpandIsMerged(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->paidCardPaymentIntentResponse()]);

        $invoice = $this->creditCardInvoiceModel();
        $invoice->gatewayOptions = [
            'statement_descriptor_suffix' => 'POTELO',
            'off_session' => false,
            'expand' => ['customer'],
        ];
        (new StripeGateway())->createInvoice($invoice);

        $params = $httpClient->calls[0][2];
        $this->assertSame('POTELO', $params['statement_descriptor_suffix']);
        // a opção do consumidor vence a chave montada pelo gateway
        $this->assertSame('false', $params['off_session']);
        // o expand do consumidor é mesclado, não descartado
        $this->assertSame(['customer', 'latest_charge.balance_transaction', 'latest_charge.refunds'], $params['expand']);
    }

    /**
     * Status do PaymentIntent sem estorno mapeado para o status genérico.
     *
     * @return array[]
     */
    public static function paymentIntentStatusDataProvider(): array
    {
        return [
            ['succeeded', InvoiceStatus::PAID],
            ['canceled', InvoiceStatus::CANCELED],
            ['processing', InvoiceStatus::PROCESSING],
            ['requires_action', InvoiceStatus::PENDING],
            ['requires_confirmation', InvoiceStatus::PENDING],
            ['requires_capture', InvoiceStatus::AUTHORIZED],
            ['requires_payment_method', InvoiceStatus::PENDING],
        ];
    }

    #[DataProvider('paymentIntentStatusDataProvider')]
    public function testStatusMapping(string $stripeStatus, InvoiceStatus $expected): void
    {
        $response = $this->paidCardPaymentIntentResponse(status: $stripeStatus);
        RecordingStripeHttpClient::withResponses([$response]);

        $this->assertSame($expected, $this->getInvoice()->status);
    }

    public function testExplicitAmountTakesPrecedenceOverItemsSum(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->paidCardPaymentIntentResponse()]);

        $invoice = $this->creditCardInvoiceModel();
        $invoice->amount = 999;
        (new StripeGateway())->createInvoice($invoice);

        $this->assertSame(999, $httpClient->calls[0][2]['amount']);
    }

    public function testAmountFallsBackToItemsSumMultiplyingQuantity(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->paidCardPaymentIntentResponse()]);

        $invoice = $this->creditCardInvoiceModel();
        $invoice->items[0]->quantity = 3;
        (new StripeGateway())->createInvoice($invoice);

        $this->assertSame(37035, $httpClient->calls[0][2]['amount']);
    }

    public function testChargeInvoiceWithCreditCardRequiresTokenOrId(): void
    {
        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';
        $invoice->creditCard = new CreditCard();

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessage('Credit card token or id is required');

        (new StripeGateway())->chargeInvoiceWithCreditCard($invoice);
    }

    /**
     * Só com o id, o driver lê a fatura antes das guardas (um GET), cria o refund e relê o
     * PaymentIntent. O `Refund` devolvido vem do objeto da Stripe e carrega a fatura relida.
     */
    public function testRefundsInvoiceTotally(): void
    {
        $refunded = $this->paidCardPaymentIntentResponse();
        $refunded['latest_charge']['amount_refunded'] = 12345;
        $refunded['latest_charge']['refunded'] = true;
        $refunded['latest_charge']['refunds'] = $this->refundListResponse([
            $this->refundResponse('re_fake123', 12345, 'succeeded'),
        ]);
        $httpClient = RecordingStripeHttpClient::withResponses([
            $this->paidCardPaymentIntentResponse(),
            $this->refundResponse('re_fake123', 12345, 'pending'),
            $refunded,
        ]);

        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';
        $result = (new StripeGateway())->refundInvoice($invoice);

        $this->assertSame([
            'get /v1/payment_intents/pi_fake123',
            'post /v1/refunds',
            'get /v1/payment_intents/pi_fake123',
        ], $this->calledPaths($httpClient));
        // sem amount: estorno total
        $this->assertSame(['payment_intent' => 'pi_fake123'], $httpClient->calls[1][2]);

        $this->assertInstanceOf(Refund::class, $result);
        $this->assertSame('re_fake123', $result->id);
        $this->assertSame('pi_fake123', $result->invoiceId);
        $this->assertSame(12345, $result->amount);
        $this->assertSame(RefundStatus::PENDING, $result->status);
        $this->assertSame(1786700100, $result->createdAt->getTimestamp());
        $this->assertSame('requested_by_customer', $result->reason);
        $this->assertSame('stripe', $result->gateway);
        $this->assertSame('re_fake123', $result->original->id);

        $this->assertSame($invoice, $result->invoice());
        $this->assertSame(InvoiceStatus::REFUNDED, $invoice->status);
        $this->assertSame(12345, $invoice->refundedAmount);
        $this->assertCount(1, $invoice->refunds);
        $this->assertSame('re_fake123', $invoice->refunds[0]->id);
        $this->assertSame(RefundStatus::SUCCEEDED, $invoice->refunds[0]->status);
        $this->assertCount(3, $httpClient->calls, 'invoice() não faz requisição');
    }

    public function testRefundsInvoicePartially(): void
    {
        $refunded = $this->paidCardPaymentIntentResponse();
        $refunded['latest_charge']['amount_refunded'] = 2345;
        $httpClient = RecordingStripeHttpClient::withResponses([
            $this->paidCardPaymentIntentResponse(),
            $this->refundResponse('re_fake123', 2345, 'pending'),
            $refunded,
        ]);

        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';
        $result = (new StripeGateway())->refundInvoice($invoice, 2345);

        $this->assertSame(
            ['payment_intent' => 'pi_fake123', 'amount' => 2345],
            $httpClient->calls[1][2]
        );
        $this->assertSame(2345, $result->amount);
        $this->assertSame(InvoiceStatus::PARTIALLY_REFUNDED, $result->invoice()->status);
        $this->assertSame(2345, $result->invoice()->refundedAmount);
    }

    public function testRefundInvoiceRequiresId(): void
    {
        $this->expectException(ModelAttributeValidationException::class);

        (new StripeGateway())->refundInvoice(new Invoice());
    }

    /**
     * Boleto ainda não existe neste driver; a guarda já nasce coberta para quando entrar.
     */
    public function testBoletoRefundWithThePaymentMethodInHandMakesNoRequest(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);
        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';
        $invoice->paymentMethod = PaymentMethod::BANK_SLIP;
        $invoice->status = InvoiceStatus::PAID;

        $exception = $this->refundExpectingRefusal($invoice);

        $this->assertSame(RefundNotSupportedException::REASON_BOLETO_NO_REFUND, $exception->reason);
        $this->assertSame(PaymentMethod::BANK_SLIP->value, $exception->paymentMethod);
        $this->assertTrue($exception->manualRefundRequired);
        $this->assertSame([], $httpClient->calls);
    }

    public function testBoletoRefundThrowsBeforePostingAfterReadingTheInvoice(): void
    {
        $response = $this->paidCardPaymentIntentResponse();
        $response['payment_method_types'] = ['boleto'];
        $response['latest_charge']['payment_method_details'] = ['type' => 'boleto', 'boleto' => []];
        $httpClient = RecordingStripeHttpClient::withResponses([$response]);

        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';
        $exception = $this->refundExpectingRefusal($invoice);

        $this->assertSame(RefundNotSupportedException::REASON_BOLETO_NO_REFUND, $exception->reason);
        $this->assertSame(['get /v1/payment_intents/pi_fake123'], $this->calledPaths($httpClient));
    }

    public function testAlreadyRefundedInvoiceWithTheStatusInHandMakesNoRequest(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);
        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';
        $invoice->paymentMethod = PaymentMethod::CREDIT_CARD;
        $invoice->status = InvoiceStatus::REFUNDED;

        $exception = $this->refundExpectingRefusal($invoice);

        $this->assertSame(RefundNotSupportedException::REASON_ALREADY_REFUNDED, $exception->reason);
        $this->assertSame(PaymentMethod::CREDIT_CARD->value, $exception->paymentMethod);
        $this->assertFalse($exception->manualRefundRequired);
        $this->assertSame([], $httpClient->calls);
    }

    /**
     * Só com o id, a leitura prévia é o que faz a guarda de fatura já estornada disparar sem
     * um POST que a Stripe recusaria com `charge_already_refunded`.
     */
    public function testAlreadyRefundedInvoiceWithOnlyTheIdIsRefusedAfterReadingIt(): void
    {
        $response = $this->paidCardPaymentIntentResponse();
        $response['latest_charge']['amount_refunded'] = 12345;
        $response['latest_charge']['refunded'] = true;
        $httpClient = RecordingStripeHttpClient::withResponses([$response]);

        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';
        $exception = $this->refundExpectingRefusal($invoice);

        $this->assertSame(RefundNotSupportedException::REASON_ALREADY_REFUNDED, $exception->reason);
        $this->assertSame(['get /v1/payment_intents/pi_fake123'], $this->calledPaths($httpClient));
        $this->assertNull($invoice->status, 'a leitura prévia não altera o model do chamador');
    }

    /**
     * Pix parcial é permitido na Stripe: a guarda da Iugu não pode vazar para cá.
     */
    public function testPartialPixRefundGoesToTheGateway(): void
    {
        $refunded = $this->paidPixPaymentIntentResponse();
        $refunded['latest_charge']['amount_refunded'] = 2345;
        $httpClient = RecordingStripeHttpClient::withResponses([
            $this->paidPixPaymentIntentResponse(),
            $this->refundResponse('re_fake123', 2345, 'succeeded'),
            $refunded,
        ]);

        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';
        $invoice->paymentMethod = PaymentMethod::PIX;
        $result = (new StripeGateway())->refundInvoice($invoice, 2345);

        $this->assertSame([
            'get /v1/payment_intents/pi_fake123',
            'post /v1/refunds',
            'get /v1/payment_intents/pi_fake123',
        ], $this->calledPaths($httpClient));
        $this->assertSame(['payment_intent' => 'pi_fake123', 'amount' => 2345], $httpClient->calls[1][2]);
        $this->assertSame('re_fake123', $result->id);
        $this->assertSame(RefundStatus::SUCCEEDED, $result->status);
        $this->assertSame(InvoiceStatus::PARTIALLY_REFUNDED, $result->invoice()->status);
        $this->assertSame(2345, $result->invoice()->refundedAmount);
    }

    /**
     * Um model lido do gateway, pago e sem estorno anterior não paga o GET extra no estorno
     * por valor: o restante estornável é o valor pago.
     */
    public function testPaidInvoiceReadFromTheGatewayDoesNotPayTheExtraGet(): void
    {
        $refunded = $this->paidCardPaymentIntentResponse();
        $refunded['latest_charge']['amount_refunded'] = 2345;
        $httpClient = RecordingStripeHttpClient::withResponses([
            $this->paidCardPaymentIntentResponse(),
            $this->refundResponse('re_fake123', 2345, 'succeeded'),
            $refunded,
        ]);
        $gateway = new StripeGateway();

        $invoice = $gateway->getInvoice($this->invoiceWithId());
        $result = $gateway->refundInvoice($invoice, 2345);

        $this->assertSame([
            'get /v1/payment_intents/pi_fake123',
            'post /v1/refunds',
            'get /v1/payment_intents/pi_fake123',
        ], $this->calledPaths($httpClient));
        $this->assertSame(2345, $result->amount);
    }

    /**
     * Fatura parcialmente estornada aceita novo estorno até o restante. Com o status fora de
     * `PAID` e sem o acumulado em `refundedAmount`, o driver relê a fatura para conhecer o
     * restante.
     */
    public function testSecondPartialRefundWithinTheRemainderGoesToTheGateway(): void
    {
        $partiallyRefunded = $this->paidCardPaymentIntentResponse();
        $partiallyRefunded['latest_charge']['amount_refunded'] = 2345;
        $refunded = $this->paidCardPaymentIntentResponse();
        $refunded['latest_charge']['amount_refunded'] = 7345;
        $httpClient = RecordingStripeHttpClient::withResponses([
            $partiallyRefunded,
            $this->refundResponse('re_fake456', 5000, 'succeeded'),
            $refunded,
        ]);

        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';
        $invoice->paymentMethod = PaymentMethod::CREDIT_CARD;
        $invoice->status = InvoiceStatus::PARTIALLY_REFUNDED;
        $invoice->paidAmount = 12345;
        $result = (new StripeGateway())->refundInvoice($invoice, 5000);

        $this->assertSame([
            'get /v1/payment_intents/pi_fake123',
            'post /v1/refunds',
            'get /v1/payment_intents/pi_fake123',
        ], $this->calledPaths($httpClient));
        $this->assertSame(['payment_intent' => 'pi_fake123', 'amount' => 5000], $httpClient->calls[1][2]);
        $this->assertSame('re_fake456', $result->id);
        $this->assertSame(5000, $result->amount);
        $this->assertSame(InvoiceStatus::PARTIALLY_REFUNDED, $result->invoice()->status);
        $this->assertSame(7345, $result->invoice()->refundedAmount);
    }

    /**
     * Sem leitura prévia (model em `PAID` com `paidAmount`), o restante é o valor pago e a
     * recusa acontece sem nenhuma requisição além da leitura inicial.
     */
    public function testRefundAboveThePaidAmountOnAModelReadFromTheGatewayThrowsWithoutAnotherRequest(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->paidCardPaymentIntentResponse()]);
        $gateway = new StripeGateway();

        $invoice = $gateway->getInvoice($this->invoiceWithId());

        try {
            $gateway->refundInvoice($invoice, 12346);
            $this->fail('Esperava RefundNotSupportedException');
        } catch (RefundNotSupportedException $e) {
            $this->assertSame(RefundNotSupportedException::REASON_AMOUNT_EXCEEDS_REFUNDABLE, $e->reason);
            $this->assertStringContainsString('12345', $e->getMessage());
        }
        $this->assertSame(['get /v1/payment_intents/pi_fake123'], $this->calledPaths($httpClient));
    }

    /**
     * Regressão: a leitura prévia parseia uma cópia, e a cópia precisa ser profunda, senão o
     * `customer` do model do chamador recebe os dados da resposta mesmo quando a guarda dispara.
     */
    public function testRefusedRefundLeavesTheCallerNestedObjectsUntouched(): void
    {
        $partiallyRefunded = $this->paidCardPaymentIntentResponse();
        $partiallyRefunded['latest_charge']['amount_refunded'] = 2345;
        RecordingStripeHttpClient::withResponses([$partiallyRefunded]);

        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';
        $invoice->customer = new Customer();
        $invoice->customer->name = 'Nome do chamador';
        $invoice->creditCard = new CreditCard();
        $this->refundExpectingRefusal($invoice, 11000);

        $this->assertSame('Nome do chamador', $invoice->customer->name);
        $this->assertNull($invoice->customer->id);
        $this->assertNull($invoice->creditCard->brand);
    }

    /**
     * Model lido do gateway em `partially_refunded`: `refundInvoice()` sem valor estorna o
     * restante, sem enviar `amount` (o acumulado em `refundedAmount` é só leitura).
     */
    public function testRefundWithoutAmountOnAPartiallyRefundedModelReadFromTheGatewayRefundsTheRemainder(): void
    {
        $partiallyRefunded = $this->paidCardPaymentIntentResponse();
        $partiallyRefunded['latest_charge']['amount_refunded'] = 2345;
        $refunded = $this->paidCardPaymentIntentResponse();
        $refunded['latest_charge']['amount_refunded'] = 12345;
        $refunded['latest_charge']['refunded'] = true;
        $httpClient = RecordingStripeHttpClient::withResponses([
            $partiallyRefunded,
            $this->refundResponse('re_fake456', 10000, 'succeeded'),
            $refunded,
        ]);
        $gateway = new StripeGateway();

        $invoice = $gateway->getInvoice($this->invoiceWithId());
        $result = $gateway->refundInvoice($invoice);

        $this->assertSame([
            'get /v1/payment_intents/pi_fake123',
            'post /v1/refunds',
            'get /v1/payment_intents/pi_fake123',
        ], $this->calledPaths($httpClient));
        $this->assertSame(['payment_intent' => 'pi_fake123'], $httpClient->calls[1][2]);
        $this->assertSame(10000, $result->amount);
        $this->assertSame(InvoiceStatus::REFUNDED, $invoice->status);
        $this->assertSame(12345, $invoice->refundedAmount);
    }

    /**
     * Model lido do gateway em `partially_refunded` traz o acumulado confiável, então o estorno
     * por valor não paga o GET extra: o restante é `paidAmount` menos `refundedAmount`.
     */
    public function testPartialRefundOnAPartiallyRefundedModelReadFromTheGatewayDoesNotPayTheExtraGet(): void
    {
        $partiallyRefunded = $this->paidCardPaymentIntentResponse();
        $partiallyRefunded['latest_charge']['amount_refunded'] = 2345;
        $refundedTwice = $this->paidCardPaymentIntentResponse();
        $refundedTwice['latest_charge']['amount_refunded'] = 7345;
        $httpClient = RecordingStripeHttpClient::withResponses([
            $partiallyRefunded,
            $this->refundResponse('re_fake456', 5000, 'succeeded'),
            $refundedTwice,
        ]);
        $gateway = new StripeGateway();

        $invoice = $gateway->getInvoice($this->invoiceWithId());
        $result = $gateway->refundInvoice($invoice, 5000);

        $this->assertSame([
            'get /v1/payment_intents/pi_fake123',
            'post /v1/refunds',
            'get /v1/payment_intents/pi_fake123',
        ], $this->calledPaths($httpClient));
        $this->assertSame(['payment_intent' => 'pi_fake123', 'amount' => 5000], $httpClient->calls[1][2]);
        $this->assertSame(5000, $result->amount);
        $this->assertSame(7345, $invoice->refundedAmount);
    }

    /**
     * Caminho antigo: escrever `refundedAmount` num model lido do gateway continua pedindo o
     * estorno parcial desse valor, com aviso de deprecação; como o acumulado deixou de ser
     * confiável, o driver relê a fatura antes.
     */
    public function testWritingRefundedAmountOnAModelReadFromTheGatewayStillRequestsThatPartialRefund(): void
    {
        $partiallyRefunded = $this->paidCardPaymentIntentResponse();
        $partiallyRefunded['latest_charge']['amount_refunded'] = 2345;
        $refundedTwice = $this->paidCardPaymentIntentResponse();
        $refundedTwice['latest_charge']['amount_refunded'] = 4690;
        $httpClient = RecordingStripeHttpClient::withResponses([
            $partiallyRefunded,
            $partiallyRefunded,
            $this->refundResponse('re_fake456', 2345, 'succeeded'),
            $refundedTwice,
        ]);
        $gateway = new StripeGateway();
        $invoice = $gateway->getInvoice($this->invoiceWithId());

        $this->expectUserDeprecationMessage('Invoice::$refundedAmount é só de leitura desde 2026-09-02; passe o valor do estorno em refund(amount:) ou refundInvoice($id, $amount)');
        $invoice->refundedAmount = 2345;
        $result = $gateway->refundInvoice($invoice);

        $this->assertSame('post /v1/refunds', $this->calledPaths($httpClient)[2]);
        $this->assertSame(['payment_intent' => 'pi_fake123', 'amount' => 2345], $httpClient->calls[2][2]);
        $this->assertSame(2345, $result->amount);
        $this->assertSame(4690, $invoice->refundedAmount);
        $this->assertNull($invoice->requestedRefundAmount());
    }

    /**
     * Model em `PAID` com `paidAmount` e sem acumulado estornado é confiável: o restante é o
     * valor pago, e o estorno por valor não relê a fatura.
     */
    public function testPartialRefundOnAPaidModelWithThePaidAmountInHandDoesNotReadTheInvoiceFirst(): void
    {
        $partiallyRefunded = $this->paidCardPaymentIntentResponse();
        $partiallyRefunded['latest_charge']['amount_refunded'] = 2345;
        $httpClient = RecordingStripeHttpClient::withResponses([
            $this->refundResponse('re_fake456', 2345, 'succeeded'),
            $partiallyRefunded,
        ]);
        $invoice = $this->invoiceWithId();
        $invoice->paymentMethod = PaymentMethod::CREDIT_CARD;
        $invoice->status = InvoiceStatus::PAID;
        $invoice->paidAmount = 12345;

        $result = (new StripeGateway())->refundInvoice($invoice, 2345);

        $this->assertSame(['post /v1/refunds', 'get /v1/payment_intents/pi_fake123'], $this->calledPaths($httpClient));
        $this->assertSame(['payment_intent' => 'pi_fake123', 'amount' => 2345], $httpClient->calls[0][2]);
        $this->assertSame(2345, $result->amount);
    }

    public function testRefundAboveThePaidAmountOnAPaidModelWithThePaidAmountInHandIsRefusedWithoutAnyRequest(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);
        $invoice = $this->invoiceWithId();
        $invoice->paymentMethod = PaymentMethod::CREDIT_CARD;
        $invoice->status = InvoiceStatus::PAID;
        $invoice->paidAmount = 12345;

        $exception = $this->refundExpectingRefusal($invoice, 12346);

        $this->assertSame(RefundNotSupportedException::REASON_AMOUNT_EXCEEDS_REFUNDABLE, $exception->reason);
        $this->assertSame([], $httpClient->calls);
    }

    public function testZeroOrNegativeAmountIsRejectedBeforeAnyRequest(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);

        foreach ([0, -1] as $amount) {
            try {
                (new StripeGateway())->refundInvoice($this->invoiceWithId(), $amount);
                $this->fail("Esperava ModelAttributeValidationException para {$amount}");
            } catch (ModelAttributeValidationException $e) {
                $this->assertStringContainsString('amount', $e->getMessage());
            }
        }

        $this->assertSame([], $httpClient->calls);
    }

    /**
     * `refundableAmount()` é `paidAmount` menos `refundedAmount` (o valor pago da Stripe vem
     * bruto); um model lido do gateway não paga requisição, um model só com o id lê a fatura, e
     * um model com o acumulado escrito pelo caminho antigo relê a fatura.
     */
    public function testRefundableAmountIsThePaidMinusTheRefundedAndReadsTheInvoiceOnlyWhenNeeded(): void
    {
        $partiallyRefunded = $this->paidCardPaymentIntentResponse();
        $partiallyRefunded['latest_charge']['amount_refunded'] = 2345;
        $refunded = $this->paidCardPaymentIntentResponse();
        $refunded['latest_charge']['amount_refunded'] = 12345;
        $refunded['latest_charge']['refunded'] = true;
        $httpClient = RecordingStripeHttpClient::withResponses([
            $partiallyRefunded,
            $partiallyRefunded,
            $refunded,
            $this->paidCardPaymentIntentResponse(status: 'requires_payment_method'),
        ]);
        $gateway = new StripeGateway();

        $read = $gateway->getInvoice($this->invoiceWithId());
        $this->assertSame(10000, $gateway->refundableAmount($read));
        $this->assertCount(1, $httpClient->calls, 'o model lido do gateway não custa requisição');

        $this->assertSame(10000, $gateway->refundableAmount($this->invoiceWithId()));
        $this->assertSame(0, $gateway->refundableAmount($this->invoiceWithId()));
        $this->assertSame(0, $gateway->refundableAmount($this->invoiceWithId()), 'fatura não paga');
        $this->assertCount(4, $httpClient->calls);
    }

    public function testRefundableAmountReReadsTheInvoiceWhenTheLegacyPathWroteTheRefundedAmount(): void
    {
        $partiallyRefunded = $this->paidCardPaymentIntentResponse();
        $partiallyRefunded['latest_charge']['amount_refunded'] = 2345;
        $httpClient = RecordingStripeHttpClient::withResponses([$partiallyRefunded, $partiallyRefunded]);
        $gateway = new StripeGateway();
        $invoice = $gateway->getInvoice($this->invoiceWithId());

        $this->expectUserDeprecationMessage('Invoice::$refundedAmount é só de leitura desde 2026-09-02; passe o valor do estorno em refund(amount:) ou refundInvoice($id, $amount)');
        $invoice->refundedAmount = 5000;

        $this->assertSame(10000, $gateway->refundableAmount($invoice));
        $this->assertCount(2, $httpClient->calls);
    }

    public function testSecondPartialRefundAboveTheRemainderThrowsBeforePosting(): void
    {
        $partiallyRefunded = $this->paidCardPaymentIntentResponse();
        $partiallyRefunded['latest_charge']['amount_refunded'] = 2345;
        $httpClient = RecordingStripeHttpClient::withResponses([$partiallyRefunded]);

        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';
        $exception = $this->refundExpectingRefusal($invoice, 11000);

        $this->assertSame(RefundNotSupportedException::REASON_AMOUNT_EXCEEDS_REFUNDABLE, $exception->reason);
        $this->assertSame(PaymentMethod::CREDIT_CARD->value, $exception->paymentMethod);
        $this->assertFalse($exception->manualRefundRequired);
        $this->assertStringContainsString('11000', $exception->getMessage());
        $this->assertStringContainsString('10000', $exception->getMessage());
        $this->assertSame(['get /v1/payment_intents/pi_fake123'], $this->calledPaths($httpClient));
        $this->assertNull($invoice->refundedAmount, 'a leitura prévia não altera o model do chamador');
    }

    public function testRefundingTheExactRemainderOfAPartiallyRefundedInvoiceGoesToTheGateway(): void
    {
        $partiallyRefunded = $this->paidCardPaymentIntentResponse();
        $partiallyRefunded['latest_charge']['amount_refunded'] = 2345;
        $refunded = $this->paidCardPaymentIntentResponse();
        $refunded['latest_charge']['amount_refunded'] = 12345;
        $refunded['latest_charge']['refunded'] = true;
        $httpClient = RecordingStripeHttpClient::withResponses([
            $partiallyRefunded,
            $this->refundResponse('re_fake456', 10000, 'succeeded'),
            $refunded,
        ]);

        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';
        $invoice->status = InvoiceStatus::PARTIALLY_REFUNDED;
        $result = (new StripeGateway())->refundInvoice($invoice, 10000);

        $this->assertSame('post', $httpClient->calls[1][0]);
        $this->assertSame(['payment_intent' => 'pi_fake123', 'amount' => 10000], $httpClient->calls[1][2]);
        $this->assertSame(InvoiceStatus::REFUNDED, $result->invoice()->status);
        $this->assertSame('re_fake456', $result->id);
    }

    public function testGetInvoiceListsTheRefundsOfTheCharge(): void
    {
        $response = $this->paidCardPaymentIntentResponse();
        $response['latest_charge']['amount_refunded'] = 5345;
        $response['latest_charge']['refunds'] = $this->refundListResponse([
            $this->refundResponse('re_fake123', 2345, 'succeeded'),
            $this->refundResponse('re_fake456', 3000, 'pending', 1786700200, null),
        ]);
        RecordingStripeHttpClient::withResponses([$response]);

        $result = $this->getInvoice();

        $this->assertSame(InvoiceStatus::PARTIALLY_REFUNDED, $result->status);
        $this->assertCount(2, $result->refunds);
        $this->assertContainsOnlyInstancesOf(Refund::class, $result->refunds);
        $this->assertSame('re_fake123', $result->refunds[0]->id);
        $this->assertSame(2345, $result->refunds[0]->amount);
        $this->assertSame(RefundStatus::SUCCEEDED, $result->refunds[0]->status);
        $this->assertSame('requested_by_customer', $result->refunds[0]->reason);
        $this->assertSame(1786700100, $result->refunds[0]->createdAt->getTimestamp());
        $this->assertSame('pi_fake123', $result->refunds[0]->invoiceId);
        $this->assertSame('stripe', $result->refunds[0]->gateway);
        $this->assertNull($result->refunds[0]->invoice, 'um Refund lido da fatura não carrega a fatura');
        $this->assertSame('re_fake456', $result->refunds[1]->id);
        $this->assertSame(RefundStatus::PENDING, $result->refunds[1]->status);
        $this->assertNull($result->refunds[1]->reason);
    }

    public function testGetInvoiceWithoutRefundHasAnEmptyRefundsList(): void
    {
        RecordingStripeHttpClient::withResponses([$this->paidCardPaymentIntentResponse()]);

        $this->assertSame([], $this->getInvoice()->refunds);
    }

    /**
     * Resposta com estorno mas sem a lista `refunds` expandida: a lista não pode contradizer
     * `refundedAmount`, então volta um único `Refund` sem id com o acumulado.
     */
    public function testGetInvoiceWithoutTheRefundsListFallsBackToASingleRefundWithoutId(): void
    {
        $response = $this->paidCardPaymentIntentResponse();
        $response['latest_charge']['amount_refunded'] = 2345;
        RecordingStripeHttpClient::withResponses([$response]);

        $loggerAnterior = \Stripe\Stripe::getLogger();
        $logger = new RecordingStripeLogger();
        \Stripe\Stripe::setLogger($logger);

        try {
            $result = $this->getInvoice();
        } finally {
            \Stripe\Stripe::setLogger($loggerAnterior);
        }

        $this->assertSame([], $logger->messages, 'ler `refunds` ausente não pode logar Undefined property');
        $this->assertCount(1, $result->refunds);
        $this->assertNull($result->refunds[0]->id);
        $this->assertSame(2345, $result->refunds[0]->amount);
        $this->assertSame(RefundStatus::SUCCEEDED, $result->refunds[0]->status);
    }

    #[DataProvider('refundStatusProvider')]
    public function testRefundStatusIsMappedToTheGenericEnum(string $stripeStatus, RefundStatus $expected): void
    {
        $response = $this->paidCardPaymentIntentResponse();
        $response['latest_charge']['amount_refunded'] = 2345;
        $response['latest_charge']['refunds'] = $this->refundListResponse([
            $this->refundResponse('re_fake123', 2345, $stripeStatus),
        ]);
        RecordingStripeHttpClient::withResponses([$response]);

        $this->assertSame($expected, $this->getInvoice()->refunds[0]->status);
    }

    public static function refundStatusProvider(): array
    {
        return [
            'pending' => ['pending', RefundStatus::PENDING],
            'requires_action' => ['requires_action', RefundStatus::PENDING],
            'succeeded' => ['succeeded', RefundStatus::SUCCEEDED],
            'failed' => ['failed', RefundStatus::FAILED],
            'canceled' => ['canceled', RefundStatus::CANCELED],
        ];
    }

    public function testRefundStatusOutsideTheMapIsReadAsUnknownWithAWarning(): void
    {
        Facade::getFacadeApplication()->instance('log', $logger = new RecordingLogger());
        $response = $this->paidCardPaymentIntentResponse();
        $response['latest_charge']['amount_refunded'] = 2345;
        $response['latest_charge']['refunds'] = $this->refundListResponse([
            $this->refundResponse('re_fake123', 2345, 'status_novo'),
        ]);
        RecordingStripeHttpClient::withResponses([$response]);

        $this->assertSame(RefundStatus::UNKNOWN, $this->getInvoice()->refunds[0]->status);
        $this->assertCount(1, $logger->records);
        $this->assertSame('warning', $logger->records[0]['level']);
        $this->assertSame(['status' => 'status_novo', 'gateway' => 'stripe'], $logger->records[0]['context']);
    }

    public function testDuplicatesPendingPixInvoiceCancelingTheOriginal(): void
    {
        $newIntent = $this->pendingPixPaymentIntentResponse();
        $newIntent['id'] = 'pi_fake456';
        $canceled = $this->pendingPixPaymentIntentResponse();
        $canceled['status'] = 'canceled';
        $canceled['next_action'] = null;
        $httpClient = RecordingStripeHttpClient::withResponses([
            $this->pendingPixPaymentIntentResponse(),
            $this->duplicableCustomerResponse(),
            $newIntent,
            $canceled,
        ]);

        $expiresAt = Carbon::now()->addDay();
        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';
        $result = (new StripeGateway())->duplicateInvoice($invoice, $expiresAt);

        $paths = array_map(static fn ($call) => $call[0] . ' ' . parse_url($call[1], PHP_URL_PATH), $httpClient->calls);
        $this->assertSame([
            'get /v1/payment_intents/pi_fake123',
            'get /v1/customers/cus_fake123',
            'post /v1/payment_intents',
            'post /v1/payment_intents/pi_fake123/cancel',
        ], $paths);

        // payload completo; o metadata é o da fatura original preservado (valores string,
        // como a Stripe devolve), não a reserialização dos items
        $this->assertSame([
            'amount' => 12345,
            'currency' => 'brl',
            'customer' => 'cus_fake123',
            'metadata' => [
                'item_0_description' => 'Assinatura mensal',
                'item_0_price' => '12345',
                'item_0_quantity' => '1',
            ],
            'payment_method_types' => ['pix'],
            'payment_method_data' => [
                'type' => 'pix',
                'billing_details' => [
                    'name' => 'Fake Customer',
                    'email' => 'email@exemplo.com',
                    'tax_id' => '20176996915',
                ],
            ],
            'confirm' => 'true',
            'payment_method_options' => ['pix' => ['expires_at' => $expiresAt->getTimestamp()]],
            'expand' => ['latest_charge.balance_transaction', 'latest_charge.refunds'],
        ], $httpClient->calls[2][2]);

        $this->assertSame('pi_fake456', $result->id);
        $this->assertSame(InvoiceStatus::PENDING, $result->status);
    }

    public function testDuplicateFallsBackToOriginalBillingTaxIdWhenCustomerHasNone(): void
    {
        $original = $this->pendingPixPaymentIntentResponse();
        $original['payment_method'] = [
            'id' => 'pm_pix_fake',
            'object' => 'payment_method',
            'type' => 'pix',
            'billing_details' => ['name' => 'Fake Customer', 'email' => 'email@exemplo.com', 'tax_id' => '201.769.969-15'],
        ];
        $customer = $this->duplicableCustomerResponse();
        $customer['tax_ids']['data'] = [];
        $newIntent = $this->pendingPixPaymentIntentResponse();
        $newIntent['id'] = 'pi_fake456';
        $canceled = $this->pendingPixPaymentIntentResponse();
        $canceled['status'] = 'canceled';
        $canceled['next_action'] = null;
        $httpClient = RecordingStripeHttpClient::withResponses([$original, $customer, $newIntent, $canceled]);

        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';
        (new StripeGateway())->duplicateInvoice($invoice, Carbon::now()->addDay());

        $this->assertSame(
            '201.769.969-15',
            $httpClient->calls[2][2]['payment_method_data']['billing_details']['tax_id']
        );
    }

    public function testDuplicateWithoutAnyTaxDocumentFailsWithoutCancelingTheOriginal(): void
    {
        $customer = $this->duplicableCustomerResponse();
        $customer['tax_ids']['data'] = [];
        $original = $this->pendingPixPaymentIntentResponse();
        $original['payment_method'] = null;
        $original['last_payment_error'] = null;
        $httpClient = RecordingStripeHttpClient::withResponses([$original, $customer]);

        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';

        try {
            (new StripeGateway())->duplicateInvoice($invoice, Carbon::now()->addDay());
            $this->fail('Expected ModelAttributeValidationException was not thrown');
        } catch (ModelAttributeValidationException $exception) {
            // a original não pode ter sido cancelada — só os dois GETs aconteceram
            $this->assertCount(2, $httpClient->calls);
        }
    }

    public function testDuplicatePassesGatewayOptionsToTheNewIntent(): void
    {
        $newIntent = $this->pendingPixPaymentIntentResponse();
        $newIntent['id'] = 'pi_fake456';
        $canceled = $this->pendingPixPaymentIntentResponse();
        $canceled['status'] = 'canceled';
        $canceled['next_action'] = null;
        $httpClient = RecordingStripeHttpClient::withResponses([
            $this->pendingPixPaymentIntentResponse(),
            $this->duplicableCustomerResponse(),
            $newIntent,
            $canceled,
        ]);

        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';
        (new StripeGateway())->duplicateInvoice($invoice, Carbon::now()->addDay(), ['statement_descriptor' => 'DUP']);

        $this->assertSame('DUP', $httpClient->calls[2][2]['statement_descriptor']);
    }

    public function testDuplicateReportsTheNewInvoiceWhenCancelingTheOriginalFails(): void
    {
        $newIntent = $this->pendingPixPaymentIntentResponse();
        $newIntent['id'] = 'pi_fake456';
        RecordingStripeHttpClient::withResponses([
            $this->pendingPixPaymentIntentResponse(),
            $this->duplicableCustomerResponse(),
            $newIntent,
            [['error' => [
                'type' => 'invalid_request_error',
                'code' => 'payment_intent_unexpected_state',
                'message' => 'This PaymentIntent could not be canceled.',
            ]], 400],
        ]);

        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';

        try {
            (new StripeGateway())->duplicateInvoice($invoice, Carbon::now()->addDay());
            $this->fail('Esperava GatewayException');
        } catch (GatewayException $e) {
            $this->assertStringContainsString('Invoice duplicated as [pi_fake456]', $e->getMessage());
            // a falha do cancelamento continua acessível, com a exceção do SDK abaixo dela
            $this->assertInstanceOf(ValidationException::class, $e->getPrevious());
            $this->assertInstanceOf(InvalidRequestException::class, $e->getPrevious()->getPrevious());
            $this->assertSame(400, $e->httpStatus);
        }
    }

    public function testDuplicateRejectsPaidInvoice(): void
    {
        RecordingStripeHttpClient::withResponses([$this->paidCardPaymentIntentResponse()]);

        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';

        try {
            (new StripeGateway())->duplicateInvoice($invoice, Carbon::now()->addDay());
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::INVOICE_DUPLICATION, $e->capability);
            $this->assertSame(UnsupportedOperationException::REASON_GATEWAY_LIMITATION, $e->reason);
            $this->assertSame('No Stripe só uma fatura Pix pendente pode ser duplicada; a fatura [pi_fake123] está [paid].', $e->getMessage());
        }
    }

    /**
     * Fatura sem cliente no PaymentIntent é restrição de `INVOICE_DUPLICATION`: a recusa vem
     * depois da leitura da original e antes de criar ou cancelar qualquer coisa.
     */
    public function testDuplicateRejectsAnInvoiceWithoutCustomerAsARestriction(): void
    {
        $pendingPix = $this->pendingPixPaymentIntentResponse();
        $pendingPix['customer'] = null;
        $httpClient = RecordingStripeHttpClient::withResponses([$pendingPix]);

        try {
            (new StripeGateway())->duplicateInvoice($this->invoiceWithId(), Carbon::parse('2026-10-01'));
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::INVOICE_DUPLICATION, $e->capability);
            $this->assertSame(UnsupportedOperationException::REASON_GATEWAY_LIMITATION, $e->reason);
            $this->assertNull($e->httpStatus);
            $this->assertStringContainsString('has no customer', $e->getMessage());
        }
        $this->assertSame(['get /v1/payment_intents/pi_fake123'], $this->calledPaths($httpClient), 'a original não é cancelada');
    }

    public function testDuplicateRejectsNonPixInvoice(): void
    {
        $response = $this->paidCardPaymentIntentResponse(status: 'requires_payment_method');
        $response['latest_charge'] = null;
        RecordingStripeHttpClient::withResponses([$response]);

        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';

        try {
            (new StripeGateway())->duplicateInvoice($invoice, Carbon::now()->addDay());
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::INVOICE_DUPLICATION, $e->capability);
            $this->assertSame(UnsupportedOperationException::REASON_GATEWAY_LIMITATION, $e->reason);
            $this->assertStringContainsString('a fatura [pi_fake123] não é Pix', $e->getMessage());
        }
    }

    public function testDuplicateInvoiceRequiresId(): void
    {
        $this->expectException(ModelAttributeValidationException::class);

        (new StripeGateway())->duplicateInvoice(new Invoice(), Carbon::now()->addDay());
    }

    private function duplicableCustomerResponse(): array
    {
        return [
            'id' => 'cus_fake123',
            'object' => 'customer',
            'name' => 'Fake Customer',
            'email' => 'email@exemplo.com',
            'phone' => null,
            'address' => null,
            'metadata' => [],
            'created' => 1786700000,
            'invoice_settings' => ['default_payment_method' => null],
            'tax_ids' => [
                'object' => 'list',
                'data' => [
                    ['id' => 'txi_fake1', 'object' => 'tax_id', 'type' => 'br_cpf', 'value' => '20176996915'],
                ],
            ],
        ];
    }

    private function invoiceWithId(): Invoice
    {
        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';

        return $invoice;
    }

    private function refundExpectingRefusal(Invoice $invoice, ?int $amount = null): RefundNotSupportedException
    {
        try {
            (new StripeGateway())->refundInvoice($invoice, $amount);
        } catch (RefundNotSupportedException $e) {
            return $e;
        }

        $this->fail('Esperava RefundNotSupportedException');
    }

    /**
     * @return string[]  método e caminho de cada chamada, na ordem
     */
    private function calledPaths(RecordingStripeHttpClient $httpClient): array
    {
        return array_map(
            static fn ($call) => $call[0] . ' ' . parse_url($call[1], PHP_URL_PATH),
            $httpClient->calls
        );
    }

    private function refundResponse(string $id, int $amount, string $status, int $created = 1786700100, ?string $reason = 'requested_by_customer'): array
    {
        return [
            'id' => $id,
            'object' => 'refund',
            'amount' => $amount,
            'currency' => 'brl',
            'status' => $status,
            'created' => $created,
            'reason' => $reason,
            'payment_intent' => 'pi_fake123',
            'charge' => 'ch_fake123',
        ];
    }

    private function refundListResponse(array $refunds): array
    {
        return [
            'object' => 'list',
            'data' => $refunds,
            'has_more' => false,
            'url' => '/v1/charges/ch_fake123/refunds',
        ];
    }

    private function getInvoice(): Invoice
    {
        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';

        return (new StripeGateway())->getInvoice($invoice);
    }

    private function creditCardInvoiceModel(): Invoice
    {
        $invoice = new Invoice();
        $invoice->customer = new Customer();
        $invoice->customer->id = 'cus_fake123';
        $invoice->availablePaymentMethods = [PaymentMethod::CREDIT_CARD];
        $invoice->creditCard = new CreditCard();
        $invoice->creditCard->id = 'pm_fake123';
        $item = new InvoiceItem();
        $item->description = 'Assinatura mensal';
        $item->price = 12345;
        $item->quantity = 1;
        $invoice->items = [$item];

        return $invoice;
    }

    private function pixInvoiceModel(): Invoice
    {
        $invoice = $this->creditCardInvoiceModel();
        $invoice->creditCard = null;
        $invoice->availablePaymentMethods = [PaymentMethod::PIX];
        $invoice->customer->name = 'Fake Customer';
        $invoice->customer->email = 'email@exemplo.com';
        $invoice->customer->taxDocument = '20176996915';
        $invoice->pixExpiresAt = Carbon::now()->addHour();

        return $invoice;
    }

    /**
     * O StripeObject registra "Undefined property" no logger da Stripe ao ler uma chave
     * ausente, e `?->` não protege contra isso porque o objeto pai existe. Em fatura pix
     * paga, payment_method_details vem sem `card`, então o parse não pode acessar a chave
     * às cegas sob pena de sujar o log de produção a cada fatura.
     */
    public function testParsingPaidPixInvoiceDoesNotLogUndefinedProperty(): void
    {
        RecordingStripeHttpClient::withResponses([$this->paidPixPaymentIntentResponse()]);

        $loggerAnterior = \Stripe\Stripe::getLogger();
        $logger = new RecordingStripeLogger();
        \Stripe\Stripe::setLogger($logger);

        try {
            $invoice = new Invoice();
            $invoice->id = 'pi_fake123';
            $result = (new StripeGateway())->getInvoice($invoice);
        } finally {
            \Stripe\Stripe::setLogger($loggerAnterior);
        }

        $this->assertSame(InvoiceStatus::PAID, $result->status);
        $this->assertSame(PaymentMethod::PIX, $result->paymentMethod);
        $this->assertNull($result->creditCard);
        $this->assertSame([], $logger->messages);
    }

    private function paidPixPaymentIntentResponse(): array
    {
        $response = $this->paidCardPaymentIntentResponse();
        $response['payment_method_types'] = ['pix'];
        $response['latest_charge']['payment_method_details'] = [
            'type' => 'pix',
            'pix' => ['bank_transaction_id' => 'E00000000202601011200abcdef123456'],
        ];

        return $response;
    }

    private function pendingPixPaymentIntentResponse(): array
    {
        $response = $this->paidCardPaymentIntentResponse(status: 'requires_action');
        $response['payment_method_types'] = ['pix'];
        $response['latest_charge'] = null;
        $response['next_action'] = [
            'type' => 'pix_display_qr_code',
            'pix_display_qr_code' => [
                'data' => '00020126pixcopiaecola',
                'image_url_png' => 'https://qr.stripe.com/test.png',
                'image_url_svg' => 'https://qr.stripe.com/test.svg',
                'expires_at' => 1786800000,
                'hosted_instructions_url' => 'https://payments.stripe.com/qr/instructions/test',
            ],
        ];

        return $response;
    }

    private function paidCardPaymentIntentResponse(string $status = 'succeeded'): array
    {
        return [
            'id' => 'pi_fake123',
            'object' => 'payment_intent',
            'status' => $status,
            'amount' => 12345,
            'currency' => 'brl',
            'customer' => 'cus_fake123',
            'created' => 1786700000,
            'payment_method_types' => ['card'],
            'next_action' => null,
            'metadata' => [
                'item_0_description' => 'Assinatura mensal',
                'item_0_price' => '12345',
                'item_0_quantity' => '1',
            ],
            'latest_charge' => [
                'id' => 'ch_fake123',
                'object' => 'charge',
                'status' => $status === 'succeeded' ? 'succeeded' : 'failed',
                'paid' => $status === 'succeeded',
                'amount' => 12345,
                'amount_captured' => 12345,
                'amount_refunded' => 0,
                'refunded' => false,
                'disputed' => false,
                'created' => 1786700010,
                'payment_method_details' => [
                    'type' => 'card',
                    'card' => ['brand' => 'visa', 'last4' => '4242'],
                ],
                'balance_transaction' => [
                    'id' => 'txn_fake123',
                    'object' => 'balance_transaction',
                    'fee' => 425,
                    'currency' => 'brl',
                ],
            ],
        ];
    }

    private function disputedCardPaymentIntentResponse(): array
    {
        $response = $this->paidCardPaymentIntentResponse();
        $response['latest_charge']['disputed'] = true;

        return $response;
    }

    /**
     * Resposta de GET /v1/disputes?charge=..., uma dispute por status informado.
     *
     * @param  string[]  $statuses
     * @return array
     */
    private function disputeListResponse(array $statuses): array
    {
        $data = [];
        foreach ($statuses as $index => $status) {
            $data[] = [
                'id' => "du_fake{$index}",
                'object' => 'dispute',
                'amount' => 12345,
                'charge' => 'ch_fake123',
                'payment_intent' => 'pi_fake123',
                'currency' => 'brl',
                'reason' => 'fraudulent',
                'status' => $status,
                'created' => 1786700020,
            ];
        }

        return [
            'object' => 'list',
            'url' => '/v1/disputes',
            'has_more' => false,
            'data' => $data,
        ];
    }

    private function paymentMethodResponse(?string $customer = null): array
    {
        return [
            'id' => 'pm_fake123',
            'object' => 'payment_method',
            'type' => 'card',
            'customer' => $customer,
            'created' => 1786700000,
            'billing_details' => ['name' => 'Faker Teste'],
            'metadata' => [],
            'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 8, 'exp_year' => 2027],
        ];
    }
}
