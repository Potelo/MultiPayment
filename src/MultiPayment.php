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
     * Verify if it has the payment method
     *
     * @param $paymentMethod
     *
     * @return bool
     */
    private function hasPaymentMethod($paymentMethod): bool
    {
        return in_array($paymentMethod, [
            Invoice::PAYMENT_METHOD_CREDIT_CARD,
            Invoice::PAYMENT_METHOD_BANK_SLIP,
        ]);
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
            if (!array_key_exists('items', $attributes)) {
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
                $customer->save();
            }

            if ($attributes['payment_method'] === Invoice::PAYMENT_METHOD_CREDIT_CARD) {
                $attributes['credit_card']['customer'] = $customer;
            }

            $invoice = new Invoice($this->gateway);
            $invoice->create($attributes);

            return new Response(Response::STATUS_SUCCESS, $invoice->save());
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
        if (!array_key_exists('customer', $attributes)) {
            throw new Exception('The customer is required.');
        }
        if (!array_key_exists('amount', $attributes)
            && !array_key_exists('items', $attributes)) {
            throw new Exception('The amount or items are required.');
        }
        if (!array_key_exists('payment_method', $attributes)) {
            throw new Exception('The payment_method are required.');
        }
        if (!$this->hasPaymentMethod($attributes['payment_method'])) {
            throw new Exception('The payment_method is invalid.');
        }

        if ($attributes['payment_method'] == Invoice::PAYMENT_METHOD_CREDIT_CARD) {
            if (!array_key_exists('credit_card', $attributes)) {
                throw new Exception('The credit_card is required for credit card payment.');
            }
            if (!array_key_exists('id', $attributes['credit_card']) &&
                !array_key_exists('token', $attributes['credit_card']) &&
                (
                    !array_key_exists('year', $attributes['credit_card']) ||
                    !array_key_exists('month', $attributes['credit_card']) ||
                    !array_key_exists('number', $attributes['credit_card']) ||
                    !array_key_exists('cvv', $attributes['credit_card'])
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
