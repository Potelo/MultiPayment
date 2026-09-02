<?php

namespace Potelo\MultiPayment\Exceptions;

/**
 * O gateway limitou a taxa de requisições (429 nos dois gateways, `rate_limit_error` na
 * Stripe). A operação não foi executada; repita depois de esperar `$retryAfter` segundos
 * quando o gateway informa o prazo.
 */
class RateLimitException extends GatewayException
{
    /**
     * Segundos a esperar antes de repetir, lidos do cabeçalho `Retry-After` quando o gateway o
     * envia. Nulo quando o gateway não informa.
     *
     * @var int|null
     */
    public ?int $retryAfter = null;

    /**
     * Cria a exceção com o prazo informado pelo gateway.
     *
     * @param  string  $message
     * @param  mixed  $errors  corpo de erro do gateway, como em `GatewayException`
     * @param  \Throwable|null  $previous
     * @param  int|null  $httpStatus
     * @param  int|null  $retryAfter  segundos a esperar, ou nulo quando o gateway não informa
     * @return static
     */
    public static function withRetryAfter(
        string $message,
        $errors = null,
        ?\Throwable $previous = null,
        ?int $httpStatus = null,
        ?int $retryAfter = null
    ): static {
        $exception = new static($message, $errors, $previous, $httpStatus);
        $exception->retryAfter = $retryAfter;

        return $exception;
    }
}
