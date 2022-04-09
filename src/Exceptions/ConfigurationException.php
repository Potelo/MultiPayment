<?php

namespace Potelo\MultiPayment\Exceptions;

use Potelo\MultiPayment\Contracts\Gateway;

class ConfigurationException extends MultiPaymentException
{
    /**
     * The gateway does not implement the interface.
     *
     * @param $gateway
     *
     * @return self
     */
    public static function GatewayInvalidInterface($gateway): self
    {
        return new static("Gateway [" . get_class($gateway) . "] must implement " . Gateway::class . " interface");
    }

    /**
     * Specified gateway is not present in the list of gateways
     *
     * @param  string  $gateway
     *
     * @return self
     */
    public static function GatewayNotConfigured(string $gateway): self
    {
        return new static("Gateway [{$gateway}] not found in configuration file.");
    }

    /**
     * Gateway not found exception.
     *
     * @param  string  $gateway
     *
     * @return self
     */
    public static function GatewayNotFound(string $gateway): self
    {
        return new static("Gateway class [{$gateway}] not found.");
    }

    public static function cannotBatchedCreate(string $model): self
    {
        return new static("Model [{$model}] does not support batched create.");
    }
}