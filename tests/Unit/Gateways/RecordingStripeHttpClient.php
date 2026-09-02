<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Stripe\ApiRequestor;

/**
 * Fake da camada HTTP do stripe-php, no molde do QueuedIuguApiRequest: devolve respostas
 * enfileiradas e grava cada chamada para asserção. Cada resposta é um array (corpo JSON,
 * status 200), um par [corpo, status], uma tripla [corpo, status, cabeçalhos] ou um
 * `\Throwable`, lançado no lugar da resposta para simular falha de conexão. Um corpo string
 * vai cru, sem codificar em JSON, para simular a página HTML de um proxy.
 */
class RecordingStripeHttpClient implements \Stripe\HttpClient\ClientInterface
{
    /** @var array<int, array{0: string, 1: string, 2: array}> */
    public array $calls = [];

    /** @var array<int, array{0: array|string, 1: int, 2?: array}|\Throwable> */
    private array $responses;

    private function __construct(array $responses)
    {
        $this->responses = array_map(static function ($response) {
            if ($response instanceof \Throwable) {
                return $response;
            }

            return isset($response[1]) && is_int($response[1])
                ? $response
                : [$response, 200];
        }, $responses);
    }

    /**
     * Cria o fake e o instala como client HTTP global do stripe-php.
     *
     * @param  array  $responses
     * @return static
     */
    public static function withResponses(array $responses): self
    {
        $httpClient = new self($responses);
        ApiRequestor::setHttpClient($httpClient);

        return $httpClient;
    }

    /**
     * @inheritDoc
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->calls[] = [$method, $absUrl, $params];

        if (empty($this->responses)) {
            throw new \RuntimeException("Unexpected Stripe request: {$method} {$absUrl}");
        }
        $response = array_shift($this->responses);
        if ($response instanceof \Throwable) {
            throw $response;
        }
        [$body, $code] = $response;
        $headers = $response[2] ?? [];

        return [is_string($body) ? $body : json_encode($body), $code, $headers];
    }
}
