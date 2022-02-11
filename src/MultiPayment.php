<?php

namespace Potelo\MultiPayment;

use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Contracts\Gateway;
use Potelo\MultiPayment\Resources\Response;
use Potelo\MultiPayment\Exceptions\GatewayException;

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
        if (is_null($gateway)) {
            $gateway = config('multi-payment.default');
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
        if (is_null(config('multi-payment.gateways.'.$name))) {
            throw GatewayException::notConfigured($name);
        }
        $className = config("multi-payment.gateways.$name.class");
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
     * @return Response
     * @throws Exceptions\GatewayException
     * @throws Exceptions\ModelAttributeValidationException
     */
    public function charge(array $attributes): Response
    {
        $invoice = new Invoice($this->gateway);
        $invoice->fill($attributes);
        $invoice->customer = new Customer($this->gateway);
        $invoice->customer->fill($attributes['customer']);
        $invoice->validate();
        if (empty($invoice->customer->id) && !$invoice->customer->save()) {
            return new Response(Response::STATUS_FAILED, $invoice->customer->getErrors());
        }

        if ($invoice->paymentMethod === Invoice::PAYMENT_METHOD_CREDIT_CARD && empty($invoice->creditCard->customer->id)) {
            $invoice->creditCard->customer = $invoice->customer;
        }

        if (!$invoice->save()) {
            return new Response(Response::STATUS_FAILED, $invoice->getErrors());
        } else {
            return new Response(Response::STATUS_SUCCESS, $invoice);
        }
    }

    /**
     * @return Invoice
     * @throws GatewayException
     */
    public function newInvoice(): Invoice
    {
        return new Invoice($this->gateway);
    }

    /**
     * @return Customer
     * @throws GatewayException
     */
    public function newCustomer(): Customer
    {
        return new Customer($this->gateway);
    }
}
