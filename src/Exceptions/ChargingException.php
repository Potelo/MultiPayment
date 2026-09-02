<?php

namespace Potelo\MultiPayment\Exceptions;

/**
 * Nome antigo de `CardDeclinedException`. Uma recusa de cartão é capturada tanto por
 * `catch (ChargingException $e)` quanto por `catch (CardDeclinedException $e)`, com
 * `declineCode`, `gatewayCode` e `retryable` preenchidos.
 *
 * @deprecated Capture `CardDeclinedException`. Este nome deixa de ser lançado numa versão maior futura.
 */
class ChargingException extends CardDeclinedException
{
}
