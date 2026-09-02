<?php

namespace Potelo\MultiPayment\Exceptions;

class MultiPaymentException extends \Exception
{
    /**
     * Status HTTP da resposta do gateway que originou a exceção. Nulo quando não houve
     * resposta HTTP: falha de rede, validação local, chave de API não configurada.
     *
     * @var int|null
     */
    public ?int $httpStatus = null;

    /**
     * Cria a exceção anexando a original do SDK (quando houver) e o status HTTP da resposta.
     *
     * @param  string  $message
     * @param  \Throwable|null  $previous
     * @param  int|null  $httpStatus
     */
    public function __construct(string $message = '', ?\Throwable $previous = null, ?int $httpStatus = null)
    {
        $this->httpStatus = $httpStatus;

        parent::__construct($message, 0, $previous);
    }
}
