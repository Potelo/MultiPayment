<?php

namespace Potelo\MultiPayment\Tests\Unit;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Refund;
use Potelo\MultiPayment\MultiPayment;
use Potelo\MultiPayment\Enums\RefundStatus;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Gateways\IuguGateway;
use Potelo\MultiPayment\Tests\Unit\Gateways\QueuedIuguApiRequest;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Driver da Iugu que recebe o fake de HTTP do teste pelo construtor sem argumentos que
 * `ConfigurationHelper::resolveGateway()` usa.
 */
class RefundTestIuguGateway extends IuguGateway
{
    public static ?QueuedIuguApiRequest $api = null;

    public function __construct()
    {
        parent::__construct(self::$api);
    }
}

class RefundTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment' => [
                'default' => 'iugu',
                'gateways' => [
                    'iugu' => ['api_key' => 'test-api-key', 'class' => RefundTestIuguGateway::class],
                ],
            ],
        ]));
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        RefundTestIuguGateway::$api = null;
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testStatusAcceptsTheStringAndReadsAsTheEnum(): void
    {
        $refund = new Refund();
        $refund->status = 'succeeded';

        $this->assertSame(RefundStatus::SUCCEEDED, $refund->status);
        $this->assertTrue(isset($refund->status));
    }

    public function testFillConvertsSnakeCaseKeysAndTheStatus(): void
    {
        $refund = new Refund();
        $refund->fill(['invoice_id' => 'inv_1', 'amount' => 500, 'status' => 'pending', 'reason' => 'duplicado']);

        $this->assertSame('inv_1', $refund->invoiceId);
        $this->assertSame(500, $refund->amount);
        $this->assertSame(RefundStatus::PENDING, $refund->status);
        $this->assertSame('duplicado', $refund->reason);
    }

    public function testToArrayEmitsSnakeCaseKeysAndTheStatusValue(): void
    {
        $refund = new Refund();
        $refund->id = 're_1';
        $refund->invoiceId = 'inv_1';
        $refund->amount = 500;
        $refund->status = RefundStatus::SUCCEEDED;
        $refund->gateway = 'stripe';

        $this->assertSame([
            'id' => 're_1',
            'invoice_id' => 'inv_1',
            'amount' => 500,
            'status' => 'succeeded',
            'gateway' => 'stripe',
        ], $refund->toArray());
    }

    public function testInvoiceReturnsTheLoadedInvoiceWithoutARequest(): void
    {
        RefundTestIuguGateway::$api = new QueuedIuguApiRequest([]);
        $invoice = new Invoice();
        $invoice->id = 'inv_1';
        $refund = new Refund();
        $refund->invoiceId = 'inv_1';
        $refund->gateway = 'iugu';
        $refund->invoice = $invoice;

        $this->assertSame($invoice, $refund->invoice());
        $this->assertSame([], RefundTestIuguGateway::$api->calls);
    }

    public function testInvoiceReadsTheInvoiceFromTheGatewayOnceWhenNotLoaded(): void
    {
        RefundTestIuguGateway::$api = new QueuedIuguApiRequest([$this->partiallyRefundedInvoiceResponse()]);
        $refund = new Refund();
        $refund->invoiceId = 'inv_1';
        $refund->gateway = 'iugu';

        $invoice = $refund->invoice();

        $this->assertSame('inv_1', $invoice->id);
        $this->assertSame(InvoiceStatus::PARTIALLY_REFUNDED, $invoice->status);
        $this->assertSame(3000, $invoice->refundedAmount);
        $this->assertCount(1, RefundTestIuguGateway::$api->calls);
        $this->assertSame('GET', RefundTestIuguGateway::$api->calls[0]['method']);
        $this->assertStringEndsWith('/invoices/inv_1', RefundTestIuguGateway::$api->calls[0]['url']);

        $this->assertSame($invoice, $refund->invoice(), 'a segunda chamada devolve a fatura guardada');
        $this->assertCount(1, RefundTestIuguGateway::$api->calls);
        $this->assertSame($invoice, $refund->invoice);
    }

    public function testInvoiceRequiresTheInvoiceId(): void
    {
        $refund = new Refund();
        $refund->gateway = 'iugu';

        $this->expectException(ModelAttributeValidationException::class);

        $refund->invoice();
    }

    /**
     * Um `Refund` de `Invoice::$refunds` não aponta de volta para a fatura, senão a
     * serialização entraria em ciclo.
     */
    public function testInvoiceWithRefundsSerializesWithoutACycle(): void
    {
        RefundTestIuguGateway::$api = new QueuedIuguApiRequest([$this->partiallyRefundedInvoiceResponse()]);

        $invoice = (new RefundTestIuguGateway())->getInvoice($this->invoiceWithId());
        $json = json_decode(json_encode($invoice), true);

        $this->assertIsArray($json);
        $this->assertCount(1, $json['refunds']);
        $this->assertSame(3000, $json['refunds'][0]['amount']);
        $this->assertSame('succeeded', $json['refunds'][0]['status']);
        $this->assertNull($json['refunds'][0]['invoice']);
        $this->assertSame('inv_1', $invoice->toArray()['refunds'][0]->invoiceId);
    }

    public function testMultiPaymentRejectsAZeroOrNegativePartialValueBeforeAnyRequest(): void
    {
        RefundTestIuguGateway::$api = new QueuedIuguApiRequest([]);

        foreach ([0, -100] as $value) {
            try {
                (new MultiPayment('iugu'))->refundInvoice('inv_1', $value);
                $this->fail("Esperava ModelAttributeValidationException para {$value}");
            } catch (ModelAttributeValidationException $e) {
                $this->assertStringContainsString('amount', $e->getMessage());
            }
        }

        $this->assertSame([], RefundTestIuguGateway::$api->calls);
    }

    private function invoiceWithId(): Invoice
    {
        $invoice = new Invoice();
        $invoice->id = 'inv_1';

        return $invoice;
    }

    private function partiallyRefundedInvoiceResponse(): object
    {
        return (object) [
            'id' => 'inv_1',
            'status' => 'partially_refunded',
            'total_cents' => 10000,
            'paid_at' => '2026-08-20T10:00:00-03:00',
            'secure_url' => 'https://faturas.iugu.com/inv_1',
            'taxes_paid_cents' => 250,
            'created_at_iso' => '2026-08-20T09:00:00-03:00',
            'paid_cents' => 7000,
            'refunded_cents' => 3000,
            'due_date' => '2026-08-25',
            'payment_method' => 'iugu_credit_card',
            'payable_with' => 'credit_card',
            'customer_id' => 'cus_1',
            'customer_name' => 'Cliente',
            'email' => 'cliente@example.com',
            'payer_phone' => null,
            'payer_phone_prefix' => null,
            'items' => [
                (object) ['description' => 'Item', 'price_cents' => 10000, 'quantity' => 1],
            ],
            'payer_address_zip_code' => null,
            'bank_slip' => null,
            'pix' => null,
            'automatic_pix' => null,
            'credit_card_transaction' => null,
            'variables' => [],
        ];
    }
}
