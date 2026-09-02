<?php

namespace Potelo\MultiPayment\Tests\Unit;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\MultiPayment;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Gateways\IuguGateway;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Potelo\MultiPayment\Tests\Unit\Gateways\QueuedIuguApiRequest;

/**
 * Cobre `charge()` por array: `customer` obrigatório antes de qualquer conversão,
 * `payment_method` honrado na escrita, `due_date` e `pix_expires_at` em campos próprios,
 * `expires_at` como alias deprecado de `due_date` e `amount` conferido contra os itens.
 */
class MultiPaymentChargeTest extends TestCase
{
    private QueuedIuguApiRequest $api;

    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment' => [
                'default' => 'iugu',
                'gateways' => ['iugu' => ['api_key' => 'iugu-key', 'class' => IuguGateway::class]],
            ],
        ]));
        Facade::setFacadeApplication($app);

        $this->api = (new QueuedIuguApiRequest([]))->installAsSdkRequester();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        QueuedIuguApiRequest::restoreSdkRequester();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testChargeWithoutCustomerFailsBeforeAnyRequest(): void
    {
        try {
            (new MultiPayment('iugu'))->charge([
                'items' => [['description' => 'Item', 'price' => 10000, 'quantity' => 1]],
                'payment_method' => 'pix',
            ]);
            $this->fail('Esperava ModelAttributeValidationException');
        } catch (ModelAttributeValidationException $e) {
            $this->assertStringContainsString('`customer` attribute is required', $e->getMessage());
        }
        $this->assertSame([], $this->api->calls);
    }

    /**
     * `payment_method` `credit_card` com `credit_card` preenchido e sem
     * `available_payment_methods` cobra o cartão na Iugu: `POST /charge` com o id do cartão e
     * `payable_with` só de cartão.
     */
    public function testChargeHonorsPaymentMethodOnWrite(): void
    {
        $this->queue([(object) ['success' => true, 'invoice_id' => 'inv_1'], $this->pendingInvoiceResponse()]);

        $invoice = (new MultiPayment('iugu'))->charge([
            'customer' => ['id' => 'cus_1', 'name' => 'Cliente', 'email' => 'cliente@example.com'],
            'items' => [['description' => 'Item', 'price' => 10000, 'quantity' => 1]],
            'payment_method' => 'credit_card',
            'credit_card' => ['id' => 'pm_1'],
        ]);

        $this->assertStringEndsWith('/charge', $this->api->calls[0]['url']);
        $this->assertSame('pm_1', $this->api->calls[0]['data']['customer_payment_method_id']);
        $this->assertSame(['credit_card'], $this->api->calls[0]['data']['payable_with']);
        $this->assertSame('inv_1', $invoice->id);
    }

    public function testChargeAcceptsACustomerInstance(): void
    {
        $this->queue([$this->pendingInvoiceResponse()]);
        $customer = new Customer();
        $customer->id = 'cus_1';
        $customer->name = 'Cliente';
        $customer->email = 'cliente@example.com';

        (new MultiPayment('iugu'))->charge([
            'customer' => $customer,
            'items' => [['description' => 'Item', 'price' => 10000, 'quantity' => 1]],
            'payment_method' => 'pix',
        ]);

        $this->assertCount(1, $this->api->calls);
        $this->assertStringEndsWith('/invoices', $this->api->calls[0]['url']);
        $this->assertSame('cus_1', $this->api->calls[0]['data']['customer_id']);
    }

    public function testChargeSendsDueDateAndPixExpiresAtSeparately(): void
    {
        $this->queue([$this->pendingInvoiceResponse()]);

        (new MultiPayment('iugu'))->charge([
            'customer' => ['id' => 'cus_1', 'name' => 'Cliente', 'email' => 'cliente@example.com'],
            'items' => [['description' => 'Item', 'price' => 10000, 'quantity' => 1]],
            'payment_method' => 'pix',
            'due_date' => '2026-10-01',
            'pix_expires_at' => '2026-09-30T18:00:00-03:00',
        ]);

        $payload = $this->api->calls[0]['data'];
        $this->assertSame('2026-10-01', $payload['due_date']);
        $this->assertSame('2026-09-30T18:00:00-03:00', $payload['pix_qr_code_expires_at']);
    }

    #[IgnoreDeprecations]
    public function testChargeStillAcceptsExpiresAtAsADeprecatedAliasOfDueDate(): void
    {
        $this->expectUserDeprecationMessage('Invoice::$expiresAt está obsoleto desde 2026-09-02; use $dueDate (vencimento) ou $pixExpiresAt (expiração do QR Code)');
        $this->queue([$this->pendingInvoiceResponse()]);

        (new MultiPayment('iugu'))->charge([
            'customer' => ['id' => 'cus_1', 'name' => 'Cliente', 'email' => 'cliente@example.com'],
            'items' => [['description' => 'Item', 'price' => 10000, 'quantity' => 1]],
            'expires_at' => '2026-10-01',
        ]);

        $this->assertSame('2026-10-01', $this->api->calls[0]['data']['due_date']);
    }

    public function testChargeRejectsAnAmountThatDiffersFromTheItemsBeforeAnyRequest(): void
    {
        try {
            (new MultiPayment('iugu'))->charge([
                'customer' => ['id' => 'cus_1', 'name' => 'Cliente', 'email' => 'cliente@example.com'],
                'amount' => 10000,
                'items' => [
                    ['description' => 'Produto 1', 'price' => 10000, 'quantity' => 1],
                    ['description' => 'Produto 2', 'price' => 5000, 'quantity' => 2],
                ],
            ]);
            $this->fail('Esperava ModelAttributeValidationException');
        } catch (ModelAttributeValidationException $e) {
            $this->assertStringContainsString('amount [10000] must equal the sum of the items [20000]', $e->getMessage());
        }
        $this->assertSame([], $this->api->calls);
    }

    private function queue(array $responses): void
    {
        $this->api = (new QueuedIuguApiRequest($responses))->installAsSdkRequester();
    }

    private function pendingInvoiceResponse(): object
    {
        return (object) [
            'id' => 'inv_1',
            'status' => 'pending',
            'total_cents' => 10000,
            'paid_cents' => 0,
            'refunded_cents' => 0,
            'due_date' => '2026-10-01',
            'secure_url' => 'https://faturas.iugu.com/inv_1',
            'customer_id' => 'cus_1',
            'customer_name' => 'Cliente',
            'email' => 'cliente@example.com',
            'items' => [(object) ['description' => 'Item', 'price_cents' => 10000, 'quantity' => 1]],
        ];
    }
}
