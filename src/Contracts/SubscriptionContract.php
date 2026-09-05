<?php

namespace  Potelo\MultiPayment\Contracts;

use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Enums\ProrationBehavior;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Models\SubscriptionPlanChange;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

interface SubscriptionContract
{
    /**
     * Cria a assinatura no gateway.
     *
     * @param  Subscription  $subscription
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     *
     * @return Subscription
     * @throws GatewayException|GatewayNotAvailableException|ModelAttributeValidationException
     */
    public function createSubscription(Subscription $subscription, ?string $idempotencyKey = null): Subscription;

    /**
     * Busca a assinatura no gateway pelo id.
     *
     * @param  Subscription  $subscription
     *
     * @return Subscription
     * @throws GatewayException|GatewayNotAvailableException|ModelAttributeValidationException
     */
    public function getSubscription(Subscription $subscription): Subscription;

    /**
     * Atualiza a assinatura no gateway.
     *
     * Os itens e os descontos são declarativos: as listas informadas passam a ser o estado da
     * assinatura, e o gateway emite as chamadas necessárias para chegar nele. Item ou desconto
     * sem `id` é sempre criação.
     *
     * @param  Subscription  $subscription
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     *
     * @return Subscription
     * @throws GatewayException|GatewayNotAvailableException|ModelAttributeValidationException
     */
    public function updateSubscription(Subscription $subscription, ?string $idempotencyKey = null): Subscription;

    /**
     * Suspende a cobrança da assinatura, mantendo-a reativável por resumeSubscription().
     *
     * @param  Subscription  $subscription
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     *
     * @return Subscription
     * @throws GatewayException|GatewayNotAvailableException|ModelAttributeValidationException
     */
    public function suspendSubscription(Subscription $subscription, ?string $idempotencyKey = null): Subscription;

    /**
     * Volta a cobrar uma assinatura suspensa.
     *
     * @param  Subscription  $subscription
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     *
     * @return Subscription
     * @throws GatewayException|GatewayNotAvailableException|ModelAttributeValidationException
     */
    public function resumeSubscription(Subscription $subscription, ?string $idempotencyKey = null): Subscription;

    /**
     * Cancela a assinatura.
     *
     * Com $atPeriodEnd, a assinatura segue cobrando até o fim do período corrente e só então é
     * cancelada; gateway que não tem esse recurso lança UnsupportedOperationException
     * (CANCEL_AT_PERIOD_END).
     *
     * @param  Subscription  $subscription
     * @param  bool  $atPeriodEnd
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     *
     * @return Subscription
     * @throws GatewayException|GatewayNotAvailableException|ModelAttributeValidationException
     * @throws \Potelo\MultiPayment\Exceptions\UnsupportedOperationException
     */
    public function cancelSubscription(
        Subscription $subscription,
        bool $atPeriodEnd = false,
        ?string $idempotencyKey = null
    ): Subscription;

    /**
     * Troca o plano da assinatura com a política de pró-rata informada.
     *
     * `CHARGE_DIFFERENCE` cobra o plano novo na hora; `NONE` não cobra nem credita nada agora
     * e, se `nextBillingAt` estiver preenchido na assinatura, a data da próxima cobrança vai na
     * mesma requisição; `CREDIT` pede ao gateway o crédito proporcional do período não usado, e
     * gateway sem `Capability::PLAN_CHANGE_PRORATION` lança `UnsupportedOperationException`
     * antes de qualquer requisição. O booleano antigo continua aceito no lugar do enum e pelo
     * nome `charge` (`true` é `CHARGE_DIFFERENCE`, `false` é `NONE`), com aviso
     * `E_USER_DEPRECATED`; `charge` informado prevalece sobre `$proration`.
     *
     * @param  Subscription  $subscription
     * @param  string  $planId
     * @param  ProrationBehavior|bool  $proration  política de pró-rata; o booleano é o `$charge` antigo, obsoleto
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     * @param  bool|null  $charge  obsoleto desde 2026-09-02; use `$proration`
     *
     * @return Subscription
     * @throws GatewayException|GatewayNotAvailableException|ModelAttributeValidationException
     * @throws \Potelo\MultiPayment\Exceptions\UnsupportedOperationException
     */
    public function changeSubscriptionPlan(
        Subscription $subscription,
        string $planId,
        ProrationBehavior|bool $proration = ProrationBehavior::CHARGE_DIFFERENCE,
        ?string $idempotencyKey = null,
        ?bool $charge = null
    ): Subscription;

    /**
     * Simula a troca de plano sem aplicá-la, devolvendo o que seria cobrado com a política de
     * pró-rata informada. As linhas de `SubscriptionPlanChange::$items` nunca faltam: quando o
     * gateway não as devolve, o driver as monta a partir dos totais da simulação. Gateway com
     * um único fluxo de simulação documenta no driver que políticas diferentes devolvem a
     * mesma prévia; `CREDIT` num gateway sem `Capability::PLAN_CHANGE_PRORATION` lança
     * `UnsupportedOperationException` antes de qualquer requisição, como em
     * `changeSubscriptionPlan()`.
     *
     * @param  Subscription  $subscription
     * @param  string  $planId
     * @param  ProrationBehavior  $proration  política de pró-rata simulada
     *
     * @return SubscriptionPlanChange
     * @throws GatewayException|GatewayNotAvailableException|ModelAttributeValidationException
     * @throws \Potelo\MultiPayment\Exceptions\UnsupportedOperationException
     */
    public function previewSubscriptionPlanChange(
        Subscription $subscription,
        string $planId,
        ProrationBehavior $proration = ProrationBehavior::CHARGE_DIFFERENCE
    ): SubscriptionPlanChange;

    /**
     * Lista as assinaturas de um cliente.
     *
     * @param  Customer  $customer
     * @param  int  $page
     * @param  int  $limit
     *
     * @return Subscription[]
     * @throws GatewayException|GatewayNotAvailableException|ModelAttributeValidationException
     */
    public function listSubscriptions(Customer $customer, int $page = 1, int $limit = 100): array;
}
