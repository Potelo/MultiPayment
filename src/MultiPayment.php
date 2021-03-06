<?php

namespace Potelo\MultiPayment;

use Illuminate\Support\Facades\Config;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Contracts\Gateway;
use Potelo\MultiPayment\Builders\InvoiceBuilder;
use Potelo\MultiPayment\Builders\CustomerBuilder;
use Potelo\MultiPayment\Exceptions\GatewayException;
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
     * @param  string|null  $gateway
     *
     * @throws GatewayException
     */
    public function __construct(?string $gateway = null)
    {
        if (empty($gateway)) {
            $gateway = Config::get('multi-payment.default');
        }
        $this->setGateway($gateway);
    }

    /**
     * Set the gateway
     *
     * @param  string  $name
     *
     * @return MultiPayment
     * @throws GatewayException
     */
    public function setGateway(string $name): MultiPayment
    {
        if (empty(Config::get('multi-payment.gateways.'.$name))) {
            throw GatewayException::notConfigured($name);
        }
        $className = Config::get("multi-payment.gateways.$name.class");
        if (!class_exists($className)) {
            throw GatewayException::notFound($name);
        }
        $gatewayClass = new $className();
        if (!$gatewayClass instanceof Gateway) {
            throw GatewayException::invalidInterface($className);
        }
        $this->gateway = $gatewayClass;
        return $this;
    }

    /**
     * Charge a customer
     *
     * @param  array  $attributes
     *
     * @return Invoice
     * @throws GatewayException|ModelAttributeValidationException
     */
    public function charge(array $attributes): Invoice
    {
        $invoice = new Invoice($this->gateway);
        $invoice->fill($attributes);
        $invoice->customer = new Customer($this->gateway);
        $invoice->customer->fill($attributes['customer']);
        $invoice->validate();
        if (empty($invoice->customer->id)) {
            $invoice->customer->save();
        }
        if (!empty($invoice->paymentMethod) && $invoice->paymentMethod === Invoice::PAYMENT_METHOD_CREDIT_CARD && empty($invoice->creditCard->customer->id)) {
            $invoice->creditCard->customer = $invoice->customer;
        }
        $invoice->save();
        return $invoice;
    }

    /**
     * Return an InvoiceBuilder instance
     *
     * @return InvoiceBuilder
     * @throws GatewayException
     */
    public function newInvoice(): InvoiceBuilder
    {
        return new InvoiceBuilder($this->gateway);
    }

    /**
     * Return a CustomerBuilder instance
     *
     * @return CustomerBuilder
     * @throws GatewayException
     */
    public function newCustomer(): CustomerBuilder
    {
        return new CustomerBuilder($this->gateway);
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
