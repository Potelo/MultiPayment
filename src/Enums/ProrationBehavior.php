<?php

namespace Potelo\MultiPayment\Enums;

/**
 * Política de pró-rata numa troca de plano: o que acontece com o valor do período já pago e
 * quando o plano novo é cobrado. Cada driver traduz o caso para o parâmetro do gateway; a
 * tabela por gateway está no README, em "Troca de plano".
 */
enum ProrationBehavior: string
{
    /**
     * Fatura a diferença agora: o plano novo é cobrado na hora, e o gateway decide o que
     * abater do período já pago.
     */
    case CHARGE_DIFFERENCE = 'charge_difference';

    /** Nada é cobrado nem creditado agora; o plano novo vale a partir da próxima cobrança do ciclo. */
    case NONE = 'none';

    /**
     * O gateway calcula o crédito proporcional do período não usado e o aplica na próxima
     * fatura. Gateway sem `Capability::PLAN_CHANGE_PRORATION` recusa antes da rede.
     */
    case CREDIT = 'credit';

    /**
     * Política equivalente ao booleano antigo de `changePlan()`: cobrar é
     * `CHARGE_DIFFERENCE`, não cobrar é `NONE`.
     *
     * @param  bool  $charge
     * @return static
     */
    public static function fromCharge(bool $charge): static
    {
        return $charge ? self::CHARGE_DIFFERENCE : self::NONE;
    }

    /**
     * Devolve a política recebida por `changePlan()`: o enum tal como veio, ou a tradução de
     * `fromCharge()` quando o chamador passou o booleano antigo, com aviso `E_USER_DEPRECATED`.
     *
     * @param  ProrationBehavior|bool  $value
     * @return static
     */
    public static function resolve(self|bool $value): static
    {
        if ($value instanceof self) {
            return $value;
        }

        trigger_error(
            'O booleano $charge de changePlan() está obsoleto desde 2026-09-02; passe'
            . ' ProrationBehavior::CHARGE_DIFFERENCE ou ProrationBehavior::NONE',
            E_USER_DEPRECATED
        );

        return self::fromCharge($value);
    }

    /**
     * Capability que o gateway precisa declarar para aceitar a política: `CREDIT` exige
     * `PLAN_CHANGE_PRORATION`; as demais fazem parte de `SUBSCRIPTIONS` e devolvem nulo.
     *
     * @return Capability|null
     */
    public function requiredCapability(): ?Capability
    {
        return $this === self::CREDIT ? Capability::PLAN_CHANGE_PRORATION : null;
    }
}
