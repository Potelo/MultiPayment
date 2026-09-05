<?php

namespace Potelo\MultiPayment\Enums;

use Potelo\MultiPayment\Helpers\LogHelper;
use Potelo\MultiPayment\Contracts\AcceptsUnknownValue;

/**
 * Status genérico de uma contestação. Cada driver traduz o status do gateway para um destes
 * casos; o valor específico do gateway fica em `Dispute::$original`.
 */
enum DisputeStatus: string implements AcceptsUnknownValue
{
    /** Contestação aguardando resposta: contestar (com o prazo em `Dispute::$dueBy`) ou acatar. */
    case OPEN = 'open';

    /** Contestação respondida, em análise pelo emissor ou pelo adquirente. */
    case UNDER_REVIEW = 'under_review';

    /** Contestação encerrada sem devolução: o valor fica com o recebedor. */
    case WON = 'won';

    /** Contestação encerrada com a disputa perdida: o valor foi devolvido ao pagador. */
    case LOST = 'lost';

    /** Contestação acatada, pelo recebedor ou por prazo vencido: o valor volta ao pagador sem disputa. */
    case ACCEPTED = 'accepted';

    /**
     * Status que a lib não reconhece. O valor original fica em `Dispute::$original` e no log
     * de aviso emitido na conversão.
     */
    case UNKNOWN = 'unknown';

    /**
     * Diz se a contestação ainda está em curso: `OPEN` ou `UNDER_REVIEW`.
     *
     * @return bool
     */
    public function isOpen(): bool
    {
        return match ($this) {
            self::OPEN, self::UNDER_REVIEW => true,
            default => false,
        };
    }

    /**
     * Diz se a contestação terminou com o valor devolvido ao pagador: `LOST` ou `ACCEPTED`.
     *
     * @return bool
     */
    public function isLost(): bool
    {
        return match ($this) {
            self::LOST, self::ACCEPTED => true,
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
            "Status de contestação desconhecido [{$value}] no gateway [" . ($gateway ?? 'desconhecido') . '], lido como unknown',
            ['status' => $value, 'gateway' => $gateway]
        );

        return self::UNKNOWN;
    }
}
