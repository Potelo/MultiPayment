<?php

namespace Potelo\MultiPayment\Traits;

use Exception;
use Potelo\MultiPayment\MultiPayment;
use Potelo\MultiPayment\Resources\Response;

trait MultiPaymentTrait
{

    /**
     * Charge the user and return the invoice
     *
     * @param  array  $options
     * @param  string|null  $gatewayName
     * @param  int|null  $amount
     *
     * @return Response
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\PropertyValidationException
     */
    public function charge(array $options, ?string $gatewayName = null, ?int $amount = null): Response
    {
        $gatewayName = $gatewayName ?? config('multi-payment.default');

        $payment = new MultiPayment($gatewayName);

        $customerId = $this->getGatewayCustomerId($gatewayName);
        if (!is_null($customerId)) {
            $options['customer']['id'] = $customerId;
        }
        if (!is_null($amount)) {
            $options['amount'] = $amount;
        }
        $response = $payment->charge($options);
        if ($response->success() && is_null($customerId)) {
            $this->setCustomerId($gatewayName, $response->getData()->customer->id);
            $this->save();
        }
        return $response;
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
    private function setCustomerId($gatewayName, $customerId)
    {
        $customerColumn = $this->getGatewayCustomerColumn($gatewayName);
        $this->{$customerColumn} = $customerId;
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
        return config("multi-payment.gateways.$gatewayName.customer_column");
    }
}
