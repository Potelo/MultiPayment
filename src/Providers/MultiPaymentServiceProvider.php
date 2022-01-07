<?php

namespace Potelo\MultiPayment\Providers;

use Potelo\MultiPayment\MultiPayment;
use Illuminate\Support\ServiceProvider;
use Potelo\MultiPayment\Gateways\MoipGateway;
use Potelo\MultiPayment\Gateways\IuguGateway;

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

        $this->app->bind(IuguGateway::class, function ($app) {
            $app->make(IuguGateway::class);
        });
        $this->app->bind(MoipGateway::class, function ($app) {
            $app->make(MoipGateway::class);
        });
        $this->app->bind('multiPayment', function ($app) {
            return new MultiPayment();
        });
    }
}
