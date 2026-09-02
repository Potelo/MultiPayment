<?php

namespace Potelo\MultiPayment\Enums;

use Potelo\MultiPayment\Helpers\LogHelper;
use Potelo\MultiPayment\Contracts\AcceptsUnknownValue;

/**
 * Status genérico da fatura. Cada driver traduz o status do gateway para um destes casos; o
 * valor específico do gateway fica em `Invoice::$original`.
 */
enum InvoiceStatus: string implements AcceptsUnknownValue
{
    /** Aguardando pagamento. */
    case PENDING = 'pending';

    /** Valor reservado no cartão, aguardando captura ou análise (cobrança em duas etapas). */
    case AUTHORIZED = 'authorized';

    /** Pagamento em processamento no gateway; a confirmação chega depois. */
    case PROCESSING = 'processing';

    /** Valor recebido. */
    case PAID = 'paid';

    /** Parte do valor foi recebida e o restante segue em aberto. */
    case PARTIALLY_PAID = 'partially_paid';

    /** Quitada fora do gateway, por baixa manual. */
    case EXTERNALLY_PAID = 'externally_paid';

    /** Estorno voluntário de parte do valor. */
    case PARTIALLY_REFUNDED = 'partially_refunded';

    /** Estorno voluntário do valor integral. Terminal. */
    case REFUNDED = 'refunded';

    /** Contestação aberta sobre uma fatura paga, com resolução pendente. */
    case DISPUTED = 'disputed';

    /** Contestação perdida: o gateway devolveu o valor ao cliente. Terminal. */
    case CHARGEBACK = 'chargeback';

    /** Cancelada antes do pagamento. Terminal. */
    case CANCELED = 'canceled';

    /** Venceu sem pagamento. Terminal. */
    case EXPIRED = 'expired';

    /**
     * Status que a lib não reconhece. O valor original fica em `Invoice::$original` e no log
     * de aviso emitido na conversão.
     */
    case UNKNOWN = 'unknown';

    /**
     * Diz se o dinheiro da fatura foi recebido, no todo ou em parte: `PAID`,
     * `PARTIALLY_PAID`, `EXTERNALLY_PAID` e `PARTIALLY_REFUNDED`. Fatura em contestação conta
     * como não recebida enquanto a disputa estiver aberta.
     *
     * @return bool
     */
    public function isSettled(): bool
    {
        return match ($this) {
            self::PAID, self::PARTIALLY_PAID, self::EXTERNALLY_PAID, self::PARTIALLY_REFUNDED => true,
            default => false,
        };
    }

    /**
     * Diz se existe contestação sobre a fatura: `DISPUTED` (aberta) ou `CHARGEBACK` (perdida).
     *
     * @return bool
     */
    public function isContested(): bool
    {
        return match ($this) {
            self::DISPUTED, self::CHARGEBACK => true,
            default => false,
        };
    }

    /**
     * Diz se a fatura chegou a um estado final, do qual o gateway não a tira: `REFUNDED`,
     * `CHARGEBACK`, `CANCELED` e `EXPIRED`.
     *
     * @return bool
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::REFUNDED, self::CHARGEBACK, self::CANCELED, self::EXPIRED => true,
            default => false,
        };
    }

    /**
     * Diz se a fatura ainda pode receber pagamento: `PENDING`, `AUTHORIZED`, `PROCESSING` e
     * `PARTIALLY_PAID`.
     *
     * @return bool
     */
    public function isOpen(): bool
    {
        return match ($this) {
            self::PENDING, self::AUTHORIZED, self::PROCESSING, self::PARTIALLY_PAID => true,
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
            "Status de fatura desconhecido [{$value}] no gateway [" . ($gateway ?? 'desconhecido') . '], lido como unknown',
            ['status' => $value, 'gateway' => $gateway]
        );

        return self::UNKNOWN;
    }
}
