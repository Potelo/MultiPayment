<?php

namespace Potelo\MultiPayment\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Tests\Unit\RecordingLogger;

class InvoiceStatusTest extends TestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testHasTheThirteenStatesOfTheNormalizedVocabulary(): void
    {
        $this->assertSame([
            'pending',
            'authorized',
            'processing',
            'paid',
            'partially_paid',
            'externally_paid',
            'partially_refunded',
            'refunded',
            'disputed',
            'chargeback',
            'canceled',
            'expired',
            'unknown',
        ], array_column(InvoiceStatus::cases(), 'value'));
    }

    /**
     * Tabela verdade completa dos cinco helpers, um caso por linha.
     *
     * @return array<string, array{InvoiceStatus, bool, bool, bool, bool, bool}>
     */
    public static function helperTruthTableProvider(): array
    {
        // [status, isSettled, isContested, isTerminal, isOpen, isPayable]
        return [
            'pending' => [InvoiceStatus::PENDING, false, false, false, true, true],
            'authorized' => [InvoiceStatus::AUTHORIZED, false, false, false, true, true],
            'processing' => [InvoiceStatus::PROCESSING, false, false, false, true, false],
            'paid' => [InvoiceStatus::PAID, true, false, false, false, false],
            'partially_paid' => [InvoiceStatus::PARTIALLY_PAID, true, false, false, true, true],
            'externally_paid' => [InvoiceStatus::EXTERNALLY_PAID, true, false, false, false, false],
            'partially_refunded' => [InvoiceStatus::PARTIALLY_REFUNDED, true, false, false, false, false],
            'refunded' => [InvoiceStatus::REFUNDED, false, false, true, false, false],
            'disputed' => [InvoiceStatus::DISPUTED, false, true, false, false, false],
            'chargeback' => [InvoiceStatus::CHARGEBACK, false, true, true, false, false],
            'canceled' => [InvoiceStatus::CANCELED, false, false, true, false, false],
            'expired' => [InvoiceStatus::EXPIRED, false, false, false, false, true],
            'unknown' => [InvoiceStatus::UNKNOWN, false, false, false, false, false],
        ];
    }

    #[DataProvider('helperTruthTableProvider')]
    public function testHelpersAnswerEachBusinessQuestion(
        InvoiceStatus $status,
        bool $settled,
        bool $contested,
        bool $terminal,
        bool $open,
        bool $payable
    ): void {
        $this->assertSame($settled, $status->isSettled(), 'isSettled');
        $this->assertSame($contested, $status->isContested(), 'isContested');
        $this->assertSame($terminal, $status->isTerminal(), 'isTerminal');
        $this->assertSame($open, $status->isOpen(), 'isOpen');
        $this->assertSame($payable, $status->isPayable(), 'isPayable');
        $this->assertFalse($terminal && $payable, 'nenhum status é terminal e pagável ao mesmo tempo');
    }

    /**
     * `EXPIRED` responde verdadeiro a `isPayable()` e falso a `isTerminal()` e a `isOpen()`.
     */
    public function testExpiredIsPayableAndNotTerminal(): void
    {
        $this->assertFalse(InvoiceStatus::EXPIRED->isTerminal());
        $this->assertTrue(InvoiceStatus::EXPIRED->isPayable());
        $this->assertFalse(InvoiceStatus::EXPIRED->isOpen());
    }

    public function testFromValueReturnsTheMatchingCaseWithoutLogging(): void
    {
        $logger = $this->bindLogger();

        $this->assertSame(InvoiceStatus::PAID, InvoiceStatus::fromValue('paid', 'iugu'));
        $this->assertSame(InvoiceStatus::PARTIALLY_PAID, InvoiceStatus::fromValue('partially_paid'));
        $this->assertSame([], $logger->records);
    }

    public function testFromValueTurnsAnUnknownStringIntoUnknownWithAWarning(): void
    {
        $logger = $this->bindLogger();

        $status = InvoiceStatus::fromValue('status_novo', 'iugu');

        $this->assertSame(InvoiceStatus::UNKNOWN, $status);
        $this->assertCount(1, $logger->records);
        $this->assertSame('warning', $logger->records[0]['level']);
        $this->assertStringContainsString('status_novo', $logger->records[0]['message']);
        $this->assertStringContainsString('iugu', $logger->records[0]['message']);
        $this->assertSame(['status' => 'status_novo', 'gateway' => 'iugu'], $logger->records[0]['context']);
    }

    public function testUnknownLogsAGatewaylessValue(): void
    {
        $logger = $this->bindLogger();

        $this->assertSame(InvoiceStatus::UNKNOWN, InvoiceStatus::unknown('x'));
        $this->assertSame(['status' => 'x', 'gateway' => null], $logger->records[0]['context']);
        $this->assertStringContainsString('desconhecido', $logger->records[0]['message']);
    }

    public function testUnknownFallsBackToErrorLogWithoutALoggerInTheContainer(): void
    {
        Facade::setFacadeApplication(new Container());
        $previous = ini_set('error_log', $file = tempnam(sys_get_temp_dir(), 'multipayment-log'));

        try {
            $this->assertSame(InvoiceStatus::UNKNOWN, InvoiceStatus::unknown('sem_logger', 'stripe'));
        } finally {
            ini_set('error_log', $previous);
        }

        $this->assertStringContainsString('sem_logger', file_get_contents($file));
        unlink($file);
    }

    private function bindLogger(): RecordingLogger
    {
        $app = new Container();
        $app->instance('log', $logger = new RecordingLogger());
        Facade::setFacadeApplication($app);

        return $logger;
    }
}
