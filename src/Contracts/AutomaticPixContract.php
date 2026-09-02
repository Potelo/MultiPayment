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
 * Quem agenda cada cobrança depende do gateway. Na Iugu a API não gerencia a recorrência: a
 * aplicação é o motor de recorrência e precisa chamar estas operações na periodicidade certa
 * para que as cobranças aconteçam, sejam reagendadas ou canceladas. No Stripe o mandato vive
 * na Subscription e o próprio gateway agenda, notifica o pagador com três dias de
 * antecedência e faz as retentativas. Ao migrar uma recorrência de um gateway que não agenda
 * para um que agenda, desligue o motor da aplicação para aquela recorrência, sob risco de
 * cobrança dupla.
 */
interface AutomaticPixContract
{
    /**
     * @throws GatewayException|GatewayNotAvailableException
     */
    public function rescheduleAutomaticPixPayment(Invoice $invoice): Invoice;

    /**
     * @throws GatewayException|GatewayNotAvailableException
     */
    public function cancelAutomaticPixScheduledPayment(
        AutomaticPixCharge $charge
    ): AutomaticPixCancellation;

    /**
     * @throws GatewayException|GatewayNotAvailableException
     */
    public function cancelAutomaticPixRecurrence(
        AutomaticPix $automaticPix
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
