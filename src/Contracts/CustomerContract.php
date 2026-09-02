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
     * @param  string|null  $idempotencyKey  idempotency key of the operation; null disables deduplication
     *
     * @return Customer
     * @throws GatewayException|GatewayNotAvailableException
     */
    public function createCustomer(Customer $customer, ?string $idempotencyKey = null): Customer;

    /**
     * Return one customer based on the customer ID
     *
     * @param  \Potelo\MultiPayment\Models\Customer  $customer
     * @return Customer
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayNotAvailableException
     */
    public function getCustomer(Customer $customer): Customer;

    /**
     * Update an existing customer
     *
     * @param  Customer  $customer
     * @param  string|null  $idempotencyKey  idempotency key of the operation; null disables deduplication
     *
     * @return Customer
     * @throws GatewayException|GatewayNotAvailableException
     */
    public function updateCustomer(Customer $customer, ?string $idempotencyKey = null): Customer;

    /**
     * Set the customer's default card
     *
     * @param  \Potelo\MultiPayment\Models\Customer  $customer
     * @param  string  $cardId
     * @param  string|null  $idempotencyKey  idempotency key of the operation; null disables deduplication
     * @return \Potelo\MultiPayment\Models\Customer
     */
    public function setCustomerDefaultCard(Customer $customer, string $cardId, ?string $idempotencyKey = null): Customer;
}
