<?php

namespace Potelo\MultiPayment\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Illuminate\Support\Facades\Event;
use Potelo\MultiPayment\Events\WebhookReceived;
use Potelo\MultiPayment\Providers\MultiPaymentServiceProvider;

/**
 * O comando de replay falha quando o driver recusa a entrega reautenticada: com
 * `webhook_secret` num gateway cujo driver espera token, a assinatura gerada não serve e o
 * parse recusa antes de qualquer despacho.
 */
class WebhookReplayCommandRefusedDeliveryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [MultiPaymentServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('multi-payment.gateways.iugu.webhook_secret', 'whsec_no_gateway_errado');
        $app['config']->set('multi-payment.gateways.iugu.webhook_token', null);
    }

    public function testADeliveryRefusedByTheDriverFailsWithoutDispatching(): void
    {
        Event::fake();

        $this->artisan('multipayment:webhook-replay', [
            'gateway' => 'iugu',
            'fixture' => __DIR__ . '/../fixtures/iugu/webhooks/invoice.created.json',
        ])->expectsOutputToContain('recusada na verificação')->assertExitCode(1);

        Event::assertNotDispatched(WebhookReceived::class);
    }
}
