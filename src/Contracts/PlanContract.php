<?php

namespace  Potelo\MultiPayment\Contracts;

use Potelo\MultiPayment\Models\Plan;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

interface PlanContract
{
    /**
     * Cria o plano no gateway.
     *
     * @param  Plan  $plan
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     *
     * @return Plan
     * @throws GatewayException|GatewayNotAvailableException
     */
    public function createPlan(Plan $plan, ?string $idempotencyKey = null): Plan;

    /**
     * Busca o plano no gateway pelo id ou pelo identifier.
     *
     * @param  Plan  $plan
     *
     * @return Plan
     * @throws GatewayException|GatewayNotAvailableException|ModelAttributeValidationException
     */
    public function getPlan(Plan $plan): Plan;

    /**
     * Lista os planos do gateway.
     *
     * @param  int  $page
     * @param  int  $limit
     *
     * @return Plan[]
     * @throws GatewayException|GatewayNotAvailableException
     */
    public function listPlans(int $page = 1, int $limit = 100): array;

    /**
     * Desativa o plano, impedindo novas assinaturas sem afetar as existentes.
     *
     * @param  Plan  $plan
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     *
     * @return Plan
     * @throws GatewayException|GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\UnsupportedOperationException
     */
    public function deactivatePlan(Plan $plan, ?string $idempotencyKey = null): Plan;
}
