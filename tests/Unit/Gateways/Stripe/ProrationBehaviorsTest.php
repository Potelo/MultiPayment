<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways\Stripe;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Potelo\MultiPayment\Enums\ProrationBehavior;
use Potelo\MultiPayment\Gateways\Stripe\ProrationBehaviors;

/**
 * Mapa de `ProrationBehavior` para o `proration_behavior` da Stripe, um caso por política.
 */
class ProrationBehaviorsTest extends TestCase
{
    #[DataProvider('behaviorProvider')]
    public function testEachPolicyHasItsStripeValue(ProrationBehavior $behavior, string $expected): void
    {
        $this->assertSame($expected, ProrationBehaviors::toStripe($behavior));
    }

    public static function behaviorProvider(): array
    {
        return [
            'charge_difference fatura na hora' => [ProrationBehavior::CHARGE_DIFFERENCE, 'always_invoice'],
            'none não cria pró-rata' => [ProrationBehavior::NONE, 'none'],
            'credit deixa o crédito para a próxima fatura' => [ProrationBehavior::CREDIT, 'create_prorations'],
        ];
    }

    public function testEveryPolicyIsMapped(): void
    {
        foreach (ProrationBehavior::cases() as $behavior) {
            $this->assertNotSame('', ProrationBehaviors::toStripe($behavior));
        }
    }
}
