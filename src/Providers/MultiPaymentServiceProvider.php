<?php

namespace Potelo\MultiPayment\Providers;

use Potelo\MultiPayment\MultiPayment;
use Illuminate\Support\ServiceProvider;

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

        $this->app->bind('multiPayment', function ($app) {
            return new MultiPayment();
        });
    }
}
