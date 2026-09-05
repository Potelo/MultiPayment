<?php

namespace Potelo\MultiPayment\Tests\Unit\Webhooks;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\WebhookEvent;
use Potelo\MultiPayment\Webhooks\WebhookDeduplicator;
use Potelo\MultiPayment\Contracts\IdempotencyStore;
use Potelo\MultiPayment\Idempotency\InMemoryIdempotencyStore;
use Potelo\MultiPayment\Exceptions\IdempotencyConflictException;

/**
 * Deduplicação de entregas de webhook por id sobre a `IdempotencyStore`: a primeira passada de
 * um id registra a chave e as seguintes marcam `isReplay`, dentro do prazo configurado.
 */
class WebhookDeduplicatorTest extends TestCase
{
    private InMemoryIdempotencyStore $store;

    private WebhookDeduplicator $deduplicator;

    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([]));
        Facade::setFacadeApplication($app);

        $this->store = new InMemoryIdempotencyStore();
        $this->deduplicator = new WebhookDeduplicator($this->store);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testTheSecondPassOfTheSameIdIsFlaggedAsReplay(): void
    {
        $first = $this->deduplicator->flagReplay(self::event('evt_1'));
        $second = $this->deduplicator->flagReplay(self::event('evt_1'));

        $this->assertFalse($first->isReplay);
        $this->assertTrue($second->isReplay);
    }

    public function testDifferentIdsAndGatewaysDoNotCollide(): void
    {
        $this->deduplicator->flagReplay(self::event('evt_1'));

        $otherId = $this->deduplicator->flagReplay(self::event('evt_2'));
        $otherGateway = $this->deduplicator->flagReplay(self::event('evt_1', 'iugu'));

        $this->assertFalse($otherId->isReplay);
        $this->assertFalse($otherGateway->isReplay);
        $this->assertTrue($this->store->has('webhook:iugu:evt_1'));
    }

    public function testAnEventWithoutIdComesBackIntactWithoutTouchingTheStore(): void
    {
        $event = $this->deduplicator->flagReplay(self::event(null));

        $this->assertFalse($event->isReplay);
        $this->assertFalse($this->store->has('webhook:stripe:'));
    }

    public function testAnEventWithoutGatewayComesBackIntactWithoutTouchingTheStore(): void
    {
        $event = $this->deduplicator->flagReplay(self::event('evt_1', ''));

        $this->assertFalse($event->isReplay);
        $this->assertFalse($this->store->has('webhook::evt_1'));
    }

    public function testAConcurrentDeliveryWithTheSameIdCountsAsReplay(): void
    {
        $conflictingStore = new class implements IdempotencyStore {
            public function remember(string $key, callable $operation, int $ttlSeconds): mixed
            {
                throw IdempotencyConflictException::concurrent($key);
            }

            public function has(string $key): bool
            {
                return false;
            }

            public function forget(string $key): void
            {
            }
        };

        $event = (new WebhookDeduplicator($conflictingStore))->flagReplay(self::event('evt_1'));

        $this->assertTrue($event->isReplay);
    }

    public function testTheIdIsForgottenAfterTheConfiguredTtl(): void
    {
        Facade::getFacadeApplication()->make('config')->set('multi-payment.webhooks.dedup_ttl', 60);
        Carbon::setTestNow('2026-09-05 10:00:00');

        $this->deduplicator->flagReplay(self::event('evt_1'));

        Carbon::setTestNow('2026-09-05 10:00:30');
        $withinTtl = $this->deduplicator->flagReplay(self::event('evt_1'));

        Carbon::setTestNow('2026-09-05 10:02:00');
        $afterTtl = $this->deduplicator->flagReplay(self::event('evt_1'));

        $this->assertTrue($withinTtl->isReplay);
        $this->assertFalse($afterTtl->isReplay);
    }

    public function testWithoutAStoreOfItsOwnItResolvesTheOneFromTheContainer(): void
    {
        Facade::getFacadeApplication()->instance(IdempotencyStore::class, $this->store);

        $deduplicator = new WebhookDeduplicator();
        $deduplicator->flagReplay(self::event('evt_1'));

        $this->assertTrue($this->store->has('webhook:stripe:evt_1'));
    }

    /**
     * Evento mínimo com o id e o gateway usados na chave de deduplicação.
     */
    public function testReleaseMakesTheSameIdCountAsFirstAgain(): void
    {
        $first = $this->deduplicator->flagReplay(self::event('evt_1'));
        $this->assertFalse($first->isReplay);

        $this->deduplicator->release($first);
        $retry = $this->deduplicator->flagReplay(self::event('evt_1'));

        $this->assertFalse($retry->isReplay);
    }

    public function testReleaseIgnoresAnEventWithoutIdOrGateway(): void
    {
        $this->deduplicator->flagReplay(self::event('evt_1'));

        $this->deduplicator->release(self::event(null));

        $this->assertTrue($this->deduplicator->flagReplay(self::event('evt_1'))->isReplay);
    }

    private static function event(?string $id, string $gateway = 'stripe'): WebhookEvent
    {
        $event = new WebhookEvent();
        $event->id = $id;
        $event->gateway = $gateway;

        return $event;
    }
}
