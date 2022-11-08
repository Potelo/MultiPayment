<?php

namespace Potelo\MultiPayment;

use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Contracts\Gateway;
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

    private Gateway $gateway;

    /**
     * MultiPayment constructor.
     *
     * @param  Gateway|string|null  $gateway
     */
    public function __construct($gateway = null)
    {
        $this->gateway = ConfigurationHelper::resolveGateway($gateway);
    }

    /**
     * @param  Gateway|string|null  $gateway
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
     * @param  string id
     *
     * @return Invoice
     * @throws GatewayException
     */
    public function getInvoice(string $id): Invoice
    {
        return Invoice::get($id, $this->gateway);
    }
}
