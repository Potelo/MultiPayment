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
     * Conclui o salvamento de um cartão que `createCreditCard()` devolveu com `requiresAction`
     * verdadeiro, depois que o pagador autenticou com o emissor. Devolve o cartão cobrável
     * (`id` preenchido) quando a autenticação foi concluída, o cartão ainda com `requiresAction`
     * quando o pagador não a concluiu, e lança `CardDeclinedException` quando o gateway
     * recusou o cartão ou o setup foi cancelado. Gateway sem `CARD_SETUP_AUTHENTICATION` lança
     * `UnsupportedOperationException` antes de qualquer requisição.
     *
     * @param  string  $setupId  `CreditCard::$setupId` devolvido por `createCreditCard()`
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     *
     * @return CreditCard
     * @throws GatewayException|GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\CardDeclinedException
     * @throws \Potelo\MultiPayment\Exceptions\UnsupportedOperationException
     */
    public function confirmCreditCardSetup(string $setupId, ?string $idempotencyKey = null): CreditCard;

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
