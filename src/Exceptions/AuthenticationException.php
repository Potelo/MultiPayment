<?php

namespace Potelo\MultiPayment\Exceptions;

/**
 * Credencial do gateway recusada: chave de API inválida, revogada, sem permissão para a operação
 * ou não configurada.
 *
 * Repetir a chamada com a mesma credencial falha de novo, então esta exceção nunca deve entrar
 * em fila de retry nem em fallback de gateway. O tratamento adequado é registrar e alertar.
 */
class AuthenticationException extends MultiPaymentException
{
    /**
     * Credencial recusada pelo gateway (401 ou 403) ou ausente na configuração.
     *
     * @param  string  $gateway
     * @param  string  $detail  mensagem do gateway ou do SDK
     * @param  \Throwable|null  $previous
     * @param  int|null  $httpStatus
     * @return static
     */
    public static function invalidCredentials(
        string $gateway,
        string $detail,
        ?\Throwable $previous = null,
        ?int $httpStatus = null
    ): static {
        $message = "Credencial do gateway {$gateway} recusada; verifique a chave de API configurada.";
        if ($detail !== '') {
            $message .= " Resposta do gateway: {$detail}";
        }

        return new static($message, $previous, $httpStatus);
    }
}
