<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Carbon\Carbon;
use Stripe\ApiRequestor;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\InvoiceItem;
use Potelo\MultiPayment\Gateways\StripeGateway;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\ChargingException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

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
            'expand' => ['latest_charge.balance_transaction'],
        ], $params);

        $this->assertSame('pi_fake123', $result->id);
        $this->assertSame(Invoice::STATUS_PAID, $result->status);
        $this->assertSame(12345, $result->amount);
        $this->assertSame(12345, $result->paidAmount);
        $this->assertSame(0, $result->refundedAmount);
        $this->assertSame(425, $result->fee);
        $this->assertInstanceOf(Carbon::class, $result->paidAt);
        $this->assertSame(Invoice::PAYMENT_METHOD_CREDIT_CARD, $result->paymentMethod);
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
        $invoice = $this->creditCardInvoiceModel();
        $invoice->availablePaymentMethods = [Invoice::PAYMENT_METHOD_CREDIT_CARD, Invoice::PAYMENT_METHOD_PIX];

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessage('exactly one payment method');

        (new StripeGateway())->createInvoice($invoice);
    }

    public function testRejectsBankSlipInvoiceWithClearMessage(): void
    {
        $invoice = $this->creditCardInvoiceModel();
        $invoice->availablePaymentMethods = [Invoice::PAYMENT_METHOD_BANK_SLIP];

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('does not support bank slip');

        (new StripeGateway())->createInvoice($invoice);
    }

    public function testCreatesPixInvoiceFullyServerSideAndParsesQrCode(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->pendingPixPaymentIntentResponse()]);

        $invoice = $this->pixInvoiceModel();
        // o parse sobrescreve expiresAt com o valor devolvido pela Stripe — captura antes
        $requestedExpiresAt = $invoice->expiresAt->getTimestamp();
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
            'expand' => ['latest_charge.balance_transaction'],
        ], $params);

        $this->assertSame(Invoice::STATUS_PENDING, $result->status);
        $this->assertSame(Invoice::PAYMENT_METHOD_PIX, $result->paymentMethod);
        $this->assertSame('00020126pixcopiaecola', $result->pix->qrCodeText);
        $this->assertSame('https://qr.stripe.com/test.png', $result->pix->qrCodeImageUrl);
        $this->assertSame('https://payments.stripe.com/qr/instructions/test', $result->url);
        $this->assertSame(1786800000, $result->expiresAt->getTimestamp());
        $this->assertNull($result->paidAmount);
    }

    public function testRejectsInvoiceWithAutomaticPixUntilSupported(): void
    {
        $invoice = $this->pixInvoiceModel();
        $invoice->automaticPix = new \Potelo\MultiPayment\Models\AutomaticPix();

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('Operation [createInvoice with automatic pix] is not yet implemented');

        (new StripeGateway())->createInvoice($invoice);
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

    public function testPixInvoiceWithoutExpiresAtOmitsPaymentMethodOptions(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->pendingPixPaymentIntentResponse()]);

        $invoice = $this->pixInvoiceModel();
        $invoice->expiresAt = null;
        (new StripeGateway())->createInvoice($invoice);

        $this->assertArrayNotHasKey('payment_method_options', $httpClient->calls[0][2]);
    }

    public function testPixInvoiceRejectsExpiresAtOutsideStripeWindow(): void
    {
        $invoice = $this->pixInvoiceModel();
        $invoice->expiresAt = Carbon::now()->subMinute();

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessage('more than 10 seconds and less than 14 days');

        (new StripeGateway())->createInvoice($invoice);
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

    public function testIdempotencyKeyFromGatewayAdicionalOptionsBecomesRequestHeader(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->pendingPixPaymentIntentResponse()]);

        $invoice = $this->pixInvoiceModel();
        $invoice->gatewayAdicionalOptions = ['idempotency_key' => 'chave-unica-123'];
        (new StripeGateway())->createInvoice($invoice);

        // a chave não pode vazar como parâmetro do payload (a API a rejeitaria)
        $this->assertArrayNotHasKey('idempotency_key', $httpClient->calls[0][2]);
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
        $this->assertSame(['expand' => ['latest_charge.balance_transaction']], $params);
        $this->assertSame(Invoice::STATUS_CANCELED, $result->status);
    }

    public function testCancelPaidInvoiceBecomesGatewayException(): void
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
            $this->fail('Expected GatewayException was not thrown');
        } catch (GatewayException $exception) {
            $this->assertSame('payment_intent_unexpected_state', $exception->getErrors()['code']);
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

        $this->assertSame(Invoice::STATUS_REFUNDED, $result->status);
        $this->assertSame(12345, $result->refundedAmount);
    }

    public function testGetInvoiceParsesPartialRefund(): void
    {
        $response = $this->paidCardPaymentIntentResponse();
        $response['latest_charge']['amount_refunded'] = 2345;
        RecordingStripeHttpClient::withResponses([$response]);

        $result = $this->getInvoice();

        $this->assertSame(Invoice::STATUS_PARTIALLY_REFUNDED, $result->status);
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

        $this->assertSame(Invoice::STATUS_PENDING, $result->status);
        $this->assertNull($result->paidAmount);
        $this->assertNull($result->paidAt);
        $this->assertSame(Invoice::PAYMENT_METHOD_PIX, $result->paymentMethod);
    }

    public function testGetInvoiceRejectsUnexpectedStatus(): void
    {
        $response = $this->paidCardPaymentIntentResponse();
        $response['status'] = 'partially_funded';
        $response['latest_charge'] = null;
        RecordingStripeHttpClient::withResponses([$response]);

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('Unexpected Stripe payment intent status: partially_funded');

        $this->getInvoice();
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
        $this->assertSame(Invoice::STATUS_PAID, $result->status);
    }

    public function testChargeInvoiceKeepsMatchingCustomerAndOmitsItFromUpdate(): void
    {
        // PI e PaymentMethod do mesmo customer: nada de customer no update
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

        $this->assertSame(['payment_method_types' => ['card']], $httpClient->calls[2][2]);
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

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('does not belong to customer');

        (new StripeGateway())->chargeInvoiceWithCreditCard($invoice);
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

    public function testGatewayAdicionalOptionsOverrideAndExpandIsMerged(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->paidCardPaymentIntentResponse()]);

        $invoice = $this->creditCardInvoiceModel();
        $invoice->gatewayAdicionalOptions = [
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
        $this->assertSame(['customer', 'latest_charge.balance_transaction'], $params['expand']);
    }

    /**
     * Status do PaymentIntent sem estorno mapeado para o status genérico.
     *
     * @return array[]
     */
    public static function paymentIntentStatusDataProvider(): array
    {
        return [
            ['succeeded', Invoice::STATUS_PAID],
            ['canceled', Invoice::STATUS_CANCELED],
            ['processing', Invoice::STATUS_PENDING],
            ['requires_action', Invoice::STATUS_PENDING],
            ['requires_confirmation', Invoice::STATUS_PENDING],
            ['requires_capture', Invoice::STATUS_PENDING],
            ['requires_payment_method', Invoice::STATUS_PENDING],
        ];
    }

    /**
     * @dataProvider paymentIntentStatusDataProvider
     */
    public function testStatusMapping(string $stripeStatus, string $expected): void
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

    public function testRefundsInvoiceTotally(): void
    {
        $refunded = $this->paidCardPaymentIntentResponse();
        $refunded['latest_charge']['amount_refunded'] = 12345;
        $refunded['latest_charge']['refunded'] = true;
        $httpClient = RecordingStripeHttpClient::withResponses([
            ['id' => 're_fake123', 'object' => 'refund', 'status' => 'pending', 'amount' => 12345],
            $refunded,
        ]);

        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';
        $result = (new StripeGateway())->refundInvoice($invoice);

        [$method, $url, $params] = $httpClient->calls[0];
        $this->assertSame('post', $method);
        $this->assertSame('/v1/refunds', parse_url($url, PHP_URL_PATH));
        // sem amount: estorno total
        $this->assertSame(['payment_intent' => 'pi_fake123'], $params);
        $this->assertSame(Invoice::STATUS_REFUNDED, $result->status);
        $this->assertSame(12345, $result->refundedAmount);
    }

    public function testRefundsInvoicePartially(): void
    {
        $refunded = $this->paidCardPaymentIntentResponse();
        $refunded['latest_charge']['amount_refunded'] = 2345;
        $httpClient = RecordingStripeHttpClient::withResponses([
            ['id' => 're_fake123', 'object' => 'refund', 'status' => 'pending', 'amount' => 2345],
            $refunded,
        ]);

        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';
        $invoice->refundedAmount = 2345;
        $result = (new StripeGateway())->refundInvoice($invoice);

        $this->assertSame(
            ['payment_intent' => 'pi_fake123', 'amount' => 2345],
            $httpClient->calls[0][2]
        );
        $this->assertSame(Invoice::STATUS_PARTIALLY_REFUNDED, $result->status);
        $this->assertSame(2345, $result->refundedAmount);
    }

    public function testRefundInvoiceRequiresId(): void
    {
        $this->expectException(ModelAttributeValidationException::class);

        (new StripeGateway())->refundInvoice(new Invoice());
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
            'expand' => ['latest_charge.balance_transaction'],
        ], $httpClient->calls[2][2]);

        $this->assertSame('pi_fake456', $result->id);
        $this->assertSame(Invoice::STATUS_PENDING, $result->status);
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

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('Invoice duplicated as [pi_fake456]');

        (new StripeGateway())->duplicateInvoice($invoice, Carbon::now()->addDay());
    }

    public function testDuplicateRejectsPaidInvoice(): void
    {
        RecordingStripeHttpClient::withResponses([$this->paidCardPaymentIntentResponse()]);

        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('Only pending invoices can be duplicated');

        (new StripeGateway())->duplicateInvoice($invoice, Carbon::now()->addDay());
    }

    public function testDuplicateRejectsNonPixInvoice(): void
    {
        $response = $this->paidCardPaymentIntentResponse(status: 'requires_payment_method');
        $response['latest_charge'] = null;
        RecordingStripeHttpClient::withResponses([$response]);

        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('Only pix invoices can be duplicated');

        (new StripeGateway())->duplicateInvoice($invoice, Carbon::now()->addDay());
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
        $invoice->availablePaymentMethods = [Invoice::PAYMENT_METHOD_CREDIT_CARD];
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
        $invoice->availablePaymentMethods = [Invoice::PAYMENT_METHOD_PIX];
        $invoice->customer->name = 'Fake Customer';
        $invoice->customer->email = 'email@exemplo.com';
        $invoice->customer->taxDocument = '20176996915';
        $invoice->expiresAt = Carbon::now()->addHour();

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

        $this->assertSame(Invoice::STATUS_PAID, $result->status);
        $this->assertSame(Invoice::PAYMENT_METHOD_PIX, $result->paymentMethod);
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
