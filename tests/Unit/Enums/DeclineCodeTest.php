<?php

namespace Potelo\MultiPayment\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use Potelo\MultiPayment\Enums\DeclineCode;
use PHPUnit\Framework\Attributes\DataProvider;

class DeclineCodeTest extends TestCase
{
    public function testHasTheVocabularyOfThePackage(): void
    {
        $this->assertSame([
            'insufficient_funds',
            'expired_card',
            'incorrect_cvc',
            'incorrect_number',
            'invalid_card',
            'lost_or_stolen',
            'fraud_suspected',
            'authentication_required',
            'brand_not_supported',
            'do_not_honor',
            'try_again',
            'generic',
            'unknown',
        ], array_map(fn (DeclineCode $code) => $code->value, DeclineCode::cases()));
    }

    #[DataProvider('retryableProvider')]
    public function testIsRetryableTruthTable(DeclineCode $code, bool $expected): void
    {
        $this->assertSame($expected, $code->isRetryable());
    }

    public static function retryableProvider(): array
    {
        return [
            'insufficient_funds' => [DeclineCode::INSUFFICIENT_FUNDS, true],
            'expired_card' => [DeclineCode::EXPIRED_CARD, false],
            'incorrect_cvc' => [DeclineCode::INCORRECT_CVC, false],
            'incorrect_number' => [DeclineCode::INCORRECT_NUMBER, false],
            'invalid_card' => [DeclineCode::INVALID_CARD, false],
            'lost_or_stolen' => [DeclineCode::LOST_OR_STOLEN, false],
            'fraud_suspected' => [DeclineCode::FRAUD_SUSPECTED, false],
            'authentication_required' => [DeclineCode::AUTHENTICATION_REQUIRED, false],
            'brand_not_supported' => [DeclineCode::BRAND_NOT_SUPPORTED, false],
            'do_not_honor' => [DeclineCode::DO_NOT_HONOR, false],
            'try_again' => [DeclineCode::TRY_AGAIN, true],
            'generic' => [DeclineCode::GENERIC, false],
            'unknown' => [DeclineCode::UNKNOWN, false],
        ];
    }

    #[DataProvider('payerActionProvider')]
    public function testRequiresPayerActionTruthTable(DeclineCode $code, bool $expected): void
    {
        $this->assertSame($expected, $code->requiresPayerAction());
    }

    public static function payerActionProvider(): array
    {
        return [
            'insufficient_funds' => [DeclineCode::INSUFFICIENT_FUNDS, false],
            'expired_card' => [DeclineCode::EXPIRED_CARD, true],
            'incorrect_cvc' => [DeclineCode::INCORRECT_CVC, true],
            'incorrect_number' => [DeclineCode::INCORRECT_NUMBER, true],
            'invalid_card' => [DeclineCode::INVALID_CARD, true],
            'lost_or_stolen' => [DeclineCode::LOST_OR_STOLEN, false],
            'fraud_suspected' => [DeclineCode::FRAUD_SUSPECTED, false],
            'authentication_required' => [DeclineCode::AUTHENTICATION_REQUIRED, true],
            'brand_not_supported' => [DeclineCode::BRAND_NOT_SUPPORTED, true],
            'do_not_honor' => [DeclineCode::DO_NOT_HONOR, false],
            'try_again' => [DeclineCode::TRY_AGAIN, false],
            'generic' => [DeclineCode::GENERIC, false],
            'unknown' => [DeclineCode::UNKNOWN, false],
        ];
    }

    public function testEveryCaseHasAOneLineDocblock(): void
    {
        $reflection = new \ReflectionEnum(DeclineCode::class);
        foreach ($reflection->getCases() as $case) {
            $doc = $case->getDocComment();
            $this->assertIsString($doc, "{$case->getName()} sem docblock");
            $this->assertMatchesRegularExpression('#^/\*\* .+ \*/$#', $doc, "{$case->getName()} com docblock de mais de uma linha");
        }
    }
}
