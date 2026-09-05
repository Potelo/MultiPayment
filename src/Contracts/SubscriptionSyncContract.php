<?php

namespace Potelo\MultiPayment\Contracts;

use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;

/**
 * Aplicação do estado das emulações de assinatura de um gateway. Implementado pelo driver que
 * declara `COUPONS` ou `CANCEL_AT_PERIOD_END` em `emulated()`; o comando
 * `multipayment:sync-subscriptions` o chama na frequência que a aplicação agendar.
 */
interface SubscriptionSyncContract
{
    /**
     * Percorre as assinaturas do gateway e aplica o que a emulação deixou agendado: remove o
     * desconto cuja validade passou e suspende, marcando como cancelada, a assinatura cujo
     * cancelamento agendado chegou à data. Executar de novo sem nada pendente não muda nada.
     * Com `$dryRun`, devolve o que seria feito sem nenhuma escrita.
     *
     * Cada ação devolvida é um array com `subscription` (id), `action` (`remove_discount`,
     * `remove_orphan_discount_variable` ou `cancel`) e `detail` (texto para o log).
     *
     * @param  bool  $dryRun
     * @return array<int, array{subscription: string, action: string, detail: string}>
     * @throws GatewayException|GatewayNotAvailableException
     */
    public function syncSubscriptions(bool $dryRun = false): array;
}
