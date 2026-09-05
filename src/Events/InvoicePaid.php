<?php

namespace Potelo\MultiPayment\Events;

use Potelo\MultiPayment\Models\WebhookEvent;

/**
 * Evento do Laravel despachado pelo pipeline de webhooks quando uma entrega chega com o tipo
 * `WebhookEventType::INVOICE_PAID`.
 */
class InvoicePaid
{
    /**
     * @param  WebhookEvent  $webhook  entrega de webhook normalizada que originou o evento
     */
    public function __construct(public readonly WebhookEvent $webhook)
    {
    }
}
