<?php

namespace Potelo\MultiPayment\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Enums\PaymentMethod;
use PHPUnit\Framework\Attributes\DataProvider;

class CapabilityTest extends TestCase
{
    /**
     * Toda capability tem um docblock, que `description()` devolve numa linha só e a tabela do
     * README publica.
     */
    #[DataProvider('capabilityProvider')]
    public function testEveryCapabilityHasAOneLineDescription(Capability $capability): void
    {
        $description = $capability->description();

        $this->assertNotSame('', $description, "{$capability->name} sem docblock");
        $this->assertStringNotContainsString("\n", $description, "{$capability->name} com quebra de linha na descrição");
        $this->assertStringNotContainsString('*', $description, "{$capability->name} com resto de docblock na descrição");
        $this->assertStringEndsWith('.', $description, "{$capability->name} sem ponto final");
    }

    #[DataProvider('capabilityProvider')]
    public function testValueIsTheSnakeCaseOfTheCaseName(Capability $capability): void
    {
        $this->assertSame(strtolower($capability->name), $capability->value);
    }

    public static function capabilityProvider(): array
    {
        $cases = [];
        foreach (Capability::cases() as $capability) {
            $cases[$capability->name] = [$capability];
        }

        return $cases;
    }

    #[DataProvider('paymentMethodProvider')]
    public function testEveryPaymentMethodMapsToACapability(PaymentMethod $paymentMethod, Capability $expected): void
    {
        $this->assertSame($expected, Capability::forPaymentMethod($paymentMethod));
    }

    public static function paymentMethodProvider(): array
    {
        return [
            'cartão' => [PaymentMethod::CREDIT_CARD, Capability::CREDIT_CARD],
            'pix' => [PaymentMethod::PIX, Capability::PIX],
            'boleto' => [PaymentMethod::BANK_SLIP, Capability::BANK_SLIP],
            'pix automático' => [PaymentMethod::AUTOMATIC_PIX, Capability::AUTOMATIC_PIX],
        ];
    }

    public function testDescriptionJoinsAMultilineDocblockIntoOneLine(): void
    {
        $this->assertSame(
            'O gateway agenda as cobranças do Pix Automático por conta própria; sem ela, a aplicação é'
            . ' o motor de recorrência e chama as operações de `AutomaticPixContract` na periodicidade'
            . ' certa.',
            Capability::MANAGES_RECURRENCE->description()
        );
    }

    public function testPaymentMethodMappingCoversEveryCase(): void
    {
        $this->assertCount(count(PaymentMethod::cases()), self::paymentMethodProvider());
    }
}
