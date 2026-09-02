<?php

namespace Potelo\MultiPayment\Tests\Unit\Exceptions;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Potelo\MultiPayment\Exceptions\MultiPaymentException;
use Potelo\MultiPayment\Exceptions\RefundNotSupportedException;

class RefundNotSupportedExceptionTest extends TestCase
{
    public function testExtendsThePackageBaseException(): void
    {
        $this->assertInstanceOf(MultiPaymentException::class, RefundNotSupportedException::boletoNoRefund('iugu'));
    }

    public function testBoletoNoRefundRequiresManualRefund(): void
    {
        $exception = RefundNotSupportedException::boletoNoRefund('stripe');

        $this->assertSame('boleto_no_refund', $exception->reason);
        $this->assertSame('bank_slip', $exception->paymentMethod);
        $this->assertTrue($exception->manualRefundRequired);
        $this->assertStringContainsString('stripe', $exception->getMessage());
    }

    public function testPixPartialNotSupportedIsFixableByTheCaller(): void
    {
        $exception = RefundNotSupportedException::pixPartialNotSupported('iugu', 500, 1000);

        $this->assertSame('pix_partial_not_supported', $exception->reason);
        $this->assertSame('pix', $exception->paymentMethod);
        $this->assertFalse($exception->manualRefundRequired);
        $this->assertStringContainsString('500', $exception->getMessage());
        $this->assertStringContainsString('1000', $exception->getMessage());
    }

    public function testPixPartialNotSupportedWithUnknownPaidAmount(): void
    {
        $exception = RefundNotSupportedException::pixPartialNotSupported('iugu', 500, null);

        $this->assertStringContainsString('desconhecido', $exception->getMessage());
    }

    public function testAlreadyRefundedKeepsThePaymentMethod(): void
    {
        $exception = RefundNotSupportedException::alreadyRefunded('iugu', 'credit_card');

        $this->assertSame('already_refunded', $exception->reason);
        $this->assertSame('credit_card', $exception->paymentMethod);
        $this->assertFalse($exception->manualRefundRequired);
    }

    public function testRefundWindowExpiredRequiresManualRefund(): void
    {
        $exception = RefundNotSupportedException::refundWindowExpired('iugu', 'pix', Carbon::parse('2026-05-01'), 90);

        $this->assertSame('refund_window_expired', $exception->reason);
        $this->assertSame('pix', $exception->paymentMethod);
        $this->assertTrue($exception->manualRefundRequired);
        $this->assertStringContainsString('90 dias', $exception->getMessage());
        $this->assertStringContainsString('2026-05-01', $exception->getMessage());
    }
}
