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
        parent::__construct($message . ' - ' . $this->getFirstError($errors));
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
     * @param $errors
     * @return string
     */
    public function getFirstError($errors): string
    {
        if (is_string($errors)) {
            return $errors;
        }
        if  (is_object($errors)) {
            $errors = (array) $errors;
        }
        if (is_array($errors)) {
            return $this->getFirstError($errors[array_key_first($errors)]);
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
