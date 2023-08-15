<?php

namespace Potelo\MultiPayment\Exceptions;

class ChargingException extends MultiPaymentException
{
    /**
     * @var int|string
     */
    protected $code;

    public function __construct($message = "", $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->code = $code;
    }
}
