<?php

namespace Potelo\MultiPayment\Tests\Feature;

use Carbon\Carbon;
use Orchestra\Testbench\TestCase;
use Illuminate\Support\Facades\Route;
use Potelo\MultiPayment\Providers\MultiPaymentServiceProvider;

/**
 * Com `multi-payment.webhooks.route.enabled` no padrão (desligado), o service provider não
 * registra a rota pronta e o caminho dela responde 404.
 */
class WebhookRouteDisabledTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    protected function getPackageProviders($app): array
    {
        return [MultiPaymentServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('multi-payment.gateways.stripe.webhook_secret', self::SECRET);
    }

    public function testTheRouteIsNotRegisteredWhenDisabled(): void
    {
        $this->assertFalse(Route::has('multipayment.webhook'));

        $body = file_get_contents(__DIR__ . '/../fixtures/stripe/webhooks/invoice.paid.json');
        $timestamp = Carbon::now()->getTimestamp();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", self::SECRET);

        $response = $this->call(
            'POST',
            '/multipayment/webhooks/stripe',
            server: ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'],
            content: $body
        );

        $response->assertStatus(404);
    }
}
