<?php

namespace Potelo\MultiPayment\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Enums\InvoiceOriginType;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

class InvoiceOriginTypeTest extends TestCase
{
    public function testHasTheTwoOrigins(): void
    {
        $this->assertSame(['payment_intent', 'invoice'], array_column(InvoiceOriginType::cases(), 'value'));
    }

    public function testInvoiceAcceptsTheStringOrTheCaseAndAlwaysReturnsTheCase(): void
    {
        $invoice = new Invoice();
        $this->assertNull($invoice->originType);

        $invoice->originType = 'payment_intent';
        $this->assertSame(InvoiceOriginType::PAYMENT_INTENT, $invoice->originType);

        $invoice->originType = InvoiceOriginType::INVOICE;
        $this->assertSame(InvoiceOriginType::INVOICE, $invoice->originType);
        $this->assertSame('invoice', $invoice->toArray()['origin_type']);
        $this->assertSame('invoice', json_decode(json_encode($invoice), true)['originType']);
    }

    public function testUnknownOriginIsRejectedOnWrite(): void
    {
        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessage('originType must be one of: payment_intent, invoice');

        $invoice = new Invoice();
        $invoice->originType = 'checkout_session';
    }
}
