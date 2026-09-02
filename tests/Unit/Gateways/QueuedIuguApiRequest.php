<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Iugu_APIRequest;

/**
 * Devolve uma resposta por chamada, na ordem, e guarda todas as chamadas feitas. Uma entrada
 * `\Throwable` na fila é lançada em vez de devolvida, para simular o SDK sinalizando 404 ou 5xx;
 * uma entrada `QueuedIuguResponse` devolve o corpo com o status HTTP informado, para simular
 * erro com corpo JSON (401, 422), que o SDK devolve sem lançar.
 *
 * Como o SDK, grava o status HTTP de cada resposta devolvida em `$iugu_last_api_response_code`.
 */
class QueuedIuguApiRequest extends Iugu_APIRequest
{
    public array $calls = [];

    /**
     * @param  array<int, object|array|\Throwable|QueuedIuguResponse>  $responses
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

        if ($response instanceof QueuedIuguResponse) {
            $GLOBALS['iugu_last_api_response_code'] = $response->status;

            return $response->body;
        }

        $GLOBALS['iugu_last_api_response_code'] = 200;

        return $response;
    }

    /**
     * Instala este fake como requester dos recursos estáticos do SDK (`Iugu_Invoice::create()`,
     * `Iugu_Customer::fetch()`, `Iugu_PaymentToken::create()`...), que não recebem o requester
     * pelo construtor do gateway. Chame `restoreSdkRequester()` no tearDown.
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
