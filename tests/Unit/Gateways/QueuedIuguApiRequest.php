<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Iugu_APIRequest;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Gateways\IuguGateway;

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
     * Instala este fake como o requester do driver da Iugu construído pela config: registra no
     * container da Facade um bind de `IuguGateway` que constrói o driver com este requester e a
     * config que `ConfigurationHelper::resolveGateway()` entregar (por exemplo, via
     * `new MultiPayment('iugu')`). Chame `restoreSdkRequester()` no tearDown.
     *
     * @return $this
     */
    public function installAsSdkRequester(): static
    {
        $app = Facade::getFacadeApplication();
        if (!$app instanceof Container) {
            throw new \RuntimeException('Defina o container da Facade antes de instalar o fake');
        }

        $app->bind(
            IuguGateway::class,
            fn ($app, array $parameters = []) => new IuguGateway($this, null, $parameters['config'] ?? null)
        );

        return $this;
    }

    /**
     * Remove o bind do driver, para o fake não vazar para outros testes que compartilhem o
     * container.
     *
     * @return void
     */
    public static function restoreSdkRequester(): void
    {
        $app = Facade::getFacadeApplication();
        if ($app instanceof \Illuminate\Container\Container) {
            unset($app[IuguGateway::class]);
        }
    }
}
