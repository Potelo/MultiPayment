<?php

namespace Potelo\MultiPayment\Events;

use Potelo\MultiPayment\Models\WebhookEvent;

/**
 * Evento do Laravel genérico, despachado pelo pipeline de webhooks para toda entrega aceita,
 * inclusive as de tipo `UNKNOWN` (o replay descartado fica de fora). Quando o tipo tem uma
 * classe própria (`InvoicePaid`, `SubscriptionCanceled`...), ela é despachada em seguida, com
 * o mesmo `WebhookEvent`.
 */
class WebhookReceived
{
    /**
     * @param  WebhookEvent  $webhook  entrega de webhook normalizada que originou o evento
     */
    public function __construct(public readonly WebhookEvent $webhook)
    {
    }
}
