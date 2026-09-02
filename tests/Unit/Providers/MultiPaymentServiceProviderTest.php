<?php

namespace Potelo\MultiPayment\Tests\Unit\Providers;

use Orchestra\Testbench\TestCase;
use Potelo\MultiPayment\Contracts\IdempotencyStore;
use Potelo\MultiPayment\Helpers\ConfigurationHelper;
use Potelo\MultiPayment\Idempotency\CacheIdempotencyStore;
use Potelo\MultiPayment\Idempotency\InMemoryIdempotencyStore;
use Potelo\MultiPayment\Providers\MultiPaymentServiceProvider;

/**
 * O service provider registra a `IdempotencyStore` sobre o cache do Laravel, com o prefixo da
 * configuração, e a aplicação pode substituí-la por um bind próprio. Usa o Testbench porque o
 * binding depende do container e do cache da aplicação; nada sai para a rede.
 */
class MultiPaymentServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [MultiPaymentServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('multi-payment.idempotency.prefix', 'teste:idem:');
    }

    public function testTheProviderBindsACacheIdempotencyStoreWithTheConfiguredPrefix(): void
    {
        $store = $this->app->make(IdempotencyStore::class);

        $this->assertInstanceOf(CacheIdempotencyStore::class, $store);
        $this->assertSame('ok', $store->remember('chave', fn () => 'ok', 60));
        $this->assertTrue($this->app['cache']->has('teste:idem:chave'));
        $this->assertSame($store::class, ConfigurationHelper::resolveIdempotencyStore()::class);
    }

    public function testTheApplicationCanReplaceTheStoreWithItsOwnBinding(): void
    {
        $own = new InMemoryIdempotencyStore();
        $this->app->instance(IdempotencyStore::class, $own);

        $this->assertSame($own, ConfigurationHelper::resolveIdempotencyStore());
    }

    public function testTheTtlComesFromTheConfigurationWithADefaultOfOneDay(): void
    {
        $this->assertSame(86400, ConfigurationHelper::idempotencyTtl());

        $this->app['config']->set('multi-payment.idempotency.ttl', 120);

        $this->assertSame(120, ConfigurationHelper::idempotencyTtl());
    }
}
