<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Iugu_APIRequest;

/**
 * Devolve uma resposta por chamada, na ordem, e guarda todas as chamadas feitas (método, url,
 * dados e cabeçalhos extras). Uma entrada `\Throwable` na fila é lançada em vez de devolvida,
 * para simular o SDK sinalizando 404 ou 5xx; uma entrada `QueuedIuguResponse` devolve o corpo
 * com o status HTTP e os cabeçalhos informados, para simular erro com corpo JSON (401, 422, 429),
 * que o SDK devolve sem lançar.
 *
 * Como o SDK, grava o status e os cabeçalhos de cada resposta em `lastResponseCode` e
 * `lastResponseHeaders` da instância.
 */
class QueuedIuguApiRequest extends Iugu_APIRequest
{
    /** @var array<int, array{method: string, url: string, data: array, headers: array}> */
    public array $calls = [];

    /**
     * @param  array<int, object|array|\Throwable|QueuedIuguResponse>  $responses
     */
    public function __construct(private array $responses)
    {
        parent::__construct();
    }

    public function request($method, $url, $data = [], $headers = [])
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'data' => $data, 'headers' => $headers];
        $this->lastResponseCode = null;
        $this->lastResponseHeaders = [];

        if (empty($this->responses)) {
            throw new \RuntimeException("Sem resposta enfileirada para {$method} {$url}");
        }

        $response = array_shift($this->responses);
        if ($response instanceof \Throwable) {
            throw $response;
        }

        if ($response instanceof QueuedIuguResponse) {
            $this->lastResponseCode = $response->status > 0 ? $response->status : null;
            $this->lastResponseHeaders = $response->headers;

            return $response->body;
        }

        $this->lastResponseCode = 200;

        return $response;
    }

    /**
     * Instala este fake como requester compartilhado do SDK (`APIResource::API()`), que é o
     * que `IuguGateway` usa quando é construído sem requester (por exemplo, via
     * `ConfigurationHelper::resolveGateway()` e `new MultiPayment('iugu')`). Chame
     * `restoreSdkRequester()` no tearDown.
     *
     * @return $this
     */
    public function installAsSdkRequester(): static
    {
        self::sdkRequesterProperty()->setValue(null, $this);

        return $this;
    }

    /**
     * Devolve o SDK ao requester real, para o fake não vazar para outros testes.
     *
     * @return void
     */
    public static function restoreSdkRequester(): void
    {
        self::sdkRequesterProperty()->setValue(null, null);
    }

    private static function sdkRequesterProperty(): \ReflectionProperty
    {
        return new \ReflectionProperty(\APIResource::class, '_apiRequester');
    }
}
