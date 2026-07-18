<?php

namespace  Potelo\MultiPayment\Contracts;

use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\AutomaticPix;
use Potelo\MultiPayment\Models\AutomaticPixCancellation;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;

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
        string $paymentId,
        string $endToEndId
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
