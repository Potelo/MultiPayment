<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Iugu_APIRequest;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Gateways\IuguGateway;
use Potelo\MultiPayment\Exceptions\GatewayException;

class IuguGatewayAutomaticPixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment.gateways.iugu.api_key' => 'test-api-key',
        ]));
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testCancelsScheduledPaymentWithRequiredQueryParameters(): void
    {
        $apiRequest = new RecordingIuguApiRequest((object) [
            'success' => true,
            'cancellation_id' => 'd87f02d3-c7bd-4096-b397-867fdae99d10',
        ]);

        $result = (new IuguGateway($apiRequest))
            ->cancelAutomaticPixScheduledPayment('payment-id', 'end-to-end-id');

        $this->assertTrue($result->success);
        $this->assertSame('POST', $apiRequest->method);
        $this->assertSame('/v1/automatic_pix/receiver_recurrence_payments/cancel', parse_url($apiRequest->url, PHP_URL_PATH));
        parse_str((string) parse_url($apiRequest->url, PHP_URL_QUERY), $query);
        $this->assertSame([
            'receiver_recurrence_payment_id' => 'payment-id',
            'end_to_end_id' => 'end-to-end-id',
        ], $query);
        $this->assertSame([], $apiRequest->data);
    }

    public function testRejectsUnsuccessfulScheduledPaymentCancellation(): void
    {
        $apiRequest = new RecordingIuguApiRequest((object) [
            'success' => false,
            'errors' => [(object) ['message' => 'Pagamento não pode ser cancelado']],
        ]);

        $this->expectException(GatewayException::class);

        (new IuguGateway($apiRequest))
            ->cancelAutomaticPixScheduledPayment('payment-id', 'end-to-end-id');
    }

    public function testCancelsInvoiceAndReturnsParsedInvoice(): void
    {
        $apiRequest = new RecordingIuguApiRequest($this->cancelledInvoiceResponse());

        $invoice = new Invoice();
        $invoice->id = 'invoice-id';

        $result = (new IuguGateway($apiRequest))->cancelInvoice($invoice);

        $this->assertSame('PUT', $apiRequest->method);
        $this->assertSame('/v1/invoices/invoice-id/cancel', parse_url($apiRequest->url, PHP_URL_PATH));
        $this->assertSame(Invoice::STATUS_CANCELED, $result->status);
        $this->assertSame('invoice-id', $result->id);
    }

    private function cancelledInvoiceResponse(): object
    {
        return (object) [
            'id' => 'invoice-id',
            'status' => 'canceled',
            'total_cents' => 100,
            'paid_at' => null,
            'secure_url' => null,
            'taxes_paid_cents' => null,
            'created_at_iso' => '2026-07-16T10:20:03-03:00',
            'paid_cents' => 0,
            'refunded_cents' => 0,
            'due_date' => '2026-07-17',
            'payment_method' => null,
            'payable_with' => 'pix',
            'customer_id' => 'customer-id',
            'customer_name' => 'Cliente',
            'email' => 'cliente@example.com',
            'payer_phone' => null,
            'payer_phone_prefix' => null,
            'items' => [],
            'payer_address_zip_code' => null,
            'bank_slip' => null,
            'pix' => null,
            'credit_card_transaction' => null,
        ];
    }
}

class RecordingIuguApiRequest extends Iugu_APIRequest
{
    public ?string $method = null;
    public ?string $url = null;
    public array $data = [];

    public function __construct(private object $response)
    {
    }

    public function request($method, $url, $data = [])
    {
        $this->method = $method;
        $this->url = $url;
        $this->data = $data;

        return $this->response;
    }
}
