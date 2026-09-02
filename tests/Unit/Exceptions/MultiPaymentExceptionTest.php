<?php

namespace Potelo\MultiPayment\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\ChargingException;
use Potelo\MultiPayment\Exceptions\MultiPaymentException;
use Potelo\MultiPayment\Exceptions\AuthenticationException;
use Potelo\MultiPayment\Exceptions\RefundNotSupportedException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;

class MultiPaymentExceptionTest extends TestCase
{
    public function testBaseExceptionCarriesPreviousAndHttpStatus(): void
    {
        $original = new \RuntimeException('sdk');

        $exception = new MultiPaymentException('erro', $original, 503);

        $this->assertSame('erro', $exception->getMessage());
        $this->assertSame($original, $exception->getPrevious());
        $this->assertSame(503, $exception->httpStatus);
    }

    public function testBaseExceptionDefaultsToNoPreviousAndNoHttpStatus(): void
    {
        $exception = new MultiPaymentException('erro');

        $this->assertNull($exception->getPrevious());
        $this->assertNull($exception->httpStatus);
    }

    public function testGatewayExceptionKeepsErrorsWhileAcceptingPreviousAndHttpStatus(): void
    {
        $original = new \RuntimeException('sdk');

        $exception = new GatewayException('erro', ['code' => 'invalid'], $original, 422);

        $this->assertSame('erro - code: invalid', $exception->getMessage());
        $this->assertSame(['code' => 'invalid'], $exception->getErrors());
        $this->assertSame($original, $exception->getPrevious());
        $this->assertSame(422, $exception->httpStatus);
    }

    public function testSubclassesWithoutOwnConstructorInheritPreviousAndHttpStatus(): void
    {
        $original = new \RuntimeException('sdk');

        $charging = new ChargingException('recusado', $original, 402);
        $unavailable = new GatewayNotAvailableException('fora do ar', $original, 502);

        $this->assertSame($original, $charging->getPrevious());
        $this->assertSame(402, $charging->httpStatus);
        $this->assertSame($original, $unavailable->getPrevious());
        $this->assertSame(502, $unavailable->httpStatus);
    }

    public function testAuthenticationExceptionNamesTheGatewayAndKeepsTheDetail(): void
    {
        $original = new \RuntimeException('Unauthorized');

        $exception = AuthenticationException::invalidCredentials('iugu', 'Unauthorized', $original, 401);

        $this->assertInstanceOf(MultiPaymentException::class, $exception);
        $this->assertNotInstanceOf(GatewayNotAvailableException::class, $exception);
        $this->assertStringContainsString('iugu', $exception->getMessage());
        $this->assertStringContainsString('Unauthorized', $exception->getMessage());
        $this->assertSame($original, $exception->getPrevious());
        $this->assertSame(401, $exception->httpStatus);
    }

    public function testAuthenticationExceptionWithoutDetailHasNoDanglingSuffix(): void
    {
        $exception = AuthenticationException::invalidCredentials('stripe', '');

        $this->assertStringEndsWith('configurada.', $exception->getMessage());
        $this->assertNull($exception->httpStatus);
    }

    public function testRefundNotSupportedExceptionAcceptsPrevious(): void
    {
        $original = new \RuntimeException('sdk');

        $exception = new RefundNotSupportedException(
            'recusado',
            'pix',
            RefundNotSupportedException::REASON_PIX_PARTIAL_NOT_SUPPORTED,
            false,
            $original
        );

        $this->assertSame($original, $exception->getPrevious());
        $this->assertSame('pix', $exception->paymentMethod);
        $this->assertFalse($exception->manualRefundRequired);
    }
}
