<?php

namespace Potelo\MultiPayment\Tests\Unit;

use Mockery;
use PHPUnit\Framework\TestCase;
use Potelo\MultiPayment\MultiPayment;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Contracts\AutomaticPixContract;
use Potelo\MultiPayment\Contracts\InvoiceCancellationContract;
use Potelo\MultiPayment\Exceptions\MultiPaymentException;

class AutomaticPixTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function testCancelsScheduledAutomaticPixPaymentThroughSupportedGateway(): void
    {
        $response = (object) [
            'success' => true,
            'cancellation_id' => 'd87f02d3-c7bd-4096-b397-867fdae99d10',
        ];

        $gateway = Mockery::mock(GatewayContract::class, AutomaticPixContract::class);
        $gateway->shouldReceive('cancelAutomaticPixScheduledPayment')
            ->once()
            ->with('payment-id', 'end-to-end-id')
            ->andReturn($response);

        $result = (new MultiPayment($gateway))
            ->cancelAutomaticPixScheduledPayment('payment-id', 'end-to-end-id');

        $this->assertSame($response, $result);
    }

    public function testCancelsInvoiceThroughGateway(): void
    {
        $cancelledInvoice = new Invoice();
        $cancelledInvoice->id = 'invoice-id';
        $cancelledInvoice->status = Invoice::STATUS_CANCELED;

        $gateway = Mockery::mock(GatewayContract::class, InvoiceCancellationContract::class);
        $gateway->shouldReceive('cancelInvoice')
            ->once()
            ->with(Mockery::on(fn(Invoice $invoice) => $invoice->id === 'invoice-id'))
            ->andReturn($cancelledInvoice);

        $result = (new MultiPayment($gateway))->cancelInvoice('invoice-id');

        $this->assertSame($cancelledInvoice, $result);
    }

    public function testRejectsInvoiceCancellationForUnsupportedGateway(): void
    {
        $this->expectException(MultiPaymentException::class);

        (new MultiPayment(Mockery::mock(GatewayContract::class)))
            ->cancelInvoice('invoice-id');
    }
}
