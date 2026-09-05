<?php

namespace Potelo\MultiPayment\Idempotency;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Potelo\MultiPayment\Contracts\IdempotencyStore;
use Potelo\MultiPayment\Exceptions\ConfigurationException;
use Potelo\MultiPayment\Exceptions\IdempotencyConflictException;

/**
 * Store sobre o cache do Laravel. O resultado da primeira execução fica guardado pelo TTL sob a
 * chave com prefixo, e a execução é protegida por um lock do cache: quem não consegue o lock
 * recebe `IdempotencyConflictException`. Exige um cache store com suporte a lock
 * (`LockProvider`: redis, memcached, database, file, array, dynamodb).
 */
final class CacheIdempotencyStore implements IdempotencyStore
{
    /** Prefixo padrão das chaves no cache. */
    public const DEFAULT_PREFIX = 'multi-payment:idempotency:';

    /**
     * Duração padrão do lock, em segundos. Cobre a requisição mais longa que o SDK da Iugu
     * espera (30 s de conexão mais 80 s de resposta).
     */
    public const DEFAULT_LOCK_SECONDS = 120;

    /**
     * @param  Repository  $cache
     * @param  string  $prefix  prefixo das chaves no cache
     * @param  int  $lockSeconds  duração do lock que protege cada execução
     */
    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix = self::DEFAULT_PREFIX,
        private readonly int $lockSeconds = self::DEFAULT_LOCK_SECONDS
    ) {
    }

    /**
     * @inheritDoc
     * @throws ConfigurationException  o cache store configurado não suporta lock
     */
    public function remember(string $key, callable $operation, int $ttlSeconds): mixed
    {
        $cacheKey = $this->cacheKey($key);

        $cached = $this->cache->get($cacheKey);
        if ($this->isHit($cached)) {
            return $cached['result'];
        }

        $lock = $this->lockProvider()->lock($cacheKey . ':lock', $this->lockSeconds);
        if (!$lock->get()) {
            throw IdempotencyConflictException::concurrent($key);
        }

        try {
            // outra execução pode ter terminado entre a leitura acima e a obtenção do lock
            $cached = $this->cache->get($cacheKey);
            if ($this->isHit($cached)) {
                return $cached['result'];
            }

            $result = $operation();
            $this->cache->put($cacheKey, ['result' => $result], $ttlSeconds);

            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * @inheritDoc
     */
    public function has(string $key): bool
    {
        return $this->isHit($this->cache->get($this->cacheKey($key)));
    }

    /**
     * @inheritDoc
     */
    public function forget(string $key): void
    {
        $this->cache->forget($this->cacheKey($key));
    }

    /**
     * Chave no cache: prefixo mais a chave da operação.
     *
     * @param  string  $key
     * @return string
     */
    private function cacheKey(string $key): string
    {
        return $this->prefix . $key;
    }

    /**
     * Diz se o valor lido do cache é um resultado guardado. O resultado viaja num envelope para
     * que uma operação que devolve nulo também conte como guardada.
     *
     * @param  mixed  $cached
     * @return bool
     */
    private function isHit(mixed $cached): bool
    {
        return is_array($cached) && array_key_exists('result', $cached);
    }

    /**
     * Cache store por trás do repositório, que precisa suportar lock.
     *
     * @return LockProvider
     * @throws ConfigurationException
     */
    private function lockProvider(): LockProvider
    {
        $store = $this->cache->getStore();
        if (!$store instanceof LockProvider) {
            throw ConfigurationException::IdempotencyStoreWithoutLock(get_class($store));
        }

        return $store;
    }
}
