<?php

namespace Potelo\MultiPayment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Enums\InvoiceStatus;

/**
 * Cobre os helpers estáticos obsoletos de `Invoice`, que delegam ao enum e aceitam tanto o
 * caso do enum quanto a string antiga.
 */
class InvoiceTest extends TestCase
{
    public static function settledProvider(): array
    {
        return [
            'paga (enum)' => [InvoiceStatus::PAID, true],
            'paga (string antiga)' => [Invoice::STATUS_PAID, true],
            'parcialmente estornada' => [Invoice::STATUS_PARTIALLY_REFUNDED, true],
            'parcialmente paga' => ['partially_paid', true],
            'paga por fora' => ['externally_paid', true],
            'pendente' => [Invoice::STATUS_PENDING, false],
            'cancelada' => [Invoice::STATUS_CANCELED, false],
            'estornada' => [Invoice::STATUS_REFUNDED, false],
            'em disputa' => [Invoice::STATUS_DISPUTED, false],
            'chargeback' => [Invoice::STATUS_CHARGEBACK, false],
            'status desconhecido' => ['qualquer_coisa', false],
        ];
    }

    #[DataProvider('settledProvider')]
    #[IgnoreDeprecations]
    public function testIsSettledDelegatesToTheEnumAndAcceptsTheOldString(InvoiceStatus|string $status, bool $expected): void
    {
        $this->assertSame($expected, Invoice::isSettled($status));
    }

    public static function contestedProvider(): array
    {
        return [
            'em disputa (enum)' => [InvoiceStatus::DISPUTED, true],
            'em disputa (string antiga)' => [Invoice::STATUS_DISPUTED, true],
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
    #[IgnoreDeprecations]
    public function testIsContestedDelegatesToTheEnumAndAcceptsTheOldString(InvoiceStatus|string $status, bool $expected): void
    {
        $this->assertSame($expected, Invoice::isContested($status));
    }

    #[IgnoreDeprecations]
    public function testIsSettledTriggersADeprecationNotice(): void
    {
        $this->expectUserDeprecationMessage('Invoice::isSettled() está obsoleto desde 2026-09-02; use $invoice->status->isSettled()');

        Invoice::isSettled(InvoiceStatus::PAID);
    }

    #[IgnoreDeprecations]
    public function testIsContestedTriggersADeprecationNotice(): void
    {
        $this->expectUserDeprecationMessage('Invoice::isContested() está obsoleto desde 2026-09-02; use $invoice->status->isContested()');

        Invoice::isContested(InvoiceStatus::DISPUTED);
    }

    /**
     * As constantes antigas continuam existindo com o mesmo valor do enum, então quem compara
     * `$invoice->status->value` com `Invoice::STATUS_PAID` continua obtendo verdadeiro.
     */
    public static function oldConstantProvider(): array
    {
        return [
            [Invoice::STATUS_PENDING, InvoiceStatus::PENDING],
            [Invoice::STATUS_PAID, InvoiceStatus::PAID],
            [Invoice::STATUS_CANCELED, InvoiceStatus::CANCELED],
            [Invoice::STATUS_REFUNDED, InvoiceStatus::REFUNDED],
            [Invoice::STATUS_PARTIALLY_REFUNDED, InvoiceStatus::PARTIALLY_REFUNDED],
            [Invoice::STATUS_DISPUTED, InvoiceStatus::DISPUTED],
            [Invoice::STATUS_CHARGEBACK, InvoiceStatus::CHARGEBACK],
        ];
    }

    #[DataProvider('oldConstantProvider')]
    public function testOldStatusConstantsKeepTheEnumValue(string $constant, InvoiceStatus $status): void
    {
        $invoice = new Invoice();
        $invoice->status = $constant;

        $this->assertSame($status, $invoice->status);
        $this->assertSame($constant, $invoice->status->value);
        $this->assertTrue($invoice->status->value === $constant);
    }
}
