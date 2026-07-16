<?php

namespace Potelo\MultiPayment\Contracts;

use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;

interface InvoiceCancellationContract
{
    /**
     * Cancel an invoice.
     *
     * @param  Invoice  $invoice
     * @return Invoice
     * @throws GatewayException|GatewayNotAvailableException
     */
    public function cancelInvoice(Invoice $invoice): Invoice;
}
