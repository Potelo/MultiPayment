<?php

namespace Potelo\MultiPayment\Exceptions;

use Potelo\MultiPayment\Contracts\Gateway;

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
        parent::__construct($message);
        $this->errors = $errors;
    }

    /**
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * The gateway does not implement the interface.
     *
     * @param $gateway
     *
     * @return GatewayException
     */
    public static function invalidInterface($gateway): GatewayException
    {
        return new static("Gateway [" . get_class($gateway) . "] must implement " . Gateway::class . " interface");
    }

    /**
     * Specified gateway is not present in the list of gateways
     *
     * @param  string  $gateway
     *
     * @return GatewayException
     */
    public static function notConfigured(string $gateway): GatewayException
    {
        return new static("Gateway [{$gateway}] not found in configuration file.");
    }

    /**
     * Gateway not found exception.
     *
     * @param  string  $gateway
     *
     * @return GatewayException
     */
    public static function notFound(string $gateway): GatewayException
    {
        return new static("Gateway class [{$gateway}] not found.");
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
