<?php

namespace Potelo\MultiPayment\Tests\Unit\Exceptions;

use PHPUnit\Framework\TestCase;
use Potelo\MultiPayment\Exceptions\GatewayException;

class GatewayExceptionTest extends TestCase
{
    public function testGetErrorsNormalizesNullStringObjectAndArray(): void
    {
        $this->assertSame([], (new GatewayException('erro'))->getErrors());
        $this->assertSame(['mensagem'], (new GatewayException('erro', 'mensagem'))->getErrors());
        $this->assertSame(
            ['code' => 'invalid'],
            (new GatewayException('erro', (object) ['code' => 'invalid']))->getErrors()
        );
        $this->assertSame(
            ['code' => 'invalid'],
            (new GatewayException('erro', ['code' => 'invalid']))->getErrors()
        );
    }
}
