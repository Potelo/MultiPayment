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
     * @param  CreditCard  $creditCard
     * @param  string|null  $idempotencyKey  idempotency key of the operation; null disables deduplication
     *
     * @return CreditCard
     * @throws GatewayException|GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\UnsupportedOperationException
     */
    public function createCreditCard(CreditCard $creditCard, ?string $idempotencyKey = null): CreditCard;

    /**
     * Get a credit card by its ID
     *
     * @throws GatewayException|GatewayNotAvailableException
     */
    public function getCreditCard(CreditCard $creditCard): CreditCard;

    /**
     * Delete a credit card
     *
     * @param  CreditCard  $creditCard
     * @param  string|null  $idempotencyKey  idempotency key of the operation; null disables deduplication
     * @throws GatewayException|GatewayNotAvailableException
     */
    public function deleteCreditCard(CreditCard $creditCard, ?string $idempotencyKey = null): void;
}
