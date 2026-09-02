<?php

namespace Potelo\MultiPayment\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

class PaymentMethodTest extends TestCase
{
    public function testValuesMatchTheOldInvoiceConstants(): void
    {
        $this->assertSame(Invoice::PAYMENT_METHOD_CREDIT_CARD, PaymentMethod::CREDIT_CARD->value);
        $this->assertSame(Invoice::PAYMENT_METHOD_BANK_SLIP, PaymentMethod::BANK_SLIP->value);
        $this->assertSame(Invoice::PAYMENT_METHOD_PIX, PaymentMethod::PIX->value);
        $this->assertSame('automatic_pix', PaymentMethod::AUTOMATIC_PIX->value);
        $this->assertCount(4, PaymentMethod::cases());
    }

    public function testSelectableExcludesAutomaticPix(): void
    {
        $this->assertSame(
            [PaymentMethod::CREDIT_CARD, PaymentMethod::BANK_SLIP, PaymentMethod::PIX],
            PaymentMethod::selectable()
        );
    }

    public function testNormalizeSelectableConvertsStringsAndKeepsCases(): void
    {
        $this->assertSame(
            [PaymentMethod::PIX, PaymentMethod::CREDIT_CARD],
            PaymentMethod::normalizeSelectable(['pix', PaymentMethod::CREDIT_CARD], 'Invoice')
        );
    }

    public function testNormalizeSelectableRejectsAutomaticPixAndUnknownValues(): void
    {
        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessage('availablePaymentMethods must be one of: credit_card, bank_slip, pix');

        PaymentMethod::normalizeSelectable([PaymentMethod::AUTOMATIC_PIX], 'Invoice');
    }

    public function testNormalizeSelectableRejectsANonArray(): void
    {
        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessage('availablePaymentMethods must be an array of payment methods');

        PaymentMethod::normalizeSelectable('pix', 'Subscription');
    }
}
