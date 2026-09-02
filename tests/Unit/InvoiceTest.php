<?php

namespace Potelo\MultiPayment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Potelo\MultiPayment\Models\Invoice;
use PHPUnit\Framework\Attributes\DataProvider;

class InvoiceTest extends TestCase
{
    public static function settledProvider(): array
    {
        return [
            'paga' => [Invoice::STATUS_PAID, true],
            'parcialmente estornada' => [Invoice::STATUS_PARTIALLY_REFUNDED, true],
            'pendente' => [Invoice::STATUS_PENDING, false],
            'cancelada' => [Invoice::STATUS_CANCELED, false],
            'estornada' => [Invoice::STATUS_REFUNDED, false],
            'em disputa' => [Invoice::STATUS_DISPUTED, false],
            'chargeback' => [Invoice::STATUS_CHARGEBACK, false],
            'status desconhecido' => ['qualquer_coisa', false],
        ];
    }

    #[DataProvider('settledProvider')]
    public function testIsSettledOnlyForStatusesWhereTheMoneyWasReceived(string $status, bool $expected): void
    {
        $this->assertSame($expected, Invoice::isSettled($status));
    }

    public static function contestedProvider(): array
    {
        return [
            'em disputa' => [Invoice::STATUS_DISPUTED, true],
            'chargeback' => [Invoice::STATUS_CHARGEBACK, true],
            'paga' => [Invoice::STATUS_PAID, false],
            'estornada' => [Invoice::STATUS_REFUNDED, false],
            'parcialmente estornada' => [Invoice::STATUS_PARTIALLY_REFUNDED, false],
            'pendente' => [Invoice::STATUS_PENDING, false],
            'cancelada' => [Invoice::STATUS_CANCELED, false],
            'status desconhecido' => ['qualquer_coisa', false],
        ];
    }

    #[DataProvider('contestedProvider')]
    public function testIsContestedOnlyForOpenOrLostDisputes(string $status, bool $expected): void
    {
        $this->assertSame($expected, Invoice::isContested($status));
    }
}
