<?php

namespace Potelo\MultiPayment\Tests\Unit;

use Carbon\Carbon;
use Stripe\ApiRequestor;
use PHPUnit\Framework\TestCase;
use Illuminate\Http\Request;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\MultiPayment;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Enums\WebhookEventType;
use Potelo\MultiPayment\Gateways\IuguGateway;
use Potelo\MultiPayment\Gateways\StripeGateway;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;
use Potelo\MultiPayment\Tests\Unit\Gateways\RecordingStripeHttpClient;

/**
 * Gateway que não declara `WEBHOOKS`, para o teste da guarda da fachada.
 */
class GatewayWithoutWebhooks extends IuguGateway
{
    public function capabilities(): array
    {
        return array_values(array_filter(
            parent::capabilities(),
            static fn (Capability $capability) => $capability !== Capability::WEBHOOKS
        ));
    }

    public function notYetImplemented(): array
    {
        return array_merge(parent::notYetImplemented(), [Capability::WEBHOOKS]);
    }
}

/**
 * Webhook pela fachada: `parseWebhook()` despacha para o driver do gateway da instância,
 * `parseWebhookRequest()` adapta um `Request` do Laravel, e o gateway sem a capability
 * `WEBHOOKS` é recusado antes de qualquer parse.
 */
class MultiPaymentWebhookTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    private const IUGU_TOKEN = 'mp-lote4-token-abc123';

    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment' => [
                'default' => 'stripe',
                'gateways' => [
                    'stripe' => [
                        'api_key' => 'sk_test_fake',
                        'class' => StripeGateway::class,
                        'webhook_secret' => self::SECRET,
                    ],
                    'iugu' => [
                        'api_key' => 'iugu-key',
                        'class' => IuguGateway::class,
                        'webhook_token' => self::IUGU_TOKEN,
                    ],
                    'sem_webhook' => ['api_key' => 'iugu-key', 'class' => GatewayWithoutWebhooks::class],
                ],
            ],
        ]));
        Facade::setFacadeApplication($app);

        RecordingStripeHttpClient::withResponses([]);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testParseWebhookDispatchesToTheInstanceGatewayDriver(): void
    {
        $body = self::rawFixture('invoice.paid');

        $event = (new MultiPayment('stripe'))->parseWebhook($body, [
            'Stripe-Signature' => self::signatureHeader($body),
        ]);

        $this->assertSame(WebhookEventType::INVOICE_PAID, $event->type);
        $this->assertSame('stripe', $event->gateway);
    }

    public function testParseWebhookRequestReadsTheBodyAndHeadersFromTheRequest(): void
    {
        $body = self::rawFixture('invoice.paid');
        $request = Request::create(
            '/webhooks/stripe',
            'POST',
            server: ['HTTP_STRIPE_SIGNATURE' => self::signatureHeader($body)],
            content: $body
        );

        $event = (new MultiPayment('stripe'))->parseWebhookRequest($request);

        $this->assertSame(WebhookEventType::INVOICE_PAID, $event->type);
        $this->assertSame('evt_1UC6upPjx0CusuMr7t76P2p5', $event->id);
    }

    public function testParseWebhookDispatchesToTheIuguDriverToo(): void
    {
        $delivery = json_decode(
            file_get_contents(__DIR__ . '/../fixtures/iugu/webhooks/invoice.created.json'),
            true
        );

        $event = (new MultiPayment('iugu'))->parseWebhook($delivery['body'], $delivery['headers']);

        $this->assertSame(WebhookEventType::INVOICE_CREATED, $event->type);
        $this->assertSame('iugu', $event->gateway);
    }

    public function testAGatewayWithoutTheCapabilityIsRefusedBeforeAnyParse(): void
    {
        try {
            (new MultiPayment('sem_webhook'))->parseWebhook('{}', []);
            $this->fail('Era esperada UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::WEBHOOKS, $e->capability);
            $this->assertSame('iugu', $e->gateway);
            $this->assertTrue($e->isNotImplemented());
        }
    }

    /**
     * Cabeçalho `Stripe-Signature` calculado sobre o corpo, válido no relógio atual.
     */
    private static function signatureHeader(string $body): string
    {
        $timestamp = Carbon::now()->getTimestamp();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", self::SECRET);

        return "t={$timestamp},v1={$signature}";
    }

    /**
     * Corpo cru de uma entrega gravada em `tests/fixtures/stripe/webhooks/<tipo>.json`.
     */
    private static function rawFixture(string $type): string
    {
        return file_get_contents(__DIR__ . "/../fixtures/stripe/webhooks/{$type}.json");
    }
}
