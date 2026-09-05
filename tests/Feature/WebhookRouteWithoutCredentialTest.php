<?php

namespace Potelo\MultiPayment\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Illuminate\Support\Facades\Event;
use Potelo\MultiPayment\Events\WebhookReceived;
use Potelo\MultiPayment\Providers\MultiPaymentServiceProvider;

/**
 * Rota ligada sem `webhook_secret` configurado: a entrega responde 500 (erro de configuração
 * da aplicação, que mantém o gateway retentando), diferente do 400 de assinatura recusada.
 */
class WebhookRouteWithoutCredentialTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [MultiPaymentServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('multi-payment.webhooks.route.enabled', true);
        $app['config']->set('multi-payment.gateways.stripe.webhook_secret', null);
    }

    public function testAMissingWebhookSecretRespondsWith500WithoutDispatching(): void
    {
        Event::fake();

        $response = $this->call(
            'POST',
            '/multipayment/webhooks/stripe',
            server: ['HTTP_STRIPE_SIGNATURE' => 't=123,v1=qualquer', 'CONTENT_TYPE' => 'application/json'],
            content: '{}'
        );

        $response->assertStatus(500);
        $this->assertSame('', $response->getContent());
        Event::assertNotDispatched(WebhookReceived::class);
    }
}
