<?php

namespace  Potelo\MultiPayment\Contracts;

use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;

interface CustomerContract
{

    /**
     * Create a new customer and return the customer
     *
     * @param  Customer  $customer
     *
     * @return Customer
     * @throws GatewayException|GatewayNotAvailableException
     */
    public function createCustomer(Customer $customer): Customer;
}
