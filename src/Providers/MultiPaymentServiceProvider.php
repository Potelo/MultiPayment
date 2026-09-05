<?php

namespace Potelo\MultiPayment\Providers;

use Potelo\MultiPayment\MultiPayment;
use Illuminate\Support\ServiceProvider;
use Potelo\MultiPayment\Contracts\IdempotencyStore;
use Potelo\MultiPayment\Console\WebhookReplayCommand;
use Potelo\MultiPayment\Console\SyncSubscriptionsCommand;
use Potelo\MultiPayment\Idempotency\CacheIdempotencyStore;
use Potelo\MultiPayment\Webhooks\WebhookController;

class MultiPaymentServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected bool $defer = false;

    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        $configFile = __DIR__ . '/../config/multi-payment.php';

        $this->publishes([
            $configFile => config_path('multi-payment.php'),
        ], 'config');

        if ($this->app->runningInConsole()) {
            $this->commands([SyncSubscriptionsCommand::class, WebhookReplayCommand::class]);
        }

        $this->registerWebhookRoute();
    }

    /**
     * Registra a rota pronta de webhooks quando `multi-payment.webhooks.route.enabled` é
     * verdadeiro. A rota nasce fora de qualquer grupo de middleware (webhook não tem sessão
     * nem CSRF); o que a aplicação precisar entra por `multi-payment.webhooks.route.middleware`.
     *
     * @return void
     */
    private function registerWebhookRoute(): void
    {
        $config = $this->app['config']->get('multi-payment.webhooks.route', []);

        if (empty($config['enabled']) || !$this->app->bound('router')) {
            return;
        }

        $this->app['router']
            ->post($config['path'] ?? '/multipayment/webhooks/{gateway}', WebhookController::class)
            ->middleware($config['middleware'] ?? [])
            ->name('multipayment.webhook');
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $configFile = __DIR__ . '/../config/multi-payment.php';
        $this->mergeConfigFrom($configFile, 'multi-payment');

        $this->app->bind('multiPayment', function ($app) {
            return $app->make(MultiPayment::class);
        });

        // a aplicação troca a store com um bind próprio de IdempotencyStore depois deste
        $this->app->bind(IdempotencyStore::class, function ($app) {
            $config = $app['config']->get('multi-payment.idempotency', []);

            return new CacheIdempotencyStore(
                $app['cache']->store($config['cache_store'] ?? null),
                $config['prefix'] ?? CacheIdempotencyStore::DEFAULT_PREFIX
            );
        });
    }
}
