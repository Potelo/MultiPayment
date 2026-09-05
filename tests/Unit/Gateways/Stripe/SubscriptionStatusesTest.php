<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways\Stripe;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Enums\SubscriptionStatus;
use Potelo\MultiPayment\Tests\Unit\RecordingLogger;
use Potelo\MultiPayment\Gateways\Stripe\SubscriptionStatuses;

/**
 * Mapa do status da Subscription da Stripe para `SubscriptionStatus`, com uma fixture de
 * `tests/fixtures/stripe/subscriptions/` por status e a precedência de `pause_collection`.
 */
class SubscriptionStatusesTest extends TestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('log', $this->logger = new RecordingLogger());
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    #[DataProvider('fixtureProvider')]
    public function testEachStripeStatusIsTranslatedFromItsFixture(string $fixture, SubscriptionStatus $expected): void
    {
        $subscription = \Stripe\Subscription::constructFrom(self::fixture($fixture));

        $this->assertSame($expected, SubscriptionStatuses::toSubscriptionStatus($subscription));
        $this->assertSame([], $this->logger->records);
    }

    public static function fixtureProvider(): array
    {
        return [
            'incomplete' => ['incomplete', SubscriptionStatus::PENDING],
            'incomplete_expired' => ['incomplete_expired', SubscriptionStatus::EXPIRED],
            'trialing' => ['trialing', SubscriptionStatus::TRIALING],
            'active' => ['active', SubscriptionStatus::ACTIVE],
            'past_due' => ['past_due', SubscriptionStatus::PAST_DUE],
            'unpaid' => ['unpaid', SubscriptionStatus::PAST_DUE],
            'canceled' => ['canceled', SubscriptionStatus::CANCELED],
            'paused' => ['paused', SubscriptionStatus::PAUSED],
            'active com pause_collection' => ['active_pause_collection', SubscriptionStatus::SUSPENDED],
        ];
    }

    /**
     * Toda string da máquina de estados documentada tem uma linha no mapa.
     */
    public function testEveryDocumentedStripeStatusIsMapped(): void
    {
        foreach (['incomplete', 'incomplete_expired', 'trialing', 'active', 'past_due', 'canceled', 'unpaid', 'paused'] as $status) {
            $this->assertNotSame(
                SubscriptionStatus::UNKNOWN,
                SubscriptionStatuses::toSubscriptionStatus((object) ['status' => $status]),
                $status
            );
        }

        $this->assertSame([], $this->logger->records);
    }

    public function testPauseCollectionDoesNotReviveAnEndedSubscription(): void
    {
        $paused = ['behavior' => 'void', 'resumes_at' => null];

        $this->assertSame(
            SubscriptionStatus::CANCELED,
            SubscriptionStatuses::toSubscriptionStatus((object) ['status' => 'canceled', 'pause_collection' => $paused])
        );
        $this->assertSame(
            SubscriptionStatus::EXPIRED,
            SubscriptionStatuses::toSubscriptionStatus((object) ['status' => 'incomplete_expired', 'pause_collection' => $paused])
        );
        $this->assertSame(
            SubscriptionStatus::SUSPENDED,
            SubscriptionStatuses::toSubscriptionStatus((object) ['status' => 'past_due', 'pause_collection' => $paused])
        );
    }

    public function testAnUnknownStatusReadsAsUnknownWithAWarningNamingStripe(): void
    {
        $status = SubscriptionStatuses::toSubscriptionStatus((object) [
            'status' => 'status_novo',
            'pause_collection' => ['behavior' => 'void'],
        ]);

        $this->assertSame(SubscriptionStatus::UNKNOWN, $status);
        $this->assertCount(1, $this->logger->records);
        $this->assertSame('warning', $this->logger->records[0]['level']);
        $this->assertSame(['status' => 'status_novo', 'gateway' => 'stripe'], $this->logger->records[0]['context']);
    }

    public function testAMissingStatusReadsAsUnknownWithAWarning(): void
    {
        $this->assertSame(SubscriptionStatus::UNKNOWN, SubscriptionStatuses::toSubscriptionStatus((object) []));
        $this->assertSame(['status' => '', 'gateway' => 'stripe'], $this->logger->records[0]['context']);
    }

    private static function fixture(string $name): array
    {
        return json_decode(
            file_get_contents(__DIR__ . '/../../../fixtures/stripe/subscriptions/' . $name . '.json'),
            true
        );
    }
}
