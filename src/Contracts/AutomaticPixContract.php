<?php

namespace  Potelo\MultiPayment\Contracts;

use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\AutomaticPix;
use Potelo\MultiPayment\Models\AutomaticPixCharge;
use Potelo\MultiPayment\Models\AutomaticPixCancellation;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;

/**
 * Operações de gestão de uma recorrência de Pix Automático.
 *
 * Quem agenda cada cobrança depende do gateway (`Capability::MANAGES_RECURRENCE`). Na Iugu a
 * API não gerencia a recorrência: a aplicação é o motor de recorrência e precisa chamar as
 * operações de agendamento na periodicidade certa para que as cobranças aconteçam, sejam
 * reagendadas ou canceladas. No Stripe o mandato vive na Subscription e o próprio gateway
 * agenda, notifica o pagador com três dias de antecedência e faz as retentativas: as
 * operações de agendamento e de cancelamento de cobrança lançam
 * `UnsupportedOperationException` com `reason` `managed_by_gateway`, e as de consulta leem o
 * Mandate. Ao migrar uma recorrência de um gateway que não agenda para um que agenda,
 * desligue o motor da aplicação para aquela recorrência, sob risco de cobrança dupla.
 */
interface AutomaticPixContract
{
    /**
     * Pede um novo agendamento de débito para uma fatura de Pix Automático que não foi paga.
     *
     * @param  Invoice  $invoice
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     * @return Invoice
     * @throws GatewayException|GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\UnsupportedOperationException  gateway que agenda por conta própria (`managed_by_gateway`)
     */
    public function rescheduleAutomaticPixPayment(Invoice $invoice, ?string $idempotencyKey = null): Invoice;

    /**
     * Cancela um pagamento agendado da recorrência.
     *
     * @param  AutomaticPixCharge  $charge
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     * @return AutomaticPixCancellation
     * @throws GatewayException|GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\UnsupportedOperationException  gateway que agenda por conta própria (`managed_by_gateway`)
     */
    public function cancelAutomaticPixScheduledPayment(
        AutomaticPixCharge $charge,
        ?string $idempotencyKey = null
    ): AutomaticPixCancellation;

    /**
     * Cancela a recorrência inteira.
     *
     * @param  AutomaticPix  $automaticPix
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     * @return AutomaticPixCancellation
     * @throws GatewayException|GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\UnsupportedOperationException  gateway que agenda por conta própria (`managed_by_gateway`)
     */
    public function cancelAutomaticPixRecurrence(
        AutomaticPix $automaticPix,
        ?string $idempotencyKey = null
    ): AutomaticPixCancellation;

    /**
     * @throws GatewayException|GatewayNotAvailableException
     */
    public function getAutomaticPixCancellation(
        AutomaticPixCancellation $cancellation
    ): AutomaticPixCancellation;

    /**
     * @return AutomaticPixCancellation[]
     * @throws GatewayException|GatewayNotAvailableException
     */
    public function listAutomaticPixCancellations(
        AutomaticPix $automaticPix,
        int $page = 1,
        int $limit = 100
    ): array;
}
