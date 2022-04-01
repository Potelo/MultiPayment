<?php

namespace Potelo\MultiPayment;

use Illuminate\Support\Facades\Config;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Contracts\Gateway;
use Potelo\MultiPayment\Builders\InvoiceBuilder;
use Potelo\MultiPayment\Builders\CustomerBuilder;
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
     * Charge a customer
     *
     * @param  array  $attributes
     *
     * @return Invoice
     * @throws GatewayException|ModelAttributeValidationException|GatewayNotAvailableException
     */
    public function charge(array $attributes): Invoice
    {
        $invoice = new Invoice($this->gateway);
        $invoice->fill($attributes);
        $invoice->customer = new Customer($this->gateway);
        $invoice->customer->fill($attributes['customer']);
        $invoice->validate();
        try {
            if (empty($invoice->customer->id)) {
                $invoice->customer->save();
            }
            if (!empty($invoice->paymentMethod) && $invoice->paymentMethod === Invoice::PAYMENT_METHOD_CREDIT_CARD && empty($invoice->creditCard->customer->id)) {
                $invoice->creditCard->customer = $invoice->customer;
            }
            $invoice->save();
        } catch (GatewayNotAvailableException $e) {
            if (Config::get('multi-payment.fallback')) {
                $nextGateway = ConfigurationHelper::getNextGateway($this->gateway);
                if (!is_null($nextGateway)) {
                    return (new self($nextGateway))->charge($attributes);
                }
            }
            throw $e;
        }

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
