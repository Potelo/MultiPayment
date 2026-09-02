<?php

namespace Potelo\MultiPayment\Idempotency;

/**
 * Derivação de chaves de idempotência para as requisições secundárias de uma operação.
 */
final class IdempotencyKey
{
    /**
     * Chave derivada `{chave}:{sufixo}` para uma requisição secundária da mesma operação (o
     * cliente criado junto com a fatura, o cartão salvo antes da cobrança). A derivação é
     * determinística, então um retry reproduz as mesmas chaves.
     *
     * @param  string|null  $idempotencyKey
     * @param  string  $suffix
     * @return string|null  nulo quando não há chave
     */
    public static function derive(?string $idempotencyKey, string $suffix): ?string
    {
        return is_null($idempotencyKey) ? null : "{$idempotencyKey}:{$suffix}";
    }
}
