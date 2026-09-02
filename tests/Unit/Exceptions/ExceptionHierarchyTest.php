<?php

namespace Potelo\MultiPayment\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\ChargingException;
use Potelo\MultiPayment\Exceptions\NotFoundException;
use Potelo\MultiPayment\Exceptions\RateLimitException;
use Potelo\MultiPayment\Exceptions\ValidationException;
use Potelo\MultiPayment\Exceptions\MultiPaymentException;
use Potelo\MultiPayment\Exceptions\CardDeclinedException;
use Potelo\MultiPayment\Exceptions\ConfigurationException;
use Potelo\MultiPayment\Exceptions\AuthenticationException;
use Potelo\MultiPayment\Exceptions\RefundNotSupportedException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;
use Potelo\MultiPayment\Exceptions\IdempotencyConflictException;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Afirma a árvore de exceções do pacote: toda classe de `src/Exceptions/` herda de
 * `MultiPaymentException`; as que traduzem uma resposta de erro do gateway herdam de
 * `GatewayException`; recusa de cartão e recusa antes da rede ficam fora dela.
 */
class ExceptionHierarchyTest extends TestCase
{
    public function testEveryExceptionClassInThePackageExtendsTheBase(): void
    {
        $files = glob(__DIR__ . '/../../../src/Exceptions/*.php');
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $class = 'Potelo\\MultiPayment\\Exceptions\\' . basename($file, '.php');
            $this->assertTrue(class_exists($class), "{$class} não existe");
            $this->assertTrue(
                $class === MultiPaymentException::class || is_subclass_of($class, MultiPaymentException::class),
                "{$class} não herda de MultiPaymentException"
            );
        }
    }

    #[DataProvider('gatewayResponseProvider')]
    public function testGatewayResponseExceptionsExtendGatewayException(string $class): void
    {
        $this->assertTrue(is_subclass_of($class, GatewayException::class), "{$class} não herda de GatewayException");
    }

    public static function gatewayResponseProvider(): array
    {
        return [
            'ValidationException' => [ValidationException::class],
            'NotFoundException' => [NotFoundException::class],
            'RateLimitException' => [RateLimitException::class],
            'IdempotencyConflictException' => [IdempotencyConflictException::class],
        ];
    }

    #[DataProvider('outsideGatewayExceptionProvider')]
    public function testTheOtherExceptionsStayOutsideGatewayException(string $class): void
    {
        $this->assertFalse(is_subclass_of($class, GatewayException::class), "{$class} não deveria herdar de GatewayException");
    }

    public static function outsideGatewayExceptionProvider(): array
    {
        return [
            'CardDeclinedException' => [CardDeclinedException::class],
            'ChargingException' => [ChargingException::class],
            'AuthenticationException' => [AuthenticationException::class],
            'GatewayNotAvailableException' => [GatewayNotAvailableException::class],
            'UnsupportedOperationException' => [UnsupportedOperationException::class],
            'RefundNotSupportedException' => [RefundNotSupportedException::class],
            'ModelAttributeValidationException' => [ModelAttributeValidationException::class],
            'ConfigurationException' => [ConfigurationException::class],
        ];
    }

    public function testChargingExceptionIsTheDeprecatedNameOfCardDeclinedException(): void
    {
        $this->assertSame(CardDeclinedException::class, get_parent_class(ChargingException::class));
    }

    public function testRateLimitExceptionCarriesRetryAfter(): void
    {
        $previous = new \RuntimeException('sdk');

        $exception = RateLimitException::withRetryAfter('Too many requests', ['type' => 'rate_limit_error'], $previous, 429, 7);

        $this->assertSame(7, $exception->retryAfter);
        $this->assertSame(['type' => 'rate_limit_error'], $exception->getErrors());
        $this->assertSame($previous, $exception->getPrevious());
        $this->assertSame(429, $exception->httpStatus);
        $this->assertNull((new RateLimitException('erro'))->retryAfter);
    }

    public function testNotFoundAndIdempotencyConflictKeepTheGatewayExceptionConstructor(): void
    {
        $previous = new \RuntimeException('sdk');

        $notFound = new NotFoundException('Error getting invoice', 'Not Found', $previous, 404);
        $conflict = new IdempotencyConflictException('Error creating invoice', ['base' => ['conflito']], $previous, 409);

        $this->assertSame(['Not Found'], $notFound->getErrors());
        $this->assertSame(404, $notFound->httpStatus);
        $this->assertSame($previous, $notFound->getPrevious());
        $this->assertSame(['base' => ['conflito']], $conflict->getErrors());
        $this->assertSame(409, $conflict->httpStatus);
    }
}
