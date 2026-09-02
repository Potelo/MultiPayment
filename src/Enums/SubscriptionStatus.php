<?php

namespace Potelo\MultiPayment\Enums;

use Potelo\MultiPayment\Helpers\LogHelper;
use Potelo\MultiPayment\Contracts\AcceptsUnknownValue;

/**
 * Status genérico da assinatura. Cada driver traduz o estado do gateway para um destes casos;
 * o valor específico do gateway fica em `Subscription::$original`.
 */
enum SubscriptionStatus: string implements AcceptsUnknownValue
{
    /** Criada e ainda sem cobrança confirmada; a primeira fatura aguarda pagamento. */
    case PENDING = 'pending';

    /** Em período de teste, sem cobrança até o fim do trial. */
    case TRIALING = 'trialing';

    /** Em dia: a cobrança corrente foi paga e a próxima está agendada. */
    case ACTIVE = 'active';

    /** Cobrança vencida sem pagamento; o gateway ou a aplicação ainda tenta receber. */
    case PAST_DUE = 'past_due';

    /** Cobrança pausada pelo gateway, sem encerrar a assinatura. */
    case PAUSED = 'paused';

    /** Cobrança interrompida pela aplicação; volta com `resume()`. */
    case SUSPENDED = 'suspended';

    /** Encerrada pela aplicação ou pelo gateway; não gera mais cobrança. */
    case CANCELED = 'canceled';

    /** Encerrada porque o ciclo terminou sem renovação ou sem pagamento. */
    case EXPIRED = 'expired';

    /**
     * Status que a lib não reconhece. O valor original fica em `Subscription::$original` e no
     * log de aviso emitido na conversão.
     */
    case UNKNOWN = 'unknown';

    /**
     * Diz se a assinatura dá direito ao serviço neste momento: `TRIALING` e `ACTIVE`.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return match ($this) {
            self::TRIALING, self::ACTIVE => true,
            default => false,
        };
    }

    /**
     * Diz se a assinatura ainda pode voltar a `ACTIVE` sem ser recriada: `PENDING`,
     * `PAST_DUE`, `PAUSED` e `SUSPENDED`.
     *
     * @return bool
     */
    public function isRecoverable(): bool
    {
        return match ($this) {
            self::PENDING, self::PAST_DUE, self::PAUSED, self::SUSPENDED => true,
            default => false,
        };
    }

    /**
     * Diz se a assinatura terminou: `CANCELED` e `EXPIRED`.
     *
     * @return bool
     */
    public function isEnded(): bool
    {
        return match ($this) {
            self::CANCELED, self::EXPIRED => true,
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
            "Status de assinatura desconhecido [{$value}] no gateway [" . ($gateway ?? 'desconhecido') . '], lido como unknown',
            ['status' => $value, 'gateway' => $gateway]
        );

        return self::UNKNOWN;
    }
}
