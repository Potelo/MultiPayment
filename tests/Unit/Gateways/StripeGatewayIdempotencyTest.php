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
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\InvoiceItem;
use Potelo\MultiPayment\Gateways\StripeGateway;
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

        $refund = (new StripeGateway())->refundInvoice(self::invoiceWithId(), 'chave-1');

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
            (new StripeGateway())->refundInvoice(self::invoiceWithId(), 'outra-chave');
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

        $this->expectException(GatewayException::class);
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
                [self::paymentMethodResponse(), self::paidCardPaymentIntentResponse()],
                [
                    'post /v1/payment_methods/pm_fake123/attach' => 'chave-1:card',
                    'post /v1/payment_intents' => 'chave-1',
                ],
            ],
            'createInvoice pix' => [
                fn (StripeGateway $g, ?string $key) => $g->createInvoice(self::pixInvoiceModel(), $key),
                [$pendingPix],
                ['post /v1/payment_intents' => 'chave-1'],
            ],
            'refundInvoice' => [
                fn (StripeGateway $g, ?string $key) => $g->refundInvoice(self::invoiceWithId(), $key),
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
                    self::paymentMethodResponse(),
                    self::paymentMethodResponse(),
                    self::stripeCustomerResponse(),
                ],
                [
                    'post /v1/payment_methods/pm_fake123/attach' => 'chave-1',
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
                [self::paymentMethodResponse(), self::paymentMethodResponse()],
                [
                    'post /v1/payment_methods' => 'chave-1:payment_method',
                    'post /v1/payment_methods/pm_fake123/attach' => 'chave-1',
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
