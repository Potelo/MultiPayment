<?php

namespace Potelo\MultiPayment\Tests\Unit;

use Psr\Log\AbstractLogger;

/**
 * Guarda o que o pacote manda para o log, para o teste afirmar nível, mensagem e contexto.
 * Registrado no container como `log`, é o que `LogHelper` encontra.
 */
class RecordingLogger extends AbstractLogger
{
    /** @var array<int, array{level: string, message: string, context: array}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }
}
