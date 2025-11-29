<?php

namespace Potelo\MultiPayment\Traits;

use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\MultiPayment;
use Illuminate\Support\Facades\Config;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

trait MultiPaymentTrait
{

    /**
     * Charge the user and return the invoice
     *
     * @param  array  $options
     * @param  string|null  $gatewayName
     * @param  int|null  $amount
     *
     * @return Invoice
     * @throws GatewayException|ModelAttributeValidationException
     */
    public function charge(array $options, ?string $gatewayName = null, ?int $amount = null): Invoice
    {
        $payment = new MultiPayment($gatewayName);

        $customerId = $this->getGatewayCustomerId($gatewayName);
        if (!empty($customerId)) {
            $options['customer']['id'] = $customerId;
        }
        if (!empty($amount)) {
            $options['amount'] = $amount;
        }
        $invoice = $payment->charge($options);
        if (empty($customerId)) {
            $this->setCustomerId($gatewayName, $invoice->customer->id);
        }
        return $invoice;
    }

    /**
     * Get the customer id from the gateway
     *
     * @param $gatewayName
     *
     * @return mixed
     */
    private function getGatewayCustomerId($gatewayName)
    {
        $customerColumn = $this->getGatewayCustomerColumn($gatewayName);
        return $this->{$customerColumn};
    }

    /**
     * Set the customer id of the gateway
     *
     * @param $gatewayName
     * @param $customerId
     *
     * @return void
     * @noinspection PhpUndefinedMethodInspection
     */
    public function setCustomerId($gatewayName, $customerId)
    {
        $customerColumn = $this->getGatewayCustomerColumn($gatewayName);
        $this->{$customerColumn} = $customerId;
        $this->save();
    }

    /**
     * Get the customer id column name
     *
     * @param $gatewayName
     *
     * @return \Illuminate\Config\Repository|\Illuminate\Contracts\Foundation\Application|mixed
     */
    private function getGatewayCustomerColumn($gatewayName)
    {
        return Config::get("multi-payment.gateways.$gatewayName.customer_column");
    }

    /**
     * Set the default credit card of the customer
     */
    public function setDefaultCreditCard(string $gatewayName, string $cardId): void
    {
        $payment = new MultiPayment($gatewayName);
        $payment->setDefaultCard($this->getGatewayCustomerId($gatewayName), $cardId);
    }

    /**
     * Delete a credit card of the customer
     */
    public function deleteCreditCard(string $gatewayName, string $cardId): void
    {
        $payment = new MultiPayment($gatewayName);
        $payment->deleteCard($this->getGatewayCustomerId($gatewayName), $cardId);
    }

    /**
     * Get the default credit card of the customer
     */
    public function defaultCreditCard(string $gatewayName): ?CreditCard
    {
        $payment = new MultiPayment($gatewayName);
        $customer = $payment->getCustomer($this->getGatewayCustomerId($gatewayName));
        return $customer->defaultCard?->refresh($gatewayName);
    }

}
