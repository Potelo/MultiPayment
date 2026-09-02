<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Stripe\ApiRequestor;

/**
 * Fake da camada HTTP do stripe-php, no molde do QueuedIuguApiRequest: devolve respostas
 * enfileiradas e grava cada chamada (método, url, parâmetros e cabeçalhos) para asserção.
 * Cada resposta é um array (corpo JSON, status 200), um par [corpo, status], uma tripla
 * [corpo, status, cabeçalhos] ou um
 * `\Throwable`, lançado no lugar da resposta para simular falha de conexão. Um corpo string
 * vai cru, sem codificar em JSON, para simular a página HTML de um proxy.
 */
class RecordingStripeHttpClient implements \Stripe\HttpClient\ClientInterface
{
    /** @var array<int, array{0: string, 1: string, 2: array, 3: string[]}> método, url, parâmetros e cabeçalhos (`Nome: valor`) */
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
     * Valor de um cabeçalho enviado na chamada de índice `$call`, ou nulo quando ausente. O
     * nome é comparado sem diferenciar maiúsculas.
     *
     * @param  int  $call
     * @param  string  $name
     * @return string|null
     */
    public function header(int $call, string $name): ?string
    {
        foreach ($this->calls[$call][3] ?? [] as $rawHeader) {
            [$headerName, $value] = array_pad(explode(':', $rawHeader, 2), 2, '');
            if (strcasecmp(trim($headerName), $name) === 0) {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->calls[] = [$method, $absUrl, $params, $headers];

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
