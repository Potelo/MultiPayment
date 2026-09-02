<?php

namespace  Potelo\MultiPayment\Contracts;

use Potelo\MultiPayment\Models\Customer;
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
     *
     * @return Subscription
     * @throws GatewayException|GatewayNotAvailableException|ModelAttributeValidationException
     */
    public function createSubscription(Subscription $subscription): Subscription;

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
     *
     * @return Subscription
     * @throws GatewayException|GatewayNotAvailableException|ModelAttributeValidationException
     */
    public function updateSubscription(Subscription $subscription): Subscription;

    /**
     * Suspende a cobrança da assinatura, mantendo-a reativável por resumeSubscription().
     *
     * @param  Subscription  $subscription
     *
     * @return Subscription
     * @throws GatewayException|GatewayNotAvailableException|ModelAttributeValidationException
     */
    public function suspendSubscription(Subscription $subscription): Subscription;

    /**
     * Volta a cobrar uma assinatura suspensa.
     *
     * @param  Subscription  $subscription
     *
     * @return Subscription
     * @throws GatewayException|GatewayNotAvailableException|ModelAttributeValidationException
     */
    public function resumeSubscription(Subscription $subscription): Subscription;

    /**
     * Cancela a assinatura.
     *
     * Com $atPeriodEnd, a assinatura segue cobrando até o fim do período corrente e só então é
     * cancelada; gateway que não tem esse recurso lança UnsupportedOperationException
     * (CANCEL_AT_PERIOD_END).
     *
     * @param  Subscription  $subscription
     * @param  bool  $atPeriodEnd
     *
     * @return Subscription
     * @throws GatewayException|GatewayNotAvailableException|ModelAttributeValidationException
     * @throws \Potelo\MultiPayment\Exceptions\UnsupportedOperationException
     */
    public function cancelSubscription(Subscription $subscription, bool $atPeriodEnd = false): Subscription;

    /**
     * Troca o plano da assinatura.
     *
     * Com $charge falso, a troca não gera cobrança imediata. Se `nextBillingAt` estiver
     * preenchido na assinatura, a data da próxima cobrança vai na mesma requisição.
     *
     * @param  Subscription  $subscription
     * @param  string  $planId
     * @param  bool  $charge
     *
     * @return Subscription
     * @throws GatewayException|GatewayNotAvailableException|ModelAttributeValidationException
     */
    public function changeSubscriptionPlan(
        Subscription $subscription,
        string $planId,
        bool $charge = true
    ): Subscription;

    /**
     * Simula a troca de plano sem aplicá-la, devolvendo o que seria cobrado.
     *
     * @param  Subscription  $subscription
     * @param  string  $planId
     *
     * @return SubscriptionPlanChange
     * @throws GatewayException|GatewayNotAvailableException|ModelAttributeValidationException
     */
    public function previewSubscriptionPlanChange(
        Subscription $subscription,
        string $planId
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
