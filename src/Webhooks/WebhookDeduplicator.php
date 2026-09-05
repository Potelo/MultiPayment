<?php

namespace Potelo\MultiPayment\Webhooks;

use Potelo\MultiPayment\Models\WebhookEvent;
use Potelo\MultiPayment\Contracts\IdempotencyStore;
use Potelo\MultiPayment\Helpers\ConfigurationHelper;
use Potelo\MultiPayment\Exceptions\IdempotencyConflictException;

/**
 * Deduplicação de entregas de webhook por id, sobre a `IdempotencyStore` do pacote. A chave é
 * `webhook:{gateway}:{id}`, com o prazo de `multi-payment.webhooks.dedup_ttl` (72 horas por
 * padrão). O deduplicador só marca `WebhookEvent::$isReplay`; descartar ou processar o replay
 * é decisão de quem consome.
 */
class WebhookDeduplicator
{
    /**
     * @var IdempotencyStore|null
     */
    private ?IdempotencyStore $store;

    /**
     * Cria o deduplicador. Sem store, a `IdempotencyStore` registrada no container é resolvida
     * na primeira marcação.
     *
     * @param  IdempotencyStore|null  $store
     */
    public function __construct(?IdempotencyStore $store = null)
    {
        $this->store = $store;
    }

    /**
     * Registra o id da entrega na store e marca `isReplay` quando ele já foi visto dentro do
     * prazo. O registro e a leitura são uma única operação da store, com um sentinela por
     * chamada: a chamada que gravou o próprio sentinela é a primeira, e qualquer outra (mesmo
     * concorrente, inclusive a que esbarra no lock da store) conta como replay. Evento sem
     * `id` ou sem `gateway` volta intacto, sem como deduplicar.
     *
     * @param  WebhookEvent  $event
     * @return WebhookEvent  o mesmo evento, com `isReplay` preenchido
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException  sem store no container
     */
    public function flagReplay(WebhookEvent $event): WebhookEvent
    {
        if (empty($event->id) || empty($event->gateway)) {
            return $event;
        }

        $key = "webhook:{$event->gateway}:{$event->id}";
        $token = bin2hex(random_bytes(16));

        try {
            $stored = $this->store()->remember($key, static fn () => $token, ConfigurationHelper::webhookDedupTtl());
            $event->isReplay = $stored !== $token;
        } catch (IdempotencyConflictException) {
            $event->isReplay = true;
        }

        return $event;
    }

    /**
     * Desfaz a marcação da entrega na store; a próxima chegada do mesmo `id` conta como
     * primeira. Usado quando o processamento falhou depois de `flagReplay()`, para a
     * retentativa do gateway não ser descartada como replay. Evento sem `id` ou sem `gateway`
     * é ignorado.
     *
     * @param  WebhookEvent  $event
     * @return void
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException  sem store no container
     */
    public function release(WebhookEvent $event): void
    {
        if (empty($event->id) || empty($event->gateway)) {
            return;
        }

        $this->store()->forget("webhook:{$event->gateway}:{$event->id}");
    }

    /**
     * Store usada na deduplicação: a do construtor ou a registrada no container.
     *
     * @return IdempotencyStore
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     */
    private function store(): IdempotencyStore
    {
        return $this->store ??= ConfigurationHelper::resolveIdempotencyStore();
    }
}
