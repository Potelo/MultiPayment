<?php

namespace Potelo\MultiPayment\Gateways\Stripe;

use Potelo\MultiPayment\Enums\ProrationBehavior;

/**
 * Tradução de `ProrationBehavior` para o parâmetro `proration_behavior` da Subscription da
 * Stripe.
 *
 * Fonte: https://docs.stripe.com/billing/subscriptions/prorations. `always_invoice` cria as
 * linhas de pró-rata e as fatura na hora; `create_prorations` cria as linhas e as deixa para
 * a próxima fatura; `none` não cria linha nenhuma.
 */
final class ProrationBehaviors
{
    /**
     * Valor de `proration_behavior` que a Stripe recebe para a política.
     *
     * @param  ProrationBehavior  $behavior
     * @return string
     */
    public static function toStripe(ProrationBehavior $behavior): string
    {
        return match ($behavior) {
            ProrationBehavior::CHARGE_DIFFERENCE => 'always_invoice',
            ProrationBehavior::NONE => 'none',
            ProrationBehavior::CREDIT => 'create_prorations',
        };
    }
}
