<?php

namespace Potelo\MultiPayment\Exceptions;

use Potelo\MultiPayment\Enums\DeclineCode;

/**
 * Cobrança recusada pelo emissor, pelo adquirente ou pelo antifraude do gateway. O gateway
 * respondeu normalmente: a recusa é do pagamento, e o que decide o próximo passo é
 * `$declineCode` (motivo no vocabulário do pacote) e `$retryable`.
 */
class CardDeclinedException extends MultiPaymentException
{
    /**
     * Motivo da recusa normalizado. `DeclineCode::UNKNOWN` quando o código do gateway ainda não
     * está mapeado; o original fica em `$gatewayCode`.
     *
     * @var DeclineCode
     */
    public DeclineCode $declineCode = DeclineCode::UNKNOWN;

    /**
     * Código de recusa como o gateway o informou (`decline_code` ou `code` na Stripe, LR na
     * Iugu). Nulo quando o gateway não informou código.
     *
     * @var string|null
     */
    public ?string $gatewayCode = null;

    /**
     * Verdadeiro quando uma nova tentativa com o mesmo cartão, sem alterar nada, tem chance de
     * ser aprovada (falha temporária, saldo insuficiente). Falso pede outro cartão, ação do
     * pagador ou outro gateway.
     *
     * @var bool
     */
    public bool $retryable = false;

    /**
     * Resposta bruta do gateway à cobrança, para diagnóstico.
     *
     * @var mixed
     */
    public $chargeResponse;

    /**
     * Razão da recusa como string. Na Stripe é a normalização antiga (`card_declined`,
     * `brand_not_supported`, `authentication_required`, `expired_card`, `insufficient_funds`,
     * `incorrect_cvc`, ou o `code` original); na Iugu é o valor de `$declineCode`.
     *
     * @deprecated Use `$declineCode`, que tem o mesmo vocabulário nos dois gateways.
     * @var string|null
     */
    public ?string $reason = null;

    /**
     * Cria a exceção a partir do motivo normalizado e do código original do gateway.
     * `$retryable` segue `DeclineCode::isRetryable()` quando não informado; o driver informa
     * quando o gateway traz orientação própria (o `advice_code` da Stripe).
     *
     * @param  string  $gateway
     * @param  DeclineCode  $declineCode
     * @param  string|null  $gatewayCode  código original, ou nulo quando o gateway não informou
     * @param  string  $detail  mensagem do gateway
     * @param  \Throwable|null  $previous
     * @param  int|null  $httpStatus
     * @param  bool|null  $retryable
     * @return static
     */
    public static function declined(
        string $gateway,
        DeclineCode $declineCode,
        ?string $gatewayCode,
        string $detail,
        ?\Throwable $previous = null,
        ?int $httpStatus = null,
        ?bool $retryable = null
    ): static {
        $code = $gatewayCode === null || $gatewayCode === '' ? 'sem código' : "código {$gatewayCode}";
        $message = "Cartão recusado pelo gateway {$gateway} ({$declineCode->value}, {$code})";
        if ($detail !== '') {
            $message .= ": {$detail}";
        }

        $exception = new static($message, $previous, $httpStatus);
        $exception->declineCode = $declineCode;
        $exception->gatewayCode = $gatewayCode === '' ? null : $gatewayCode;
        $exception->retryable = $retryable ?? $declineCode->isRetryable();
        $exception->reason = $declineCode->value;

        return $exception;
    }
}
