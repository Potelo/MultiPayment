<?php

namespace Potelo\MultiPayment\Models;

use Potelo\MultiPayment\Contracts\Gateway;

abstract class Model
{

    protected ?Gateway $gatewayClass = null;

    public function __construct(?Gateway $gatewayClass = null)
    {
        $this->gatewayClass = $gatewayClass;
    }

    public function create(array $data)
    {
        $this->fill($data);
        return $this->save();
    }

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

    public function fill(array $data)
    {
        foreach ($data as $key => $value) {
            $key = lcfirst(str_replace('_', '', ucwords($key, '_')));
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }

    abstract public function toArray();

    /**
     * to array without null values.
     */
    public function toArrayWithoutEmpty(): array
    {
        $data = $this->toArray();
        foreach ($data as $key => $value) {
            if (is_null($value) || $value === '' || (is_array($value) && empty($value))) {
                unset($data[$key]);
            }
        }
        return $data;
    }
}
