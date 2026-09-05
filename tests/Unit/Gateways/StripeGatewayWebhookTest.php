<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Carbon\Carbon;
use Stripe\ApiRequestor;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Refund;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\WebhookEvent;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Gateways\StripeGateway;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Enums\DeclineCode;
use Potelo\MultiPayment\Enums\WebhookEventType;
use Potelo\MultiPayment\Exceptions\WebhookSignatureException;
use Potelo\MultiPayment\Tests\Unit\RecordingLogger;

/**
 * Webhook do Stripe por `parseWebhook()`: verificação do `Stripe-Signature`,
 * tradução dos eventos para o tipo comum, campos normalizados do `WebhookEvent` e hidratação
 * sob demanda. Os corpos em `tests/fixtures/stripe/webhooks/` são entregas reais gravadas com
 * `stripe listen`; a assinatura é recalculada aqui com um secret de teste, sobre os mesmos
 * bytes, porque a verificação depende só do par corpo e secret.
 */
class StripeGatewayWebhookTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment' => [
                'gateways' => [
                    'stripe' => [
                        'api_key' => 'sk_test_fake',
                        'class' => StripeGateway::class,
                        'webhook_secret' => self::SECRET,
                    ],
                ],
            ],
        ]));
        $app->instance('log', $this->logger = new RecordingLogger());
        Facade::setFacadeApplication($app);

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

    #[DataProvider('recordedDeliveryProvider')]
    public function testEachRecordedDeliveryMapsToTheExpectedCommonType(string $fixture, WebhookEventType $expected): void
    {
        $event = $this->parse(self::rawFixture($fixture));

        $this->assertSame($expected, $event->type);
    }

    public static function recordedDeliveryProvider(): array
    {
        return [
            'customer.subscription.created' => ['customer.subscription.created', WebhookEventType::SUBSCRIPTION_CREATED],
            'customer.subscription.updated' => ['customer.subscription.updated', WebhookEventType::SUBSCRIPTION_UPDATED],
            'customer.subscription.deleted' => ['customer.subscription.deleted', WebhookEventType::SUBSCRIPTION_CANCELED],
            'invoice.created' => ['invoice.created', WebhookEventType::INVOICE_CREATED],
            'invoice.finalized' => ['invoice.finalized', WebhookEventType::UNKNOWN],
            'invoice.paid' => ['invoice.paid', WebhookEventType::INVOICE_PAID],
            'invoice.payment_failed' => ['invoice.payment_failed', WebhookEventType::INVOICE_PAYMENT_FAILED],
            'payment_intent.succeeded' => ['payment_intent.succeeded', WebhookEventType::UNKNOWN],
            'payment_intent.payment_failed' => ['payment_intent.payment_failed', WebhookEventType::UNKNOWN],
            'charge.refunded' => ['charge.refunded', WebhookEventType::REFUND_CREATED],
            'charge.dispute.created' => ['charge.dispute.created', WebhookEventType::DISPUTE_OPENED],
            'setup_intent.succeeded' => ['setup_intent.succeeded', WebhookEventType::PAYMENT_METHOD_UPDATED],
        ];
    }

    #[DataProvider('assembledDeliveryProvider')]
    public function testTheTypeRulesThatDependOnTheObject(callable $mutate, WebhookEventType $expected): void
    {
        $payload = json_decode(self::rawFixture('customer.subscription.updated'), true);
        $payload = $mutate($payload);

        $event = $this->parse(json_encode($payload));

        $this->assertSame($expected, $event->type);
    }

    public static function assembledDeliveryProvider(): array
    {
        $withObject = static function (array $changes) {
            return static function (array $payload) use ($changes) {
                $payload['data']['object'] = array_merge($payload['data']['object'], $changes);

                return $payload;
            };
        };

        return [
            'updated com cancel_at_period_end' => [
                $withObject(['cancel_at_period_end' => true]),
                WebhookEventType::SUBSCRIPTION_CANCELED,
            ],
            'updated com status canceled' => [
                $withObject(['status' => 'canceled']),
                WebhookEventType::SUBSCRIPTION_CANCELED,
            ],
            'updated com pause_collection' => [
                $withObject(['pause_collection' => ['behavior' => 'void']]),
                WebhookEventType::SUBSCRIPTION_SUSPENDED,
            ],
            'evento fora do mapa' => [
                static function (array $payload) {
                    $payload['type'] = 'customer.subscription.paused';

                    return $payload;
                },
                WebhookEventType::UNKNOWN,
            ],
            'payment_method.updated' => [
                static function (array $payload) {
                    $payload['type'] = 'payment_method.updated';

                    return $payload;
                },
                WebhookEventType::PAYMENT_METHOD_UPDATED,
            ],
        ];
    }

    public function testAValidlySignedBodyThatDoesNotDecodeBecomesUnknownWithTheRawBody(): void
    {
        $body = 'corpo que não é json';

        $event = $this->parse($body);

        $this->assertSame(WebhookEventType::UNKNOWN, $event->type);
        $this->assertSame($body, $event->raw);
        $this->assertNull($event->id);
        $this->assertSame('stripe', $event->gateway);
        $this->assertCount(1, $this->logger->records);
        $this->assertSame('warning', $this->logger->records[0]['level']);
    }

    public function testAnInvoicePaidFromARenewalCycleBecomesSubscriptionRenewed(): void
    {
        $payload = json_decode(self::rawFixture('invoice.paid'), true);
        $payload['data']['object']['billing_reason'] = 'subscription_cycle';

        $event = $this->parse(json_encode($payload));

        $this->assertSame(WebhookEventType::SUBSCRIPTION_RENEWED, $event->type);
        $this->assertTrue($event->type->concernsInvoice());
        $this->assertTrue($event->type->concernsSubscription());
        $this->assertSame('in_1UC6unPjx0CusuMr2Jkqq5Fb', $event->invoiceId);
        $this->assertSame('sub_1UC6unPjx0CusuMrH0YMAWK6', $event->subscriptionId);
    }

    public function testAnInvoiceVoidedBecomesInvoiceCanceled(): void
    {
        $payload = json_decode(self::rawFixture('invoice.paid'), true);
        $payload['type'] = 'invoice.voided';
        $payload['data']['object']['status'] = 'void';

        $event = $this->parse(json_encode($payload));

        $this->assertSame(WebhookEventType::INVOICE_CANCELED, $event->type);
    }

    public function testAClosedDisputeBecomesDisputeClosed(): void
    {
        $payload = json_decode(self::rawFixture('charge.dispute.created'), true);
        $payload['type'] = 'charge.dispute.closed';
        $payload['data']['object']['status'] = 'lost';

        $event = $this->parse(json_encode($payload));

        $this->assertSame(WebhookEventType::DISPUTE_CLOSED, $event->type);
    }

    public function testAMandateUpdateBecomesPixMandateChanged(): void
    {
        $payload = json_decode(self::rawFixture('setup_intent.succeeded'), true);
        $payload['type'] = 'mandate.updated';
        $payload['data']['object'] = ['id' => 'mandate_1', 'object' => 'mandate', 'status' => 'inactive'];

        $event = $this->parse(json_encode($payload));

        $this->assertSame(WebhookEventType::PIX_MANDATE_CHANGED, $event->type);
        $this->assertSame('mandate', $event->resourceType);
        $this->assertSame('mandate_1', $event->resourceId);
    }

    public function testTheEventCarriesTheNormalizedFields(): void
    {
        $event = $this->parse(self::rawFixture('customer.subscription.created'));

        $this->assertSame('evt_1UC6upPjx0CusuMrLVt80Lm2', $event->id);
        $this->assertSame('stripe', $event->gateway);
        $this->assertSame(1788565983, $event->occurredAt->getTimestamp());
        $this->assertSame('subscription', $event->resourceType);
        $this->assertSame('sub_1UC6unPjx0CusuMrH0YMAWK6', $event->resourceId);
        $this->assertSame('sub_1UC6unPjx0CusuMrH0YMAWK6', $event->subscriptionId);
        $this->assertNull($event->invoiceId);
        $this->assertNull($event->declineCode);
        $this->assertFalse($event->isReplay);
        $this->assertIsArray($event->raw);
        $this->assertSame('customer.subscription.created', $event->raw['type']);
    }

    public function testAnInvoiceEventPointsTheInvoiceAndItsSubscription(): void
    {
        $event = $this->parse(self::rawFixture('invoice.paid'));

        $this->assertSame('in_1UC6unPjx0CusuMr2Jkqq5Fb', $event->invoiceId);
        $this->assertSame('sub_1UC6unPjx0CusuMrH0YMAWK6', $event->subscriptionId);
    }

    /**
     * Endpoint de webhook configurado numa versão de API anterior entrega o id da assinatura
     * no campo `subscription` da raiz da fatura, sem o objeto `parent`.
     */
    public function testAnInvoiceFromAnOlderEndpointApiVersionStillPointsTheSubscription(): void
    {
        $payload = json_decode(self::rawFixture('invoice.paid'), true);
        unset($payload['data']['object']['parent']);
        $payload['data']['object']['subscription'] = 'sub_versao_antiga';

        $event = $this->parse(json_encode($payload));

        $this->assertSame('sub_versao_antiga', $event->subscriptionId);
    }

    public function testAChargeEventPointsTheInvoiceByItsPaymentIntent(): void
    {
        $event = $this->parse(self::rawFixture('charge.refunded'));

        $this->assertSame('pi_3UC6vrPjx0CusuMr1p8wB8OF', $event->invoiceId);
    }

    public function testADisputeEventPointsTheInvoiceAndReturnsTheDisputeIdWithoutRequest(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);

        $event = $this->parse(self::rawFixture('charge.dispute.created'));

        $this->assertSame('pi_3UC6vvPjx0CusuMr1QDzJkyL', $event->invoiceId);
        $this->assertSame('du_1UC6vvPjx0CusuMrFCR9SiV0', $event->disputeId);
        $this->assertSame('du_1UC6vvPjx0CusuMrFCR9SiV0', $event->dispute());
        $this->assertSame([], $httpClient->calls);
    }

    public function testAPaymentIntentEventOfASingleSaleKeepsTheDeclineCodeFromThePayload(): void
    {
        $event = $this->parse(self::rawFixture('payment_intent.payment_failed'));

        $this->assertSame(WebhookEventType::UNKNOWN, $event->type);
        $this->assertSame('pi_3UC6wQPjx0CusuMr0U8FJLrn', $event->invoiceId);
        $this->assertSame(DeclineCode::GENERIC, $event->declineCode);
    }

    public function testHydratingTheInvoiceCostsOneReadAndFillsTheDeclineCode(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::fixture('invoices/open_after_declined_attempt'),
            self::fixture('payment_intents/after_declined_attempt'),
        ]);

        $event = $this->parse(self::rawFixture('invoice.payment_failed'));
        $this->assertNull($event->declineCode);

        $invoice = $event->invoice();

        $this->assertInstanceOf(Invoice::class, $invoice);
        $this->assertSame([
            'get /v1/invoices/in_1UC6wQPjx0CusuMrikar2pXa',
            'get /v1/payment_intents/pi_3UBHTpPjx0CusuMr1JTEiHGi',
        ], self::calledPaths($httpClient));
        $this->assertSame(DeclineCode::GENERIC, $invoice->lastPaymentError->declineCode);
        $this->assertSame(DeclineCode::GENERIC, $event->declineCode);

        $this->assertSame($invoice, $event->invoice());
        $this->assertCount(2, $httpClient->calls);
    }

    public function testHydratingTheSubscriptionCostsOneReadAndIsCached(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::fixture('subscriptions/active'),
            self::fixture('invoices/paid'),
            self::fixture('payment_intents/paid'),
        ]);

        $event = $this->parse(self::rawFixture('customer.subscription.created'));

        $subscription = $event->subscription();

        $this->assertInstanceOf(Subscription::class, $subscription);
        $this->assertSame('get /v1/subscriptions/sub_1UC6unPjx0CusuMrH0YMAWK6', self::calledPaths($httpClient)[0]);
        $callCount = count($httpClient->calls);

        $this->assertSame($subscription, $event->subscription());
        $this->assertCount($callCount, $httpClient->calls);
    }

    public function testTheRefundComesFromTheHydratedInvoiceAndSharesTheRead(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::fixture('payment_intents/refunded'),
        ]);

        $event = $this->parse(self::rawFixture('charge.refunded'));

        $refund = $event->refund();

        $this->assertInstanceOf(Refund::class, $refund);
        $this->assertSame('re_3UBHTpPjx0CusuMr1X9KHYad', $refund->id);
        $this->assertSame(['get /v1/payment_intents/pi_3UC6vrPjx0CusuMr1p8wB8OF'], self::calledPaths($httpClient));

        $this->assertNotNull($event->invoice());
        $this->assertCount(1, $httpClient->calls);
    }

    public function testAnEventWithoutInvoiceOrSubscriptionHydratesToNullWithoutRequest(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);

        $event = $this->parse(self::rawFixture('setup_intent.succeeded'));

        $this->assertNull($event->invoice());
        $this->assertNull($event->subscription());
        $this->assertNull($event->refund());
        $this->assertSame([], $httpClient->calls);
    }

    public function testAMissingSignatureHeaderIsRefused(): void
    {
        try {
            (new StripeGateway())->parseWebhook(self::rawFixture('invoice.paid'), []);
            $this->fail('Era esperada WebhookSignatureException');
        } catch (WebhookSignatureException $e) {
            $this->assertSame(WebhookSignatureException::REASON_MISSING_HEADER, $e->reason);
            $this->assertSame('stripe', $e->gateway);
        }
    }

    public function testASignatureFromAnotherSecretIsRefused(): void
    {
        $body = self::rawFixture('invoice.paid');

        try {
            (new StripeGateway())->parseWebhook($body, [
                'Stripe-Signature' => self::signatureHeader($body, secret: 'whsec_outro'),
            ]);
            $this->fail('Era esperada WebhookSignatureException');
        } catch (WebhookSignatureException $e) {
            $this->assertSame(WebhookSignatureException::REASON_INVALID_SIGNATURE, $e->reason);
        }
    }

    public function testATamperedBodyIsRefused(): void
    {
        $body = self::rawFixture('invoice.paid');
        $header = self::signatureHeader($body);

        $this->expectException(WebhookSignatureException::class);
        (new StripeGateway())->parseWebhook($body . ' ', ['Stripe-Signature' => $header]);
    }

    public function testAHeaderWithoutTimestampOrSignatureIsRefused(): void
    {
        try {
            (new StripeGateway())->parseWebhook(self::rawFixture('invoice.paid'), [
                'Stripe-Signature' => 'v0=abc123',
            ]);
            $this->fail('Era esperada WebhookSignatureException');
        } catch (WebhookSignatureException $e) {
            $this->assertSame(WebhookSignatureException::REASON_INVALID_SIGNATURE, $e->reason);
        }
    }

    public function testATimestampOlderThanTheToleranceIsRefused(): void
    {
        $body = self::rawFixture('invoice.paid');
        $header = self::signatureHeader($body, Carbon::now()->getTimestamp() - 400);

        try {
            (new StripeGateway())->parseWebhook($body, ['Stripe-Signature' => $header]);
            $this->fail('Era esperada WebhookSignatureException');
        } catch (WebhookSignatureException $e) {
            $this->assertSame(WebhookSignatureException::REASON_TIMESTAMP_OUT_OF_TOLERANCE, $e->reason);
        }
    }

    public function testATimestampInTheFutureBeyondTheToleranceIsRefused(): void
    {
        $body = self::rawFixture('invoice.paid');
        $header = self::signatureHeader($body, Carbon::now()->getTimestamp() + 400);

        try {
            (new StripeGateway())->parseWebhook($body, ['Stripe-Signature' => $header]);
            $this->fail('Era esperada WebhookSignatureException');
        } catch (WebhookSignatureException $e) {
            $this->assertSame(WebhookSignatureException::REASON_TIMESTAMP_OUT_OF_TOLERANCE, $e->reason);
        }
    }

    public function testAToleranceOfZeroDisablesTheTimestampCheck(): void
    {
        Facade::getFacadeApplication()->make('config')
            ->set('multi-payment.gateways.stripe.webhook_tolerance', 0);
        $body = self::rawFixture('invoice.paid');
        $header = self::signatureHeader($body, Carbon::now()->getTimestamp() - 999999);

        $event = (new StripeGateway())->parseWebhook($body, ['Stripe-Signature' => $header]);

        $this->assertSame(WebhookEventType::INVOICE_PAID, $event->type);
    }

    public function testTheToleranceComesFromTheConfiguration(): void
    {
        Facade::getFacadeApplication()->make('config')
            ->set('multi-payment.gateways.stripe.webhook_tolerance', 1000);
        $body = self::rawFixture('invoice.paid');
        $header = self::signatureHeader($body, Carbon::now()->getTimestamp() - 400);

        $event = (new StripeGateway())->parseWebhook($body, ['Stripe-Signature' => $header]);

        $this->assertSame(WebhookEventType::INVOICE_PAID, $event->type);
    }

    public function testAMissingSecretIsRefusedBeforeAnyComparison(): void
    {
        Facade::getFacadeApplication()->make('config')
            ->set('multi-payment.gateways.stripe.webhook_secret', null);
        $body = self::rawFixture('invoice.paid');

        try {
            (new StripeGateway())->parseWebhook($body, ['Stripe-Signature' => self::signatureHeader($body)]);
            $this->fail('Era esperada WebhookSignatureException');
        } catch (WebhookSignatureException $e) {
            $this->assertSame(WebhookSignatureException::REASON_MISSING_SECRET, $e->reason);
        }
    }

    /**
     * O nome do cabeçalho é comparado sem diferenciar maiúsculas e o valor pode vir em lista,
     * como o Laravel entrega em `Request::headers->all()`; entre várias `v1`, basta uma
     * conferir (a Stripe envia mais de uma durante a rotação do secret).
     */
    public function testTheHeaderIsAcceptedInLaravelFormAndWithMultipleSignatures(): void
    {
        $body = self::rawFixture('invoice.paid');
        $timestamp = Carbon::now()->getTimestamp();
        $valid = hash_hmac('sha256', "{$timestamp}.{$body}", self::SECRET);
        $header = "t={$timestamp},v1=assinatura-antiga,v1={$valid},v0=fictícia";

        $event = (new StripeGateway())->parseWebhook($body, ['stripe-signature' => [$header]]);

        $this->assertSame(WebhookEventType::INVOICE_PAID, $event->type);
    }

    public function testTheGatewayDeclaresTheWebhooksCapability(): void
    {
        $this->assertTrue((new StripeGateway())->supports(Capability::WEBHOOKS));
    }

    /**
     * Faz o parse com uma assinatura recém calculada sobre o corpo, válida no relógio atual.
     */
    private function parse(string $rawBody): WebhookEvent
    {
        return (new StripeGateway())->parseWebhook($rawBody, [
            'Stripe-Signature' => self::signatureHeader($rawBody),
        ]);
    }

    /**
     * Cabeçalho `Stripe-Signature` calculado sobre o corpo, no formato `t=...,v1=...`.
     */
    private static function signatureHeader(string $body, ?int $timestamp = null, string $secret = self::SECRET): string
    {
        $timestamp ??= Carbon::now()->getTimestamp();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

        return "t={$timestamp},v1={$signature}";
    }

    /**
     * Corpo cru de uma entrega gravada em `tests/fixtures/stripe/webhooks/<tipo>.json`.
     */
    private static function rawFixture(string $type): string
    {
        return file_get_contents(__DIR__ . "/../../fixtures/stripe/webhooks/{$type}.json");
    }

    /**
     * Resposta gravada em `tests/fixtures/stripe/<caminho>.json`, como array.
     */
    private static function fixture(string $path): array
    {
        return json_decode(file_get_contents(__DIR__ . "/../../fixtures/stripe/{$path}.json"), true);
    }

    /**
     * Método e caminho de cada chamada feita ao fake, na ordem.
     */
    private static function calledPaths(RecordingStripeHttpClient $httpClient): array
    {
        return array_map(
            static fn (array $call) => $call[0] . ' ' . parse_url($call[1], PHP_URL_PATH),
            $httpClient->calls
        );
    }
}
