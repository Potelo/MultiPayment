<?php

namespace Potelo\MultiPayment\Enums;

/**
 * Tipo normalizado de um evento de webhook (`WebhookEvent::$type`), comum aos gateways. Cada
 * driver traduz os nomes de evento próprios do gateway para estes casos; evento fora do mapa
 * do driver vira `UNKNOWN`, com o payload preservado em `WebhookEvent::$raw`.
 */
enum WebhookEventType: string
{
    /** Assinatura criada. */
    case SUBSCRIPTION_CREATED = 'subscription.created';

    /**
     * Assinatura renovada: a cobrança de um novo ciclo foi paga. No Stripe o evento nasce do
     * `invoice.paid` do ciclo, então ele carrega a fatura paga em `invoice()` e um
     * `INVOICE_PAID` separado não é entregue para a mesma renovação.
     */
    case SUBSCRIPTION_RENEWED = 'subscription.renewed';

    /** Assinatura alterada (plano, método de pagamento, itens ou outro atributo). */
    case SUBSCRIPTION_UPDATED = 'subscription.updated';

    /** Assinatura cancelada, de imediato ou agendada para o fim do ciclo. */
    case SUBSCRIPTION_CANCELED = 'subscription.canceled';

    /** Assinatura com a cobrança interrompida pela aplicação (`suspend()`). */
    case SUBSCRIPTION_SUSPENDED = 'subscription.suspended';

    /** Fatura criada. */
    case INVOICE_CREATED = 'invoice.created';

    /**
     * Fatura alterada sem um tipo mais específico. Na Iugu é o resultado da resolução de
     * `invoice.status_changed` quando o status relido não corresponde a pagamento,
     * cancelamento, estorno ou contestação.
     */
    case INVOICE_UPDATED = 'invoice.updated';

    /** Fatura paga. */
    case INVOICE_PAID = 'invoice.paid';

    /** Tentativa de pagamento da fatura recusada; o motivo fica em `WebhookEvent::$declineCode`. */
    case INVOICE_PAYMENT_FAILED = 'invoice.payment_failed';

    /** Fatura cancelada. */
    case INVOICE_CANCELED = 'invoice.canceled';

    /** Estorno registrado numa fatura paga. */
    case REFUND_CREATED = 'refund.created';

    /** Contestação aberta pelo portador sobre uma cobrança paga. */
    case DISPUTE_OPENED = 'dispute.opened';

    /** Contestação encerrada (ganha, perdida ou retirada); o desfecho fica no payload. */
    case DISPUTE_CLOSED = 'dispute.closed';

    /** Método de pagamento de um cliente criado ou alterado. */
    case PAYMENT_METHOD_UPDATED = 'payment_method.updated';

    /** Autorização (mandato) de Pix Automático criada, alterada ou cancelada pelo pagador. */
    case PIX_MANDATE_CHANGED = 'pix.mandate_changed';

    /** Evento que o driver não mapeia para um tipo comum; o payload fica em `WebhookEvent::$raw`. */
    case UNKNOWN = 'unknown';

    /**
     * Diz se o evento trata de uma fatura: os tipos `INVOICE_*`, o estorno, a contestação e a
     * renovação de assinatura, que carrega a fatura paga do ciclo. Nesses eventos
     * `WebhookEvent::invoice()` hidrata a fatura quando o gateway informa o id dela.
     *
     * @return bool
     */
    public function concernsInvoice(): bool
    {
        return match ($this) {
            self::INVOICE_CREATED,
            self::INVOICE_UPDATED,
            self::INVOICE_PAID,
            self::INVOICE_PAYMENT_FAILED,
            self::INVOICE_CANCELED,
            self::REFUND_CREATED,
            self::DISPUTE_OPENED,
            self::DISPUTE_CLOSED,
            self::SUBSCRIPTION_RENEWED => true,
            default => false,
        };
    }

    /**
     * Diz se o evento trata de uma assinatura: os tipos `SUBSCRIPTION_*`. Nesses eventos
     * `WebhookEvent::subscription()` hidrata a assinatura quando o gateway informa o id dela.
     *
     * @return bool
     */
    public function concernsSubscription(): bool
    {
        return match ($this) {
            self::SUBSCRIPTION_CREATED,
            self::SUBSCRIPTION_RENEWED,
            self::SUBSCRIPTION_UPDATED,
            self::SUBSCRIPTION_CANCELED,
            self::SUBSCRIPTION_SUSPENDED => true,
            default => false,
        };
    }
}
