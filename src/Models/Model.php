<?php

namespace Potelo\MultiPayment\Models;

abstract class Model
{

    public function create(array $data)
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
