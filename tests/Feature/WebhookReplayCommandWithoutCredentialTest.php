<?php

namespace Potelo\MultiPayment\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Illuminate\Support\Facades\Event;
use Potelo\MultiPayment\Events\WebhookReceived;
use Potelo\MultiPayment\Providers\MultiPaymentServiceProvider;

/**
 * O comando de replay recusa o gateway sem `webhook_secret` nem `webhook_token` configurado:
 * sem credencial não há como reautenticar a entrega, e nada chega ao pipeline.
 */
class WebhookReplayCommandWithoutCredentialTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [MultiPaymentServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('multi-payment.gateways.iugu.webhook_token', null);
    }

    public function testAGatewayWithoutWebhookCredentialFails(): void
    {
        Event::fake();

        $this->artisan('multipayment:webhook-replay', [
            'gateway' => 'iugu',
            'fixture' => __DIR__ . '/../fixtures/iugu/webhooks/invoice.created.json',
        ])->expectsOutputToContain('não há como reautenticar')->assertExitCode(1);

        Event::assertNotDispatched(WebhookReceived::class);
    }
}
