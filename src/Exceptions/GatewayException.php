<?php

namespace Potelo\MultiPayment\Exceptions;

class GatewayException extends MultiPaymentException
{
    /** @var mixed */
    private $errors;

    /**
     * GatewayException constructor.
     *
     * @param  string  $message
     * @param $errors
     */
    public function __construct(string $message = "", $errors = null)
    {
        $this->errors = $errors;
        parent::__construct($message . ' - ' . $this->getFirstError());
    }

    /**
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * returns the first error message.
     *
     * @return string
     */
    public function getFirstError(): string
    {
        if (is_string($this->errors)) {
            return $this->errors;
        }
        if (is_array($this->errors)) {
            return $this->errors[array_key_first($this->errors)];
        }
        return '';
    }

    /**
     * Method not found in gateway.
     *
     * @param  string  $gatewayClass
     * @param  string  $method
     *
     * @return GatewayException
     */
    public static function methodNotFound(string $gatewayClass, string $method): GatewayException
    {
        return new static("Gateway [{$gatewayClass}] does not have method [$method]");
    }
}
