<?php

namespace Potelo\MultiPayment\Tests\Feature;

use Orchestra\Testbench\TestCase;
use Illuminate\Support\Facades\Event;
use Potelo\MultiPayment\Events\InvoicePaid;
use Potelo\MultiPayment\Events\InvoiceCreated;
use Potelo\MultiPayment\Events\WebhookReceived;
use Potelo\MultiPayment\Providers\MultiPaymentServiceProvider;

/**
 * Comando `multipayment:webhook-replay`: reautentica a fixture com a credencial configurada,
 * roda o pipeline sem tocar na deduplicação e falha com mensagem clara para arquivo ou
 * configuração ausentes. Sem rede: as fixtures usadas não hidratam no parse.
 */
class WebhookReplayCommandTest extends TestCase
{
    private const STRIPE_FIXTURE = __DIR__ . '/../fixtures/stripe/webhooks/invoice.paid.json';

    private const IUGU_FIXTURE = __DIR__ . '/../fixtures/iugu/webhooks/invoice.created.json';

    protected function getPackageProviders($app): array
    {
        return [MultiPaymentServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('multi-payment.gateways.stripe.webhook_secret', 'whsec_replay_secret');
        // token diferente do gravado na fixture, para provar que o comando o substitui
        $app['config']->set('multi-payment.gateways.iugu.webhook_token', 'token-configurado-no-replay');
    }

    public function testAStripeFixtureIsReplayedThroughThePipelineWithAFreshSignature(): void
    {
        Event::fake();

        $this->artisan('multipayment:webhook-replay', [
            'gateway' => 'stripe',
            'fixture' => self::STRIPE_FIXTURE,
        ])->assertExitCode(0);

        Event::assertDispatched(WebhookReceived::class);
        Event::assertDispatched(InvoicePaid::class);
    }

    public function testReplayingTheSameFixtureTwiceDispatchesTwice(): void
    {
        Event::fake();

        foreach ([1, 2] as $ignored) {
            $this->artisan('multipayment:webhook-replay', [
                'gateway' => 'stripe',
                'fixture' => self::STRIPE_FIXTURE,
            ])->assertExitCode(0);
        }

        Event::assertDispatchedTimes(InvoicePaid::class, 2);
    }

    public function testAnIuguEnvelopeIsReplayedWithTheConfiguredToken(): void
    {
        Event::fake();

        $this->artisan('multipayment:webhook-replay', [
            'gateway' => 'iugu',
            'fixture' => self::IUGU_FIXTURE,
        ])->assertExitCode(0);

        Event::assertDispatched(InvoiceCreated::class);
    }

    public function testAMissingFixtureFileFailsWithoutRunningThePipeline(): void
    {
        Event::fake();

        $this->artisan('multipayment:webhook-replay', [
            'gateway' => 'stripe',
            'fixture' => __DIR__ . '/nao-existe.json',
        ])->expectsOutputToContain('não encontrado')->assertExitCode(1);

        Event::assertNotDispatched(WebhookReceived::class);
    }

    public function testAnUnconfiguredGatewayFails(): void
    {
        $this->artisan('multipayment:webhook-replay', [
            'gateway' => 'inexistente',
            'fixture' => self::STRIPE_FIXTURE,
        ])->expectsOutputToContain('não está configurado')->assertExitCode(1);
    }

}
