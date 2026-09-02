<?php

namespace Potelo\MultiPayment\Tests\Unit\Idempotency;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Potelo\MultiPayment\Idempotency\InMemoryIdempotencyStore;
use Potelo\MultiPayment\Exceptions\IdempotencyConflictException;

class InMemoryIdempotencyStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-02 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function testExecutesTheOperationOnceAndReturnsTheStoredResultAfterwards(): void
    {
        $store = new InMemoryIdempotencyStore();
        $executions = 0;
        $operation = function () use (&$executions) {
            $executions++;

            return (object) ['id' => 'inv_1', 'execution' => $executions];
        };

        $first = $store->remember('chave', $operation, 60);
        $second = $store->remember('chave', $operation, 60);

        $this->assertSame(1, $executions);
        $this->assertSame($first, $second);
        $this->assertTrue($store->has('chave'));
        $this->assertFalse($store->has('outra'));
    }

    public function testDistinctKeysExecuteSeparately(): void
    {
        $store = new InMemoryIdempotencyStore();
        $executions = 0;
        $operation = function () use (&$executions) {
            return ++$executions;
        };

        $this->assertSame(1, $store->remember('a', $operation, 60));
        $this->assertSame(2, $store->remember('b', $operation, 60));
    }

    public function testANullResultIsStoredToo(): void
    {
        $store = new InMemoryIdempotencyStore();
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
        $store = new InMemoryIdempotencyStore();
        $executions = 0;
        $operation = function () use (&$executions) {
            return ++$executions;
        };

        $store->remember('chave', $operation, 60);
        Carbon::setTestNow('2026-09-02 12:01:00');

        $this->assertFalse($store->has('chave'));
        $this->assertSame(2, $store->remember('chave', $operation, 60));
    }

    public function testAnOperationThatThrowsIsNotStored(): void
    {
        $store = new InMemoryIdempotencyStore();
        $executions = 0;
        $operation = function () use (&$executions) {
            $executions++;
            if ($executions === 1) {
                throw new \RuntimeException('falha transitória');
            }

            return 'ok';
        };

        try {
            $store->remember('chave', $operation, 60);
            $this->fail('Esperava RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertFalse($store->has('chave'));
        }

        $this->assertSame('ok', $store->remember('chave', $operation, 60));
        $this->assertSame(2, $executions);
    }

    public function testAConcurrentExecutionWithTheSameKeyIsAConflict(): void
    {
        $store = new InMemoryIdempotencyStore();

        $this->expectException(IdempotencyConflictException::class);
        $this->expectExceptionMessageMatches('/\[chave\]/');

        $store->remember('chave', function () use ($store) {
            // reentrância com a mesma chave é a única concorrência possível num só processo
            return $store->remember('chave', fn () => 'aninhada', 60);
        }, 60);
    }

    public function testForgetDropsTheStoredResult(): void
    {
        $store = new InMemoryIdempotencyStore();
        $store->remember('chave', fn () => 'ok', 60);

        $store->forget('chave');

        $this->assertFalse($store->has('chave'));
    }
}
