<?php

namespace Potelo\MultiPayment\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use Potelo\MultiPayment\Enums\DeclineCode;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\ChargingException;
use Potelo\MultiPayment\Exceptions\MultiPaymentException;
use Potelo\MultiPayment\Exceptions\CardDeclinedException;

class CardDeclinedExceptionTest extends TestCase
{
    public function testExtendsThePackageBaseExceptionAndNotTheGatewayOne(): void
    {
        $exception = CardDeclinedException::declined('iugu', DeclineCode::GENERIC, null, '');

        $this->assertInstanceOf(MultiPaymentException::class, $exception);
        $this->assertNotInstanceOf(GatewayException::class, $exception);
    }

    public function testDeclinedFillsCodeGatewayCodeRetryableAndReason(): void
    {
        $previous = new \RuntimeException('sdk');

        $exception = CardDeclinedException::declined('stripe', DeclineCode::INSUFFICIENT_FUNDS, 'insufficient_funds', 'Your card has insufficient funds.', $previous, 402);

        $this->assertSame(DeclineCode::INSUFFICIENT_FUNDS, $exception->declineCode);
        $this->assertSame('insufficient_funds', $exception->gatewayCode);
        $this->assertTrue($exception->retryable);
        $this->assertSame('insufficient_funds', $exception->reason);
        $this->assertSame($previous, $exception->getPrevious());
        $this->assertSame(402, $exception->httpStatus);
        $this->assertNull($exception->chargeResponse);
        $this->assertSame(
            'Cartão recusado pelo gateway stripe (insufficient_funds, código insufficient_funds): Your card has insufficient funds.',
            $exception->getMessage()
        );
    }

    public function testRetryableCanBeOverriddenByTheDriver(): void
    {
        $forced = CardDeclinedException::declined('stripe', DeclineCode::GENERIC, 'generic_decline', '', null, null, true);
        $denied = CardDeclinedException::declined('stripe', DeclineCode::TRY_AGAIN, 'processing_error', '', null, null, false);

        $this->assertTrue($forced->retryable);
        $this->assertFalse($denied->retryable);
    }

    public function testMessageWithoutCodeAndWithoutDetail(): void
    {
        $exception = CardDeclinedException::declined('iugu', DeclineCode::UNKNOWN, '', '');

        $this->assertNull($exception->gatewayCode);
        $this->assertSame('Cartão recusado pelo gateway iugu (unknown, sem código)', $exception->getMessage());
    }

    public function testDefaultsBeforeDeclinedIsCalled(): void
    {
        $exception = new CardDeclinedException('recusado');

        $this->assertSame(DeclineCode::UNKNOWN, $exception->declineCode);
        $this->assertNull($exception->gatewayCode);
        $this->assertFalse($exception->retryable);
        $this->assertNull($exception->reason);
    }

    public function testChargingExceptionIsACardDeclinedExceptionAndIsCaughtByBothNames(): void
    {
        $exception = ChargingException::declined('iugu', DeclineCode::EXPIRED_CARD, '54', 'Cartão vencido');

        $this->assertInstanceOf(ChargingException::class, $exception);
        $this->assertInstanceOf(CardDeclinedException::class, $exception);
        $this->assertSame(DeclineCode::EXPIRED_CARD, $exception->declineCode);

        $caught = [];
        try {
            throw $exception;
        } catch (ChargingException $e) {
            $caught[] = 'charging';
        }
        try {
            throw $exception;
        } catch (CardDeclinedException $e) {
            $caught[] = 'card_declined';
        }

        $this->assertSame(['charging', 'card_declined'], $caught);
    }

    public function testChargingExceptionKeepsThePreviousConstructorSignature(): void
    {
        $previous = new \RuntimeException('sdk');

        $exception = new ChargingException('recusado', $previous, 402);
        $exception->chargeResponse = ['LR' => '51'];
        $exception->reason = 'card_declined';

        $this->assertSame($previous, $exception->getPrevious());
        $this->assertSame(402, $exception->httpStatus);
        $this->assertSame(['LR' => '51'], $exception->chargeResponse);
        $this->assertSame('card_declined', $exception->reason);
    }
}
