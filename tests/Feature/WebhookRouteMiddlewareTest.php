<?php

namespace Potelo\MultiPayment\Tests\Feature;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Orchestra\Testbench\TestCase;
use Potelo\MultiPayment\Providers\MultiPaymentServiceProvider;

/**
 * Middleware de curto-circuito para provar que a lista configurada roda antes do pipeline.
 */
class TeapotMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return new Response('', 418);
    }
}

/**
 * A rota pronta aplica o caminho e o middleware de `multi-payment.webhooks.route`: o caminho
 * customizado substitui o padrão, e o middleware configurado roda antes do pipeline.
 */
class WebhookRouteMiddlewareTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [MultiPaymentServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('multi-payment.webhooks.route.enabled', true);
        $app['config']->set('multi-payment.webhooks.route.path', '/hooks/{gateway}');
        $app['config']->set('multi-payment.webhooks.route.middleware', [TeapotMiddleware::class]);
    }

    public function testTheConfiguredMiddlewareRunsBeforeThePipeline(): void
    {
        $this->call('POST', '/hooks/stripe', content: '{}')->assertStatus(418);
    }

    public function testTheConfiguredPathReplacesTheDefaultOne(): void
    {
        $this->call('POST', '/multipayment/webhooks/stripe', content: '{}')->assertStatus(404);
    }
}
