<?php

namespace Potelo\MultiPayment\Exceptions;

class ChargingException extends MultiPaymentException
{
    /**
     * @var mixed $chargeResponse The charge response from the gateway
     */
    public $chargeResponse;
}
