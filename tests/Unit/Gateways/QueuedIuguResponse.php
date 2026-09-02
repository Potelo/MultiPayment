<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

/**
 * Corpo, status HTTP e cabeçalhos (nome em minúsculas) de uma resposta enfileirada em
 * `QueuedIuguApiRequest`.
 */
final class QueuedIuguResponse
{
    public function __construct(
        public readonly object|array $body,
        public readonly int $status,
        public readonly array $headers = []
    ) {
    }
}
