<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Carbon\Carbon;
use Stripe\ApiRequestor;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Potelo\MultiPayment\Models\Plan;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\InvoiceItem;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Models\SubscriptionItem;
use Potelo\MultiPayment\Models\SubscriptionDiscount;
use Potelo\MultiPayment\Gateways\StripeGateway;
use Potelo\MultiPayment\Enums\PlanInterval;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\RefundNotSupportedException;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;

/**
 * Chave de idempotência no driver da Stripe: toda requisição de escrita de uma operação leva o
 * cabeçalho `Idempotency-Key`, a principal com a chave informada e as secundárias com chaves
 * derivadas distintas; leituras não levam nada, e sem chave nenhum cabeçalho é enviado.
 */
class StripeGatewayIdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment.gateways.stripe.api_key' => 'sk_test_fake',
        ]));
        Facade::setFacadeApplication($app);
        Carbon::setTestNow('2026-09-02 12:00:00');

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

    /**
     * @param  array  $expectedHeaders  `método caminho` na ordem das chamadas, com o cabeçalho
     *                                  esperado (nulo em leitura); como mapa ou, quando um
     *                                  caminho se repete, como lista de pares
     */
    #[DataProvider('operationProvider')]
    public function testEveryWriteOfTheOperationCarriesTheKeyOrADerivedOne(\Closure $operation, array $responses, array $expectedHeaders): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses($responses);

        $operation(new StripeGateway(), 'chave-1');

        $actual = [];
        foreach ($httpClient->calls as $index => [$method, $url, $params]) {
            $actual[] = [$method . ' ' . parse_url($url, PHP_URL_PATH), $httpClient->header($index, 'Idempotency-Key')];
            $this->assertArrayNotHasKey('idempotency_key', $params);
        }
        $expected = array_is_list($expectedHeaders)
            ? $expectedHeaders
            : array_map(null, array_keys($expectedHeaders), array_values($expectedHeaders));
        $this->assertSame($expected, $actual);

        $keysSent = array_filter(array_column($actual, 1));
        $this->assertSame($keysSent, array_unique($keysSent), 'duas escritas da mesma operação não podem repetir a chave');
    }

    /**
     * Fatura já estornada com a mesma chave: a guarda cede e a Stripe repete o refund original.
     */
    public function testRefundRetryOnARefundedInvoiceReplaysTheOriginalRefund(): void
    {
        $refunded = self::paidCardPaymentIntentResponse();
        $refunded['latest_charge']['amount_refunded'] = 12345;
        $refunded['latest_charge']['refunded'] = true;
        $httpClient = RecordingStripeHttpClient::withResponses([
            $refunded,
            ['id' => 're_original', 'object' => 'refund', 'amount' => 12345, 'status' => 'succeeded', 'created' => 1786700100, 'reason' => null],
            $refunded,
        ]);

        $refund = (new StripeGateway())->refundInvoice(self::invoiceWithId(), null, 'chave-1');

        $this->assertSame('re_original', $refund->id);
        $this->assertSame('chave-1', $httpClient->header(1, 'Idempotency-Key'));
    }

    /**
     * Sem chave a guarda continua recusando antes da rede.
     */
    public function testRefundOnARefundedInvoiceWithoutAKeyIsRefusedBeforeTheNetwork(): void
    {
        $refunded = self::paidCardPaymentIntentResponse();
        $refunded['latest_charge']['amount_refunded'] = 12345;
        $refunded['latest_charge']['refunded'] = true;
        $httpClient = RecordingStripeHttpClient::withResponses([$refunded]);

        try {
            (new StripeGateway())->refundInvoice(self::invoiceWithId());
            $this->fail('Esperava RefundNotSupportedException');
        } catch (RefundNotSupportedException $e) {
            $this->assertSame(RefundNotSupportedException::REASON_ALREADY_REFUNDED, $e->reason);
        }

        $this->assertCount(1, $httpClient->calls);
    }

    /**
     * Com chave e a Stripe recusando o refund (a chave não é a de um estorno anterior), a
     * recusa da guarda é a que sobe.
     */
    public function testRefundRetryRefusedByStripeSurfacesTheGuardRefusal(): void
    {
        $refunded = self::paidCardPaymentIntentResponse();
        $refunded['latest_charge']['amount_refunded'] = 12345;
        $refunded['latest_charge']['refunded'] = true;
        $httpClient = RecordingStripeHttpClient::withResponses([
            $refunded,
            [['error' => ['type' => 'invalid_request_error', 'code' => 'charge_already_refunded', 'message' => 'Charge has already been refunded.']], 400],
        ]);

        try {
            (new StripeGateway())->refundInvoice(self::invoiceWithId(), null, 'outra-chave');
            $this->fail('Esperava RefundNotSupportedException');
        } catch (RefundNotSupportedException $e) {
            $this->assertSame(RefundNotSupportedException::REASON_ALREADY_REFUNDED, $e->reason);
        }

        $this->assertCount(2, $httpClient->calls);
    }

    public function testDuplicateRetryAcceptsTheAlreadyCanceledOriginalWhenAKeyIsGiven(): void
    {
        $pendingPix = self::pendingPixPaymentIntentResponse();
        $canceled = array_merge($pendingPix, ['status' => 'canceled', 'next_action' => null]);
        $httpClient = RecordingStripeHttpClient::withResponses([
            $canceled,
            self::stripeCustomerResponse(),
            array_merge($pendingPix, ['id' => 'pi_fake456']),
            $canceled,
        ]);

        $duplicated = (new StripeGateway())->duplicateInvoice(self::invoiceWithId(), Carbon::now()->addDay(), [], 'chave-1');

        $this->assertSame('pi_fake456', $duplicated->id);
        $this->assertSame('chave-1', $httpClient->header(2, 'Idempotency-Key'));
        $this->assertSame('chave-1:cancel_original', $httpClient->header(3, 'Idempotency-Key'));
    }

    public function testDuplicateOfACanceledInvoiceWithoutAKeyIsStillRefused(): void
    {
        $pendingPix = self::pendingPixPaymentIntentResponse();
        RecordingStripeHttpClient::withResponses([array_merge($pendingPix, ['status' => 'canceled', 'next_action' => null])]);

        $this->expectException(UnsupportedOperationException::class);

        (new StripeGateway())->duplicateInvoice(self::invoiceWithId(), Carbon::now()->addDay());
    }

    public function testDeleteRetryOnADetachedCardSkipsTheOwnershipCheckWhenAKeyIsGiven(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::paymentMethodResponse(null),
            self::paymentMethodResponse(null),
        ]);
        $creditCard = self::creditCardModel();
        $creditCard->id = 'pm_fake123';

        (new StripeGateway())->deleteCreditCard($creditCard, 'chave-1');

        $this->assertSame('post', $httpClient->calls[1][0]);
        $this->assertSame('chave-1', $httpClient->header(1, 'Idempotency-Key'));
    }

    public function testDeleteOfADetachedCardWithoutAKeyIsRefused(): void
    {
        RecordingStripeHttpClient::withResponses([self::paymentMethodResponse(null)]);
        $creditCard = self::creditCardModel();
        $creditCard->id = 'pm_fake123';

        $this->expectException(UnsupportedOperationException::class);
        $this->expectExceptionMessageMatches('/does not belong/');

        (new StripeGateway())->deleteCreditCard($creditCard);
    }

    /**
     * No retry do update de cliente a Stripe repete a resposta antiga (com o tax id já
     * trocado), e o `deleteTaxId` do id já excluído responde 404, que é ignorado.
     */
    public function testUpdateCustomerRetryIgnoresTheTaxIdAlreadyDeleted(): void
    {
        $freshCustomer = self::stripeCustomerResponse();
        $freshCustomer['tax_ids']['data'][0]['value'] = '68419761001';
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::stripeCustomerResponse(),
            ['id' => 'txi_fake2', 'object' => 'tax_id', 'type' => 'br_cpf', 'value' => '68419761001'],
            [['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'param' => 'id', 'message' => 'No such tax id']], 404],
            $freshCustomer,
        ]);
        $customer = new Customer();
        $customer->id = 'cus_fake123';
        $customer->taxDocument = '68419761001';

        $result = (new StripeGateway())->updateCustomer($customer, 'chave-1');

        $this->assertSame('68419761001', $result->taxDocument);
        $this->assertCount(4, $httpClient->calls);
    }

    public function testChargeUpdateAlwaysSendsTheCardCustomerSoTheRetryPayloadIsTheSame(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::paymentMethodResponse('cus_fake123'),
            self::paidCardPaymentIntentResponse('requires_payment_method'),
            self::paidCardPaymentIntentResponse('requires_payment_method'),
            self::paidCardPaymentIntentResponse(),
        ]);
        $invoice = self::invoiceWithId();
        $invoice->creditCard = new CreditCard();
        $invoice->creditCard->id = 'pm_fake123';

        (new StripeGateway())->chargeInvoiceWithCreditCard($invoice, 'chave-1');

        $this->assertSame(['payment_method_types' => ['card'], 'customer' => 'cus_fake123'], $httpClient->calls[2][2]);
    }

    #[DataProvider('operationProvider')]
    public function testWithoutAKeyNoHeaderIsSent(\Closure $operation, array $responses, array $expectedHeaders): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses($responses);

        $operation(new StripeGateway(), null);

        foreach (array_keys($httpClient->calls) as $index) {
            $this->assertNull($httpClient->header($index, 'Idempotency-Key'));
        }
    }

    public static function operationProvider(): array
    {
        $freshCustomer = self::stripeCustomerResponse();
        $freshCustomer['tax_ids']['data'][0]['value'] = '68419761001';

        $pendingPix = self::pendingPixPaymentIntentResponse();
        $refunded = self::paidCardPaymentIntentResponse();
        $refunded['latest_charge']['amount_refunded'] = 12345;
        $refunded['latest_charge']['refunded'] = true;

        return [
            'createCustomer' => [
                fn (StripeGateway $g, ?string $key) => $g->createCustomer(self::customerModel(), $key),
                [self::stripeCustomerResponse()],
                ['post /v1/customers' => 'chave-1'],
            ],
            'updateCustomer trocando o documento' => [
                function (StripeGateway $g, ?string $key) {
                    $customer = new Customer();
                    $customer->id = 'cus_fake123';
                    $customer->taxDocument = '68419761001';

                    return $g->updateCustomer($customer, $key);
                },
                [
                    self::stripeCustomerResponse(),
                    ['id' => 'txi_fake2', 'object' => 'tax_id', 'type' => 'br_cpf', 'value' => '68419761001'],
                    ['id' => 'txi_fake1', 'object' => 'tax_id', 'deleted' => true],
                    $freshCustomer,
                ],
                [
                    'post /v1/customers/cus_fake123' => 'chave-1',
                    'post /v1/customers/cus_fake123/tax_ids' => 'chave-1:tax_id',
                    'delete /v1/customers/cus_fake123/tax_ids/txi_fake1' => null,
                    'get /v1/customers/cus_fake123' => null,
                ],
            ],
            'setCustomerDefaultCard' => [
                function (StripeGateway $g, ?string $key) {
                    $customer = new Customer();
                    $customer->id = 'cus_fake123';

                    return $g->setCustomerDefaultCard($customer, 'pm_fake123', $key);
                },
                [self::stripeCustomerResponse()],
                ['post /v1/customers/cus_fake123' => 'chave-1'],
            ],
            'createInvoice com cartão salvo' => [
                fn (StripeGateway $g, ?string $key) => $g->createInvoice(self::creditCardInvoiceModel(), $key),
                [self::paidCardPaymentIntentResponse()],
                ['post /v1/payment_intents' => 'chave-1'],
            ],
            'createInvoice salvando o cartão antes' => [
                function (StripeGateway $g, ?string $key) {
                    $invoice = self::creditCardInvoiceModel();
                    $invoice->creditCard = new CreditCard();
                    $invoice->creditCard->token = 'pm_fake123';

                    return $g->createInvoice($invoice, $key);
                },
                [self::setupIntentResponse(), self::paidCardPaymentIntentResponse()],
                [
                    'post /v1/setup_intents' => 'chave-1:card',
                    'post /v1/payment_intents' => 'chave-1',
                ],
            ],
            'createInvoice pix' => [
                fn (StripeGateway $g, ?string $key) => $g->createInvoice(self::pixInvoiceModel(), $key),
                [$pendingPix],
                ['post /v1/payment_intents' => 'chave-1'],
            ],
            'createInvoice boleto' => [
                fn (StripeGateway $g, ?string $key) => $g->createInvoice(self::bankSlipInvoiceModel(), $key),
                [self::fixture('payment_intents/boleto_requires_action')],
                ['post /v1/payment_intents' => 'chave-1'],
            ],
            'refundInvoice' => [
                fn (StripeGateway $g, ?string $key) => $g->refundInvoice(self::invoiceWithId(), null, $key),
                [
                    self::paidCardPaymentIntentResponse(),
                    ['id' => 're_fake123', 'object' => 'refund', 'amount' => 12345, 'status' => 'pending', 'created' => 1786700100, 'reason' => null],
                    $refunded,
                ],
                [
                    ['get /v1/payment_intents/pi_fake123', null],
                    ['post /v1/refunds', 'chave-1'],
                    ['get /v1/payment_intents/pi_fake123', null],
                ],
            ],
            'refundInvoice de fatura de assinatura' => [
                function (StripeGateway $g, ?string $key) {
                    $invoice = new Invoice();
                    $invoice->id = 'in_1UBHTnPjx0CusuMrjxjg8WhK';

                    return $g->refundInvoice($invoice, null, $key);
                },
                [
                    self::fixture('invoices/paid'),
                    self::fixture('payment_intents/paid'),
                    ['id' => 're_fake123', 'object' => 'refund', 'amount' => 12345, 'status' => 'pending', 'created' => 1786700100, 'reason' => null],
                    self::fixture('invoices/paid'),
                    self::fixture('payment_intents/refunded'),
                ],
                [
                    ['get /v1/invoices/in_1UBHTnPjx0CusuMrjxjg8WhK', null],
                    ['get /v1/payment_intents/pi_3UBHTpPjx0CusuMr1JTEiHGi', null],
                    ['post /v1/refunds', 'chave-1'],
                    ['get /v1/invoices/in_1UBHTnPjx0CusuMrjxjg8WhK', null],
                    ['get /v1/payment_intents/pi_3UBHTpPjx0CusuMr1JTEiHGi', null],
                ],
            ],
            'captureInvoice' => [
                fn (StripeGateway $g, ?string $key) => $g->captureInvoice(self::invoiceWithId(), null, $key),
                [self::paidCardPaymentIntentResponse()],
                ['post /v1/payment_intents/pi_fake123/capture' => 'chave-1'],
            ],
            'contestDispute' => [
                fn (StripeGateway $g, ?string $key) => $g->contestDispute('du_fake123', ['uncategorized_text' => 'evidencia'], $key),
                [self::disputeResponse('under_review')],
                ['post /v1/disputes/du_fake123' => 'chave-1'],
            ],
            'acceptDispute' => [
                fn (StripeGateway $g, ?string $key) => $g->acceptDispute('du_fake123', $key),
                [self::disputeResponse('lost')],
                ['post /v1/disputes/du_fake123/close' => 'chave-1'],
            ],
            'chargeInvoiceWithCreditCard sobre fatura de assinatura' => [
                function (StripeGateway $g, ?string $key) {
                    $invoice = new Invoice();
                    $invoice->id = 'in_1UBHTnPjx0CusuMrjxjg8WhK';
                    $invoice->creditCard = new CreditCard();
                    $invoice->creditCard->id = 'pm_fake123';

                    return $g->chargeInvoiceWithCreditCard($invoice, $key);
                },
                [self::fixture('invoices/paid'), self::fixture('payment_intents/paid')],
                [
                    'post /v1/invoices/in_1UBHTnPjx0CusuMrjxjg8WhK/pay' => 'chave-1',
                    'get /v1/payment_intents/pi_3UBHTpPjx0CusuMr1JTEiHGi' => null,
                ],
            ],
            'chargeInvoiceWithCreditCard sobre fatura de assinatura com token' => [
                function (StripeGateway $g, ?string $key) {
                    $invoice = new Invoice();
                    $invoice->id = 'in_1UBHTnPjx0CusuMrjxjg8WhK';
                    $invoice->creditCard = new CreditCard();
                    $invoice->creditCard->token = 'pm_fake123';

                    return $g->chargeInvoiceWithCreditCard($invoice, $key);
                },
                [
                    self::fixture('invoices/paid'),
                    self::fixture('payment_intents/paid'),
                    self::setupIntentResponse(),
                    self::fixture('invoices/paid'),
                    self::fixture('payment_intents/paid'),
                ],
                [
                    ['get /v1/invoices/in_1UBHTnPjx0CusuMrjxjg8WhK', null],
                    ['get /v1/payment_intents/pi_3UBHTpPjx0CusuMr1JTEiHGi', null],
                    ['post /v1/setup_intents', 'chave-1:card'],
                    ['post /v1/invoices/in_1UBHTnPjx0CusuMrjxjg8WhK/pay', 'chave-1'],
                    ['get /v1/payment_intents/pi_3UBHTpPjx0CusuMr1JTEiHGi', null],
                ],
            ],
            'chargeInvoiceWithCreditCard com cartão salvo' => [
                function (StripeGateway $g, ?string $key) {
                    $invoice = self::invoiceWithId();
                    $invoice->creditCard = new CreditCard();
                    $invoice->creditCard->id = 'pm_fake123';

                    return $g->chargeInvoiceWithCreditCard($invoice, $key);
                },
                [
                    self::paymentMethodResponse('cus_fake123'),
                    self::paidCardPaymentIntentResponse('requires_payment_method'),
                    self::paidCardPaymentIntentResponse('requires_payment_method'),
                    self::paidCardPaymentIntentResponse(),
                ],
                [
                    'get /v1/payment_methods/pm_fake123' => null,
                    'get /v1/payment_intents/pi_fake123' => null,
                    'post /v1/payment_intents/pi_fake123' => 'chave-1:update',
                    'post /v1/payment_intents/pi_fake123/confirm' => 'chave-1',
                ],
            ],
            'chargeInvoiceWithCreditCard com token legado' => [
                function (StripeGateway $g, ?string $key) {
                    $invoice = self::invoiceWithId();
                    $invoice->creditCard = new CreditCard();
                    $invoice->creditCard->token = 'tok_fake123';

                    return $g->chargeInvoiceWithCreditCard($invoice, $key);
                },
                [
                    self::paymentMethodResponse(),
                    self::paymentMethodResponse(),
                    self::paidCardPaymentIntentResponse('requires_payment_method'),
                    self::paidCardPaymentIntentResponse('requires_payment_method'),
                    self::paidCardPaymentIntentResponse(),
                ],
                [
                    'post /v1/payment_methods' => 'chave-1:payment_method',
                    'get /v1/payment_methods/pm_fake123' => null,
                    'get /v1/payment_intents/pi_fake123' => null,
                    'post /v1/payment_intents/pi_fake123' => 'chave-1:update',
                    'post /v1/payment_intents/pi_fake123/confirm' => 'chave-1',
                ],
            ],
            'cancelInvoice' => [
                fn (StripeGateway $g, ?string $key) => $g->cancelInvoice(self::invoiceWithId(), $key),
                [array_merge(self::paidCardPaymentIntentResponse('canceled'), ['latest_charge' => null])],
                ['post /v1/payment_intents/pi_fake123/cancel' => 'chave-1'],
            ],
            'duplicateInvoice' => [
                fn (StripeGateway $g, ?string $key) => $g->duplicateInvoice(self::invoiceWithId(), Carbon::now()->addDay(), [], $key),
                [
                    $pendingPix,
                    self::stripeCustomerResponse(),
                    array_merge($pendingPix, ['id' => 'pi_fake456']),
                    array_merge($pendingPix, ['status' => 'canceled', 'next_action' => null]),
                ],
                [
                    'get /v1/payment_intents/pi_fake123' => null,
                    'get /v1/customers/cus_fake123' => null,
                    'post /v1/payment_intents' => 'chave-1',
                    'post /v1/payment_intents/pi_fake123/cancel' => 'chave-1:cancel_original',
                ],
            ],
            'createCreditCard padrão com descrição' => [
                function (StripeGateway $g, ?string $key) {
                    $creditCard = self::creditCardModel();
                    $creditCard->description = 'principal';
                    $creditCard->default = true;

                    return $g->createCreditCard($creditCard, $key);
                },
                [
                    self::setupIntentResponse(metadata: ['description' => 'principal', 'set_as_default' => '1']),
                    self::paymentMethodResponse('cus_fake123'),
                    self::stripeCustomerResponse(),
                ],
                [
                    'post /v1/setup_intents' => 'chave-1',
                    'post /v1/payment_methods/pm_fake123' => 'chave-1:metadata',
                    'post /v1/customers/cus_fake123' => 'chave-1:default',
                ],
            ],
            'createCreditCard com token legado' => [
                function (StripeGateway $g, ?string $key) {
                    $creditCard = self::creditCardModel();
                    $creditCard->token = 'tok_fake123';

                    return $g->createCreditCard($creditCard, $key);
                },
                [self::paymentMethodResponse(), self::setupIntentResponse()],
                [
                    'post /v1/payment_methods' => 'chave-1:payment_method',
                    'post /v1/setup_intents' => 'chave-1',
                ],
            ],
            'createCreditCard anexando o cartão que a Stripe devolveu sem cliente' => [
                fn (StripeGateway $g, ?string $key) => $g->createCreditCard(self::creditCardModel(), $key),
                [self::setupIntentResponse(paymentMethodCustomer: null), self::paymentMethodResponse('cus_fake123')],
                [
                    'post /v1/setup_intents' => 'chave-1',
                    'post /v1/payment_methods/pm_fake123/attach' => 'chave-1:attach',
                ],
            ],
            'confirmCreditCardSetup' => [
                fn (StripeGateway $g, ?string $key) => $g->confirmCreditCardSetup('seti_fake123', $key),
                [
                    self::setupIntentResponse(metadata: ['description' => 'principal', 'set_as_default' => '1']),
                    self::paymentMethodResponse('cus_fake123'),
                    self::stripeCustomerResponse(),
                ],
                [
                    'get /v1/setup_intents/seti_fake123' => null,
                    'post /v1/payment_methods/pm_fake123' => 'chave-1:metadata',
                    'post /v1/customers/cus_fake123' => 'chave-1:default',
                ],
            ],
            'deleteCreditCard' => [
                function (StripeGateway $g, ?string $key) {
                    $creditCard = self::creditCardModel();
                    $creditCard->id = 'pm_fake123';

                    return $g->deleteCreditCard($creditCard, $key);
                },
                [self::paymentMethodResponse('cus_fake123'), self::paymentMethodResponse()],
                [
                    'get /v1/payment_methods/pm_fake123' => null,
                    'post /v1/payment_methods/pm_fake123/detach' => 'chave-1',
                ],
            ],
            'createPlan' => [
                fn (StripeGateway $g, ?string $key) => $g->createPlan(self::planModel(), $key),
                [self::productResponse(), self::priceResponse()],
                [
                    'post /v1/products' => 'chave-1:product',
                    'post /v1/prices' => 'chave-1',
                ],
            ],
            'deactivatePlan' => [
                function (StripeGateway $g, ?string $key) {
                    $plan = new Plan();
                    $plan->id = 'price_fake1';

                    return $g->deactivatePlan($plan, $key);
                },
                [self::priceResponse()],
                ['post /v1/prices/price_fake1' => 'chave-1'],
            ],
            'createSubscription com cartão salvo' => [
                fn (StripeGateway $g, ?string $key) => $g->createSubscription(self::subscriptionModel('pm_fake123'), $key),
                [
                    self::subscriptionFixture(),
                    self::stripeInvoiceFixture(),
                    self::fixture('payment_intents/paid'),
                ],
                [
                    'post /v1/subscriptions' => 'chave-1',
                    'get /v1/invoices/in_1UBJmkPjx0CusuMrN6Yc2Ha1' => null,
                    'get /v1/payment_intents/pi_3UBHTpPjx0CusuMr1JTEiHGi' => null,
                ],
            ],
            'createSubscription salvando o cartão antes' => [
                function (StripeGateway $g, ?string $key) {
                    $subscription = self::subscriptionModel();
                    $subscription->creditCard = new CreditCard();
                    $subscription->creditCard->token = 'pm_fake123';

                    return $g->createSubscription($subscription, $key);
                },
                [
                    self::setupIntentResponse(),
                    self::subscriptionFixture(),
                    self::stripeInvoiceFixture(),
                    self::fixture('payment_intents/paid'),
                ],
                [
                    'post /v1/setup_intents' => 'chave-1:card',
                    'post /v1/subscriptions' => 'chave-1',
                    'get /v1/invoices/in_1UBJmkPjx0CusuMrN6Yc2Ha1' => null,
                    'get /v1/payment_intents/pi_3UBHTpPjx0CusuMr1JTEiHGi' => null,
                ],
            ],
            'createSubscription com item extra' => [
                function (StripeGateway $g, ?string $key) {
                    $subscription = self::subscriptionModel('pm_fake123');
                    $item = new SubscriptionItem();
                    $item->description = 'Consultas extras';
                    $item->amount = 2500;
                    $subscription->items = [$item];

                    return $g->createSubscription($subscription, $key);
                },
                [
                    self::productResponse(),
                    self::priceResponse(),
                    self::subscriptionFixture(),
                    self::stripeInvoiceFixture(),
                    self::fixture('payment_intents/paid'),
                ],
                [
                    'post /v1/products' => 'chave-1:item0_product',
                    'get /v1/prices/price_fake1' => null,
                    'post /v1/subscriptions' => 'chave-1',
                    'get /v1/invoices/in_1UBJmkPjx0CusuMrN6Yc2Ha1' => null,
                    'get /v1/payment_intents/pi_3UBHTpPjx0CusuMr1JTEiHGi' => null,
                ],
            ],
            'createSubscription com desconto' => [
                function (StripeGateway $g, ?string $key) {
                    $subscription = self::subscriptionModel('pm_fake123');
                    $discount = new SubscriptionDiscount();
                    $discount->description = 'Promo';
                    $discount->amountOff = 500;
                    $subscription->discounts = [$discount];

                    return $g->createSubscription($subscription, $key);
                },
                [
                    self::couponResponse(),
                    self::subscriptionFixture(),
                    self::stripeInvoiceFixture(),
                    self::fixture('payment_intents/paid'),
                ],
                [
                    'post /v1/coupons' => 'chave-1:discount0_coupon',
                    'post /v1/subscriptions' => 'chave-1',
                    'get /v1/invoices/in_1UBJmkPjx0CusuMrN6Yc2Ha1' => null,
                    'get /v1/payment_intents/pi_3UBHTpPjx0CusuMr1JTEiHGi' => null,
                ],
            ],
            'createSubscription boleto' => [
                function (StripeGateway $g, ?string $key) {
                    $subscription = self::subscriptionModel();
                    $subscription->planId = 'price_1UC8LwPjx0CusuMrr3Vq7Hpk';
                    $subscription->availablePaymentMethods = [PaymentMethod::BANK_SLIP];

                    return $g->createSubscription($subscription, $key);
                },
                [
                    self::fixture('subscriptions/active_send_invoice_boleto'),
                    self::fixture('invoices/open_boleto_send_invoice'),
                    self::fixture('invoices/open_boleto_send_invoice'),
                ],
                [
                    'post /v1/subscriptions' => 'chave-1',
                    'post /v1/invoices/in_1UC8LxPjx0CusuMr8L1JgWdN/finalize' => 'chave-1:finalize',
                    'get /v1/invoices/in_1UC8LxPjx0CusuMr8L1JgWdN' => null,
                ],
            ],
            'updateSubscription' => [
                function (StripeGateway $g, ?string $key) {
                    $subscription = new Subscription();
                    $subscription->id = 'sub_fake1';
                    $subscription->metadata = ['origem' => 'teste'];

                    return $g->updateSubscription($subscription, $key);
                },
                [self::subscriptionFixture()],
                ['post /v1/subscriptions/sub_fake1' => 'chave-1'],
            ],
            'updateSubscription com desconto novo' => [
                function (StripeGateway $g, ?string $key) {
                    $subscription = new Subscription();
                    $subscription->id = 'sub_fake1';
                    $discount = new SubscriptionDiscount();
                    $discount->description = 'Promo';
                    $discount->amountOff = 500;
                    $subscription->discounts = [$discount];

                    return $g->updateSubscription($subscription, $key);
                },
                [self::couponResponse(), self::subscriptionFixture()],
                [
                    'post /v1/coupons' => 'chave-1:discount0_coupon',
                    'post /v1/subscriptions/sub_fake1' => 'chave-1',
                ],
            ],
            'suspendSubscription' => [
                fn (StripeGateway $g, ?string $key) => $g->suspendSubscription(self::subscriptionWithId(), $key),
                [self::subscriptionFixture()],
                ['post /v1/subscriptions/sub_fake1' => 'chave-1'],
            ],
            'resumeSubscription' => [
                fn (StripeGateway $g, ?string $key) => $g->resumeSubscription(self::subscriptionWithId(), $key),
                [self::subscriptionFixture()],
                ['post /v1/subscriptions/sub_fake1' => 'chave-1'],
            ],
            'cancelSubscription imediato' => [
                fn (StripeGateway $g, ?string $key) => $g->cancelSubscription(self::subscriptionWithId(), false, $key),
                [self::subscriptionFixture()],
                ['delete /v1/subscriptions/sub_fake1' => 'chave-1'],
            ],
            'cancelSubscription ao fim do período' => [
                fn (StripeGateway $g, ?string $key) => $g->cancelSubscription(self::subscriptionWithId(), true, $key),
                [self::subscriptionFixture()],
                ['post /v1/subscriptions/sub_fake1' => 'chave-1'],
            ],
            'changeSubscriptionPlan sem cobrança' => [
                fn (StripeGateway $g, ?string $key) => $g->changeSubscriptionPlan(
                    self::subscriptionWithId(),
                    'price_fake2',
                    \Potelo\MultiPayment\Enums\ProrationBehavior::NONE,
                    $key
                ),
                [self::subscriptionFixture(), self::subscriptionFixture()],
                [
                    'get /v1/subscriptions/sub_fake1' => null,
                    'post /v1/subscriptions/sub_fake1' => 'chave-1',
                ],
            ],
        ];
    }

    #[IgnoreDeprecations]
    public function testTheLegacyKeyInTheDuplicateOptionsIsUsedAndKeptOutOfThePayload(): void
    {
        $pendingPix = self::pendingPixPaymentIntentResponse();
        $httpClient = RecordingStripeHttpClient::withResponses([
            $pendingPix,
            self::stripeCustomerResponse(),
            array_merge($pendingPix, ['id' => 'pi_fake456']),
            array_merge($pendingPix, ['status' => 'canceled', 'next_action' => null]),
        ]);

        $this->expectUserDeprecationMessage("gateway_options['idempotency_key'] está obsoleto desde 2026-09-02; passe idempotencyKey como argumento da operação");

        (new StripeGateway())->duplicateInvoice(self::invoiceWithId(), Carbon::now()->addDay(), ['idempotency_key' => 'chave-antiga', 'statement_descriptor' => 'DUP']);

        $this->assertSame('chave-antiga', $httpClient->header(2, 'Idempotency-Key'));
        $this->assertSame('DUP', $httpClient->calls[2][2]['statement_descriptor']);
        $this->assertArrayNotHasKey('idempotency_key', $httpClient->calls[2][2]);
    }

    #[IgnoreDeprecations]
    public function testTheLegacyKeyOnTheCustomerStaysOutOfThePayload(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([self::stripeCustomerResponse()]);

        $customer = self::customerModel();
        $customer->gatewayOptions = ['idempotency_key' => 'chave-antiga', 'description' => 'VIP'];

        $this->expectUserDeprecationMessage("gateway_options['idempotency_key'] está obsoleto desde 2026-09-02; passe idempotencyKey como argumento da operação");

        (new StripeGateway())->createCustomer($customer);

        $this->assertSame('chave-antiga', $httpClient->header(0, 'Idempotency-Key'));
        $this->assertSame('VIP', $httpClient->calls[0][2]['description']);
        $this->assertArrayNotHasKey('idempotency_key', $httpClient->calls[0][2]);
    }

    private static function invoiceWithId(): Invoice
    {
        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';

        return $invoice;
    }

    private static function planModel(): Plan
    {
        $plan = new Plan();
        $plan->name = 'Mensal';
        $plan->identifier = 'plano_mensal';
        $plan->amount = 10000;
        $plan->interval = PlanInterval::MONTH;

        return $plan;
    }

    /**
     * Assinatura pronta para criação, com o plano pelo id de Price (a busca por `lookup_key`
     * é leitura e ficaria fora das asserções de cabeçalho).
     */
    private static function subscriptionModel(?string $cardId = null): Subscription
    {
        $subscription = new Subscription();
        $subscription->customer = new Customer();
        $subscription->customer->id = 'cus_fake123';
        $subscription->planId = 'price_fake1';
        if (!is_null($cardId)) {
            $subscription->creditCard = new CreditCard();
            $subscription->creditCard->id = $cardId;
        }

        return $subscription;
    }

    private static function subscriptionWithId(): Subscription
    {
        $subscription = new Subscription();
        $subscription->id = 'sub_fake1';

        return $subscription;
    }

    private static function productResponse(): array
    {
        return ['id' => 'prod_fake1', 'object' => 'product', 'name' => 'Mensal', 'active' => true, 'created' => 1786700000, 'metadata' => []];
    }

    private static function priceResponse(): array
    {
        return [
            'id' => 'price_fake1',
            'object' => 'price',
            'active' => true,
            'currency' => 'brl',
            'lookup_key' => 'plano_mensal',
            'nickname' => 'Mensal',
            'created' => 1786700000,
            'product' => self::productResponse(),
            'recurring' => ['interval' => 'month', 'interval_count' => 1, 'usage_type' => 'licensed'],
            'type' => 'recurring',
            'unit_amount' => 10000,
            'unit_amount_decimal' => '10000',
        ];
    }

    private static function couponResponse(): array
    {
        return [
            'id' => 'co_fake1',
            'object' => 'coupon',
            'amount_off' => 500,
            'currency' => 'brl',
            'duration' => 'forever',
            'name' => 'Promo',
            'valid' => true,
            'created' => 1786700000,
            'metadata' => [],
        ];
    }

    private static function subscriptionFixture(): array
    {
        return self::fixture('subscriptions/active');
    }

    private static function stripeInvoiceFixture(): array
    {
        return self::fixture('invoices/paid');
    }

    private static function fixture(string $path): array
    {
        return json_decode(file_get_contents(__DIR__ . "/../../fixtures/stripe/{$path}.json"), true);
    }

    private static function customerModel(): Customer
    {
        $customer = new Customer();
        $customer->name = 'Fake Customer';
        $customer->email = 'email@exemplo.com';
        $customer->taxDocument = '20176996915';

        return $customer;
    }

    private static function creditCardModel(): CreditCard
    {
        $creditCard = new CreditCard();
        $creditCard->token = 'pm_fake123';
        $creditCard->customer = new Customer();
        $creditCard->customer->id = 'cus_fake123';

        return $creditCard;
    }

    private static function creditCardInvoiceModel(): Invoice
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

    private static function pixInvoiceModel(): Invoice
    {
        $invoice = self::creditCardInvoiceModel();
        $invoice->creditCard = null;
        $invoice->availablePaymentMethods = [PaymentMethod::PIX];
        $invoice->customer->name = 'Fake Customer';
        $invoice->customer->email = 'email@exemplo.com';
        $invoice->customer->taxDocument = '20176996915';

        return $invoice;
    }

    private static function bankSlipInvoiceModel(): Invoice
    {
        $invoice = self::pixInvoiceModel();
        $invoice->availablePaymentMethods = [PaymentMethod::BANK_SLIP];
        $invoice->customer->address = new \Potelo\MultiPayment\Models\Address();
        $invoice->customer->address->street = 'Av Paulista';
        $invoice->customer->address->number = '1234';
        $invoice->customer->address->city = 'Sao Paulo';
        $invoice->customer->address->state = 'SP';
        $invoice->customer->address->zipCode = '01310000';

        return $invoice;
    }

    private static function stripeCustomerResponse(): array
    {
        return [
            'id' => 'cus_fake123',
            'object' => 'customer',
            'name' => 'Fake Customer',
            'email' => 'email@exemplo.com',
            'phone' => null,
            'created' => 1786700000,
            'metadata' => [],
            'address' => null,
            'invoice_settings' => ['default_payment_method' => null],
            'tax_ids' => [
                'object' => 'list',
                'data' => [
                    ['id' => 'txi_fake1', 'object' => 'tax_id', 'type' => 'br_cpf', 'value' => '20176996915'],
                ],
            ],
        ];
    }

    private static function paymentMethodResponse(?string $customer = null): array
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

    private static function disputeResponse(string $status): array
    {
        return [
            'id' => 'du_fake123',
            'object' => 'dispute',
            'amount' => 12345,
            'charge' => 'ch_fake123',
            'payment_intent' => 'pi_fake123',
            'reason' => 'fraudulent',
            'status' => $status,
            'created' => 1786700020,
        ];
    }

    /**
     * SetupIntent confirmado, com o PaymentMethod expandido (anexado ao cliente por padrão).
     */
    private static function setupIntentResponse(?string $paymentMethodCustomer = 'cus_fake123', array $metadata = []): array
    {
        return [
            'id' => 'seti_fake123',
            'object' => 'setup_intent',
            'status' => 'succeeded',
            'customer' => 'cus_fake123',
            'usage' => 'off_session',
            'client_secret' => 'seti_fake123_secret_fake',
            'created' => 1786700000,
            'payment_method_types' => ['card'],
            'metadata' => $metadata,
            'next_action' => null,
            'last_setup_error' => null,
            'payment_method' => self::paymentMethodResponse($paymentMethodCustomer),
        ];
    }

    private static function paidCardPaymentIntentResponse(string $status = 'succeeded'): array
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
            'latest_charge' => $status === 'succeeded' ? [
                'id' => 'ch_fake123',
                'object' => 'charge',
                'status' => 'succeeded',
                'paid' => true,
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
            ] : null,
        ];
    }

    private static function pendingPixPaymentIntentResponse(): array
    {
        $response = self::paidCardPaymentIntentResponse('requires_action');
        $response['payment_method_types'] = ['pix'];
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
}
