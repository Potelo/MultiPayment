<?php

namespace Potelo\MultiPayment\Contracts;

use Potelo\MultiPayment\Models\WebhookEvent;
use Potelo\MultiPayment\Exceptions\WebhookSignatureException;

/**
 * Leitura de webhooks do gateway, guardada pela capability `WEBHOOKS`. O contract fica fora da
 * composição de `GatewayContract` enquanto nem todo gateway o implementa, como
 * `SubscriptionContract`.
 */
interface WebhookContract
{
    /**
     * Verifica a autenticidade de uma entrega de webhook e a traduz num `WebhookEvent`
     * normalizado. Recebe o corpo cru, byte a byte como entregue (a verificação de assinatura
     * depende disso), e os cabeçalhos da requisição como `nome => valor` (o valor pode ser uma
     * lista, como o Laravel entrega; o nome é comparado sem diferenciar maiúsculas). Evento
     * que o driver não mapeia vira `WebhookEventType::UNKNOWN` com o payload preservado. O
     * parse não deduplica; ver `WebhookDeduplicator`.
     *
     * @param  string  $rawBody  corpo cru da requisição
     * @param  array  $headers  cabeçalhos da requisição
     * @return WebhookEvent
     * @throws WebhookSignatureException  entrega recusada na verificação de autenticidade
     */
    public function parseWebhook(string $rawBody, array $headers): WebhookEvent;
}
