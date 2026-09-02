<?php

namespace Potelo\MultiPayment\Gateways\Stripe;

use Potelo\MultiPayment\Enums\SubscriptionStatus;

/**
 * Tradução do status da Subscription da Stripe para `SubscriptionStatus`.
 *
 * Fonte: máquina de estados da Subscription em
 * https://docs.stripe.com/billing/subscriptions/overview#subscription-statuses e o campo
 * `pause_collection` em https://docs.stripe.com/billing/subscriptions/pause-payment. A
 * Stripe tem dois jeitos de pausar: o status `paused` (trial terminou sem método de
 * pagamento) e `pause_collection` preenchido (cobrança pausada sem mudar o status); os dois
 * leem como `PAUSED`.
 */
final class SubscriptionStatuses
{
    /** @var array<string, SubscriptionStatus> */
    private const MAP = [
        'incomplete' => SubscriptionStatus::PENDING,
        'incomplete_expired' => SubscriptionStatus::EXPIRED,
        'trialing' => SubscriptionStatus::TRIALING,
        'active' => SubscriptionStatus::ACTIVE,
        'past_due' => SubscriptionStatus::PAST_DUE,
        'unpaid' => SubscriptionStatus::PAST_DUE,
        'canceled' => SubscriptionStatus::CANCELED,
        'paused' => SubscriptionStatus::PAUSED,
    ];

    /**
     * Status genérico de uma Subscription da Stripe, a partir de `status` e de
     * `pause_collection`. `pause_collection` preenchido devolve `PAUSED` a menos que a
     * assinatura já tenha terminado (`canceled`, `incomplete_expired`). Status fora do mapa
     * devolve `UNKNOWN` com aviso no log.
     *
     * @param  object  $stripeSubscription  `\Stripe\Subscription` ou objeto com os mesmos campos
     * @return SubscriptionStatus
     */
    public static function toSubscriptionStatus(object $stripeSubscription): SubscriptionStatus
    {
        $status = (string) ($stripeSubscription->status ?? '');
        $mapped = self::MAP[$status] ?? SubscriptionStatus::unknown($status, 'stripe');

        if (
            $mapped !== SubscriptionStatus::UNKNOWN
            && !$mapped->isEnded()
            && !empty($stripeSubscription->pause_collection)
        ) {
            return SubscriptionStatus::PAUSED;
        }

        return $mapped;
    }
}
