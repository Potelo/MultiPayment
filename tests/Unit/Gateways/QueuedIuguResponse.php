<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

/**
 * Corpo e status HTTP de uma resposta enfileirada em `QueuedIuguApiRequest`.
 */
final class QueuedIuguResponse
{
    public function __construct(public readonly object|array $body, public readonly int $status)
    {
    }
}
