<?php

namespace Potelo\MultiPayment\Builders;

use Potelo\MultiPayment\Models\Model;

abstract class Builder
{
    protected Model $model;
    protected array $validationAttributes = [];

    /**
     * @param  string  $validationAttribute
     *
     * @return void
     */
    public function addValidationAttribute(string $validationAttribute): void
    {
        $this->validationAttributes[] = $validationAttribute;
    }

}