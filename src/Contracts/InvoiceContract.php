<?php

namespace  Potelo\MultiPayment\Contracts;

use Carbon\Carbon;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Refund;
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
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     *
     * @return Invoice
     * @throws GatewayException|GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\UnsupportedOperationException
     */
    public function createInvoice(Invoice $invoice, ?string $idempotencyKey = null): Invoice;

    /**
     * Return one invoice based on the invoice ID
     *
     * @param  \Potelo\MultiPayment\Models\Invoice  $invoice
     * @return Invoice
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayNotAvailableException
     */
    public function getInvoice(Invoice $invoice): Invoice;

    /**
     * Refund an invoice
     *
     * Full refund when `refundedAmount` is empty; partial when set. The gateway throws
     * `RefundNotSupportedException` before any request when its own rules already guarantee
     * the refusal (bank slip, partial Pix on Iugu, invoice already refunded, amount above the
     * refundable remainder, window expired). Returns the created `Refund`, with the invoice
     * re-read after the refund in `$refund->invoice`; the given model is updated in place.
     *
     * @param  Invoice  $invoice
     * @param  string|null  $idempotencyKey  idempotency key of the operation; null disables deduplication
     *
     * @return Refund
     * @throws GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\RefundNotSupportedException
     */
    public function refundInvoice(Invoice $invoice, ?string $idempotencyKey = null): Refund;

    /**
     * Charge an invoice with a credit card
     *
     * @param  \Potelo\MultiPayment\Models\Invoice  $invoice
     * @param  string|null  $idempotencyKey  idempotency key of the operation; null disables deduplication
     * @return \Potelo\MultiPayment\Models\Invoice
     * @throws \Potelo\MultiPayment\Exceptions\ChargingException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     */
    public function chargeInvoiceWithCreditCard(Invoice $invoice, ?string $idempotencyKey = null): Invoice;

    /**
     * Duplicate an invoice
     *
     * @param  Invoice  $invoice
     * @param  \Carbon\Carbon  $expiresAt
     * @param  array  $gatewayOptions
     * @param  string|null  $idempotencyKey  idempotency key of the operation; null disables deduplication
     * @return Invoice
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\UnsupportedOperationException
     */
    public function duplicateInvoice(
        Invoice $invoice,
        Carbon $expiresAt,
        array $gatewayOptions = [],
        ?string $idempotencyKey = null
    ): Invoice;

    /**
     * Cancel an invoice.
     *
     * @param  Invoice  $invoice
     * @param  string|null  $idempotencyKey  idempotency key of the operation; null disables deduplication
     * @return Invoice
     * @throws GatewayException|GatewayNotAvailableException
     */
    public function cancelInvoice(Invoice $invoice, ?string $idempotencyKey = null): Invoice;
}
