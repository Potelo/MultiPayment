<?php

namespace Potelo\MultiPayment\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\ValidationException;

class ValidationExceptionTest extends TestCase
{
    public function testExtendsGatewayExceptionAndKeepsTheBody(): void
    {
        $previous = new \RuntimeException('sdk');

        $exception = ValidationException::withFieldErrors(
            'Error creating customer',
            ['email' => ['inválido']],
            ['email' => ['inválido']],
            $previous,
            422
        );

        $this->assertInstanceOf(GatewayException::class, $exception);
        $this->assertSame(['email' => ['inválido']], $exception->fieldErrors);
        $this->assertSame(['email' => ['inválido']], $exception->getErrors());
        $this->assertSame('Error creating customer - email.0: inválido', $exception->getMessage());
        $this->assertSame($previous, $exception->getPrevious());
        $this->assertSame(422, $exception->httpStatus);
    }

    public function testFieldErrorsAreEmptyByDefault(): void
    {
        $this->assertSame([], (new ValidationException('erro'))->fieldErrors);
    }

    #[DataProvider('normalizeProvider')]
    public function testNormalizeFieldErrors($errors, array $expected): void
    {
        $this->assertSame($expected, ValidationException::normalizeFieldErrors($errors));
    }

    public static function normalizeProvider(): array
    {
        return [
            'nulo' => [null, []],
            'string vazia' => ['', []],
            'array vazio' => [[], []],
            'string' => ['Unauthorized', ['base' => ['Unauthorized']]],
            'objeto por campo (formato do SDK da Iugu)' => [
                ['email' => ['não é válido', 'já está em uso'], 'cpf_cnpj' => 'inválido'],
                ['email' => ['não é válido', 'já está em uso'], 'cpf_cnpj' => ['inválido']],
            ],
            'stdClass por campo' => [
                (object) ['email' => ['não é válido']],
                ['email' => ['não é válido']],
            ],
            'lista sem campo' => [
                ['erro um', 'erro dois'],
                ['base' => ['erro um', 'erro dois']],
            ],
            'lista de objetos com message (Pix Automático)' => [
                [(object) ['message' => 'Pagamento não pode ser cancelado'], ['message' => 'Outro']],
                ['base' => ['Pagamento não pode ser cancelado', 'Outro']],
            ],
            'valor que não é texto vira JSON' => [
                ['amount' => [['min' => 100]]],
                ['amount' => ['{"min":100}']],
            ],
            'escalar que não é string' => [42, ['base' => ['42']]],
        ];
    }
}
