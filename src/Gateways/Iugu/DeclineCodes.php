<?php

namespace Potelo\MultiPayment\Gateways\Iugu;

use Potelo\MultiPayment\Enums\DeclineCode;

/**
 * Tradução dos códigos LR (retorno da adquirente) que a Iugu devolve numa recusa de cartão
 * para `DeclineCode`.
 *
 * Fonte: "Tabela de LRs" em https://dev.iugu.com/docs/tabela-de-lrs. A resposta da cobrança
 * traz o código no campo `LR` e o repete na mensagem (`"..., LR: 51"`); `extractLr()` lê os
 * dois. Códigos de cartão presente (senha, chip, saque) e erros de credenciamento do lojista
 * ficam fora da tabela e chegam como `UNKNOWN`, com o LR preservado em `gatewayCode`.
 */
final class DeclineCodes
{
    /** @var array<string, DeclineCode> */
    private const MAP = [
        // saldo ou limite
        '51' => DeclineCode::INSUFFICIENT_FUNDS,
        '61' => DeclineCode::INSUFFICIENT_FUNDS,
        '65' => DeclineCode::INSUFFICIENT_FUNDS,
        '70' => DeclineCode::INSUFFICIENT_FUNDS,
        'BL' => DeclineCode::INSUFFICIENT_FUNDS,
        'DM' => DeclineCode::INSUFFICIENT_FUNDS,
        'N4' => DeclineCode::INSUFFICIENT_FUNDS,

        // cartão vencido
        '54' => DeclineCode::EXPIRED_CARD,

        // número do cartão
        '14' => DeclineCode::INCORRECT_NUMBER,
        '25' => DeclineCode::INCORRECT_NUMBER,

        // dados do cartão ou do pagador ("verifique os dados", "dados inválidos", conta inexistente,
        // cartão novo não desbloqueado, emissor não localizado pelo BIN)
        '1' => DeclineCode::INVALID_CARD,
        '12' => DeclineCode::INVALID_CARD,
        '15' => DeclineCode::INVALID_CARD,
        '30' => DeclineCode::INVALID_CARD,
        '46' => DeclineCode::INVALID_CARD,
        '56' => DeclineCode::INVALID_CARD,
        '78' => DeclineCode::INVALID_CARD,
        '101' => DeclineCode::INVALID_CARD,
        '111' => DeclineCode::INVALID_CARD,
        '115' => DeclineCode::INVALID_CARD,
        '122' => DeclineCode::INVALID_CARD,
        '6P' => DeclineCode::INVALID_CARD,
        'AV' => DeclineCode::INVALID_CARD,
        'BM' => DeclineCode::INVALID_CARD,
        'BP' => DeclineCode::INVALID_CARD,
        'BR' => DeclineCode::INVALID_CARD,
        'CF' => DeclineCode::INVALID_CARD,
        'CG' => DeclineCode::INVALID_CARD,
        'DF' => DeclineCode::INVALID_CARD,
        'DQ' => DeclineCode::INVALID_CARD,
        'G4' => DeclineCode::INVALID_CARD,
        'KA' => DeclineCode::INVALID_CARD,
        'KE' => DeclineCode::INVALID_CARD,
        'U3' => DeclineCode::INVALID_CARD,

        // cartão perdido, roubado, retido ou bloqueado pelo emissor
        '4' => DeclineCode::LOST_OR_STOLEN,
        '41' => DeclineCode::LOST_OR_STOLEN,
        '43' => DeclineCode::LOST_OR_STOLEN,
        '62' => DeclineCode::LOST_OR_STOLEN,
        '146' => DeclineCode::LOST_OR_STOLEN,
        'BN' => DeclineCode::LOST_OR_STOLEN,

        // fraude confirmada, suspeita ou antifraude
        '7' => DeclineCode::FRAUD_SUSPECTED,
        '59' => DeclineCode::FRAUD_SUSPECTED,
        'AF01' => DeclineCode::FRAUD_SUSPECTED,
        'AF02' => DeclineCode::FRAUD_SUSPECTED,
        'BP171' => DeclineCode::FRAUD_SUSPECTED,

        // autenticação do pagador não realizada
        'AI' => DeclineCode::AUTHENTICATION_REQUIRED,

        // transação não permitida para o cartão, função incorreta (crédito ou débito), produto não habilitado
        '39' => DeclineCode::BRAND_NOT_SUPPORTED,
        '52' => DeclineCode::BRAND_NOT_SUPPORTED,
        '53' => DeclineCode::BRAND_NOT_SUPPORTED,
        '57' => DeclineCode::BRAND_NOT_SUPPORTED,
        '79' => DeclineCode::BRAND_NOT_SUPPORTED,
        '5C' => DeclineCode::BRAND_NOT_SUPPORTED,
        'AB' => DeclineCode::BRAND_NOT_SUPPORTED,
        'AC' => DeclineCode::BRAND_NOT_SUPPORTED,
        'AH' => DeclineCode::BRAND_NOT_SUPPORTED,
        'C1' => DeclineCode::BRAND_NOT_SUPPORTED,
        'DS' => DeclineCode::BRAND_NOT_SUPPORTED,
        'EK' => DeclineCode::BRAND_NOT_SUPPORTED,
        'G5' => DeclineCode::BRAND_NOT_SUPPORTED,

        // "contate a central do seu cartão", "não tente novamente", transação negada pelo emissor,
        // suspensão de pagamento recorrente pelo emissor, violação de segurança
        '5' => DeclineCode::DO_NOT_HONOR,
        '6' => DeclineCode::DO_NOT_HONOR,
        '60' => DeclineCode::DO_NOT_HONOR,
        '63' => DeclineCode::DO_NOT_HONOR,
        '67' => DeclineCode::DO_NOT_HONOR,
        '93' => DeclineCode::DO_NOT_HONOR,
        '99' => DeclineCode::DO_NOT_HONOR,
        '100' => DeclineCode::DO_NOT_HONOR,
        '109' => DeclineCode::DO_NOT_HONOR,
        '110' => DeclineCode::DO_NOT_HONOR,
        '116' => DeclineCode::DO_NOT_HONOR,
        '121' => DeclineCode::DO_NOT_HONOR,
        '181' => DeclineCode::DO_NOT_HONOR,
        '200' => DeclineCode::DO_NOT_HONOR,
        'B1' => DeclineCode::DO_NOT_HONOR,
        'B2' => DeclineCode::DO_NOT_HONOR,
        'BP176' => DeclineCode::DO_NOT_HONOR,
        'C2' => DeclineCode::DO_NOT_HONOR,
        'C3' => DeclineCode::DO_NOT_HONOR,
        'FC' => DeclineCode::DO_NOT_HONOR,
        'FG' => DeclineCode::DO_NOT_HONOR,
        'GA' => DeclineCode::DO_NOT_HONOR,
        'GD' => DeclineCode::DO_NOT_HONOR,
        'GF' => DeclineCode::DO_NOT_HONOR,
        'GK' => DeclineCode::DO_NOT_HONOR,
        'GT' => DeclineCode::DO_NOT_HONOR,
        'N7' => DeclineCode::DO_NOT_HONOR,
        'NR' => DeclineCode::DO_NOT_HONOR,
        'R0' => DeclineCode::DO_NOT_HONOR,
        'R1' => DeclineCode::DO_NOT_HONOR,
        'R2' => DeclineCode::DO_NOT_HONOR,
        'R3' => DeclineCode::DO_NOT_HONOR,
        'RE' => DeclineCode::DO_NOT_HONOR,
        'RP' => DeclineCode::DO_NOT_HONOR,
        'SC' => DeclineCode::DO_NOT_HONOR,

        // emissor fora do ar, falha de sistema ou de comunicação, timeout, problema no adquirente
        '19' => DeclineCode::TRY_AGAIN,
        '28' => DeclineCode::TRY_AGAIN,
        '85' => DeclineCode::TRY_AGAIN,
        '89' => DeclineCode::TRY_AGAIN,
        '90' => DeclineCode::TRY_AGAIN,
        '91' => DeclineCode::TRY_AGAIN,
        '92' => DeclineCode::TRY_AGAIN,
        '96' => DeclineCode::TRY_AGAIN,
        '98' => DeclineCode::TRY_AGAIN,
        '911' => DeclineCode::TRY_AGAIN,
        '912' => DeclineCode::TRY_AGAIN,
        '999' => DeclineCode::TRY_AGAIN,
        '99A' => DeclineCode::TRY_AGAIN,
        '99B' => DeclineCode::TRY_AGAIN,
        '99C' => DeclineCode::TRY_AGAIN,
        '99TA' => DeclineCode::TRY_AGAIN,
        '99Z' => DeclineCode::TRY_AGAIN,
        'AA' => DeclineCode::TRY_AGAIN,
        'AF' => DeclineCode::TRY_AGAIN,
        'AG' => DeclineCode::TRY_AGAIN,
        'BD' => DeclineCode::TRY_AGAIN,
        'BO' => DeclineCode::TRY_AGAIN,
        'BP900' => DeclineCode::TRY_AGAIN,
        'BP901' => DeclineCode::TRY_AGAIN,
        'BP902' => DeclineCode::TRY_AGAIN,

        // valor ou data inválidos para a transação, transação duplicada
        '13' => DeclineCode::GENERIC,
        '64' => DeclineCode::GENERIC,
        '80' => DeclineCode::GENERIC,
        '94' => DeclineCode::GENERIC,
        '97' => DeclineCode::GENERIC,
        'FE' => DeclineCode::GENERIC,
    ];

