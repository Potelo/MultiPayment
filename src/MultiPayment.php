<?php

namespace Potelo\MultiPayment;

use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Contracts\Gateway;
use Potelo\MultiPayment\Resources\Response;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\PropertyValidationException;

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
     * @throws PropertyValidationException|Exceptions\GatewayException
     */
    public function charge(array $attributes): Response
    {
        $this->validateAttributes($attributes);
        if (empty($attributes['items'])) {
            $attributes['items'] = [];

            $attributes['items'][] = [
                'description' => 'Nova cobrança',
                'quantity' => 1,
                'price' => $attributes['amount'],
            ];
            unset($attributes['amount']);
        }

        $customer = new Customer($this->gateway);
        $customer->fill($attributes['customer']);
        $attributes['customer'] = $customer;
        if (empty($customer->id)) {
            if (!$customer->save()) {
                return new Response(Response::STATUS_FAILED, $customer->getErrors());
            }
        }

        if ($attributes['payment_method'] === Invoice::PAYMENT_METHOD_CREDIT_CARD) {
            $attributes['credit_card']['customer'] = $customer;
        }

        $invoice = new Invoice($this->gateway);
        $invoice->fill($attributes);

        if (!$invoice->save()) {
            return new Response(Response::STATUS_FAILED, $invoice->getErrors());
        } else {
            return new Response(Response::STATUS_SUCCESS, $invoice);
        }
    }

    /**
     * @param  array  $attributes
     *
     * @return void
     * @throws PropertyValidationException
     */
    private function validateAttributes(array $attributes): void
    {
        if (empty($attributes['customer'])) {
            throw new PropertyValidationException('The customer is required.');
        }
        if (empty($attributes['amount'])
            && empty($attributes['items'])) {
            throw new PropertyValidationException('The amount or items are required.');
        }
        if (empty($attributes['payment_method'])) {
            throw new PropertyValidationException('The payment_method are required.');
        }
        if (Invoice::hasPaymentMethod($attributes['payment_method'])) {
            throw new PropertyValidationException('The payment_method is invalid.');
        }

        if ($attributes['payment_method'] == Invoice::PAYMENT_METHOD_CREDIT_CARD) {
            if (empty($attributes['credit_card'])) {
                throw new PropertyValidationException('The credit_card is required for credit card payment.');
            }
            if (empty($attributes['credit_card']['id']) &&
                empty($attributes['credit_card']['token']) &&
                (
                    empty($attributes['credit_card']['year']) ||
                    empty($attributes['credit_card']['month']) ||
                    empty($attributes['credit_card']['number']) ||
                    empty($attributes['credit_card']['cvv'])
                )
            ) {
                throw new PropertyValidationException('The id or token or number, month, year, cvv are required.');
            }
        }

        if (($attributes['payment_method'] == Invoice::PAYMENT_METHOD_BANK_SLIP) && empty($attributes['customer']['address'])) {
            throw new PropertyValidationException('The customer address is required for bank slip payment method');
        }
    }
}
