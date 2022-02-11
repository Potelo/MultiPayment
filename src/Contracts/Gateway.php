<?php

namespace  Potelo\MultiPayment\Contracts;

use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Exceptions\GatewayException;

interface Gateway
{

    /**
     * Create a new customer and return the customer id
     *
     * @param  Customer  $customer
     *
     * @return Customer
     * @throws GatewayException
     */
    public function createCustomer(Customer $customer): Customer;

    /**
     * Return the gateway required attributes to create a new Customer
     *
     * @return array
     */
    public function requiredCustomerAttributes(): array;

    /**
     * create a new invoice
     *
     * @param  Invoice  $invoice
     *
     * @return Invoice
     * @throws GatewayException
     */
    public function createInvoice(Invoice $invoice): Invoice;

    /**
     * Return the gateway required attributes to create a new Invoice
     *
     * @return string[]
     */
    public function requiredInvoiceAttributes(): array;
    
}
