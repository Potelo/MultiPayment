<?php

namespace Potelo\MultiPayment\Exceptions;

/**
 * A chave de idempotência já foi usada com outro payload, ou a requisição original com a
 * mesma chave ainda está em andamento (409 na Iugu, `idempotency_error` na Stripe). Não
 * repita com a mesma chave e outro conteúdo; consulte o resultado da primeira requisição ou
 * use uma chave nova.
 */
class IdempotencyConflictException extends GatewayException
{
}
