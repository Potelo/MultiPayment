<?php

namespace Potelo\MultiPayment\Tests\Unit;

use Mockery;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Potelo\MultiPayment\MultiPayment;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\AutomaticPix;
use Potelo\MultiPayment\Models\AutomaticPixCancellation;
use Potelo\MultiPayment\Contracts\GatewayContract;

class AutomaticPixTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function testBuildsAutomaticPixAsPartOfInvoice(): void
    {
        $gateway = Mockery::mock(GatewayContract::class);

        $invoice = (new MultiPayment($gateway))->newInvoice()
            ->addAutomaticPix(
                AutomaticPix::AUTHORIZATION_TYPE_QR_CODE_WITH_PAYMENT,
                AutomaticPix::FREQUENCY_MONTHLY,
                '2026-08-01',
                'contract-123',
                '2027-08-01',
                AutomaticPix::RETRY_POLICY_ALLOWED
            )
            ->get();

        $this->assertInstanceOf(AutomaticPix::class, $invoice->automaticPix);
        $this->assertSame(
            AutomaticPix::AUTHORIZATION_TYPE_QR_CODE_WITH_PAYMENT,
            $invoice->automaticPix->authorizationType
        );
        $this->assertSame(AutomaticPix::FREQUENCY_MONTHLY, $invoice->automaticPix->frequency);
        $this->assertSame('2026-08-01', $invoice->automaticPix->startsAt->format('Y-m-d'));
        $this->assertSame('contract-123', $invoice->automaticPix->contractReference);
        $this->assertSame('2027-08-01', $invoice->automaticPix->endsAt->format('Y-m-d'));
        $this->assertSame(AutomaticPix::RETRY_POLICY_ALLOWED, $invoice->automaticPix->retryPolicy);
    }

    public function testFillsAutomaticPixModelFromInvoiceAttributes(): void
    {
        $invoice = new Invoice();
        $invoice->fill([
            'automatic_pix' => [
                'authorization_type' => AutomaticPix::AUTHORIZATION_TYPE_QR_CODE_WITH_RECURRENCE_OFFER,
                'frequency' => AutomaticPix::FREQUENCY_WEEKLY,
                'starts_at' => '2026-08-01',
                'contract_reference' => 'contract-456',
                'retry_policy' => AutomaticPix::RETRY_POLICY_NOT_ALLOWED,
            ],
        ]);

        $this->assertInstanceOf(AutomaticPix::class, $invoice->automaticPix);
        $this->assertSame(
            AutomaticPix::AUTHORIZATION_TYPE_QR_CODE_WITH_RECURRENCE_OFFER,
            $invoice->automaticPix->authorizationType
        );
        $this->assertSame('contract-456', $invoice->automaticPix->contractReference);
        $this->assertInstanceOf(Carbon::class, $invoice->automaticPix->startsAt);
    }

    public function testCancelsScheduledAutomaticPixPaymentThroughScalarContract(): void
    {
        $cancellation = new AutomaticPixCancellation();
        $cancellation->id = 'cancellation-id';

        $gateway = Mockery::mock(GatewayContract::class);
        $gateway->shouldReceive('cancelAutomaticPixScheduledPayment')
            ->once()
            ->with('payment-id', 'end-to-end-id')
            ->andReturn($cancellation);

        $result = (new MultiPayment($gateway))
            ->cancelAutomaticPixScheduledPayment('payment-id', 'end-to-end-id');

        $this->assertSame($cancellation, $result);
    }

    public function testCancelsAutomaticPixRecurrenceThroughModelContract(): void
    {
        $cancellation = new AutomaticPixCancellation();
        $gateway = Mockery::mock(GatewayContract::class);
        $gateway->shouldReceive('cancelAutomaticPixRecurrence')
            ->once()
            ->with(Mockery::on(fn (AutomaticPix $automaticPix) => $automaticPix->id === 'recurrence-id'))
            ->andReturn($cancellation);

        $result = (new MultiPayment($gateway))->cancelAutomaticPixRecurrence('recurrence-id');

        $this->assertSame($cancellation, $result);
    }

    public function testReschedulesAutomaticPixPaymentThroughInvoiceContract(): void
    {
        $invoice = new Invoice();
        $invoice->id = 'invoice-id';

        $gateway = Mockery::mock(GatewayContract::class);
        $gateway->shouldReceive('rescheduleAutomaticPixPayment')
            ->once()
            ->with(Mockery::on(fn (Invoice $model) => $model->id === 'invoice-id'))
            ->andReturn($invoice);

        $result = (new MultiPayment($gateway))->rescheduleAutomaticPixPayment('invoice-id');

        $this->assertSame($invoice, $result);
    }

    public function testGetsAndListsMappedCancellations(): void
    {
        $cancellation = new AutomaticPixCancellation();
        $cancellation->id = 'cancellation-id';
        $cancellation->recurrenceId = 'recurrence-id';

        $gateway = Mockery::mock(GatewayContract::class);
        $gateway->shouldReceive('getAutomaticPixCancellation')
            ->once()
            ->with(Mockery::on(fn (AutomaticPixCancellation $model) =>
                $model->id === 'cancellation-id' && $model->recurrenceId === 'recurrence-id'
            ))
            ->andReturn($cancellation);
        $gateway->shouldReceive('listAutomaticPixCancellations')
            ->once()
            ->with(Mockery::on(fn (AutomaticPix $model) => $model->id === 'recurrence-id'), 2, 25)
            ->andReturn([$cancellation]);

        $multiPayment = new MultiPayment($gateway);

        $this->assertSame(
            $cancellation,
            $multiPayment->getAutomaticPixCancellation('recurrence-id', 'cancellation-id')
        );
        $this->assertSame(
            [$cancellation],
            $multiPayment->listAutomaticPixCancellations('recurrence-id', 2, 25)
        );
    }

    public function testCancelsInvoiceThroughGateway(): void
    {
        $cancelledInvoice = new Invoice();
        $cancelledInvoice->id = 'invoice-id';
        $cancelledInvoice->status = Invoice::STATUS_CANCELED;

        $gateway = Mockery::mock(GatewayContract::class);
        $gateway->shouldReceive('cancelInvoice')
            ->once()
            ->with(Mockery::on(fn (Invoice $invoice) => $invoice->id === 'invoice-id'))
            ->andReturn($cancelledInvoice);

        $result = (new MultiPayment($gateway))->cancelInvoice('invoice-id');

        $this->assertSame($cancelledInvoice, $result);
    }
}
