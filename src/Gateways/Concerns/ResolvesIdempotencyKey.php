<?php

namespace Potelo\MultiPayment\Gateways\Concerns;

use Potelo\MultiPayment\Models\Model;
use Potelo\MultiPayment\Idempotency\IdempotencyKey;

/**
 * Resolve a chave de idempotência de uma operação nos drivers: o argumento da operação tem
 * precedência, e a chave antiga em `gatewayOptions['idempotency_key']` é aceita como reserva
 * com aviso de deprecação e nunca vai no corpo da requisição.
 */
trait ResolvesIdempotencyKey
{
    /** Nome antigo da chave em `gatewayOptions`, aceito com aviso de deprecação. */
    private const LEGACY_IDEMPOTENCY_OPTION = 'idempotency_key';

    /**
     * Chave de idempotência da operação: a informada como argumento ou, na falta dela, a chave
     * antiga em `gatewayOptions['idempotency_key']` do model (ou das opções extras informadas),
     * que emite `E_USER_DEPRECATED` quando presente.
     *
     * @param  string|null  $idempotencyKey  chave informada como argumento da operação
     * @param  Model  $model
     * @param  array  $extraOptions  opções extras recebidas fora do model (`duplicateInvoice`)
     * @return string|null
     */
    protected function idempotencyKeyFor(?string $idempotencyKey, Model $model, array $extraOptions = []): ?string
    {
        $legacy = $model->gatewayOptions[self::LEGACY_IDEMPOTENCY_OPTION]
            ?? $extraOptions[self::LEGACY_IDEMPOTENCY_OPTION]
            ?? null;

        if (!is_null($legacy)) {
            trigger_error(
                "gateway_options['idempotency_key'] está obsoleto desde 2026-09-02; passe idempotencyKey como argumento da operação",
                E_USER_DEPRECATED
            );
        }

        if (!is_null($idempotencyKey)) {
            return $idempotencyKey;
        }

        return is_scalar($legacy) && (string) $legacy !== '' ? (string) $legacy : null;
    }

    /**
     * Opções do gateway sem a chave antiga de idempotência, para ela não ir no corpo da
     * requisição.
     *
     * @param  array  $gatewayOptions
     * @return array
     */
    protected static function withoutIdempotencyKey(array $gatewayOptions): array
    {
        unset($gatewayOptions[self::LEGACY_IDEMPOTENCY_OPTION]);

        return $gatewayOptions;
    }

    /**
     * Chave derivada `{chave}:{sufixo}` para uma requisição secundária da mesma operação (o
     * cartão salvo antes da cobrança, a remoção de itens antes do update). A derivação é
     * determinística, então um retry reproduz as mesmas chaves.
     *
     * @param  string|null  $idempotencyKey
     * @param  string  $suffix
     * @return string|null  nulo quando não há chave
     */
    protected static function derivedIdempotencyKey(?string $idempotencyKey, string $suffix): ?string
    {
        return IdempotencyKey::derive($idempotencyKey, $suffix);
    }
}
