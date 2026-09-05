<?php

namespace Potelo\MultiPayment\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Enums\DisputeStatus;
use Potelo\MultiPayment\Tests\Unit\RecordingLogger;

class DisputeStatusTest extends TestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testHasTheFiveStatesPlusUnknown(): void
    {
        $this->assertSame([
            'open',
            'under_review',
            'won',
            'lost',
            'accepted',
            'unknown',
        ], array_column(DisputeStatus::cases(), 'value'));
    }

    public static function helperProvider(): array
    {
        return [
            'open' => [DisputeStatus::OPEN, true, false],
            'under_review' => [DisputeStatus::UNDER_REVIEW, true, false],
            'won' => [DisputeStatus::WON, false, false],
            'lost' => [DisputeStatus::LOST, false, true],
            'accepted' => [DisputeStatus::ACCEPTED, false, true],
            'unknown' => [DisputeStatus::UNKNOWN, false, false],
        ];
    }

    /**
     * Cada estado responde a no máximo um helper: `isOpen()` enquanto a contestação está em
     * curso, `isLost()` quando o valor foi devolvido ao pagador.
     */
    #[DataProvider('helperProvider')]
    public function testEachStateAnswersTheExpectedHelpers(DisputeStatus $status, bool $open, bool $lost): void
    {
        $this->assertSame($open, $status->isOpen());
        $this->assertSame($lost, $status->isLost());
    }

    public function testFromValueReturnsTheMatchingCaseWithoutLogging(): void
    {
        $logger = $this->bindLogger();

        $this->assertSame(DisputeStatus::WON, DisputeStatus::fromValue('won', 'stripe'));
        $this->assertSame(DisputeStatus::OPEN, DisputeStatus::fromValue('open'));
        $this->assertSame([], $logger->records);
    }

    public function testFromValueTurnsAnUnknownStringIntoUnknownWithAWarning(): void
    {
        $logger = $this->bindLogger();

        $status = DisputeStatus::fromValue('status_novo', 'iugu');

        $this->assertSame(DisputeStatus::UNKNOWN, $status);
        $this->assertCount(1, $logger->records);
        $this->assertSame('warning', $logger->records[0]['level']);
        $this->assertStringContainsString('status_novo', $logger->records[0]['message']);
        $this->assertStringContainsString('iugu', $logger->records[0]['message']);
        $this->assertSame(['status' => 'status_novo', 'gateway' => 'iugu'], $logger->records[0]['context']);
    }

    private function bindLogger(): RecordingLogger
    {
        $app = new Container();
        $app->instance('log', $logger = new RecordingLogger());
        Facade::setFacadeApplication($app);

        return $logger;
    }
}
