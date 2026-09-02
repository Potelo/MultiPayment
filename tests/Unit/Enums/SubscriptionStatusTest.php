<?php

namespace Potelo\MultiPayment\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Enums\SubscriptionStatus;
use Potelo\MultiPayment\Tests\Unit\RecordingLogger;

class SubscriptionStatusTest extends TestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testHasTheNineStatesOfTheNormalizedVocabulary(): void
    {
        $this->assertSame([
            'pending',
            'trialing',
            'active',
            'past_due',
            'paused',
            'suspended',
            'canceled',
            'expired',
            'unknown',
        ], array_column(SubscriptionStatus::cases(), 'value'));
    }

    /**
     * Tabela verdade completa dos três helpers, um caso por linha.
     *
     * @return array<string, array{SubscriptionStatus, bool, bool, bool}>
     */
    public static function helperTruthTableProvider(): array
    {
        // [status, isActive, isRecoverable, isEnded]
        return [
            'pending' => [SubscriptionStatus::PENDING, false, true, false],
            'trialing' => [SubscriptionStatus::TRIALING, true, false, false],
            'active' => [SubscriptionStatus::ACTIVE, true, false, false],
            'past_due' => [SubscriptionStatus::PAST_DUE, false, true, false],
            'paused' => [SubscriptionStatus::PAUSED, false, true, false],
            'suspended' => [SubscriptionStatus::SUSPENDED, false, true, false],
            'canceled' => [SubscriptionStatus::CANCELED, false, false, true],
            'expired' => [SubscriptionStatus::EXPIRED, false, false, true],
            'unknown' => [SubscriptionStatus::UNKNOWN, false, false, false],
        ];
    }

    #[DataProvider('helperTruthTableProvider')]
    public function testHelpersAnswerEachBusinessQuestion(
        SubscriptionStatus $status,
        bool $active,
        bool $recoverable,
        bool $ended
    ): void {
        $this->assertSame($active, $status->isActive(), 'isActive');
        $this->assertSame($recoverable, $status->isRecoverable(), 'isRecoverable');
        $this->assertSame($ended, $status->isEnded(), 'isEnded');
    }

    /**
     * Cada estado responde verdadeiro a exatamente um helper, exceto `UNKNOWN`, que não
     * responde a nenhum.
     */
    #[DataProvider('helperTruthTableProvider')]
    public function testHelpersPartitionTheKnownStates(
        SubscriptionStatus $status,
        bool $active,
        bool $recoverable,
        bool $ended
    ): void {
        $expected = $status === SubscriptionStatus::UNKNOWN ? 0 : 1;

        $this->assertSame($expected, (int) $active + (int) $recoverable + (int) $ended);
    }

    public function testTheOldConstantsKeepTheEnumValues(): void
    {
        $this->assertSame(SubscriptionStatus::TRIALING->value, Subscription::STATUS_TRIALING);
        $this->assertSame(SubscriptionStatus::ACTIVE->value, Subscription::STATUS_ACTIVE);
        $this->assertSame(SubscriptionStatus::SUSPENDED->value, Subscription::STATUS_SUSPENDED);
        $this->assertSame(SubscriptionStatus::PENDING->value, Subscription::STATUS_PENDING);
        $this->assertSame(SubscriptionStatus::PAST_DUE->value, Subscription::STATUS_PAST_DUE);
        $this->assertSame(SubscriptionStatus::EXPIRED->value, Subscription::STATUS_EXPIRED);
        $this->assertSame(SubscriptionStatus::CANCELED->value, Subscription::STATUS_CANCELED);
    }

    public function testFromValueReturnsTheMatchingCaseWithoutLogging(): void
    {
        $logger = $this->bindLogger();

        $this->assertSame(SubscriptionStatus::ACTIVE, SubscriptionStatus::fromValue('active', 'iugu'));
        $this->assertSame(SubscriptionStatus::PAST_DUE, SubscriptionStatus::fromValue('past_due'));
        $this->assertSame([], $logger->records);
    }

    public function testFromValueTurnsAnUnknownStringIntoUnknownWithAWarning(): void
    {
        $logger = $this->bindLogger();

        $status = SubscriptionStatus::fromValue('status_novo', 'stripe');

        $this->assertSame(SubscriptionStatus::UNKNOWN, $status);
        $this->assertCount(1, $logger->records);
        $this->assertSame('warning', $logger->records[0]['level']);
        $this->assertStringContainsString('assinatura', $logger->records[0]['message']);
        $this->assertStringContainsString('status_novo', $logger->records[0]['message']);
        $this->assertStringContainsString('stripe', $logger->records[0]['message']);
        $this->assertSame(['status' => 'status_novo', 'gateway' => 'stripe'], $logger->records[0]['context']);
    }

    public function testUnknownLogsAGatewaylessValue(): void
    {
        $logger = $this->bindLogger();

        $this->assertSame(SubscriptionStatus::UNKNOWN, SubscriptionStatus::unknown('x'));
        $this->assertSame(['status' => 'x', 'gateway' => null], $logger->records[0]['context']);
        $this->assertStringContainsString('desconhecido', $logger->records[0]['message']);
    }

    private function bindLogger(): RecordingLogger
    {
        $app = new Container();
        $app->instance('log', $logger = new RecordingLogger());
        Facade::setFacadeApplication($app);

        return $logger;
    }
}
