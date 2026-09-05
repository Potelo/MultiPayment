<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\WebhookEvent;
use Potelo\MultiPayment\Gateways\IuguGateway;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Enums\DeclineCode;
use Potelo\MultiPayment\Enums\WebhookEventType;
use Potelo\MultiPayment\Exceptions\WebhookSignatureException;
use Potelo\MultiPayment\Tests\Unit\RecordingLogger;

/**
 * Webhook da Iugu por `parseWebhook()`: autenticação pelo token do header `authorization`,
 * parse do corpo urlencoded com `data[...]`, tradução dos eventos para o tipo comum e a
 * resolução de `invoice.status_changed` pela fatura relida. Os corpos em
 * `tests/fixtures/iugu/webhooks/` são entregas reais da sandbox; as marcadas como montadas no
 * README da pasta seguem o formato observado.
 */
class IuguGatewayWebhookTest extends TestCase
{
    private const TOKEN = 'mp-lote4-token-abc123';

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment' => [
                'gateways' => [
                    'iugu' => [
                        'api_key' => 'test-api-key',
                        'class' => IuguGateway::class,
                        'webhook_token' => self::TOKEN,
                    ],
                ],
            ],
        ]));
        $app->instance('log', $this->logger = new RecordingLogger());
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        QueuedIuguApiRequest::restoreSdkRequester();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    #[DataProvider('deliveryProvider')]
    public function testEachDeliveryMapsToTheExpectedCommonType(string $fixture, WebhookEventType $expected): void
    {
        [$body, $headers] = self::fixture($fixture);

        $event = (new IuguGateway(new QueuedIuguApiRequest([])))->parseWebhook($body, $headers);

        $this->assertSame($expected, $event->type);
    }

    public static function deliveryProvider(): array
    {
        return [
            'invoice.created' => ['invoice.created', WebhookEventType::INVOICE_CREATED],
            'invoice.created de assinatura' => ['invoice.created.subscription', WebhookEventType::INVOICE_CREATED],
            'invoice.payment_failed' => ['invoice.payment_failed', WebhookEventType::INVOICE_PAYMENT_FAILED],
            'invoice.refund' => ['invoice.refund', WebhookEventType::REFUND_CREATED],
            'invoice.partially_refunded' => ['invoice.partially_refunded', WebhookEventType::REFUND_CREATED],
            'subscription.created' => ['subscription.created', WebhookEventType::SUBSCRIPTION_CREATED],
            'subscription.changed' => ['subscription.changed', WebhookEventType::SUBSCRIPTION_UPDATED],
            'subscription.suspended' => ['subscription.suspended', WebhookEventType::SUBSCRIPTION_SUSPENDED],
            'customer_payment_method.new' => ['customer_payment_method.new', WebhookEventType::PAYMENT_METHOD_UPDATED],
            'subscription.renewed' => ['subscription.renewed', WebhookEventType::SUBSCRIPTION_RENEWED],
            'subscription.expired' => ['subscription.expired', WebhookEventType::SUBSCRIPTION_CANCELED],
            'subscription.activated' => ['subscription.activated', WebhookEventType::SUBSCRIPTION_UPDATED],
            'invoice.due' => ['invoice.due', WebhookEventType::UNKNOWN],
            'invoice.dunning_action' => ['invoice.dunning_action', WebhookEventType::INVOICE_PAYMENT_FAILED],
            'automatic_pix.authorization_changed' => ['automatic_pix.authorization_changed', WebhookEventType::PIX_MANDATE_CHANGED],
        ];
    }

    /**
     * `invoice.status_changed` é multiuso: o tipo comum sai do status normalizado da fatura
     * relida, com um GET no parse.
     */
    #[DataProvider('statusChangedProvider')]
    public function testStatusChangedResolvesTheTypeFromTheRereadInvoice(string $iuguStatus, WebhookEventType $expected): void
    {
        [$body, $headers] = self::fixture('invoice.status_changed.paid');
        $api = new QueuedIuguApiRequest([self::invoiceResponse(['status' => $iuguStatus])]);

        $event = (new IuguGateway($api))->parseWebhook($body, $headers);

        $this->assertSame($expected, $event->type);
        $this->assertCount(1, $api->calls);
        $this->assertStringContainsString('/invoices/7DBF6AACBE5643029EBE766E49A8F92F', $api->calls[0]['url']);
    }

    public static function statusChangedProvider(): array
    {
        return [
            'paid' => ['paid', WebhookEventType::INVOICE_PAID],
            'externally_paid' => ['externally_paid', WebhookEventType::INVOICE_PAID],
            'canceled' => ['canceled', WebhookEventType::INVOICE_CANCELED],
            'expired' => ['expired', WebhookEventType::INVOICE_CANCELED],
            'refunded' => ['refunded', WebhookEventType::REFUND_CREATED],
            'partially_refunded' => ['partially_refunded', WebhookEventType::REFUND_CREATED],
            'in_protest' => ['in_protest', WebhookEventType::DISPUTE_OPENED],
            'chargeback' => ['chargeback', WebhookEventType::DISPUTE_CLOSED],
            'pending' => ['pending', WebhookEventType::INVOICE_UPDATED],
            'partially_paid' => ['partially_paid', WebhookEventType::INVOICE_UPDATED],
        ];
    }

    public function testStatusChangedSharesTheRereadInvoiceWithTheHydration(): void
    {
        [$body, $headers] = self::fixture('invoice.status_changed.paid');
        $api = new QueuedIuguApiRequest([self::invoiceResponse()]);

        $event = (new IuguGateway($api))->parseWebhook($body, $headers);
        $invoice = $event->invoice();

        $this->assertInstanceOf(Invoice::class, $invoice);
        $this->assertSame('7DBF6AACBE5643029EBE766E49A8F92F', $invoice->id);
        $this->assertSame($invoice, $event->invoice());
        $this->assertCount(1, $api->calls);
    }

    public function testStatusChangedWithoutTheInvoiceIdBecomesUnknownWithAWarningAndNoRequest(): void
    {
        $api = new QueuedIuguApiRequest([]);

        $event = (new IuguGateway($api))
            ->parseWebhook('event=invoice.status_changed&data%5Bstatus%5D=paid', self::headers());

        $this->assertSame(WebhookEventType::UNKNOWN, $event->type);
        $this->assertSame([], $api->calls);
        $this->assertCount(1, $this->logger->records);
        $this->assertSame('warning', $this->logger->records[0]['level']);
    }

    public function testStatusChangedOfAnInvoiceThatNoLongerExistsBecomesUnknownWithAWarning(): void
    {
        [$body, $headers] = self::fixture('invoice.status_changed.paid');
        $api = new QueuedIuguApiRequest([new \IuguObjectNotFound('{"errors":"Not Found"}', 404)]);

        $event = (new IuguGateway($api))->parseWebhook($body, $headers);

        $this->assertSame(WebhookEventType::UNKNOWN, $event->type);
        $this->assertSame('7DBF6AACBE5643029EBE766E49A8F92F', $event->invoiceId);
        $this->assertCount(1, $this->logger->records);
        $this->assertSame('warning', $this->logger->records[0]['level']);
    }

    public function testTheEventCarriesTheNormalizedFields(): void
    {
        Carbon::setTestNow('2026-09-05 15:00:00');
        [$body, $headers] = self::fixture('invoice.created');

        $event = (new IuguGateway(new QueuedIuguApiRequest([])))->parseWebhook($body, $headers);

        $this->assertSame('300f1d32-b899-4a0c-b1a7-d30b471ebe9d', $event->id);
        $this->assertSame('iugu', $event->gateway);
        $this->assertSame('2026-09-05 15:00:00', $event->occurredAt->format('Y-m-d H:i:s'));
        $this->assertSame('invoice', $event->resourceType);
        $this->assertSame('7DBF6AACBE5643029EBE766E49A8F92F', $event->resourceId);
        $this->assertSame('7DBF6AACBE5643029EBE766E49A8F92F', $event->invoiceId);
        $this->assertNull($event->subscriptionId);
        $this->assertNull($event->declineCode);
        $this->assertFalse($event->isReplay);
        $this->assertIsArray($event->raw);
        $this->assertSame('invoice.created', $event->raw['event']);
        $this->assertSame('pending', $event->raw['data']['status']);
    }

    public function testAnInvoiceEventOfASubscriptionPointsTheInvoiceAndTheSubscription(): void
    {
        [$body, $headers] = self::fixture('invoice.created.subscription');

        $event = (new IuguGateway(new QueuedIuguApiRequest([])))->parseWebhook($body, $headers);

        $this->assertSame('A87840AF316242138EA4944BAA7BDF8A', $event->invoiceId);
        $this->assertSame('868D8D08415D4C41AEEC907F22A013CF', $event->subscriptionId);
    }

    public function testASubscriptionEventPointsTheSubscription(): void
    {
        [$body, $headers] = self::fixture('subscription.suspended');

        $event = (new IuguGateway(new QueuedIuguApiRequest([])))->parseWebhook($body, $headers);

        $this->assertSame('subscription', $event->resourceType);
        $this->assertSame('868D8D08415D4C41AEEC907F22A013CF', $event->subscriptionId);
        $this->assertNull($event->invoiceId);
    }

    public function testThePaymentMethodTriggerIdentifiesTheMethodWithoutInvoiceOrSubscription(): void
    {
        [$body, $headers] = self::fixture('customer_payment_method.new');

        $event = (new IuguGateway(new QueuedIuguApiRequest([])))->parseWebhook($body, $headers);

        $this->assertSame('customer_payment_method', $event->resourceType);
        $this->assertSame('7C5C81E70AEC4200A0151E700125AA0A', $event->resourceId);
        $this->assertNull($event->invoiceId);
        $this->assertNull($event->subscriptionId);
    }

    public function testAPaymentFailureCarriesTheDeclineCodeFromTheLrWithoutAnyRequest(): void
    {
        [$body, $headers] = self::fixture('invoice.payment_failed');
        $api = new QueuedIuguApiRequest([]);

        $event = (new IuguGateway($api))->parseWebhook($body, $headers);

        $this->assertSame(DeclineCode::DO_NOT_HONOR, $event->declineCode);
        $this->assertSame([], $api->calls);
    }

    public function testHydratingTheInvoiceCostsOneReadAndIsCached(): void
    {
        [$body, $headers] = self::fixture('invoice.payment_failed');
        $api = (new QueuedIuguApiRequest([
            self::invoiceResponse(['id' => 'A76133A304FF46A2B7C21A40B617CA42', 'status' => 'pending']),
        ]))->installAsSdkRequester();

        $event = (new IuguGateway(new QueuedIuguApiRequest([])))->parseWebhook($body, $headers);
        $invoice = $event->invoice();

        $this->assertInstanceOf(Invoice::class, $invoice);
        $this->assertCount(1, $api->calls);
        // a fatura da Iugu não expõe o LR nesta resposta, então o declineCode do payload permanece
        $this->assertSame(DeclineCode::DO_NOT_HONOR, $event->declineCode);

        $this->assertSame($invoice, $event->invoice());
        $this->assertCount(1, $api->calls);
    }

    /**
     * Evento fora do mapa vira `UNKNOWN`, e o prefixo `invoice.` ainda aponta a fatura para a
     * hidratação, como nos eventos de PaymentIntent do outro driver.
     */
    public function testAnEventOutsideTheMapBecomesUnknownAndStillPointsTheResource(): void
    {
        $body = 'event=invoice.released&data%5Bid%5D=7DBF6AACBE5643029EBE766E49A8F92F';

        $event = (new IuguGateway(new QueuedIuguApiRequest([])))->parseWebhook($body, self::headers());

        $this->assertSame(WebhookEventType::UNKNOWN, $event->type);
        $this->assertSame('7DBF6AACBE5643029EBE766E49A8F92F', $event->invoiceId);
        $this->assertSame('invoice.released:7DBF6AACBE5643029EBE766E49A8F92F', $event->id);
        $this->assertSame([], $this->logger->records);
    }

    public function testABodyWithoutTheEventFieldBecomesUnknownWithTheRawBody(): void
    {
        $body = 'corpo=sem-evento';

        $event = (new IuguGateway(new QueuedIuguApiRequest([])))->parseWebhook($body, self::headers());

        $this->assertSame(WebhookEventType::UNKNOWN, $event->type);
        $this->assertSame($body, $event->raw);
        $this->assertSame('iugu', $event->gateway);
        $this->assertCount(1, $this->logger->records);
        $this->assertSame('warning', $this->logger->records[0]['level']);
    }

    /**
     * Sem o cabeçalho `idempotency-key`, o id da entrega é derivado de evento, id do recurso e
     * status; sem nem o id do recurso, fica nulo.
     */
    public function testTheDeliveryIdFallsBackToADerivedOneWhenTheHeaderIsMissing(): void
    {
        [$body] = self::fixture('invoice.created');

        $event = (new IuguGateway(new QueuedIuguApiRequest([])))->parseWebhook($body, self::headers());

        $this->assertSame('invoice.created:7DBF6AACBE5643029EBE766E49A8F92F:pending', $event->id);

        $eventWithoutResource = (new IuguGateway(new QueuedIuguApiRequest([])))
            ->parseWebhook('event=invoice.created', self::headers());

        $this->assertNull($eventWithoutResource->id);
    }

    public function testABlankDeliveryIdHeaderCountsAsMissingAndTheDerivedIdApplies(): void
    {
        [$body] = self::fixture('invoice.created');

        $event = (new IuguGateway(new QueuedIuguApiRequest([])))->parseWebhook($body, [
            'authorization' => self::TOKEN,
            'idempotency-key' => '',
        ]);

        $this->assertSame('invoice.created:7DBF6AACBE5643029EBE766E49A8F92F:pending', $event->id);
    }

    public function testAMissingTokenConfigurationIsRefusedBeforeAnyComparison(): void
    {
        Facade::getFacadeApplication()->make('config')
            ->set('multi-payment.gateways.iugu.webhook_token', null);
        [$body, $headers] = self::fixture('invoice.created');

        try {
            (new IuguGateway(new QueuedIuguApiRequest([])))->parseWebhook($body, $headers);
            $this->fail('Era esperada WebhookSignatureException');
        } catch (WebhookSignatureException $e) {
            $this->assertSame(WebhookSignatureException::REASON_MISSING_SECRET, $e->reason);
            $this->assertSame('iugu', $e->gateway);
        }
    }

    public function testADeliveryWithoutTheAuthorizationHeaderIsRefused(): void
    {
        [$body] = self::fixture('invoice.created');

        try {
            (new IuguGateway(new QueuedIuguApiRequest([])))->parseWebhook($body, []);
            $this->fail('Era esperada WebhookSignatureException');
        } catch (WebhookSignatureException $e) {
            $this->assertSame(WebhookSignatureException::REASON_MISSING_HEADER, $e->reason);
            $this->assertSame('iugu', $e->gateway);
        }
    }

    public function testADeliveryWithABlankAuthorizationHeaderCountsAsMissing(): void
    {
        [$body] = self::fixture('invoice.created');

        try {
            (new IuguGateway(new QueuedIuguApiRequest([])))->parseWebhook($body, ['authorization' => ' ']);
            $this->fail('Era esperada WebhookSignatureException');
        } catch (WebhookSignatureException $e) {
            $this->assertSame(WebhookSignatureException::REASON_MISSING_HEADER, $e->reason);
        }
    }

    public function testADeliveryWithAnotherTokenIsRefused(): void
    {
        [$body] = self::fixture('invoice.created');

        try {
            (new IuguGateway(new QueuedIuguApiRequest([])))->parseWebhook($body, ['authorization' => 'outro-token']);
            $this->fail('Era esperada WebhookSignatureException');
        } catch (WebhookSignatureException $e) {
            $this->assertSame(WebhookSignatureException::REASON_INVALID_SIGNATURE, $e->reason);
            $this->assertSame('iugu', $e->gateway);
        }
    }

    /**
     * O nome do cabeçalho é comparado sem diferenciar maiúsculas e o valor pode vir em lista,
     * como o Laravel entrega em `Request::headers->all()`.
     */
    public function testTheTokenIsAcceptedInLaravelHeaderForm(): void
    {
        [$body] = self::fixture('invoice.created');

        $event = (new IuguGateway(new QueuedIuguApiRequest([])))->parseWebhook($body, [
            'Authorization' => [self::TOKEN],
        ]);

        $this->assertSame(WebhookEventType::INVOICE_CREATED, $event->type);
    }

    public function testTheGatewayDeclaresTheWebhooksCapability(): void
    {
        $this->assertTrue((new IuguGateway(new QueuedIuguApiRequest([])))->supports(Capability::WEBHOOKS));
    }

    /**
     * Corpo cru e cabeçalhos de uma entrega gravada em `tests/fixtures/iugu/webhooks/<nome>.json`.
     *
     * @return array{0: string, 1: array}
     */
    private static function fixture(string $name): array
    {
        $delivery = json_decode(file_get_contents(__DIR__ . "/../../fixtures/iugu/webhooks/{$name}.json"), true);

        return [$delivery['body'], $delivery['headers']];
    }

    /**
     * Cabeçalhos mínimos de uma entrega com o token válido.
     */
    private static function headers(): array
    {
        return ['authorization' => self::TOKEN];
    }

    /**
     * Fatura no formato de `GET /v1/invoices/{id}`, com os campos que `parseInvoice()` lê.
     */
    private static function invoiceResponse(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => '7DBF6AACBE5643029EBE766E49A8F92F',
            'status' => 'paid',
            'total_cents' => 10000,
            'paid_at' => '2026-09-05T13:20:06-03:00',
            'secure_url' => 'https://faturas.iugu.com/7dbf6aac',
            'taxes_paid_cents' => 150,
            'created_at_iso' => '2026-09-05T13:19:00-03:00',
            'paid_cents' => 10000,
            'refunded_cents' => 0,
            'due_date' => '2026-09-06',
            'payment_method' => 'iugu_credit_card',
            'payable_with' => null,
            'customer_id' => 'cus_1',
            'customer_name' => 'Lote4 Webhook',
            'email' => 'lote4-webhook@example.com',
            'payer_phone' => null,
            'payer_phone_prefix' => null,
            'items' => [
                (object) ['description' => 'Item', 'price_cents' => 10000, 'quantity' => 1],
            ],
            'payer_address_zip_code' => null,
            'bank_slip' => null,
            'pix' => null,
            'automatic_pix' => null,
            'credit_card_transaction' => null,
            'variables' => [],
        ], $overrides);
    }
}
