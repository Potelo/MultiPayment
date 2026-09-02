<?php

namespace Potelo\MultiPayment\Gateways\Stripe;

use Potelo\MultiPayment\Enums\DeclineCode;

/**
 * Tradução dos códigos de recusa da Stripe para `DeclineCode`.
 *
 * Fonte: tabela "Códigos de pagamento recusado do cartão" em
 * https://docs.stripe.com/declines/codes (valores de `decline_code`) e a lista de `code` de
 * erro de cartão em https://docs.stripe.com/error-codes. Os dois campos compartilham nomes
 * (`expired_card`, `incorrect_cvc`, `processing_error`...), então uma única tabela cobre o
 * `decline_code`, lido primeiro, e o `code`, lido quando não há `decline_code`.
 */
final class DeclineCodes
{
    /** @var array<string, DeclineCode> */
    private const MAP = [
        'insufficient_funds' => DeclineCode::INSUFFICIENT_FUNDS,
        'card_velocity_exceeded' => DeclineCode::INSUFFICIENT_FUNDS,
        'withdrawal_count_limit_exceeded' => DeclineCode::INSUFFICIENT_FUNDS,

        'expired_card' => DeclineCode::EXPIRED_CARD,

        'incorrect_cvc' => DeclineCode::INCORRECT_CVC,
        'invalid_cvc' => DeclineCode::INCORRECT_CVC,

        'incorrect_number' => DeclineCode::INCORRECT_NUMBER,
        'invalid_number' => DeclineCode::INCORRECT_NUMBER,

        'invalid_expiry_month' => DeclineCode::INVALID_CARD,
        'invalid_expiry_year' => DeclineCode::INVALID_CARD,
        'invalid_account' => DeclineCode::INVALID_CARD,
        'new_account_information_available' => DeclineCode::INVALID_CARD,
        'incorrect_address' => DeclineCode::INVALID_CARD,
        'incorrect_zip' => DeclineCode::INVALID_CARD,

        'lost_card' => DeclineCode::LOST_OR_STOLEN,
        'stolen_card' => DeclineCode::LOST_OR_STOLEN,
        'pickup_card' => DeclineCode::LOST_OR_STOLEN,
        'restricted_card' => DeclineCode::LOST_OR_STOLEN,

        'fraudulent' => DeclineCode::FRAUD_SUSPECTED,
        'merchant_blacklist' => DeclineCode::FRAUD_SUSPECTED,

        'authentication_required' => DeclineCode::AUTHENTICATION_REQUIRED,
        'authentication_not_handled' => DeclineCode::AUTHENTICATION_REQUIRED,
        'mobile_device_authentication_required' => DeclineCode::AUTHENTICATION_REQUIRED,

        'card_not_supported' => DeclineCode::BRAND_NOT_SUPPORTED,
        'currency_not_supported' => DeclineCode::BRAND_NOT_SUPPORTED,

        'do_not_honor' => DeclineCode::DO_NOT_HONOR,
        'do_not_try_again' => DeclineCode::DO_NOT_HONOR,
        'call_issuer' => DeclineCode::DO_NOT_HONOR,
        'no_action_taken' => DeclineCode::DO_NOT_HONOR,
        'not_permitted' => DeclineCode::DO_NOT_HONOR,
        'revocation_of_all_authorizations' => DeclineCode::DO_NOT_HONOR,
        'revocation_of_authorization' => DeclineCode::DO_NOT_HONOR,
        'security_violation' => DeclineCode::DO_NOT_HONOR,
        'service_not_allowed' => DeclineCode::DO_NOT_HONOR,
        'stop_payment_order' => DeclineCode::DO_NOT_HONOR,
        'transaction_not_allowed' => DeclineCode::DO_NOT_HONOR,

        'processing_error' => DeclineCode::TRY_AGAIN,
        'issuer_not_available' => DeclineCode::TRY_AGAIN,
        'reenter_transaction' => DeclineCode::TRY_AGAIN,
        'try_again_later' => DeclineCode::TRY_AGAIN,
        'approve_with_id' => DeclineCode::TRY_AGAIN,

        'generic_decline' => DeclineCode::GENERIC,
        'card_declined' => DeclineCode::GENERIC,
        'duplicate_transaction' => DeclineCode::GENERIC,
        'invalid_amount' => DeclineCode::GENERIC,
        'testmode_decline' => DeclineCode::GENERIC,
    ];

    /**
     * Traduz o código da Stripe para o vocabulário do pacote. Nulo quando o código não está
     * na tabela, para o driver preservar o original e registrar no log.
     *
     * @param  string|null  $stripeCode  `decline_code` ou, na falta dele, `code` do erro
     * @return DeclineCode|null
     */
    public static function toDeclineCode(?string $stripeCode): ?DeclineCode
    {
        if ($stripeCode === null || $stripeCode === '') {
            return null;
        }

        return self::MAP[$stripeCode] ?? null;
    }

    /**
     * Lê a orientação de nova tentativa que a Stripe envia em `advice_code` junto com a
     * recusa. Nulo quando não há orientação ou ela não fala de nova tentativa
     * (`confirm_card_data`), caso em que vale o padrão do `DeclineCode`.
     *
     * @param  string|null  $adviceCode
     * @return bool|null
     */
    public static function retryableFromAdvice(?string $adviceCode): ?bool
    {
        return match ($adviceCode) {
            'try_again_later' => true,
            'do_not_try_again' => false,
            default => null,
        };
    }
}
