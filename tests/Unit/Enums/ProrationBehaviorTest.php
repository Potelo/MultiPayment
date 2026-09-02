<?php

namespace Potelo\MultiPayment\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Enums\ProrationBehavior;

class ProrationBehaviorTest extends TestCase
{
    public function testHasTheThreePolicies(): void
    {
        $this->assertSame(
            ['charge_difference', 'none', 'credit'],
            array_column(ProrationBehavior::cases(), 'value')
        );
    }

    #[DataProvider('chargeProvider')]
    public function testFromChargeTranslatesTheOldBoolean(bool $charge, ProrationBehavior $expected): void
    {
        $this->assertSame($expected, ProrationBehavior::fromCharge($charge));
    }

    public static function chargeProvider(): array
    {
        return [
            'true cobra a diferença' => [true, ProrationBehavior::CHARGE_DIFFERENCE],
            'false não cobra' => [false, ProrationBehavior::NONE],
        ];
    }

    public function testResolveReturnsTheEnumAsIsWithoutDeprecation(): void
    {
        foreach (ProrationBehavior::cases() as $behavior) {
            $this->assertSame($behavior, ProrationBehavior::resolve($behavior));
        }
    }

    #[IgnoreDeprecations]
    public function testResolveTranslatesTheBooleanWithADeprecationNotice(): void
    {
        $this->expectUserDeprecationMessage(
            'O booleano $charge de changePlan() está obsoleto desde 2026-09-02; passe'
            . ' ProrationBehavior::CHARGE_DIFFERENCE ou ProrationBehavior::NONE'
        );

        $this->assertSame(ProrationBehavior::NONE, ProrationBehavior::resolve(false));
    }

    /**
     * Só `CREDIT` depende de uma capability além de `SUBSCRIPTIONS`.
     */
    public function testOnlyCreditRequiresACapability(): void
    {
        $this->assertSame(Capability::PLAN_CHANGE_PRORATION, ProrationBehavior::CREDIT->requiredCapability());
        $this->assertNull(ProrationBehavior::CHARGE_DIFFERENCE->requiredCapability());
        $this->assertNull(ProrationBehavior::NONE->requiredCapability());
    }
}
