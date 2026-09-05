<?php

namespace Potelo\MultiPayment\Tests\Unit;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\PaymentError;
use Potelo\MultiPayment\Enums\DeclineCode;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * O model `PaymentError` (`Invoice::$lastPaymentError`): a resposta de `retryable()`, a
 * conversão de `declineCode` na escrita e o preenchimento pelo `fill()` da fatura.
 */
class PaymentErrorTest extends TestCase
{
    /**
     * Sem orientação do gateway, `retryable()` segue `DeclineCode::isRetryable()`; a orientação
     * (`$retryable`) prevalece quando existe, nas duas direções.
     */
    public function testRetryableFollowsTheDeclineCodeUnlessTheGatewayAdvisedOtherwise(): void
    {
        $error = new PaymentError();
        $this->assertFalse($error->retryable(), 'sem declineCode responde falso');

        $error->declineCode = DeclineCode::TRY_AGAIN;
        $this->assertTrue($error->retryable());

        $error->declineCode = DeclineCode::GENERIC;
        $this->assertFalse($error->retryable());

        $error->retryable = true;
        $this->assertTrue($error->retryable(), 'a orientação do gateway prevalece');

        $error->declineCode = DeclineCode::TRY_AGAIN;
        $error->retryable = false;
        $this->assertFalse($error->retryable());
    }

    /**
     * `declineCode` aceita a string do valor na escrita e devolve o enum; string fora do enum
     * é recusada com os valores aceitos.
     */
    public function testDeclineCodeIsCastOnWrite(): void
    {
        $error = new PaymentError();
        $error->fill(['decline_code' => 'insufficient_funds', 'occurred_at' => '2026-09-01T10:00:00-03:00']);

        $this->assertSame(DeclineCode::INSUFFICIENT_FUNDS, $error->declineCode);
        $this->assertInstanceOf(Carbon::class, $error->occurredAt);

        $this->expectException(ModelAttributeValidationException::class);
        $error->declineCode = 'motivo_novo';
    }

    /**
     * `Invoice::fill()` aceita `last_payment_error` como array aninhado, e o `clone` da fatura
     * copia o objeto, para parsear a cópia não alterar o original.
     */
    public function testInvoiceFillsAndClonesTheLastPaymentError(): void
    {
        $invoice = new Invoice();
        $invoice->fill([
            'amount' => 10000,
            'last_payment_error' => ['decline_code' => 'do_not_honor', 'gateway_code' => '05'],
        ]);

        $this->assertSame(DeclineCode::DO_NOT_HONOR, $invoice->lastPaymentError->declineCode);
        $this->assertSame('05', $invoice->lastPaymentError->gatewayCode);

        $copy = clone $invoice;
        $copy->lastPaymentError->gatewayCode = '51';

        $this->assertSame('05', $invoice->lastPaymentError->gatewayCode);
    }
}
