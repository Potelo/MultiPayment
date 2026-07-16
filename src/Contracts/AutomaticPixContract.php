<?php

namespace  Potelo\MultiPayment\Contracts;

use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;

/**
 * Operações de Pix Automático (recorrência do Bacen).
 *
 * Por ser específico de gateways que suportam o Pix Automático, fica fora do
 * GatewayContract para não obrigar implementações que não suportam recorrência.
 */
interface AutomaticPixContract
{
    /**
     * Solicita o cancelamento de um pagamento agendado de Pix Automático.
     *
     * @param  string  $receiverRecurrencePaymentId  UUID do pagamento agendado.
     * @param  string  $endToEndId  Identificador E2E do pagamento.
     * @return object  Resposta do gateway.
     * @throws GatewayException|GatewayNotAvailableException
     */
    public function cancelAutomaticPixScheduledPayment(
        string $receiverRecurrencePaymentId,
        string $endToEndId
    ): object;

    /**
     * Solicita o cancelamento de uma recorrência de Pix Automático.
     *
     * @param  string  $recurrenceId  UUID da recorrência (receiver_recurrence_id).
     * @return object  Resposta do gateway.
     * @throws GatewayException|GatewayNotAvailableException
     */
    public function cancelAutomaticPixRecurrence(string $recurrenceId): object;
}
