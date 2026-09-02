<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Gateways\IuguGateway;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Enums\InvoiceOriginType;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Tests\Unit\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;

class IuguGatewayInvoiceStatusTest extends TestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment.gateways.iugu.api_key' => 'test-api-key',
        ]));
        $app->instance('log', $this->logger = new RecordingLogger());
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    /**
     * Mapa completo dos onze status oficiais da fatura Iugu, mais `partially_refunded` e
     * `authorized`, que o gateway já conhecia, para o status genérico. Cada status lê como o
     * caso homônimo, exceto `draft` (lê como `PENDING`, junto com `pending`) e `in_analysis`
     * (lê como `AUTHORIZED`, junto com `authorized`).
     */
    public static function statusProvider(): array
    {
        return [
            'pending' => ['pending', InvoiceStatus::PENDING],
            'draft' => ['draft', InvoiceStatus::PENDING],
            'in_analysis' => ['in_analysis', InvoiceStatus::AUTHORIZED],
            'authorized' => ['authorized', InvoiceStatus::AUTHORIZED],
            'paid' => ['paid', InvoiceStatus::PAID],
            'partially_paid' => ['partially_paid', InvoiceStatus::PARTIALLY_PAID],
            'externally_paid' => ['externally_paid', InvoiceStatus::EXTERNALLY_PAID],
            'partially_refunded' => ['partially_refunded', InvoiceStatus::PARTIALLY_REFUNDED],
            'refunded' => ['refunded', InvoiceStatus::REFUNDED],
            'in_protest' => ['in_protest', InvoiceStatus::DISPUTED],
            'chargeback' => ['chargeback', InvoiceStatus::CHARGEBACK],
            'canceled' => ['canceled', InvoiceStatus::CANCELED],
            'expired' => ['expired', InvoiceStatus::EXPIRED],
        ];
    }

    #[DataProvider('statusProvider')]
    public function testMapsEveryIuguInvoiceStatusToTheGenericOne(string $iuguStatus, InvoiceStatus $expected): void
    {
        $this->assertSame($expected, $this->mapStatus($iuguStatus));
        $this->assertSame([], $this->logger->records);
    }

    /**
     * Caminho público: cada status da Iugu passa por `getInvoice()` e chega no model como o
     * caso do enum, com o valor cru preservado em `original`.
     */
    #[DataProvider('statusProvider')]
    public function testGetInvoiceParsesEveryIuguStatusFromTheGatewayResponse(string $iuguStatus, InvoiceStatus $expected): void
    {
        $api = new QueuedIuguApiRequest([$this->invoiceResponse(['status' => $iuguStatus])]);

        $invoice = (new IuguGateway($api))->getInvoice($this->invoiceWithId());

        $this->assertSame($expected, $invoice->status);
        $this->assertSame($iuguStatus, $invoice->original->status);
        $this->assertSame([], $this->logger->records);
    }

    /**
     * Toda fatura da Iugu é lida do objeto de fatura do gateway: `originType` é `INVOICE`.
     */
    public function testGetInvoiceMarksTheOriginAsInvoice(): void
    {
        $api = new QueuedIuguApiRequest([$this->invoiceResponse()]);

        $invoice = (new IuguGateway($api))->getInvoice($this->invoiceWithId());

        $this->assertSame(InvoiceOriginType::INVOICE, $invoice->originType);
        $this->assertSame('invoice', $invoice->toArray()['origin_type']);
    }

    public function testNoIuguStatusIsFlattenedAnymore(): void
    {
        $this->assertSame(InvoiceStatus::PARTIALLY_PAID, $this->mapStatus('partially_paid'));
        $this->assertSame(InvoiceStatus::EXTERNALLY_PAID, $this->mapStatus('externally_paid'));
        $this->assertSame(InvoiceStatus::AUTHORIZED, $this->mapStatus('in_analysis'));
        $this->assertSame(InvoiceStatus::EXPIRED, $this->mapStatus('expired'));
        $this->assertNotSame(InvoiceStatus::PENDING, $this->mapStatus('partially_paid'));
        $this->assertNotSame(InvoiceStatus::CANCELED, $this->mapStatus('expired'));
    }

    public function testInProtestNoLongerReadsAsPaid(): void
    {
        $status = $this->mapStatus('in_protest');

        $this->assertNotSame(InvoiceStatus::PAID, $status);
        $this->assertSame(InvoiceStatus::DISPUTED, $status);
        $this->assertFalse($status->isSettled());
        $this->assertTrue($status->isContested());
    }

    public function testChargebackNoLongerReadsAsRefunded(): void
    {
        $status = $this->mapStatus('chargeback');

        $this->assertNotSame(InvoiceStatus::REFUNDED, $status);
        $this->assertSame(InvoiceStatus::CHARGEBACK, $status);
        $this->assertFalse($status->isSettled());
        $this->assertTrue($status->isContested());
    }

    public function testUnknownStatusBecomesUnknownWithAWarningInsteadOfThrowing(): void
    {
        $api = new QueuedIuguApiRequest([$this->invoiceResponse(['status' => 'status_novo'])]);

        $invoice = (new IuguGateway($api))->getInvoice($this->invoiceWithId());

        $this->assertSame(InvoiceStatus::UNKNOWN, $invoice->status);
        $this->assertSame('status_novo', $invoice->original->status);
        $this->assertCount(1, $this->logger->records);
        $this->assertSame('warning', $this->logger->records[0]['level']);
        $this->assertSame(['status' => 'status_novo', 'gateway' => 'iugu'], $this->logger->records[0]['context']);
        $this->assertFalse($invoice->status->isSettled());
        $this->assertFalse($invoice->status->isOpen());
        $this->assertFalse($invoice->status->isTerminal());
    }

    /**
     * Cada `payment_method` da Iugu chega como o caso do enum; valor desconhecido fica nulo.
     */
    public static function paymentMethodProvider(): array
    {
        return [
            'cartão' => ['iugu_credit_card', PaymentMethod::CREDIT_CARD],
            'boleto' => ['iugu_bank_slip', PaymentMethod::BANK_SLIP],
            'pix' => ['iugu_pix', PaymentMethod::PIX],
            'desconhecido' => ['iugu_novidade', null],
            'ausente' => [null, null],
        ];
    }

    #[DataProvider('paymentMethodProvider')]
    public function testGetInvoiceParsesTheIuguPaymentMethod(?string $iuguPaymentMethod, ?PaymentMethod $expected): void
    {
        $api = new QueuedIuguApiRequest([$this->invoiceResponse(['payment_method' => $iuguPaymentMethod])]);

        $invoice = (new IuguGateway($api))->getInvoice($this->invoiceWithId());

        $this->assertSame($expected, $invoice->paymentMethod);
    }

    /**
     * `payable_with` explícito preenche `availablePaymentMethods` com os métodos pedidos;
     * `all` expande para os três selecionáveis.
     */
    public static function payableWithProvider(): array
    {
        return [
            'lista explícita' => [['bank_slip', 'pix'], [PaymentMethod::BANK_SLIP, PaymentMethod::PIX]],
            'string única' => ['credit_card', [PaymentMethod::CREDIT_CARD]],
            'all' => ['all', [PaymentMethod::CREDIT_CARD, PaymentMethod::BANK_SLIP, PaymentMethod::PIX]],
            'valor desconhecido é ignorado' => [['pix', 'cripto'], [PaymentMethod::PIX]],
        ];
    }

    #[DataProvider('payableWithProvider')]
    public function testGetInvoiceParsesPayableWithIntoAvailablePaymentMethods(array|string $payableWith, array $expected): void
    {
        $api = new QueuedIuguApiRequest([$this->invoiceResponse(['payable_with' => $payableWith])]);

        $invoice = (new IuguGateway($api))->getInvoice($this->invoiceWithId());

        $this->assertSame($expected, $invoice->availablePaymentMethods);
    }

    /**
     * Caminho público: a fatura resumida em `recent_invoices` da assinatura passa pelo mesmo
     * mapa, então uma resposta real da Iugu com `in_protest` chega como `disputed`.
     */
    public function testDisputedInvoiceFromTheGatewayResponseIsParsedAsDisputed(): void
    {
        $subscription = $this->readSubscriptionWithLatestInvoiceStatus('in_protest');

        $this->assertSame(InvoiceStatus::DISPUTED, $subscription->latestInvoice->status);
        $this->assertSame('in_protest', $subscription->latestInvoice->original->status);
    }

    public function testChargebackInvoiceFromTheGatewayResponseIsParsedAsChargeback(): void
    {
        $subscription = $this->readSubscriptionWithLatestInvoiceStatus('chargeback');

        $this->assertSame(InvoiceStatus::CHARGEBACK, $subscription->latestInvoice->status);
        $this->assertSame('chargeback', $subscription->latestInvoice->original->status);
    }

    public static function contestedStatusProvider(): array
    {
        return [
            'in_protest' => ['in_protest'],
            'chargeback' => ['chargeback'],
        ];
    }

    /**
     * Disputa e chargeback não são dívida em aberto: a assinatura não vira `past_due` por
     * causa deles, mesmo com a data de cobrança no passado.
     */
    #[DataProvider('contestedStatusProvider')]
    public function testContestedInvoicesDoNotMakeTheSubscriptionPastDue(string $iuguStatus): void
    {
        $subscription = $this->readSubscriptionWithLatestInvoiceStatus($iuguStatus, '2026-08-01');

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
    }

    private function mapStatus(string $iuguStatus): InvoiceStatus
    {
        $method = new \ReflectionMethod(IuguGateway::class, 'iuguStatusToMultiPayment');

        return $method->invoke(null, $iuguStatus);
    }

    private function invoiceWithId(): Invoice
    {
        $invoice = new Invoice();
        $invoice->id = 'inv_1';

        return $invoice;
    }

    /**
     * Fatura no formato de `GET /v1/invoices/{id}`, com os campos que `parseInvoice()` lê.
     */
    private function invoiceResponse(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 'inv_1',
            'status' => 'paid',
            'total_cents' => 10000,
            'paid_at' => '2026-08-20T10:00:00-03:00',
            'secure_url' => 'https://faturas.iugu.com/inv_1',
            'taxes_paid_cents' => 150,
            'created_at_iso' => '2026-08-20T09:00:00-03:00',
            'paid_cents' => 10000,
            'refunded_cents' => 0,
            'due_date' => '2026-08-25',
            'payment_method' => 'iugu_credit_card',
            'payable_with' => null,
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
        ], $overrides);
    }

    private function readSubscriptionWithLatestInvoiceStatus(string $iuguStatus, string $expiresAt = '2026-10-01'): Subscription
    {
        $api = new QueuedIuguApiRequest([
            (object) [
                'id' => 'sub_1',
                'customer_id' => 'cus_1',
                'plan_identifier' => 'plano_mensal',
                'price_cents' => 10000,
                'expires_at' => $expiresAt,
                'created_at' => '2026-09-01T10:00:00-03:00',
                'active' => true,
                'suspended' => false,
                'in_trial' => false,
                'recent_invoices' => [
                    (object) ['id' => 'inv_1', 'status' => $iuguStatus, 'due_date' => '2026-09-01'],
                ],
            ],
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        return (new IuguGateway($api))->getSubscription($subscription);
    }
}
