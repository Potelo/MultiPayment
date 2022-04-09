<?php

namespace  Potelo\MultiPayment\Contracts;

use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;

interface Gateway
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

    /**
     * create a new invoice
     *
     * @param  Invoice  $invoice
     *
     * @return Invoice
     * @throws GatewayException|GatewayNotAvailableException
     */
    public function createInvoice(Invoice $invoice): Invoice;

    /**
     * Return one invoice based on the invoice ID
     * 
     * @param string $id
     * 
     * @return Invoice
     * @throws GatewayException|GatewayNotAvailableException
     */
    public function getInvoice(string $id): Invoice;

    /**
     * Create a credit card
     *
     * @param CreditCard $creditCard
     *
     * @return CreditCard
     * @throws GatewayException|GatewayNotAvailableException
     */
    public function createCreditCard(CreditCard $creditCard): CreditCard;
    
}
