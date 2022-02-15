<?php

namespace Potelo\MultiPayment\Traits;

use Potelo\MultiPayment\MultiPayment;
use Potelo\MultiPayment\Helpers\Config;
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
        $gatewayName = $gatewayName ?? Config::get('default');

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
        return Config::get("gateways.$gatewayName.customer_column");
    }
}
