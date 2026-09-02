<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Iugu_APIRequest;

/**
 * Devolve uma resposta por chamada, na ordem, e guarda todas as chamadas feitas. Uma entrada
 * `\Throwable` na fila é lançada em vez de devolvida, para simular o SDK sinalizando 404 ou 5xx.
 */
class QueuedIuguApiRequest extends Iugu_APIRequest
{
    public array $calls = [];

    /**
     * @param  array<int, object|array|\Throwable>  $responses
     */
    public function __construct(private array $responses)
    {
    }

    public function request($method, $url, $data = [])
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'data' => $data];

        if (empty($this->responses)) {
            throw new \RuntimeException("Sem resposta enfileirada para {$method} {$url}");
        }

        $response = array_shift($this->responses);
        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }
}
