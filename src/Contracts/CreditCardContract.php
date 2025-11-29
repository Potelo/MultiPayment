<?php

namespace  Potelo\MultiPayment\Contracts;

use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;

interface CreditCardContract
{
    /**
     * Create a credit card
     *
     * @param CreditCard $creditCard
     *
     * @return CreditCard
     * @throws GatewayException|GatewayNotAvailableException
     */
    public function createCreditCard(CreditCard $creditCard): CreditCard;

    /**
     * Get a credit card by its ID
     *
     * @throws GatewayException|GatewayNotAvailableException
     */
    public function getCreditCard(CreditCard $creditCard): CreditCard;

    /**
     * Delete a credit card
     *
     * @throws GatewayException|GatewayNotAvailableException
     */
    public function deleteCreditCard(CreditCard $creditCard): void;
}
