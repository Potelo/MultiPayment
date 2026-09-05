<?php

namespace Potelo\MultiPayment\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Facade;
use Illuminate\Contracts\Container\Container;
use Potelo\MultiPayment\Events;
use Potelo\MultiPayment\MultiPayment;
use Potelo\MultiPayment\Helpers\LogHelper;
use Potelo\MultiPayment\Models\WebhookEvent;
use Potelo\MultiPayment\Enums\WebhookEventType;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Exceptions\ConfigurationException;
use Potelo\MultiPayment\Exceptions\WebhookSignatureException;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;

/**
 * Pipeline de consumo de uma entrega de webhook: verifica a autenticidade pelo driver,
 * deduplica, despacha os eventos do Laravel e monta a resposta HTTP. A rota pronta do pacote
 * usa este pipeline; a aplicação que registra a própria rota chama
 * `MultiPayment::webhooks()->handle($request)` e recebe o mesmo comportamento.
 */
class WebhookHandler
{
    /**
     * @var GatewayContract|string|null
     */
    private GatewayContract|string|null $gateway;

    /**
     * @var WebhookDeduplicator
     */
    private WebhookDeduplicator $deduplicator;

    /**
     * Cria o pipeline. O gateway informado é o usado quando a requisição não traz o parâmetro
     * de rota `gateway`; sem os dois, vale o gateway default da configuração.
     *
     * @param  GatewayContract|string|null  $gateway
     * @param  WebhookDeduplicator|null  $deduplicator
     */
    public function __construct(GatewayContract|string|null $gateway = null, ?WebhookDeduplicator $deduplicator = null)
    {
        $this->gateway = $gateway;
        $this->deduplicator = $deduplicator ?? new WebhookDeduplicator();
    }

