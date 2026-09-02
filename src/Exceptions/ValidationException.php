<?php

namespace Potelo\MultiPayment\Exceptions;

/**
 * O gateway recusou o payload da requisição (400 ou 422 na Iugu, `invalid_request_error` na
 * Stripe): parâmetro ausente, inválido ou incompatível com o estado do recurso. Repetir a
 * mesma chamada falha de novo; corrija a chamada. Os erros por campo estão em
 * `$fieldErrors`; o corpo original continua em `getErrors()`.
 */
class ValidationException extends GatewayException
{
    /**
     * Mensagens de erro agrupadas por campo, no formato `campo => [mensagens]`. Erro que o
     * gateway não atribui a um campo fica na chave `base`.
     *
     * @var array<string, array<int, string>>
     */
    public array $fieldErrors = [];

    /**
     * Cria a exceção com os erros por campo já normalizados.
     *
     * @param  string  $message
     * @param  array<string, array<int, string>>  $fieldErrors
     * @param  mixed  $errors  corpo de erro do gateway, como em `GatewayException`
     * @param  \Throwable|null  $previous
     * @param  int|null  $httpStatus
     * @return static
     */
    public static function withFieldErrors(
        string $message,
        array $fieldErrors,
        $errors = null,
        ?\Throwable $previous = null,
        ?int $httpStatus = null
    ): static {
        $exception = new static($message, $errors, $previous, $httpStatus);
        $exception->fieldErrors = $fieldErrors;

        return $exception;
    }

    /**
     * Converte o corpo de erro de um gateway para o formato `campo => [mensagens]`. String vira
     * `base`; lista sem chave nomeada vira `base`; valor que não é string vira o seu JSON.
     *
     * @param  mixed  $errors  string, objeto ou array com os erros do gateway
     * @return array<string, array<int, string>>
     */
    public static function normalizeFieldErrors($errors): array
    {
        if ($errors === null || $errors === '' || $errors === []) {
            return [];
        }

        if (is_string($errors)) {
            return ['base' => [$errors]];
        }

        if (is_object($errors)) {
            $errors = (array) $errors;
        }

        if (!is_array($errors)) {
            return ['base' => [json_encode($errors)]];
        }

        $fieldErrors = [];
        foreach ($errors as $field => $messages) {
            $key = is_int($field) ? 'base' : (string) $field;
            foreach (self::messagesOf($messages) as $message) {
                $fieldErrors[$key][] = $message;
            }
        }

        return $fieldErrors;
    }

    /**
     * Achata o valor de um campo numa lista de strings.
     *
     * @param  mixed  $messages
     * @return array<int, string>
     */
    private static function messagesOf($messages): array
    {
        if (is_string($messages)) {
            return [$messages];
        }

        if (is_object($messages)) {
            $messages = (array) $messages;
        }

        if (!is_array($messages)) {
            return [json_encode($messages)];
        }

        $list = [];
        foreach ($messages as $message) {
            if (is_string($message)) {
                $list[] = $message;
            } elseif (is_object($message) && isset($message->message) && is_string($message->message)) {
                $list[] = $message->message;
            } elseif (is_array($message) && isset($message['message']) && is_string($message['message'])) {
                $list[] = $message['message'];
            } else {
                $list[] = json_encode($message);
            }
        }

        return $list;
    }
}
