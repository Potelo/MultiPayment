<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways\Stripe;

use PHPUnit\Framework\TestCase;
use Potelo\MultiPayment\Enums\DeclineCode;
use PHPUnit\Framework\Attributes\DataProvider;
use Potelo\MultiPayment\Gateways\Stripe\DeclineCodes;

/**
 * Tabela verdade do mapa de `decline_code` e `code` da Stripe para `DeclineCode` e da
 * orientação de nova tentativa em `advice_code`.
 */
class DeclineCodesTest extends TestCase
{
    #[DataProvider('codeProvider')]
    public function testStripeCodeIsTranslated(string $stripeCode, DeclineCode $expected): void
    {
        $this->assertSame($expected, DeclineCodes::toDeclineCode($stripeCode));
    }

    public static function codeProvider(): array
    {
        return [
            'insufficient_funds' => ['insufficient_funds', DeclineCode::INSUFFICIENT_FUNDS],
            'card_velocity_exceeded' => ['card_velocity_exceeded', DeclineCode::INSUFFICIENT_FUNDS],
            'withdrawal_count_limit_exceeded' => ['withdrawal_count_limit_exceeded', DeclineCode::INSUFFICIENT_FUNDS],
            'expired_card' => ['expired_card', DeclineCode::EXPIRED_CARD],
            'incorrect_cvc' => ['incorrect_cvc', DeclineCode::INCORRECT_CVC],
            'invalid_cvc' => ['invalid_cvc', DeclineCode::INCORRECT_CVC],
            'incorrect_number' => ['incorrect_number', DeclineCode::INCORRECT_NUMBER],
            'invalid_number' => ['invalid_number', DeclineCode::INCORRECT_NUMBER],
            'invalid_expiry_month' => ['invalid_expiry_month', DeclineCode::INVALID_CARD],
            'invalid_account' => ['invalid_account', DeclineCode::INVALID_CARD],
            'incorrect_zip' => ['incorrect_zip', DeclineCode::INVALID_CARD],
            'lost_card' => ['lost_card', DeclineCode::LOST_OR_STOLEN],
            'stolen_card' => ['stolen_card', DeclineCode::LOST_OR_STOLEN],
            'pickup_card' => ['pickup_card', DeclineCode::LOST_OR_STOLEN],
            'restricted_card' => ['restricted_card', DeclineCode::LOST_OR_STOLEN],
            'fraudulent' => ['fraudulent', DeclineCode::FRAUD_SUSPECTED],
            'merchant_blacklist' => ['merchant_blacklist', DeclineCode::FRAUD_SUSPECTED],
            'authentication_required' => ['authentication_required', DeclineCode::AUTHENTICATION_REQUIRED],
            'authentication_not_handled' => ['authentication_not_handled', DeclineCode::AUTHENTICATION_REQUIRED],
            'card_not_supported' => ['card_not_supported', DeclineCode::BRAND_NOT_SUPPORTED],
            'currency_not_supported' => ['currency_not_supported', DeclineCode::BRAND_NOT_SUPPORTED],
            'do_not_honor' => ['do_not_honor', DeclineCode::DO_NOT_HONOR],
            'call_issuer' => ['call_issuer', DeclineCode::DO_NOT_HONOR],
            'transaction_not_allowed' => ['transaction_not_allowed', DeclineCode::DO_NOT_HONOR],
            'security_violation' => ['security_violation', DeclineCode::DO_NOT_HONOR],
            'processing_error' => ['processing_error', DeclineCode::TRY_AGAIN],
            'issuer_not_available' => ['issuer_not_available', DeclineCode::TRY_AGAIN],
            'try_again_later' => ['try_again_later', DeclineCode::TRY_AGAIN],
            'generic_decline' => ['generic_decline', DeclineCode::GENERIC],
            'card_declined (code sem decline_code)' => ['card_declined', DeclineCode::GENERIC],
            'duplicate_transaction' => ['duplicate_transaction', DeclineCode::GENERIC],
        ];
    }

    #[DataProvider('unmappedProvider')]
    public function testUnmappedOrEmptyCodeIsNull(?string $stripeCode): void
    {
        $this->assertNull(DeclineCodes::toDeclineCode($stripeCode));
    }

    public static function unmappedProvider(): array
    {
        return [
            'nulo' => [null],
            'vazio' => [''],
            'PIN (cartão presente)' => ['offline_pin_required'],
            'inexistente' => ['made_up_code'],
        ];
    }

    #[DataProvider('adviceProvider')]
    public function testAdviceCodeDecidesRetryableOnlyWhenItTalksAboutRetrying(?string $advice, ?bool $expected): void
    {
        $this->assertSame($expected, DeclineCodes::retryableFromAdvice($advice));
    }

    public static function adviceProvider(): array
    {
        return [
            'try_again_later' => ['try_again_later', true],
            'do_not_try_again' => ['do_not_try_again', false],
            'confirm_card_data' => ['confirm_card_data', null],
            'ausente' => [null, null],
        ];
    }
}
