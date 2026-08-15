<?php

namespace Potelo\MultiPayment\Exceptions;

class ChargingException extends MultiPaymentException
{
    /**
     * @var mixed $chargeResponse The charge response from the gateway
     */
    public $chargeResponse;

    /**
     * Razão normalizada da falha de cobrança, independente de gateway (ex.: `card_declined`,
     * `brand_not_supported`, `authentication_required`), para a aplicação decidir
     * programaticamente um fallback de gateway. Nula quando o gateway não a preenche.
     *
     * @var string|null
     */
    public ?string $reason = null;
}
