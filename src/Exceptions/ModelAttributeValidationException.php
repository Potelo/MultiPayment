<?php

namespace Potelo\MultiPayment\Exceptions;

class ModelAttributeValidationException extends \Exception
{
    public static function required($model, $attribute): ModelAttributeValidationException
    {
        return new static("The `{$attribute}` attribute is required for the `{$model}` model.");
    }

    public static function invalid($model, $attribute, $message): ModelAttributeValidationException
    {
        return new static("The `{$attribute}` attribute is invalid for the `{$model}` model. {$message}");
    }
}