<?php

namespace Potelo\MultiPayment\Tests\Unit\Exceptions;

use Mockery;
use PHPUnit\Framework\TestCase;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Exceptions\MultiPaymentException;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;

class UnsupportedOperationExceptionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function testExtendsThePackageBaseException(): void
    {
        $this->assertInstanceOf(
            MultiPaymentException::class,
            UnsupportedOperationException::notImplemented('stripe', Capability::BANK_SLIP)
        );
    }

    public function testNotImplementedAttributesTheGapToTheLibrary(): void
    {
        $exception = UnsupportedOperationException::notImplemented('stripe', Capability::BANK_SLIP);

        $this->assertSame(Capability::BANK_SLIP, $exception->capability);
        $this->assertSame('stripe', $exception->gateway);
        $this->assertSame('not_implemented', $exception->reason);
        $this->assertTrue($exception->isNotImplemented());
        $this->assertSame(
            'A capability [bank_slip] ainda não está implementada nesta lib para o gateway stripe; o gateway oferece o recurso.',
            $exception->getMessage()
        );
        $this->assertNull($exception->httpStatus);
    }

    public function testGatewayLimitationAttributesTheGapToTheGateway(): void
    {
        $exception = UnsupportedOperationException::gatewayLimitation('iugu', Capability::PARTIAL_REFUND_PIX, 'Repita sem valor parcial.');

        $this->assertSame(Capability::PARTIAL_REFUND_PIX, $exception->capability);
        $this->assertSame('iugu', $exception->gateway);
        $this->assertSame('gateway_limitation', $exception->reason);
        $this->assertFalse($exception->isNotImplemented());
        $this->assertSame(
            'O gateway iugu não oferece a capability [partial_refund_pix]. Repita sem valor parcial.',
            $exception->getMessage()
        );
    }

    public function testRestrictedKeepsTheCapabilityAndUsesTheGivenMessage(): void
    {
        $exception = UnsupportedOperationException::restricted('stripe', Capability::INVOICE_DUPLICATION, 'Só Pix pendente.');

        $this->assertSame('Só Pix pendente.', $exception->getMessage());
        $this->assertSame(Capability::INVOICE_DUPLICATION, $exception->capability);
        $this->assertSame('gateway_limitation', $exception->reason);
    }

    public function testForGatewayReadsTheReasonFromTheDeclaration(): void
    {
        $gateway = Mockery::mock(GatewayContract::class);
        $gateway->shouldReceive('notYetImplemented')->andReturn([Capability::BANK_SLIP]);
        $gateway->shouldReceive('__toString')->andReturn('falso');

        $notImplemented = UnsupportedOperationException::forGateway($gateway, Capability::BANK_SLIP);
        $limitation = UnsupportedOperationException::forGateway($gateway, Capability::INSTALLMENTS, 'Detalhe.');

        $this->assertSame('not_implemented', $notImplemented->reason);
        $this->assertSame('falso', $notImplemented->gateway);
        $this->assertSame('gateway_limitation', $limitation->reason);
        $this->assertStringEndsWith(' Detalhe.', $limitation->getMessage());
    }

}
