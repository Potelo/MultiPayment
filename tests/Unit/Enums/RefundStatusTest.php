<?php

namespace Potelo\MultiPayment\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Enums\RefundStatus;
use Potelo\MultiPayment\Tests\Unit\RecordingLogger;

class RefundStatusTest extends TestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testHasTheFourStatesPlusUnknown(): void
    {
        $this->assertSame([
            'pending',
            'succeeded',
            'failed',
            'canceled',
            'unknown',
        ], array_column(RefundStatus::cases(), 'value'));
    }

    public static function isFailedProvider(): array
    {
        return [
            'pending' => [RefundStatus::PENDING, false],
            'succeeded' => [RefundStatus::SUCCEEDED, false],
            'failed' => [RefundStatus::FAILED, true],
            'canceled' => [RefundStatus::CANCELED, true],
            'unknown' => [RefundStatus::UNKNOWN, false],
        ];
    }

    #[DataProvider('isFailedProvider')]
    public function testIsFailedIsTrueOnlyWhenTheMoneyDidNotGoBack(RefundStatus $status, bool $failed): void
    {
        $this->assertSame($failed, $status->isFailed());
    }

    public function testFromValueReturnsTheMatchingCaseWithoutLogging(): void
    {
        $logger = $this->bindLogger();

        $this->assertSame(RefundStatus::SUCCEEDED, RefundStatus::fromValue('succeeded', 'stripe'));
        $this->assertSame(RefundStatus::PENDING, RefundStatus::fromValue('pending'));
        $this->assertSame([], $logger->records);
    }

    public function testFromValueTurnsAnUnknownStringIntoUnknownWithAWarning(): void
    {
        $logger = $this->bindLogger();

        $status = RefundStatus::fromValue('status_novo', 'stripe');

        $this->assertSame(RefundStatus::UNKNOWN, $status);
        $this->assertCount(1, $logger->records);
        $this->assertSame('warning', $logger->records[0]['level']);
        $this->assertStringContainsString('status_novo', $logger->records[0]['message']);
        $this->assertStringContainsString('stripe', $logger->records[0]['message']);
        $this->assertSame(['status' => 'status_novo', 'gateway' => 'stripe'], $logger->records[0]['context']);
    }

    private function bindLogger(): RecordingLogger
    {
        $app = new Container();
        $app->instance('log', $logger = new RecordingLogger());
        Facade::setFacadeApplication($app);

        return $logger;
    }
}
