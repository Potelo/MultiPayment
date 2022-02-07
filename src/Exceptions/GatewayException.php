<?php

namespace Potelo\MultiPayment\Exceptions;

use Throwable;
use Potelo\MultiPayment\Contracts\Gateway;

class GatewayException extends \Exception
{
    /** @var array */
    private array $errors;

    /**
     * GatewayException constructor.
     *
     * @param  string  $message
     * @param  int  $code
     * @param  Throwable|null  $previous
     * @param  array  $errors
     */
    public function __construct(string $message = "", int $code = 0, Throwable $previous = null, array $errors = [])
    {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }

    /**
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

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