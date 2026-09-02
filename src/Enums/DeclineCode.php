<?php

namespace Potelo\MultiPayment\Enums;

/**
 * Motivo normalizado de uma recusa de cartão, no vocabulário do pacote. Cada driver traduz o
 * código do gateway (`decline_code` da Stripe, LR da Iugu) para um destes casos; o código
 * original fica em `CardDeclinedException::$gatewayCode`.
 */
enum DeclineCode: string
{
    /** Saldo ou limite do cartão insuficiente para o valor cobrado. */
    case INSUFFICIENT_FUNDS = 'insufficient_funds';

    /** Cartão vencido. */
    case EXPIRED_CARD = 'expired_card';

    /** Código de segurança (CVC) incorreto. */
    case INCORRECT_CVC = 'incorrect_cvc';

    /** Número do cartão incorreto ou ausente. */
    case INCORRECT_NUMBER = 'incorrect_number';

    /** Outros dados do cartão ou do pagador inválidos: validade, conta inexistente, cartão não desbloqueado. */
    case INVALID_CARD = 'invalid_card';

    /** Cartão perdido, roubado ou bloqueado pelo emissor; o motivo exato não deve ser exibido ao pagador. */
    case LOST_OR_STOLEN = 'lost_or_stolen';

    /** Recusa por suspeita de fraude (emissor, adquirente ou antifraude do gateway); tratar como recusa genérica diante do pagador. */
    case FRAUD_SUSPECTED = 'fraud_suspected';

    /** O emissor exige autenticação do pagador (3DS) antes de aprovar; a cobrança fora de sessão não consegue atender. */
    case AUTHENTICATION_REQUIRED = 'authentication_required';

    /** Bandeira, função (crédito ou débito) ou moeda do cartão não aceita nesta cobrança; candidato a outro gateway. */
    case BRAND_NOT_SUPPORTED = 'brand_not_supported';

    /** O emissor recusou sem detalhar e orienta o pagador a procurá-lo; nova tentativa igual tende a ser recusada de novo. */
    case DO_NOT_HONOR = 'do_not_honor';

    /** Falha temporária no emissor, no adquirente ou na comunicação; uma nova tentativa pode ser aprovada. */
    case TRY_AGAIN = 'try_again';

    /** Recusa sem motivo específico informado pelo gateway. */
    case GENERIC = 'generic';

    /** Código do gateway que o pacote ainda não mapeia; o original está em `gatewayCode`. */
    case UNKNOWN = 'unknown';

    /**
     * Diz se uma nova tentativa com o mesmo cartão, sem alterar dado nenhum, tem chance de ser
     * aprovada. Verdadeiro para falha temporária e para saldo insuficiente (o saldo muda com o
     * tempo); falso para os demais, inclusive `UNKNOWN`.
     *
     * @return bool
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::TRY_AGAIN, self::INSUFFICIENT_FUNDS => true,
            default => false,
        };
    }

    /**
     * Diz se a recusa pede ação do pagador antes de qualquer nova tentativa: corrigir dados,
     * autenticar o cartão ou informar outro.
     *
     * @return bool
     */
    public function requiresPayerAction(): bool
    {
        return match ($this) {
            self::EXPIRED_CARD,
            self::INCORRECT_CVC,
            self::INCORRECT_NUMBER,
            self::INVALID_CARD,
            self::AUTHENTICATION_REQUIRED,
            self::BRAND_NOT_SUPPORTED => true,
            default => false,
        };
    }
}
