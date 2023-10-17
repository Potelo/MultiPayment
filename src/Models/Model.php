<?php

namespace Potelo\MultiPayment\Models;

use Potelo\MultiPayment\Contracts\Gateway;
use Potelo\MultiPayment\Helpers\ConfigurationHelper;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

abstract class Model
{

    /**
     * @var array $gatewayAdicionalOptions Gateway adicional options Can be used to send adicional options to the gateway and override the default options
     */
    public array $gatewayAdicionalOptions = [];

    /**
     * Create a new instance of the model with an array of attributes.
     *
     * @param  array  $data
     * @param  null  $gateway
     *
     * @return void
     * @throws GatewayException
     * @throws GatewayNotAvailableException
     * @throws ModelAttributeValidationException
     */
    public function create(array $data, $gateway = null): void
    {
        $this->fill($data);
        $this->save($gateway);
    }

    /**
     * If gateway is set, then we will use it to save the model
     *
     * @param  Gateway|string|null  $gateway
     * @param  bool  $validate
     *
     * @return void
     * @throws GatewayException|GatewayNotAvailableException|ModelAttributeValidationException
     */
    public function save($gateway = null, bool $validate = true): void
    {
        $class = $this->getClassName();
        if (property_exists($this, 'id') && !empty($this->id)) {
            $method = 'update';
            $validate = false;
        } else {
            $method = 'create';
        }
        $method = $method . $class;

        if ($validate) {
            $this->validate();
        }
        $gatewayClass = ConfigurationHelper::resolveGateway($gateway);
        if (!method_exists($gatewayClass, $method)) {
            throw GatewayException::methodNotFound(get_class($gatewayClass), $method);
        }
        $gatewayClass->$method($this);
    }

    /**
     * Validate the model.
     *
     * @param  array  $attributes
     * @param  array  $excludedAttributes
     *
     * @return void
     * @throws ModelAttributeValidationException
     */
    public function validate(array $attributes = [], array $excludedAttributes = []): void
    {
        if (empty($attributes)) {
            $attributes = array_keys(get_class_vars(get_class($this)));
        }
        $attributes = array_diff_key($attributes, array_flip($excludedAttributes));
        foreach ($attributes as $attribute) {
            $validateAttributeMethod = 'validate' . ucfirst($attribute). 'Attribute';
            if (property_exists($this, $attribute) && !empty($this->$attribute) && method_exists($this, $validateAttributeMethod)) {
                $this->$validateAttributeMethod();
            }
        }
        $this->attributesExtraValidation($attributes);
    }

    /**
     * Model attributes validation for specific cases if necessary.
     * This method is called after the validation of the model attributes.
     * Need to be implemented in the child class.
     *
     * @param  array  $attributes
     *
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function attributesExtraValidation(array $attributes): void
    {
        //
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
    public function toArray(): array
    {
        $array = [];
        $reflect = new \ReflectionClass($this);
        $props = $reflect->getProperties(\ReflectionProperty::IS_PUBLIC);
        foreach ($props as $prop) {
            if (!empty($this->{$prop->getName()})) {
                $key = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $prop->getName()));
                $array[$key] = $this->{$prop->getName()};
            }

        }
        return $array;
    }

    /**
     * Return the class name of the model without namespace.
     *
     * @return string
     */
    protected static function getClassName(): string
    {
        return substr(strrchr(get_called_class(), '\\'), 1);
    }

    /**
     * Get the model instance by id in the gateway.
     *
     * @param  string  $id
     * @param  Gateway|string|null  $gateway
     *
     * @return static
     * @throws GatewayException
     */
    public static function get(string $id, $gateway = null): Model
    {
        $method = 'get' . self::getClassName();
        $gateway = ConfigurationHelper::resolveGateway($gateway);
        if (!method_exists($gateway, $method)) {
            throw GatewayException::methodNotFound(get_class($gateway), $method);
        }
        return $gateway->$method($id);
    }
}
