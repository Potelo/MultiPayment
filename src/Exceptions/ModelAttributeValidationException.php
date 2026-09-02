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

    /**
     * Chave desconhecida em `fill()`: o model não tem a propriedade correspondente. A mensagem
     * lista as chaves aceitas.
     *
     * @param  string  $model
     * @param  string  $key  chave recebida, como veio no array
     * @param  string[]  $accepted  chaves aceitas, em `snake_case`
     * @return ModelAttributeValidationException
     */
    public static function unknownAttribute(string $model, string $key, array $accepted): ModelAttributeValidationException
    {
        return new static(
            "The `{$key}` key is unknown for the `{$model}` model. Accepted keys: " . implode(', ', $accepted) . '.'
        );
    }
}
