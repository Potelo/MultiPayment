<?php

namespace Potelo\MultiPayment\Tests\Unit\Capabilities;

use PHPUnit\Framework\TestCase;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Capabilities\CapabilityRestriction;

class CapabilityRestrictionTest extends TestCase
{
    public function testOnlyTheDescriptionIsRequired(): void
    {
        $restriction = new CapabilityRestriction('Só numa parte dos casos.');

        $this->assertSame('Só numa parte dos casos.', $restriction->description);
        $this->assertNull($restriction->allowedPaymentMethods);
        $this->assertNull($restriction->allowedBrands);
        $this->assertNull($restriction->maxInstallments);
    }

    public function testAllowsPaymentMethodFollowsTheListAndIsTrueWithoutOne(): void
    {
        $byMethod = new CapabilityRestriction('Só Pix.', allowedPaymentMethods: [PaymentMethod::PIX]);
        $unrestricted = new CapabilityRestriction('Outra restrição.', maxInstallments: 12);

        $this->assertTrue($byMethod->allowsPaymentMethod(PaymentMethod::PIX));
        $this->assertFalse($byMethod->allowsPaymentMethod(PaymentMethod::CREDIT_CARD));
        $this->assertTrue($unrestricted->allowsPaymentMethod(PaymentMethod::CREDIT_CARD));
    }

    public function testAllowsBrandIgnoresCaseAndIsTrueWithoutAList(): void
    {
        $byBrand = new CapabilityRestriction('Só Visa e Mastercard.', allowedBrands: ['visa', 'mastercard']);
        $unrestricted = new CapabilityRestriction('Outra restrição.');

        $this->assertTrue($byBrand->allowsBrand('visa'));
        $this->assertTrue($byBrand->allowsBrand('Mastercard'));
        $this->assertFalse($byBrand->allowsBrand('elo'));
        $this->assertTrue($unrestricted->allowsBrand('elo'));
    }

    public function testIsReadOnly(): void
    {
        $restriction = new CapabilityRestriction('Só numa parte dos casos.', maxInstallments: 12);

        $this->expectException(\Error::class);

        $restriction->maxInstallments = 6;
    }
}
