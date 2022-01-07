<?php

namespace Potelo\MultiPayment;

use Exception;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Contracts\Gateway;
use Potelo\MultiPayment\Resources\Response;

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
     * @throws Exception
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
     * @throws Exception
     */
    public function setGateway(string $name): MultiPayment
    {
        if (is_null(config('multi-payment.gateways.'.$name))) {
            throw new Exception("Gateway [$name] not found");
        }
        $className = config("multi-payment.gateways.$name.class");
        if (!class_exists($className)) {
            throw new Exception("Gateway [$name] not found");
        }
        $gatewayClass = new $className();
        if (!$gatewayClass instanceof Gateway) {
            throw new Exception("Gateway [$className] must implement " . Gateway::class . " interface");
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
     */
    public function charge(array $attributes): Response
    {
        try {
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
        } catch (Exception $e) {
            return new Response(Response::STATUS_FAILED, $e);
        }
    }

    /**
     * @param  array  $attributes
     *
     * @return void
     * @throws Exception
     */
    private function validateAttributes(array $attributes): void
    {
        if (empty($attributes['customer'])) {
            throw new Exception('The customer is required.');
        }
        if (empty($attributes['amount'])
            && empty($attributes['items'])) {
            throw new Exception('The amount or items are required.');
        }
        if (empty($attributes['payment_method'])) {
            throw new Exception('The payment_method are required.');
        }
        if (!in_array($attributes['payment_method'], [
            Invoice::PAYMENT_METHOD_CREDIT_CARD,
            Invoice::PAYMENT_METHOD_BANK_SLIP,
        ])) {
            throw new Exception('The payment_method is invalid.');
        }

        if ($attributes['payment_method'] == Invoice::PAYMENT_METHOD_CREDIT_CARD) {
            if (empty($attributes['credit_card'])) {
                throw new Exception('The credit_card is required for credit card payment.');
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
                throw new Exception('The id or token or number, month, year, cvv are required.');
            }
        }

        if (
            $attributes['payment_method'] == Invoice::PAYMENT_METHOD_BANK_SLIP &&
            empty($attributes['customer']['address'])
        ) {
            throw new Exception('The customer address is required for bank slip payment.');
        }
    }
}
