<?php

namespace Potelo\MultiPayment\Models;

use Exception;
use Potelo\MultiPayment\Contracts\Gateway;
use Potelo\MultiPayment\Exceptions\GatewayException;

abstract class Model
{

    /**
     * The gateway instance.
     * @var Gateway|null
     */
    protected ?Gateway $gatewayClass = null;

    /**
     * The error encountered during saving.
     * @var Exception
     */
    protected Exception $errors;

    /**
     * Create a new instance of the model.
     *
     * @param  Gateway|string|null  $gateway
     *
     * @throws GatewayException
     */
    public function __construct($gateway = null)
    {
        if (!is_null($gateway)) {
            $this->setGatewayClass($gateway);
        }
    }

    /**
     * Set the gateway class.
     *
     * @param  Gateway|string  $gatewayClass
     *
     * @return void
     * @throws GatewayException
     */
    private function setGatewayClass($gatewayClass): void
    {
        if (is_string($gatewayClass)) {
            if (is_null(config('multi-payment.gateways.'.$gatewayClass))) {
                throw GatewayException::notConfigured($gatewayClass);
            }
            $className = config("multi-payment.gateways.$gatewayClass.class");
            if (!class_exists($className)) {
                throw GatewayException::notFound($className);
            }
            $gatewayClass = new $className;
        }
        if (!$gatewayClass instanceof Gateway) {
            throw GatewayException::invalidInterface(get_class($gatewayClass));
        }
        $this->gatewayClass = $gatewayClass;
    }

    /**
     * Create a new instance of the model with an array of attributes.
     *
     * @param  array  $data
     *
     * @return bool
     * @throws GatewayException
     */
    public function create(array $data): bool
    {
        $this->fill($data);
        return $this->save();
    }

    /**
     * If gateway is set, then we will use it to save the model
     *
     * @return bool
     * @throws GatewayException
     */
    public function save(): bool
    {
        if (is_null($this->gatewayClass)) {
            throw new GatewayException("Gateway not set");
        }
        $class = substr(strrchr(get_class($this), '\\'), 1);
        if (property_exists($this, 'id') && !empty($this->id)) {
            $method = 'update';
        } else {
            $method = 'create';
        }
        $method = $method . $class;
        if (!method_exists($this->gatewayClass, $method)) {
            throw GatewayException::methodNotFound(get_class($this->gatewayClass), $method);
        }
        try {
            $this->gatewayClass->$method($this);
            return true;
        }
        catch (GatewayException $e) {
            $this->errors = $e;
            return false;
        }
    }

    /**
     * Fill the model with an array of attributes.
     *
     * @param  array  $data
     *
     * @return void
     */
    public function fill(array $data): void
    {
        foreach ($data as $key => $value) {
            $key = lcfirst(str_replace('_', '', ucwords($key, '_')));
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }

    /**
     * Convert the model instance to an array.
     *
     * @return array
     */
    abstract public function toArray(): array;

    /**
     * toArray without null values and empty values.
     *
     * @return array
     */
    public function toArrayWithoutEmpty(): array
    {
        $data = $this->toArray();
        foreach ($data as $key => $value) {
            if (empty($value)) {
                unset($data[$key]);
            }
        }
        return $data;
    }

    /**
     * Get the error encountered during saving.
     *
     * @return Exception
     */
    public function getErrors(): Exception
    {
        return $this->errors;
    }
}
