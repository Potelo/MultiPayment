<?php

namespace Potelo\MultiPayment\Exceptions;

/**
 * A chave de idempotência já foi usada, ou a requisição original com a mesma chave ainda está
 * em andamento (409 na Iugu, `idempotency_error` na Stripe, lock da `IdempotencyStore`
 * ocupado). Não repita com a mesma chave e outro conteúdo; consulte o resultado da primeira
 * requisição (`resourceId`, quando o gateway o informa) ou use uma chave nova.
 */
class IdempotencyConflictException extends GatewayException
{
    /**
     * Id do recurso criado pela primeira requisição com a chave, quando o gateway o informa na
     * resposta de conflito (a Iugu o devolve em `resource_id` para fatura e cobrança; para
     * cliente e assinatura responde `processing`, que fica nulo aqui).
     *
     * @var string|null
     */
    public ?string $resourceId = null;

    /**
     * Cria a exceção com o id do recurso original informado pelo gateway.
     *
     * @param  string  $message
     * @param  mixed  $errors  corpo de erro do gateway, como em `GatewayException`
     * @param  \Throwable|null  $previous
     * @param  int|null  $httpStatus
     * @param  string|null  $resourceId
     * @return static
     */
    public static function withResourceId(
        string $message,
        $errors = null,
        ?\Throwable $previous = null,
        ?int $httpStatus = null,
        ?string $resourceId = null
    ): static {
        $exception = new static($message, $errors, $previous, $httpStatus);
        $exception->resourceId = $resourceId;

        return $exception;
    }

    /**
     * A chave já foi usada, na `IdempotencyStore` da lib, numa operação diferente desta.
     *
     * @param  string  $key
     * @return static
     */
    public static function reusedOnAnotherOperation(string $key): static
    {
        return new static(
            "A chave de idempotência [{$key}] já foi usada em outra operação; use uma chave por operação."
        );
    }

    /**
     * Outra execução com a mesma chave está em andamento na `IdempotencyStore` da lib.
     *
     * @param  string  $key
     * @return static
     */
    public static function concurrent(string $key): static
    {
        return new static(
            "Outra requisição com a chave de idempotência [{$key}] está em andamento; "
            . 'aguarde o resultado dela em vez de repetir.'
        );
    }
}
