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
     * Estorna uma fatura: o restante estornável quando `$amount` é nulo, ou o valor informado em
     * centavos (zero ou negativo lança `ModelAttributeValidationException`). O driver lança
     * `RefundNotSupportedException` antes de qualquer requisição quando a regra do gateway já
     * garante a recusa (boleto, Pix parcial na Iugu, fatura já estornada, valor acima do
     * restante, prazo vencido). Devolve o `Refund` criado, com a fatura relida em
     * `$refund->invoice`; o model recebido é atualizado no lugar.
     *
     * @param  Invoice  $invoice
     * @param  int|null  $amount  valor em centavos; nulo estorna o restante
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     *
     * @return Refund
     * @throws GatewayException|GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\RefundNotSupportedException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     */
    public function refundInvoice(Invoice $invoice, ?int $amount = null, ?string $idempotencyKey = null): Refund;

    /**
     * Valor que ainda pode ser estornado na fatura, em centavos: zero para fatura não paga,
     * já integralmente estornada ou paga com boleto, cujo estorno `refundInvoice()` recusa.
     * Lê a fatura (um GET) quando o model não traz o valor pago ou o método de pagamento.
     *
     * @param  Invoice  $invoice
     * @return int
     * @throws GatewayException|GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\UnsupportedOperationException  fatura que o driver não estorna
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException  `id` ausente
     */
    public function refundableAmount(Invoice $invoice): int;

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
