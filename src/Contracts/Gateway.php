<?php

namespace  Potelo\MultiPayment\Contracts;

use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Exceptions\GatewayException;

interface Gateway
{

    /**
     * Create a new customer and return the customer
     *
     * @param  Customer  $customer
     *
     * @return Customer
     * @throws GatewayException
     */
    public function createCustomer(Customer $customer): Customer;

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
     * Return one invoice based on the invoice ID
     * 
     * @param string $id
     * 
     * @return Invoice
     * @throws GatewayException
     */
    public function getInvoice(string $id): Invoice;
    
}
