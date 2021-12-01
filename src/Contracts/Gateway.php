<?php

namespace  Potelo\MultiPayment\Contracts;

use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;

interface Gateway
{

    /**
     * Create a new customer and return the customer id
     *
     * @param  Customer  $customer
     * @return Customer
     */
    public function createCustomer(Customer $customer): Customer;

    /**
     * create a new invoice
     *
     * @param  Invoice  $invoice
     * @return Invoice
     */
    public function createInvoice(Invoice $invoice): Invoice;

}
