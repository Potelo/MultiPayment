<?php

namespace Potelo\MultiPayment;

use Potelo\MultiPayment\Models\BankSlip;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Contracts\Gateway;
use Potelo\MultiPayment\Models\InvoiceItem;
use Potelo\MultiPayment\Resources\Response;

/**
 * Class MultiPayment
 */
class MultiPayment
{
    public const PAYMENT_METHOD_CREDIT_CARD = 'credit_card';
    public const PAYMENT_METHOD_BANK_SLIP = 'bank_slip';

    public Gateway $gateway;

    /**
     * MultiPayment constructor.
     *
     * @param  string|null  $gateway
     * @throws \Exception
     */
    public function __construct(?string $gateway = null)
    {
        if (is_null($gateway)) {
            $gateway = config('multi-payment.default');
        }
        $this->gateway($gateway);
    }

    /**
     * Verify if has the payment method
     *
     * @param $paymentMethod
     * @return bool
     */
    public function hasPaymentMethod($paymentMethod): bool
    {
        return in_array($paymentMethod, [
            self::PAYMENT_METHOD_CREDIT_CARD,
            self::PAYMENT_METHOD_BANK_SLIP,
        ]);
    }

    /**
     * Set the gateway
     *
     * @param  string  $name
     * @return MultiPayment
     * @throws \Exception
     */
    public function gateway(string $name): MultiPayment
    {
        if (is_null(config('multi-payment.gateways.'.$name))) {
            throw new \Exception("Gateway [{$name}] not found in config");
        }
        $className = config("multi-payment.gateways.$name.class");
        if (!class_exists($className)) {
            throw new \Exception("Gateway [$name] not found");
        }
        $gatewayClass = new $className;
        if (!$gatewayClass instanceof Gateway) {
            throw new \Exception("Gateway [$className] must implement " . Gateway::class . " interface");
        }
        $this->gateway = new $className();
        return $this;
    }

    /**
     * Charge a customer
     *
     * @param  array  $attributes
     * @return Response
     */
    public function charge(array $attributes): Response
    {
        try {
            if (!array_key_exists('customer', $attributes)) {
                throw new \Exception('The customer is required.');
            }
            if (!array_key_exists('id', $attributes['customer'])) {
                $customer = $this->createCustomer($attributes['customer']);
            } else {
                $customer = new Customer();
                $customer->create($attributes['customer']);
            }
            return new Response(Response::STATUS_SUCCESS, $this->createInvoice($attributes, $customer));
        } catch (\Exception $e) {
            return new Response(Response::STATUS_FAILED, $e->getMessage());
        }
    }

    /**
     * Create a Invoice
     *
     * @param  array  $attributes
     * @param  Customer|null  $customer
     * @return Invoice
     * @throws \Exception
     */
    public function createInvoice(array $attributes, ?Customer $customer = null): Invoice
    {
        if (! array_key_exists('amount', $attributes)
            && ! array_key_exists('items', $attributes)) {
            throw new \Exception('The amount or items are required.');
        }
        if (!array_key_exists('payment_method', $attributes)) {
            throw new \Exception('The payment_method are required.');
        }
        if (! $this->hasPaymentMethod($attributes['payment_method'])) {
            throw new \Exception('The payment_method is invalid.');
        }
        if (!array_key_exists('customer', $attributes)) {
            throw new \Exception('The customer is required.');
        }

        if ($attributes['payment_method'] == 'credit_card') {
            if (! array_key_exists('credit_card', $attributes)) {
                throw new \Exception('The credit_card is required for credit card payment.');
            }
            if (!array_key_exists('token', $attributes['credit_card']) &&
                !array_key_exists('year', $attributes['credit_card']) &&
                !array_key_exists('month', $attributes['credit_card']) &&
                !array_key_exists('number', $attributes['credit_card']) &&
                !array_key_exists('cvv', $attributes['credit_card'])
            ) {
                throw new \Exception('The token or number, month, year, cvv are required for credit card payment.');
            }
        }

        if ($attributes['payment_method'] == 'bank_slip' &&
            is_null($customer->address)
        ) {
            throw new \Exception('The customer address is required for bank slip payment.');
        }

        if (! array_key_exists('items', $attributes)
        ) {
            $attributes['items'] = [];

            array_push($attributes['items'], [
                'description' => 'Nova cobrança',
                'quantity' => 1,
                'price' => $attributes['amount'],
            ]);
            unset($attributes['amount']);
        }

        $invoice = new Invoice();
        $invoice->items = [];

        foreach ($attributes['items'] as $item) {
            $invoiceItem = new InvoiceItem();
            $invoiceItem->description = $item['description'];
            $invoiceItem->quantity = $item['quantity'];
            $invoiceItem->price = $item['price'];
            array_push($invoice->items, $invoiceItem);
        }

        $invoice->customer = $customer;
        $invoice->paymentMethod = $attributes['payment_method'];
        $invoice->amount = $attributes['amount'] ?? 0;

        if ($attributes['payment_method'] == self::PAYMENT_METHOD_CREDIT_CARD) {
            if (array_key_exists('name', $attributes) &&
                ! array_key_exists('first_name', $attributes['credit_card']) &&
                ! array_key_exists('last_name', $attributes['credit_card'])) {
                $names = explode(' ', $attributes['name']);
                $attributes['credit_card']['first_name'] = $names[0];
                $attributes['credit_card']['last_name'] = $names[array_key_last($names)];
            }
            $invoice->creditCard = new CreditCard();
            $invoice->creditCard->create($attributes['credit_card']);
        } elseif ($attributes['payment_method'] == self::PAYMENT_METHOD_BANK_SLIP) {
            $invoice->bankSlip = new BankSlip();
            if (array_key_exists('bank_slip', $attributes) &&
                array_key_exists('expiration_date', $attributes['bank_slip'])) {
                $invoice->bankSlip->expirationDate = new \DateTime($attributes['bank_slip']['expiration_date']);
            } else {
                $invoice->bankSlip->expirationDate = new \DateTime();
            }
        }
        return $this->gateway->createInvoice($invoice);
    }

    /**
     * Create a Customer
     *
     * @param  array  $attributes
     * @return Customer
     * @throws \Exception
     */
    public function createCustomer(array $attributes): Customer
    {
        if (!array_key_exists('name', $attributes)) {
            throw new \Exception('The name is required.');
        }
        if (!array_key_exists('email', $attributes)) {
            throw new \Exception('The email is required.');
        }
        $customer = new Customer();
        $customer->create($attributes);
        return $this->gateway->createCustomer($customer);
    }
}
