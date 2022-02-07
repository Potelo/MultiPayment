<?php

namespace Potelo\MultiPayment\Exceptions;

use Potelo\MultiPayment\Contracts\Gateway;

class GatewayException extends \Exception
{
    public static function invalidInterface($gateway): GatewayException
    {
        return new static("Gateway [" . get_class($gateway) . "] must implement " . Gateway::class . " interface");
    }

    public static function notConfigured($gateway): GatewayException
    {
        return new static("Gateway [{$gateway}] not found in configuration file.");
    }

    public static function notFound($gateway): GatewayException
    {
        return new static("Gateway class [{$gateway}] not found.");
    }

    public static function methodNotFound($gatewayClass, $method): GatewayException
    {
        return new static("Gateway [{$gatewayClass}] does not have method [$method]");
    }
}