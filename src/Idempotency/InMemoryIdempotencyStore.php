<?php

namespace Potelo\MultiPayment\Idempotency;

use Carbon\Carbon;
use Potelo\MultiPayment\Contracts\IdempotencyStore;
use Potelo\MultiPayment\Exceptions\IdempotencyConflictException;

/**
 * Store em memória, válida só dentro do processo. Serve aos testes e a scripts de uma execução;
 * numa aplicação web cada requisição começa com a memória vazia, então use `CacheIdempotencyStore`.
 */
final class InMemoryIdempotencyStore implements IdempotencyStore
{
    /** @var array<string, array{result: mixed, expiresAt: int}> */
    private array $results = [];

    /** @var array<string, true> */
    private array $inProgress = [];

    /**
     * @inheritDoc
     */
    public function remember(string $key, callable $operation, int $ttlSeconds): mixed
    {
        if ($this->has($key)) {
            return $this->results[$key]['result'];
        }

        if (isset($this->inProgress[$key])) {
            throw IdempotencyConflictException::concurrent($key);
        }

        $this->inProgress[$key] = true;
        try {
            $result = $operation();
        } finally {
            unset($this->inProgress[$key]);
        }

        $this->results[$key] = ['result' => $result, 'expiresAt' => Carbon::now()->getTimestamp() + $ttlSeconds];

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function has(string $key): bool
    {
        if (!isset($this->results[$key])) {
            return false;
        }

        if ($this->results[$key]['expiresAt'] <= Carbon::now()->getTimestamp()) {
            unset($this->results[$key]);

            return false;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function forget(string $key): void
    {
        unset($this->results[$key]);
    }
}