    /**
     * Consome uma entrega de webhook e devolve a resposta HTTP para o gateway. O driver vem do
     * parâmetro de rota `gateway` (senão, do gateway do construtor ou do default). Entrega
     * recusada na verificação de autenticidade responde 400, e gateway desconhecido ou sem a
     * capability `WEBHOOKS` responde 404, os dois sem detalhe no corpo (o motivo fica no log).
     * Credencial de webhook não configurada (`missing_secret`) responde 500: é erro de
     * configuração da aplicação, e o 5xx mantém o gateway retentando até ela ser corrigida.
     * Entrega aceita segue para `handleParsed()`.
     *
     * @param  Request  $request
     * @return Response
     * @throws ConfigurationException  deduplicação sem `IdempotencyStore` no container
     */
    public function handle(Request $request): Response
    {
        try {
            $multiPayment = new MultiPayment($request->route('gateway') ?? $this->gateway);
            $event = $multiPayment->parseWebhookRequest($request);
        } catch (WebhookSignatureException $e) {
            LogHelper::warning('multi-payment: entrega de webhook recusada na verificação de autenticidade.', [
                'reason' => $e->reason,
            ]);

            $status = $e->reason === WebhookSignatureException::REASON_MISSING_SECRET ? 500 : 400;

            return new Response('', $status);
        } catch (UnsupportedOperationException|ConfigurationException $e) {
            LogHelper::warning('multi-payment: entrega de webhook para um gateway desconhecido ou sem webhooks.', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return new Response('', 404);
        }

        return $this->handleParsed($event);
    }

    /**
     * Deduplica, despacha e responde por uma entrega já verificada e traduzida. Replay dentro
     * do prazo de deduplicação é descartado com 200 e sem despachar nada. Evento de tipo
     * `UNKNOWN` também responde 200 (com registro no log) e despacha só o `WebhookReceived`
     * genérico. Falha num listener síncrono desfaz a marcação da deduplicação antes de subir,
     * para a retentativa do gateway processar a entrega de novo. Com `$deduplicate` falso a
     * entrega não toca na store, para replay manual não queimar o id.
     *
     * @param  WebhookEvent  $event
     * @param  bool  $deduplicate
     * @return Response
     * @throws ConfigurationException  deduplicação sem `IdempotencyStore` no container
     */
    public function handleParsed(WebhookEvent $event, bool $deduplicate = true): Response
    {
        if ($deduplicate) {
            $this->deduplicator->flagReplay($event);
        }

        if ($event->isReplay) {
            LogHelper::info('multi-payment: entrega de webhook repetida descartada.', [
                'gateway' => $event->gateway,
                'id' => $event->id,
            ]);

            return new Response('', 200);
        }

        if ($event->type === WebhookEventType::UNKNOWN || is_null($event->type)) {
            LogHelper::info('multi-payment: entrega de webhook sem tipo comum confirmada com 200.', [
                'gateway' => $event->gateway,
                'id' => $event->id,
                'resource_type' => $event->resourceType,
                'resource_id' => $event->resourceId,
            ]);
        }

        try {
            $this->dispatchLaravelEvents($event);
        } catch (\Throwable $e) {
            if ($deduplicate) {
                $this->deduplicator->release($event);
            }

            throw $e;
        }

        return new Response('', 200);
    }

    /**
     * Classe de evento do Laravel correspondente a um tipo comum; nulo para `UNKNOWN`, que só
     * gera o `WebhookReceived` genérico.
     *
     * @param  WebhookEventType  $type
     * @return class-string|null
     */
    public static function eventClassFor(WebhookEventType $type): ?string
    {
        return match ($type) {
            WebhookEventType::SUBSCRIPTION_CREATED => Events\SubscriptionCreated::class,
            WebhookEventType::SUBSCRIPTION_RENEWED => Events\SubscriptionRenewed::class,
            WebhookEventType::SUBSCRIPTION_UPDATED => Events\SubscriptionUpdated::class,
            WebhookEventType::SUBSCRIPTION_CANCELED => Events\SubscriptionCanceled::class,
            WebhookEventType::SUBSCRIPTION_SUSPENDED => Events\SubscriptionSuspended::class,
            WebhookEventType::INVOICE_CREATED => Events\InvoiceCreated::class,
            WebhookEventType::INVOICE_UPDATED => Events\InvoiceUpdated::class,
            WebhookEventType::INVOICE_PAID => Events\InvoicePaid::class,
            WebhookEventType::INVOICE_PAYMENT_FAILED => Events\InvoicePaymentFailed::class,
            WebhookEventType::INVOICE_CANCELED => Events\InvoiceCanceled::class,
            WebhookEventType::REFUND_CREATED => Events\RefundCreated::class,
            WebhookEventType::DISPUTE_OPENED => Events\DisputeOpened::class,
            WebhookEventType::DISPUTE_CLOSED => Events\DisputeClosed::class,
            WebhookEventType::PAYMENT_METHOD_UPDATED => Events\PaymentMethodUpdated::class,
            WebhookEventType::PIX_MANDATE_CHANGED => Events\PixMandateChanged::class,
            WebhookEventType::UNKNOWN => null,
        };
    }

    /**
     * Despacha o `WebhookReceived` genérico e, quando o tipo tem classe própria, o evento
     * tipado, na ordem. O despacho é síncrono, pelo dispatcher do container; sem dispatcher
     * registrado (uso fora do Laravel), nada é despachado.
     *
     * @param  WebhookEvent  $event
     * @return void
     */
    private function dispatchLaravelEvents(WebhookEvent $event): void
    {
        $app = Facade::getFacadeApplication();
        if (!$app instanceof Container || !$app->bound('events')) {
            return;
        }

        $dispatcher = $app->make('events');
        $dispatcher->dispatch(new Events\WebhookReceived($event));

        $class = is_null($event->type) ? null : self::eventClassFor($event->type);
        if (!is_null($class)) {
            $dispatcher->dispatch(new $class($event));
        }
    }
}
