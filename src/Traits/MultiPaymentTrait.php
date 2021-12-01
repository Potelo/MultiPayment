<?php

namespace Potelo\MultiPayment\Traits;

use Potelo\MultiPayment\MultiPayment;
use Potelo\MultiPayment\Resources\Response;

trait MultiPaymentTrait
{

    /**
     * Charge the user and return the invoice
     *
     * @param  int|null  $amount
     * @param  array|null  $options
     * @param  string|null  $gatewayName
     * @return Response
     */
    public function charge(?int $amount, ?array $options = [], ?string $gatewayName = null): Response
    {
        try {
            $gatewayName = $gatewayName ?? config('multi-payment.default');

            $payment = new MultiPayment($gatewayName);

            $customerId = $this->getGatewayCustomerId($gatewayName);
            if (! is_null($customerId)) {
                $options['customer']['id'] = $customerId;
            }

            if (! is_null($amount)) {
                $options['amount'] = $amount;
            }
            $response = $payment->charge($options);
            if ($response->success() && is_null($customerId)) {
                $this->setCustomerId($gatewayName, $response->getData()->customer->id);
                $this->save();
            }
            return $response;
        } catch (\Exception $e) {
            return new Response(Response::STATUS_FAILED, $e);
        }
    }

    private function getGatewayCustomerId($gatewayName)
    {
        $customerColumn = $this->getGatewayCustomerColumn($gatewayName);
        return $this->{$customerColumn};
    }

    private function setCustomerId($gatewayName, $customerId)
    {
        $customerColumn = $this->getGatewayCustomerColumn($gatewayName);
        $this->{$customerColumn} = $customerId;
    }

    private function getGatewayCustomerColumn($gatewayName)
    {
        return config("multi-payment.gateways.{$gatewayName}.customer_column");
    }
}
