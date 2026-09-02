<?php

namespace Potelo\MultiPayment\Tests\Unit\Exceptions;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Exceptions\MultiPaymentException;
use Potelo\MultiPayment\Exceptions\RefundNotSupportedException;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;

class RefundNotSupportedExceptionTest extends TestCase
{
    public function testExtendsThePackageBaseException(): void
    {
        $this->assertInstanceOf(MultiPaymentException::class, RefundNotSupportedException::boletoNoRefund('iugu'));
    }

    /**
     * A classe fica fora da árvore de `UnsupportedOperationException`: `instanceof` e
     * `is_subclass_of` respondem falso.
     */
    public function testIsNotAnUnsupportedOperation(): void
    {
        $this->assertNotInstanceOf(UnsupportedOperationException::class, RefundNotSupportedException::boletoNoRefund('iugu'));
        $this->assertFalse(is_subclass_of(RefundNotSupportedException::class, UnsupportedOperationException::class));
    }

    public function testIsCaughtByItsOwnNameAndNotByUnsupportedOperation(): void
    {
        $caught = [];

        try {
            throw RefundNotSupportedException::boletoNoRefund('stripe');
        } catch (RefundNotSupportedException $e) {
            $caught[] = 'refund';
        }

        try {
            throw RefundNotSupportedException::alreadyRefunded('stripe', 'pix');
        } catch (UnsupportedOperationException $e) {
            $caught[] = 'unsupported';
        } catch (MultiPaymentException $e) {
            $caught[] = 'base';
        }

        $this->assertSame(['refund', 'base'], $caught);
    }

    public function testCapabilityLimitationsCarryTheCapabilityAndTheGateway(): void
    {
        $boleto = RefundNotSupportedException::boletoNoRefund('iugu');
        $pix = RefundNotSupportedException::pixPartialNotSupported('iugu', 500, 1000);

        $this->assertTrue($boleto->isCapabilityLimitation());
        $this->assertSame(Capability::REFUND_BANK_SLIP, $boleto->capability);
        $this->assertSame('iugu', $boleto->gateway);
        $this->assertTrue($pix->isCapabilityLimitation());
        $this->assertSame(Capability::PARTIAL_REFUND_PIX, $pix->capability);
    }

    public function testStateRefusalsHaveNoCapability(): void
    {
        $refusals = [
            RefundNotSupportedException::alreadyRefunded('stripe', 'pix'),
            RefundNotSupportedException::amountExceedsRefundable('stripe', 'credit_card', 11000, 10000),
            RefundNotSupportedException::refundWindowExpired('iugu', 'pix', Carbon::parse('2026-05-01'), 90),
        ];

        foreach ($refusals as $refusal) {
            $this->assertFalse($refusal->isCapabilityLimitation(), $refusal->reason);
            $this->assertNull($refusal->capability, $refusal->reason);
        }
        $this->assertSame('stripe', $refusals[0]->gateway);
    }

    public function testHttpStatusIsAlwaysNull(): void
    {
        $this->assertNull(RefundNotSupportedException::boletoNoRefund('iugu')->httpStatus);
        $this->assertNull(RefundNotSupportedException::alreadyRefunded('stripe', 'pix')->httpStatus);
    }

    /**
     * O construtor de cinco argumentos continua aceito; gateway e capability ficam vazios.
     */
    public function testKeepsThePreviousConstructorSignature(): void
    {
        $previous = new \RuntimeException('sdk');
        $exception = new RefundNotSupportedException('msg', 'pix', RefundNotSupportedException::REASON_ALREADY_REFUNDED, false, $previous);

        $this->assertSame($previous, $exception->getPrevious());
        $this->assertSame('', $exception->gateway);
        $this->assertNull($exception->capability);
        $this->assertFalse($exception->isCapabilityLimitation());
        $this->assertSame('already_refunded', $exception->reason);
    }

    public function testIsNotImplementedIsDeprecatedAndAlwaysFalse(): void
    {
        $this->expectUserDeprecationMessage('RefundNotSupportedException::isNotImplemented() está obsoleto desde 2026-09-02 e responde sempre falso');

        $this->assertFalse(RefundNotSupportedException::boletoNoRefund('iugu')->isNotImplemented());
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

    public function testAmountExceedsRefundableIsFixableByTheCaller(): void
    {
        $exception = RefundNotSupportedException::amountExceedsRefundable('stripe', 'credit_card', 11000, 10000);

        $this->assertSame('amount_exceeds_refundable', $exception->reason);
        $this->assertSame('credit_card', $exception->paymentMethod);
        $this->assertFalse($exception->manualRefundRequired);
        $this->assertNull($exception->capability);
        $this->assertSame('stripe', $exception->gateway);
        $this->assertStringContainsString('11000', $exception->getMessage());
        $this->assertStringContainsString('10000', $exception->getMessage());
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
