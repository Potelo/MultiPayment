<?php

namespace Potelo\MultiPayment;

use Carbon\Carbon;
use Potelo\MultiPayment\Exceptions\MultiPaymentException;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Builders\InvoiceBuilder;
use Potelo\MultiPayment\Builders\CustomerBuilder;
use Potelo\MultiPayment\Builders\CreditCardBuilder;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Helpers\ConfigurationHelper;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Class MultiPayment
 */
class MultiPayment
{

    private GatewayContract $gateway;

    /**
     * MultiPayment constructor.
     *
     * @param  GatewayContract|string|null  $gateway
     */
    public function __construct($gateway = null)
    {
        $this->gateway = ConfigurationHelper::resolveGateway($gateway);
    }

    /**
     * @param  GatewayContract|string|null  $gateway
     * @return MultiPayment
     */
    public function setGateway($gateway): MultiPayment
    {
        $this->gateway = ConfigurationHelper::resolveGateway($gateway);
        return $this;
    }

    /**
     * Charge a customer
     *
     * @param  array  $attributes
     *
     * @return Invoice
     * @throws GatewayException|ModelAttributeValidationException|GatewayNotAvailableException
     */
    public function charge(array $attributes): Invoice
    {
        $invoice = new Invoice();
        $invoice->fill($attributes);
        $invoice->customer = new Customer();
        $invoice->customer->fill($attributes['customer']);

        $invoice->save($this->gateway);
        return $invoice;
    }

    /**
     * Return an InvoiceBuilder instance
     *
     * @return InvoiceBuilder
     */
    public function newInvoice(): InvoiceBuilder
    {
        return new InvoiceBuilder($this->gateway);
    }

    /**
     * Return a CustomerBuilder instance
     *
     * @return CustomerBuilder
     */
    public function newCustomer(): CustomerBuilder
    {
        return new CustomerBuilder($this->gateway);
    }

    /**
     * Return a CreditCardBuilder instance
     *
     * @return CreditCardBuilder
     */
    public function newCreditCard(): CreditCardBuilder
    {
        return new CreditCardBuilder($this->gateway);
    }

    /**
     * Return an invoice based on the invoice ID
     *
     * @param  string  $id
     *
     * @return \Potelo\MultiPayment\Models\Invoice
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function getInvoice(string $id): Invoice
    {
        return Invoice::get($id, $this->gateway);
    }

    /**
     * Duplicate an invoice
     *
     * @param  \Potelo\MultiPayment\Models\Invoice|string  $invoice
     * @param  \Carbon\Carbon  $expiresAt
     * @param  array  $gatewayOptions
     *
     * @return \Potelo\MultiPayment\Models\Invoice
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function duplicateInvoice(Invoice|string $invoice, Carbon $expiresAt, array $gatewayOptions = []): Invoice
    {
        if (is_string($invoice)) {
            $invoiceInstance = new Invoice();
            $invoiceInstance->id = $invoice;
            $invoice = $invoiceInstance;
        }

        return $invoice->duplicate($expiresAt, $gatewayOptions);
    }

    /**
     * Return an Customer based on the customer ID
     *
     * @param  string  $id
     *
     * @return \Potelo\MultiPayment\Models\Customer
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function getCustomer(string $id): Customer
    {
        return Customer::get($id, $this->gateway);
    }

    /**
     * Refund an invoice
     *
     * @param  string  $id
     * @param  int|null  $partialValueCents
     *
     * @return void
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function refundInvoice(string $id, ?int $partialValueCents = null): Invoice
    {
        $invoice = new Invoice();
        $invoice->id = $id;
        $invoice->gateway = $this->gateway;

        if ($partialValueCents) {
            $invoice->refundedAmount = $partialValueCents;
        }

        return $invoice->refund();

    }

    /**
     * Charge invoice with credit card
     *
     * @param  Invoice|string  $invoice
     * @param  string|null  $creditCardToken
     * @param  string|null  $creditCardId
     *
     * @return \Potelo\MultiPayment\Models\Invoice
     *
     * @throws \Potelo\MultiPayment\Exceptions\ChargingException
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     * @throws \Potelo\MultiPayment\Exceptions\MultiPaymentException
     */
    public function chargeInvoiceWithCreditCard($invoice, ?string $creditCardToken = null, ?string $creditCardId = null): Invoice
    {
        if (is_string($invoice)) {
            $invoiceInstance = new Invoice();
            $invoiceInstance->id = $invoice;
            $invoice = $invoiceInstance;
        }

        if (!empty($creditCardToken) && !empty($creditCardId)) {
            throw new MultiPaymentException('"creditCardToken" and "creditCardId" are mutually exclusive');
        }

        if (!empty($creditCardToken)) {
            $invoice->creditCard = new CreditCard();
            $invoice->creditCard->token = $creditCardToken;
        } elseif (!empty($creditCardId)) {
            $invoice->creditCard = new CreditCard();
            $invoice->creditCard->id = $creditCardId;
        }

        if (empty($invoice->creditCard)) {
            throw new MultiPaymentException('"invoice->creditCard" or "creditCardToken" or "creditCardId" must be provided');
        }

        $invoice->gateway = $this->gateway;
        $invoice->creditCard->gateway = $this->gateway;

        return $invoice->chargeInvoiceWithCreditCard();
    }
}
