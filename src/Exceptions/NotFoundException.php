<?php

namespace Potelo\MultiPayment\Exceptions;

/**
 * O recurso pedido não existe no gateway (404 na Iugu, `resource_missing` na Stripe): id
 * errado, de outra conta ou já removido. `httpStatus` é 404.
 */
class NotFoundException extends GatewayException
{
}
