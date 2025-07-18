<?php

namespace  Potelo\MultiPayment\Contracts;

use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;

interface InvoiceContract
{

    /**
     * create a new invoice
     *
     * @param  Invoice  $invoice
     *
     * @return Invoice
     * @throws GatewayException|GatewayNotAvailableException
     */
    public function createInvoice(Invoice $invoice): Invoice;

    /**
     * Return one invoice based on the invoice ID
     * 
     * @param string $id
     * 
     * @return Invoice
     * @throws GatewayException|GatewayNotAvailableException
     */
    public function getInvoice(string $id): Invoice;

    /**
     * Refund an invoice
     *
     * @param  Invoice  $invoice
     *
     * @return Invoice
     * @throws GatewayException
     */
    public function refundInvoice(Invoice $invoice): Invoice;

    /**
     * String representation of the gateway
     *
     * @return string
     */

    /**
     * Charge an invoice with a credit card
     *
     * @param  \Potelo\MultiPayment\Models\Invoice  $invoice
     * @return \Potelo\MultiPayment\Models\Invoice
     * @throws \Potelo\MultiPayment\Exceptions\ChargingException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     */
    public function chargeInvoiceWithCreditCard(Invoice $invoice): Invoice;
}
