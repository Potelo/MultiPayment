<?php

namespace Potelo\MultiPayment\Enums;

use Potelo\MultiPayment\Helpers\LogHelper;
use Potelo\MultiPayment\Contracts\AcceptsUnknownValue;

/**
 * Status genérico de um estorno. Cada driver traduz o status do gateway para um destes casos;
 * o valor específico do gateway fica em `Refund::$original`.
 */
enum RefundStatus: string implements AcceptsUnknownValue
{
    /** Estorno aceito pelo gateway e ainda em processamento, ou aguardando ação do cliente. */
    case PENDING = 'pending';

    /** Valor devolvido ao cliente. */
    case SUCCEEDED = 'succeeded';

    /** O gateway não conseguiu devolver o valor (cartão cancelado, conta fechada). */
    case FAILED = 'failed';

    /** Estorno cancelado antes de ser concluído. */
    case CANCELED = 'canceled';

    /**
     * Status que a lib não reconhece. O valor original fica em `Refund::$original` e no log
     * de aviso emitido na conversão.
     */
    case UNKNOWN = 'unknown';

    /**
     * Diz se o estorno terminou sem devolver o valor: `FAILED` ou `CANCELED`.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return match ($this) {
            self::FAILED, self::CANCELED => true,
            default => false,
        };
    }

    /**
     * Converte o valor de string no caso correspondente. Valor fora do enum devolve `UNKNOWN`
     * e registra um aviso no log com o valor original e o gateway.
     *
     * @param  string  $value
     * @param  string|null  $gateway
     * @return static
     */
    public static function fromValue(string $value, ?string $gateway = null): static
    {
        return self::tryFrom($value) ?? self::unknown($value, $gateway);
    }

    /**
     * Devolve `UNKNOWN` e registra um aviso no log com o valor original e o gateway.
     *
     * @param  string  $value
     * @param  string|null  $gateway
     * @return static
     */
    public static function unknown(string $value, ?string $gateway = null): static
    {
        LogHelper::warning(
            "Status de estorno desconhecido [{$value}] no gateway [" . ($gateway ?? 'desconhecido') . '], lido como unknown',
            ['status' => $value, 'gateway' => $gateway]
        );

        return self::UNKNOWN;
    }
}
