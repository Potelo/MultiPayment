<?php

namespace Potelo\MultiPayment\Console;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Potelo\MultiPayment\MultiPayment;
use Potelo\MultiPayment\Webhooks\WebhookHandler;
use Potelo\MultiPayment\Exceptions\WebhookSignatureException;

/**
 * Reproduz uma entrega de webhook gravada em arquivo contra a aplicação em desenvolvimento,
 * pelo mesmo pipeline da rota do pacote (parse, despacho dos eventos do Laravel e resposta).
 * A entrega é reautenticada com a credencial configurada do gateway antes do parse, e a
 * deduplicação não é consultada nem gravada, para o mesmo arquivo poder ser reproduzido
 * quantas vezes for preciso.
 */
class WebhookReplayCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'multipayment:webhook-replay
        {gateway : Nome do gateway na configuração (iugu, stripe...)}
        {fixture : Caminho do arquivo com a entrega gravada}';

    /**
     * @var string
     */
    protected $description = 'Reproduz uma entrega de webhook gravada em arquivo pelo pipeline da rota do pacote';

    /**
     * Lê o arquivo da fixture, reautentica a entrega, a traduz pelo driver e a entrega ao
     * pipeline, escrevendo o tipo resolvido e a resposta na saída. O arquivo pode ser um
     * envelope `{method, headers, body}` (formato das capturas da Iugu do pacote) ou o corpo
     * cru da entrega (um evento do Stripe, por exemplo).
     *
     * @return int
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\UnsupportedOperationException
     */
    public function handle(): int
    {
        $gatewayName = $this->argument('gateway');
        $path = $this->argument('fixture');

        if (empty(Config::get("multi-payment.gateways.{$gatewayName}"))) {
            $this->error("O gateway [{$gatewayName}] não está configurado em multi-payment.gateways.");

            return self::FAILURE;
        }

        if (!is_file($path)) {
            $this->error("Arquivo de fixture não encontrado: {$path}");

            return self::FAILURE;
        }

        [$body, $headers] = $this->deliveryFrom(file_get_contents($path));
        $headers = $this->reauthenticate($gatewayName, $body, $headers);
        if (is_null($headers)) {
            return self::FAILURE;
        }

        $uri = str_replace(
            '{gateway}',
            $gatewayName,
            Config::get('multi-payment.webhooks.route.path') ?? '/multipayment/webhooks/{gateway}'
        );
        $request = Request::create($uri, 'POST', content: $body);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        try {
            $event = (new MultiPayment($gatewayName))->parseWebhookRequest($request);
        } catch (WebhookSignatureException $e) {
            $this->error("Entrega recusada na verificação de autenticidade ({$e->reason}).");

            return self::FAILURE;
        }

        $type = $event->type?->value ?? 'unknown';
        $this->line("Entrega {$event->id}: tipo {$type}, recurso {$event->resourceType} {$event->resourceId}.");

        $response = (new WebhookHandler())->handleParsed($event, false);
        $this->info(
            "Pipeline respondeu {$response->getStatusCode()}; eventos do Laravel despachados"
            . ' de forma síncrona, sem consultar a deduplicação.'
        );

        return self::SUCCESS;
    }

    /**
     * Separa corpo e cabeçalhos do conteúdo do arquivo: um JSON com a chave `body` é o
     * envelope de entrega gravada (com `headers` opcionais); qualquer outro conteúdo é o
     * corpo cru, sem cabeçalhos.
     *
     * @param  string  $contents
     * @return array{0: string, 1: array}
     */
    private function deliveryFrom(string $contents): array
    {
        $decoded = json_decode($contents, true);

        if (is_array($decoded) && array_key_exists('body', $decoded)) {
            return [(string) $decoded['body'], (array) ($decoded['headers'] ?? [])];
        }

        return [$contents, []];
    }

    /**
     * Substitui a credencial da entrega pela configurada no gateway: com `webhook_secret`, um
     * cabeçalho `Stripe-Signature` novo assinado sobre o corpo no relógio atual; com
     * `webhook_token`, o token no cabeçalho `authorization`. Sem nenhuma das duas chaves,
     * escreve o erro e devolve nulo.
     *
     * @param  string  $gatewayName
     * @param  string  $body
     * @param  array  $headers
     * @return array|null
     */
    private function reauthenticate(string $gatewayName, string $body, array $headers): ?array
    {
        $secret = Config::get("multi-payment.gateways.{$gatewayName}.webhook_secret");
        if (!empty($secret)) {
            $timestamp = Carbon::now()->getTimestamp();
            $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
            $headers['stripe-signature'] = "t={$timestamp},v1={$signature}";

            return $headers;
        }

        $token = Config::get("multi-payment.gateways.{$gatewayName}.webhook_token");
        if (!empty($token)) {
            $headers['authorization'] = $token;

            return $headers;
        }

        $this->error(
            "O gateway [{$gatewayName}] não tem webhook_secret nem webhook_token configurado;"
            . ' sem credencial não há como reautenticar a entrega.'
        );

        return null;
    }
}
