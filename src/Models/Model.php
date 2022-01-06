<?php

namespace Potelo\MultiPayment\Models;

use Potelo\MultiPayment\Contracts\Gateway;

abstract class Model
{

    protected ?Gateway $gatewayClass = null;

    /**
     * Create a new instance of the model.
     *
     * @param  Gateway|null  $gatewayClass
     */
    public function __construct(?Gateway $gatewayClass = null)
    {
        $this->gatewayClass = $gatewayClass;
    }

    /**
     * Create a new instance of the model with an array of attributes.
     *
     * @param  array  $data
     *
     * @return mixed
     */
    public function create(array $data)
    {
        $this->fill($data);
        return $this->save();
    }

    /**
     * If gateway is set, then we will use it to save the model
     *
     * @return mixed
     */
    public function save()
    {
        $class = substr(strrchr(get_class($this), '\\'), 1);
        $method = 'create' . $class;
        $contracts = class_implements($this->gatewayClass);
        if (in_array(Gateway::class, $contracts) && method_exists($this->gatewayClass, $method)) {
            return $this->gatewayClass->$method($this);
        }
        return null;
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
}
