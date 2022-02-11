<?php

namespace Potelo\MultiPayment\Exceptions;

class ModelAttributeValidationException extends MultiPaymentException
{
    /**
     * The attribute is required
     *
     * @param string $model
     * @param string $attribute
     *
     * @return ModelAttributeValidationException
     */
    public static function required(string $model, string $attribute): ModelAttributeValidationException
    {
        return new static("The `{$attribute}` attribute is required for the `{$model}` model.");
    }

    /**
     * The attribute is not valid
     *
     * @param  string  $model
     * @param  string  $attribute
     * @param  string  $message
     *
     * @return ModelAttributeValidationException
     */
    public static function invalid(string $model, string $attribute, string $message = ''): ModelAttributeValidationException
    {
        return new static("The `{$attribute}` attribute is invalid for the `{$model}` model. {$message}");
    }
}