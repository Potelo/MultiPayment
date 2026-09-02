<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Gateways\IuguGateway;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;

class IuguGatewayInvoiceTest extends TestCase
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
        Carbon::setTestNow();
        QueuedIuguApiRequest::restoreSdkRequester();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    /**
     * As `gatewayOptions` entram no payload de `POST /invoices` como campos de primeiro nível e
     * sobrescrevem o que o driver preenche por padrão, como o `expires_in` zerado.
     */
    public function testCreateInvoiceMergesGatewayOptionsIntoIuguPayload(): void
    {
        $api = (new QueuedIuguApiRequest([$this->pendingInvoiceResponse()]))->installAsSdkRequester();

        $invoice = new Invoice();
        $invoice->fill([
            'customer' => ['id' => 'cus_1', 'name' => 'Cliente', 'email' => 'cliente@example.com'],
            'items' => [['description' => 'Item', 'price' => 10000, 'quantity' => 1]],
            'due_date' => '2026-10-01',
            'gateway_options' => ['expires_in' => 5, 'payable_with' => ['bank_slip', 'pix']],
        ]);

        (new IuguGateway($api))->createInvoice($invoice);

        $this->assertCount(1, $api->calls);
        $this->assertSame('POST', $api->calls[0]['method']);
        $this->assertStringEndsWith('/invoices', $api->calls[0]['url']);

        $payload = $api->calls[0]['data'];
        $this->assertSame(5, $payload['expires_in']);
        $this->assertSame(['bank_slip', 'pix'], $payload['payable_with']);
        $this->assertSame('2026-10-01', $payload['due_date']);
        $this->assertSame(['expires_in' => 5, 'payable_with' => ['bank_slip', 'pix']], $invoice->gatewayOptions);
    }

    /**
     * `availablePaymentMethods` vai para a Iugu como `payable_with` de strings; uma string
     * apensada por `[]=` (que entra no array sem conversão) é normalizada antes do envio.
     */
    public function testCreateInvoiceSendsAvailablePaymentMethodsAsIuguStrings(): void
    {
        $api = (new QueuedIuguApiRequest([$this->pendingInvoiceResponse()]))->installAsSdkRequester();

        $invoice = new Invoice();
        $invoice->fill([
            'customer' => ['id' => 'cus_1', 'name' => 'Cliente', 'email' => 'cliente@example.com'],
            'items' => [['description' => 'Item', 'price' => 10000, 'quantity' => 1]],
            'due_date' => '2026-10-01',
        ]);
        $invoice->availablePaymentMethods = [PaymentMethod::BANK_SLIP];
        $invoice->availablePaymentMethods[] = 'pix';

        (new IuguGateway($api))->createInvoice($invoice);

        $this->assertStringEndsWith('/invoices', $api->calls[0]['url']);
        $this->assertSame(['bank_slip', 'pix'], $api->calls[0]['data']['payable_with']);
    }

    /**
     * Cartão em `availablePaymentMethods` com um cartão salvo vai por `POST /charge`, com o
     * id do cartão em `customer_payment_method_id`; a fatura cobrada é lida em seguida.
     */
    public function testCreateInvoiceWithCreditCardChargesTheSavedCard(): void
    {
        $api = (new QueuedIuguApiRequest([
            (object) ['success' => true, 'invoice_id' => 'inv_1'],
            $this->pendingInvoiceResponse(),
        ]))->installAsSdkRequester();

        $invoice = new Invoice();
        $invoice->fill([
            'customer' => ['id' => 'cus_1', 'name' => 'Cliente', 'email' => 'cliente@example.com'],
            'items' => [['description' => 'Item', 'price' => 10000, 'quantity' => 1]],
            'available_payment_methods' => ['credit_card'],
            'credit_card' => ['id' => 'pm_1'],
        ]);

        (new IuguGateway($api))->createInvoice($invoice);

        $this->assertCount(2, $api->calls);
        $this->assertStringEndsWith('/charge', $api->calls[0]['url']);
        $this->assertSame('pm_1', $api->calls[0]['data']['customer_payment_method_id']);
        $this->assertSame(['credit_card'], $api->calls[0]['data']['payable_with']);
        $this->assertStringEndsWith('/invoices/inv_1', $api->calls[1]['url']);
    }

    public function testCreateInvoiceWithoutGatewayOptionsKeepsTheDefaultExpiresIn(): void
    {
        $api = (new QueuedIuguApiRequest([$this->pendingInvoiceResponse()]))->installAsSdkRequester();

        $invoice = new Invoice();
        $invoice->fill([
            'customer' => ['id' => 'cus_1', 'name' => 'Cliente', 'email' => 'cliente@example.com'],
            'items' => [['description' => 'Item', 'price' => 10000, 'quantity' => 1]],
            'due_date' => '2026-10-01',
        ]);

        (new IuguGateway($api))->createInvoice($invoice);

        $payload = $api->calls[0]['data'];
        $this->assertSame(0, $payload['expires_in']);
        $this->assertArrayNotHasKey('payable_with', $payload);
    }

    private function pendingInvoiceResponse(): object
    {
        return (object) [
            'id' => 'inv_1',
            'status' => 'pending',
            'total_cents' => 10000,
            'paid_at' => null,
            'secure_url' => 'https://faturas.iugu.com/inv_1',
            'taxes_paid_cents' => null,
            'created_at_iso' => '2026-09-02T09:00:00-03:00',
            'paid_cents' => 0,
            'refunded_cents' => 0,
            'due_date' => '2026-10-01',
            'payment_method' => null,
            'payable_with' => ['bank_slip', 'pix'],
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

    /**
     * `paymentMethod` de cartão com `availablePaymentMethods` vazia cobra o cartão por
     * `POST /charge`, com `payable_with` só de cartão.
     */
    public function testPaymentMethodAloneWithASavedCardChargesTheCard(): void
    {
        $api = (new QueuedIuguApiRequest([
            (object) ['success' => true, 'invoice_id' => 'inv_1'],
            $this->pendingInvoiceResponse(),
        ]))->installAsSdkRequester();

        $invoice = new Invoice();
        $invoice->fill([
            'customer' => ['id' => 'cus_1', 'name' => 'Cliente', 'email' => 'cliente@example.com'],
            'items' => [['description' => 'Item', 'price' => 10000, 'quantity' => 1]],
            'payment_method' => 'credit_card',
            'credit_card' => ['id' => 'pm_1'],
        ]);

        (new IuguGateway($api))->createInvoice($invoice);

        $this->assertStringEndsWith('/charge', $api->calls[0]['url']);
        $this->assertSame('pm_1', $api->calls[0]['data']['customer_payment_method_id']);
        $this->assertSame(['credit_card'], $api->calls[0]['data']['payable_with']);
    }

    public function testACardAloneChargesTheCard(): void
    {
        $api = (new QueuedIuguApiRequest([
            (object) ['success' => true, 'invoice_id' => 'inv_1'],
            $this->pendingInvoiceResponse(),
        ]))->installAsSdkRequester();

        $invoice = new Invoice();
        $invoice->fill([
            'customer' => ['id' => 'cus_1', 'name' => 'Cliente', 'email' => 'cliente@example.com'],
            'items' => [['description' => 'Item', 'price' => 10000, 'quantity' => 1]],
            'credit_card' => ['id' => 'pm_1'],
        ]);

        (new IuguGateway($api))->createInvoice($invoice);

        $this->assertStringEndsWith('/charge', $api->calls[0]['url']);
        $this->assertSame(['credit_card'], $api->calls[0]['data']['payable_with']);
    }

    /**
     * Cartão como método sem `creditCard` abre a fatura só a cartão por `POST /invoices`, sem
     * cobrança.
     */
    public function testPaymentMethodCardWithoutACardOpensTheInvoiceWithoutCharging(): void
    {
        $api = (new QueuedIuguApiRequest([$this->pendingInvoiceResponse()]))->installAsSdkRequester();

        $invoice = new Invoice();
        $invoice->fill([
            'customer' => ['id' => 'cus_1', 'name' => 'Cliente', 'email' => 'cliente@example.com'],
            'items' => [['description' => 'Item', 'price' => 10000, 'quantity' => 1]],
            'payment_method' => 'credit_card',
        ]);

        (new IuguGateway($api))->createInvoice($invoice);

        $this->assertCount(1, $api->calls);
        $this->assertStringEndsWith('/invoices', $api->calls[0]['url']);
        $this->assertSame(['credit_card'], $api->calls[0]['data']['payable_with']);
    }

    public function testPaymentMethodAloneWithPixOpensThePixInvoice(): void
    {
        $api = (new QueuedIuguApiRequest([$this->pendingInvoiceResponse()]))->installAsSdkRequester();

        $invoice = new Invoice();
        $invoice->fill([
            'customer' => ['id' => 'cus_1', 'name' => 'Cliente', 'email' => 'cliente@example.com'],
            'items' => [['description' => 'Item', 'price' => 10000, 'quantity' => 1]],
            'payment_method' => PaymentMethod::PIX,
        ]);

        (new IuguGateway($api))->createInvoice($invoice);

        $this->assertStringEndsWith('/invoices', $api->calls[0]['url']);
        $this->assertSame(['pix'], $api->calls[0]['data']['payable_with']);
    }

    /**
     * `dueDate` vai em `due_date` (só o dia) e `pixExpiresAt` em `pix_qr_code_expires_at`
     * (ISO 8601 com hora); sem `dueDate`, o vencimento é o dia em que o QR Code expira.
     */
    #[DataProvider('datesProvider')]
    public function testDueDateAndPixExpiresAtGoToTheirOwnIuguFields(array $data, string $dueDate, ?string $pixExpiresAt): void
    {
        Carbon::setTestNow('2026-09-02 10:00:00');
        $api = (new QueuedIuguApiRequest([$this->pendingInvoiceResponse()]))->installAsSdkRequester();

        $invoice = new Invoice();
        $invoice->fill(array_merge([
            'customer' => ['id' => 'cus_1', 'name' => 'Cliente', 'email' => 'cliente@example.com'],
            'items' => [['description' => 'Item', 'price' => 10000, 'quantity' => 1]],
        ], $data));

        (new IuguGateway($api))->createInvoice($invoice);

        $payload = $api->calls[0]['data'];
        $this->assertSame($dueDate, $payload['due_date']);
        $this->assertSame($pixExpiresAt, $payload['pix_qr_code_expires_at'] ?? null);
    }

    public static function datesProvider(): array
    {
        return [
            'so vencimento' => [['due_date' => '2026-10-01'], '2026-10-01', null],
            'vencimento e expiracao do QR' => [
                ['due_date' => '2026-10-01', 'pix_expires_at' => '2026-09-30T18:00:00-03:00'],
                '2026-10-01',
                '2026-09-30T18:00:00-03:00',
            ],
            'so expiracao do QR' => [
                ['pix_expires_at' => '2026-09-30T18:00:00-03:00'],
                '2026-09-30',
                '2026-09-30T18:00:00-03:00',
            ],
            'nenhuma' => [[], '2026-09-02', null],
        ];
    }

    /**
     * O método pedido na escrita fica no model só enquanto a Iugu não informa o método com que
     * a fatura foi paga.
     */
    public function testParseOverwritesTheRequestedPaymentMethodWithTheOneTheInvoiceWasPaidWith(): void
    {
        $paid = $this->pendingInvoiceResponse();
        $paid->status = 'paid';
        $paid->payment_method = 'iugu_credit_card';
        $api = (new QueuedIuguApiRequest([$this->pendingInvoiceResponse(), $paid]))->installAsSdkRequester();
        $gateway = new IuguGateway($api);

        $invoice = new Invoice();
        $invoice->fill([
            'customer' => ['id' => 'cus_1', 'name' => 'Cliente', 'email' => 'cliente@example.com'],
            'items' => [['description' => 'Item', 'price' => 10000, 'quantity' => 1]],
            'payment_method' => 'pix',
            'available_payment_methods' => ['pix', 'credit_card'],
        ]);

        $gateway->createInvoice($invoice);
        $this->assertSame(PaymentMethod::PIX, $invoice->paymentMethod);

        $gateway->getInvoice($invoice);
        $this->assertSame(PaymentMethod::CREDIT_CARD, $invoice->paymentMethod);
    }

    public function testParseReadsDueDateAndKeepsThePixExpiryTheModelHad(): void
    {
        $api = (new QueuedIuguApiRequest([$this->pendingInvoiceResponse()]))->installAsSdkRequester();

        $invoice = new Invoice();
        $invoice->fill([
            'customer' => ['id' => 'cus_1', 'name' => 'Cliente', 'email' => 'cliente@example.com'],
            'items' => [['description' => 'Item', 'price' => 10000, 'quantity' => 1]],
            'pix_expires_at' => '2026-09-30T18:00:00-03:00',
        ]);

        $result = (new IuguGateway($api))->createInvoice($invoice);

        $this->assertSame('2026-10-01', $result->dueDate->format('Y-m-d'));
        $this->assertSame('2026-09-30T18:00:00-03:00', $result->pixExpiresAt->toIso8601String());
    }

    public function testParseReadsThePixExpiryWhenTheInvoiceBringsIt(): void
    {
        $response = $this->pendingInvoiceResponse();
        $response->pix_qr_code_expires_at = '2026-10-01T12:00:00-03:00';
        $api = (new QueuedIuguApiRequest([$response]))->installAsSdkRequester();

        $invoice = new Invoice();
        $invoice->id = 'inv_1';
        $result = (new IuguGateway($api))->getInvoice($invoice);

        $this->assertSame('2026-10-01T12:00:00-03:00', $result->pixExpiresAt->toIso8601String());
        $this->assertSame('2026-10-01', $result->dueDate->format('Y-m-d'));
    }
}
