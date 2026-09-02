<?php

namespace Potelo\MultiPayment\Exceptions;

/**
 * Gateway fora do ar no momento da chamada: erro 5xx, falha de conexão ou timeout de rede.
 *
 * A aplicação pode repetir a operação mais tarde ou tentar outro gateway. Credencial recusada
 * chega como `AuthenticationException`.
 */
class GatewayNotAvailableException extends MultiPaymentException
{

}
