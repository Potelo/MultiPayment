<?php

namespace Potelo\MultiPayment\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Potelo\MultiPayment\Events\InvoicePaid;
use Potelo\MultiPayment\Events\InvoiceCreated;
use Potelo\MultiPayment\Events\WebhookReceived;
use Potelo\MultiPayment\Facades\MultiPayment;
use Potelo\MultiPayment\Providers\MultiPaymentServiceProvider;

/**
 * Rota pronta do pacote e pipeline de webhooks: registro condicionado à configuração,
 * verificação sem vazamento de detalhe, descarte de replay, despacho dos eventos do Laravel e
 * o mesmo pipeline disponível para uma rota própria. Sem rede: o parse do Stripe só verifica a
 * assinatura, e a entrega da Iugu usada não hidrata no parse.
 */
class WebhookRouteTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    private const IUGU_TOKEN = 'mp-lote4-token-abc123';

    protected function getPackageProviders($app): array
    {
        return [MultiPaymentServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // a store de idempotência da deduplicação exige um cache com lock
        $app['config']->set('cache.default', 'array');
        $app['config']->set('multi-payment.webhooks.route.enabled', true);
        $app['config']->set('multi-payment.gateways.stripe.webhook_secret', self::SECRET);
        $app['config']->set('multi-payment.gateways.iugu.webhook_token', self::IUGU_TOKEN);
    }

    public function testAValidStripeDeliveryDispatchesTheLaravelEvents(): void
    {
        Event::fake();

        $this->assertTrue(Route::has('multipayment.webhook'));
        $this->postStripeDelivery(self::rawFixture('invoice.paid'))->assertStatus(200);

        Event::assertDispatched(
            WebhookReceived::class,
            fn (WebhookReceived $event) => $event->webhook->id === 'evt_1UC6upPjx0CusuMr7t76P2p5'
        );
        Event::assertDispatched(
            InvoicePaid::class,
            fn (InvoicePaid $event) => $event->webhook->id === 'evt_1UC6upPjx0CusuMr7t76P2p5'
        );
    }

    public function testAnInvalidSignatureIsRefusedWith400WithoutDetailInTheBody(): void
    {
        Event::fake();

        $body = self::rawFixture('invoice.paid');
        $response = $this->call(
            'POST',
            '/multipayment/webhooks/stripe',
            server: ['HTTP_STRIPE_SIGNATURE' => 't=123,v1=assinatura-invalida', 'CONTENT_TYPE' => 'application/json'],
            content: $body
        );

        $response->assertStatus(400);
        $this->assertSame('', $response->getContent());
        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function testAReplayIsAcknowledgedWith200WithoutDispatchingAnything(): void
    {
        $body = self::rawFixture('invoice.paid');
        $this->postStripeDelivery($body)->assertStatus(200);

        Event::fake();
        $this->postStripeDelivery($body)->assertStatus(200);

        Event::assertNotDispatched(WebhookReceived::class);
        Event::assertNotDispatched(InvoicePaid::class);
    }

    public function testAnUnknownEventIsAcknowledgedWith200WithOnlyTheGenericEvent(): void
    {
        Event::fake();

        $this->postStripeDelivery(self::rawFixture('payment_intent.succeeded'))->assertStatus(200);

        Event::assertDispatched(WebhookReceived::class);
        Event::assertNotDispatched(InvoicePaid::class);
    }

    public function testAnUnknownGatewayRespondsWith404WithoutDetailInTheBody(): void
    {
        Event::fake();

        $response = $this->call('POST', '/multipayment/webhooks/inexistente', content: '{}');

        $response->assertStatus(404);
        $this->assertSame('', $response->getContent());
        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function testTheGenericEventIsDispatchedBeforeTheTypedOne(): void
    {
        $order = [];
        Event::listen(WebhookReceived::class, function () use (&$order) {
            $order[] = 'generic';
        });
        Event::listen(InvoicePaid::class, function () use (&$order) {
            $order[] = 'typed';
        });

        $this->postStripeDelivery(self::rawFixture('invoice.paid'))->assertStatus(200);

        $this->assertSame(['generic', 'typed'], $order);
    }

    public function testAFailingListenerReleasesTheDeduplicationForTheRetry(): void
    {
        $calls = 0;
        Event::listen(InvoicePaid::class, function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new \RuntimeException('listener quebrado');
            }
        });

        $body = self::rawFixture('invoice.paid');
        $this->postStripeDelivery($body)->assertStatus(500);
        $this->postStripeDelivery($body)->assertStatus(200);

        $this->assertSame(2, $calls);
    }

    public function testARouteWithoutTheGatewayParameterUsesTheInstanceGateway(): void
    {
        Event::fake();
        Route::post(
            '/meus-webhooks-stripe',
            fn (Request $request) => MultiPayment::setGateway('stripe')->webhooks()->handle($request)
        );

        $this->postStripeDelivery(self::rawFixture('invoice.paid'), '/meus-webhooks-stripe')->assertStatus(200);

        Event::assertDispatched(InvoicePaid::class);
    }

    public function testAnIuguDeliveryIsAcceptedWithTheConfiguredToken(): void
    {
        Event::fake();

        $delivery = json_decode(
            file_get_contents(__DIR__ . '/../fixtures/iugu/webhooks/invoice.created.json'),
            true
        );

        $response = $this->call(
            'POST',
            '/multipayment/webhooks/iugu',
            server: [
                'HTTP_AUTHORIZATION' => $delivery['headers']['authorization'],
                'HTTP_IDEMPOTENCY_KEY' => $delivery['headers']['idempotency-key'],
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            ],
            content: $delivery['body']
        );

        $response->assertStatus(200);
        Event::assertDispatched(InvoiceCreated::class);
    }

    public function testAnApplicationRouteReusesThePipelineThroughTheFacade(): void
    {
        Event::fake();
        Route::post('/meus-webhooks/{gateway}', fn (Request $request) => MultiPayment::webhooks()->handle($request));

        $this->postStripeDelivery(self::rawFixture('invoice.paid'), '/meus-webhooks/stripe')->assertStatus(200);

        Event::assertDispatched(InvoicePaid::class);
    }

    /**
     * Envia uma entrega do Stripe assinada com o secret de teste no relógio atual.
     */
    private function postStripeDelivery(string $body, string $uri = '/multipayment/webhooks/stripe'): \Illuminate\Testing\TestResponse
    {
        $timestamp = Carbon::now()->getTimestamp();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", self::SECRET);

        return $this->call(
            'POST',
            $uri,
            server: ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'],
            content: $body
        );
    }

    /**
     * Corpo cru de uma entrega gravada em `tests/fixtures/stripe/webhooks/<tipo>.json`.
     */
    private static function rawFixture(string $type): string
    {
        return file_get_contents(__DIR__ . "/../fixtures/stripe/webhooks/{$type}.json");
    }
}
