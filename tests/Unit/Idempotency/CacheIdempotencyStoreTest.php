<?php

namespace Potelo\MultiPayment\Tests\Unit\Idempotency;

use Carbon\Carbon;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use PHPUnit\Framework\TestCase;
use Potelo\MultiPayment\Idempotency\CacheIdempotencyStore;
use Potelo\MultiPayment\Exceptions\ConfigurationException;
use Potelo\MultiPayment\Exceptions\IdempotencyConflictException;

/**
 * Usa o `ArrayStore` do Laravel, que implementa `LockProvider`, como cache; o comportamento
 * é o mesmo em redis, memcached, database e file.
 */
class CacheIdempotencyStoreTest extends TestCase
{
    private Repository $cache;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-02 12:00:00');
        $this->cache = new Repository(new ArrayStore());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testExecutesTheOperationOnceAndStoresTheResultUnderThePrefixedKey(): void
    {
        $store = new CacheIdempotencyStore($this->cache);
        $executions = 0;
        $operation = function () use (&$executions) {
            $executions++;

            return (object) ['id' => 'inv_1'];
        };

        $first = $store->remember('chave', $operation, 60);
        $second = $store->remember('chave', $operation, 60);

        $this->assertSame(1, $executions);
        $this->assertEquals($first, $second);
        $this->assertTrue($store->has('chave'));
        $this->assertTrue($this->cache->has(CacheIdempotencyStore::DEFAULT_PREFIX . 'chave'));
        // o lock é liberado ao fim da execução
        $this->assertTrue($this->cache->getStore()->lock(CacheIdempotencyStore::DEFAULT_PREFIX . 'chave:lock')->get());
    }

    public function testACustomPrefixIsApplied(): void
    {
        $store = new CacheIdempotencyStore($this->cache, 'app:idem:');

        $store->remember('chave', fn () => 'ok', 60);

        $this->assertTrue($this->cache->has('app:idem:chave'));
        $this->assertFalse($this->cache->has(CacheIdempotencyStore::DEFAULT_PREFIX . 'chave'));
    }

    public function testANullResultCountsAsStored(): void
    {
        $store = new CacheIdempotencyStore($this->cache);
        $executions = 0;
        $operation = function () use (&$executions) {
            $executions++;

            return null;
        };

        $this->assertNull($store->remember('chave', $operation, 60));
        $this->assertNull($store->remember('chave', $operation, 60));
        $this->assertSame(1, $executions);
        $this->assertTrue($store->has('chave'));
    }

    public function testTheResultExpiresAfterTheTtl(): void
    {
        $store = new CacheIdempotencyStore($this->cache);
        $executions = 0;
        $operation = function () use (&$executions) {
            return ++$executions;
        };

        $store->remember('chave', $operation, 60);
        Carbon::setTestNow('2026-09-02 12:01:01');

        $this->assertFalse($store->has('chave'));
        $this->assertSame(2, $store->remember('chave', $operation, 60));
    }

    public function testForgetRemovesTheStoredResultAndTheKeyExecutesAgain(): void
    {
        $store = new CacheIdempotencyStore($this->cache);
        $executions = 0;
        $operation = function () use (&$executions) {
            return ++$executions;
        };

        $store->remember('chave', $operation, 60);
        $store->forget('chave');

        $this->assertFalse($store->has('chave'));
        $this->assertSame(2, $store->remember('chave', $operation, 60));
    }

    public function testAnOperationThatThrowsIsNotStoredAndReleasesTheLock(): void
    {
        $store = new CacheIdempotencyStore($this->cache);

        try {
            $store->remember('chave', function () {
                throw new \RuntimeException('falha transitória');
            }, 60);
            $this->fail('Esperava RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertFalse($store->has('chave'));
        }

        $this->assertSame('ok', $store->remember('chave', fn () => 'ok', 60));
    }

    public function testAKeyWhoseLockIsHeldElsewhereIsAConflict(): void
    {
        $store = new CacheIdempotencyStore($this->cache);
        $held = $this->cache->getStore()->lock(CacheIdempotencyStore::DEFAULT_PREFIX . 'chave:lock', 60, 'outro-processo');
        $this->assertTrue($held->get());

        try {
            $store->remember('chave', fn () => 'nunca executa', 60);
            $this->fail('Esperava IdempotencyConflictException');
        } catch (IdempotencyConflictException $e) {
            $this->assertStringContainsString('[chave]', $e->getMessage());
            $this->assertNull($e->httpStatus);
            $this->assertFalse($store->has('chave'));
        } finally {
            $held->release();
        }
    }

    public function testACacheStoreWithoutLockSupportIsAConfigurationError(): void
    {
        $storeWithoutLock = new class implements Store {
            private array $items = [];

            public function get($key)
            {
                return $this->items[$key] ?? null;
            }

            public function many(array $keys)
            {
                return array_map(fn ($key) => $this->get($key), array_combine($keys, $keys));
            }

            public function put($key, $value, $seconds)
            {
                $this->items[$key] = $value;

                return true;
            }

            public function putMany(array $values, $seconds)
            {
                foreach ($values as $key => $value) {
                    $this->put($key, $value, $seconds);
                }

                return true;
            }

            public function increment($key, $value = 1)
            {
                return $this->items[$key] = ($this->items[$key] ?? 0) + $value;
            }

            public function decrement($key, $value = 1)
            {
                return $this->increment($key, -$value);
            }

            public function forever($key, $value)
            {
                return $this->put($key, $value, 0);
            }

            public function forget($key)
            {
                unset($this->items[$key]);

                return true;
            }

            public function flush()
            {
                $this->items = [];

                return true;
            }

            public function getPrefix()
            {
                return '';
            }
        };
        $store = new CacheIdempotencyStore(new Repository($storeWithoutLock));

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/não suporta lock/');

        $store->remember('chave', fn () => 'nunca executa', 60);
    }
}
