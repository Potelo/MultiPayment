<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Stripe\ApiRequestor;

/**
 * Fake da camada HTTP do stripe-php, no molde do RecordingIuguApiRequest: devolve respostas
 * enfileiradas e grava cada chamada para asserção. Cada resposta é um array (corpo JSON,
 * status 200) ou um par [corpo, status].
 */
class RecordingStripeHttpClient implements \Stripe\HttpClient\ClientInterface
{
    /** @var array<int, array{0: string, 1: string, 2: array}> */
    public array $calls = [];

    /** @var array<int, array{0: array, 1: int}> */
    private array $responses;

    private function __construct(array $responses)
    {
        $this->responses = array_map(static function ($response) {
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
        [$body, $code] = array_shift($this->responses);

        return [json_encode($body), $code, []];
    }
}