    /**
     * Traduz o código LR para o vocabulário do pacote. A tabela oficial lista os códigos de um
     * dígito com e sem zero à esquerda (`5` e `05`), então código só de dígitos é comparado sem
     * os zeros iniciais. Nulo quando o código não está na tabela, para o driver preservar o
     * original e registrar no log.
     *
     * @param  string|null  $lr
     * @return DeclineCode|null
     */
    public static function toDeclineCode(?string $lr): ?DeclineCode
    {
        if ($lr === null || $lr === '') {
            return null;
        }

        $key = strtoupper($lr);
        if (ctype_digit($key)) {
            $key = ltrim($key, '0') ?: '0';
        }

        return self::MAP[$key] ?? null;
    }

    /**
     * Lê o código LR de uma resposta da Iugu: o campo `LR` quando existe (o formato da
     * resposta de `POST /v1/charge`), senão `lr` minúsculo (o formato do webhook
     * `invoice.payment_failed`), senão o trecho `LR: xx` de `info_message` ou `message`. Nulo
     * quando a resposta não traz código.
     *
     * @param  object  $charge  resposta de cobrança, fatura ou payload com o LR
     * @return string|null
     */
    public static function extractLr(object $charge): ?string
    {
        $lr = $charge->LR ?? $charge->lr ?? null;
        if (is_string($lr) && trim($lr) !== '') {
            return strtoupper(trim($lr));
        }
        if (is_int($lr)) {
            return (string) $lr;
        }

        foreach (['info_message', 'message'] as $field) {
            $text = $charge->{$field} ?? null;
            if (is_string($text) && preg_match('/\bLR:?\s*([A-Za-z0-9]{1,5})\b/', $text, $matches)) {
                return strtoupper($matches[1]);
            }
        }

        return null;
    }
}
