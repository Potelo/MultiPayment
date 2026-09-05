<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Dispute;
use Potelo\MultiPayment\Gateways\IuguGateway;
use Potelo\MultiPayment\Enums\DisputeStatus;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Idempotency\InMemoryIdempotencyStore;
use Potelo\MultiPayment\Exceptions\IdempotencyConflictException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;
use Potelo\MultiPayment\Tests\Unit\RecordingLogger;

/**
 * Operações de contestação do driver Iugu: busca, listagem, contestar com arquivos e acatar,
 * mais o preenchimento de `Invoice::$disputes` na leitura de fatura contestada. A sandbox da
 * Iugu não produz contestação, então as respostas destes testes são montadas com os campos da
 * documentação de Listar Contestação (`id`, `invoice_id`, `status`, `expires_at`).
 */
class IuguGatewayDisputeTest extends TestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment.gateways.iugu.api_key' => 'test-api-key',
            'multi-payment.gateways.iugu.class' => IuguGateway::class,
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

    public function testGetDisputeParsesTheChargeback(): void
    {
        $api = new QueuedIuguApiRequest([self::chargebackResponse()]);

        $dispute = new Dispute();
        $dispute->id = 'chb_1';
        $result = (new IuguGateway($api))->getDispute($dispute);

        $this->assertSame('GET', $api->calls[0]['method']);
        $this->assertStringContainsString('/chargebacks/chb_1', $api->calls[0]['url']);

        $this->assertSame($dispute, $result);
        $this->assertSame('chb_1', $result->id);
        $this->assertSame('inv_1', $result->invoiceId);
        $this->assertSame(DisputeStatus::OPEN, $result->status);
        $this->assertInstanceOf(Carbon::class, $result->dueBy);
        $this->assertSame('2026-09-20', $result->dueBy->format('Y-m-d'));
        $this->assertSame('2026-09-05', $result->openedAt->format('Y-m-d'));
        // a Iugu não documenta o valor nem a data do desfecho na contestação
        $this->assertNull($result->amount);
        $this->assertNull($result->closedAt);
        $this->assertSame('iugu', $result->gateway);
        $this->assertSame([], $this->logger->records);
    }

    public function testGetDisputeWithoutIdIsRefusedBeforeAnyRequest(): void
    {
        $api = new QueuedIuguApiRequest([]);

        try {
            (new IuguGateway($api))->getDispute(new Dispute());
            $this->fail('Era esperada ModelAttributeValidationException');
        } catch (ModelAttributeValidationException) {
        }

        $this->assertSame([], $api->calls);
    }

    /**
     * Mapa dos onze status oficiais de contestação da Iugu para o genérico.
     */
    public static function statusProvider(): array
    {
        return [
            'pending' => ['pending', DisputeStatus::OPEN],
            'error' => ['error', DisputeStatus::OPEN],
            'processing_file' => ['processing_file', DisputeStatus::UNDER_REVIEW],
            'contested_by_client' => ['contested_by_client', DisputeStatus::UNDER_REVIEW],
            'waiting_resolution' => ['waiting_resolution', DisputeStatus::UNDER_REVIEW],
            'won' => ['won', DisputeStatus::WON],
            'reverted' => ['reverted', DisputeStatus::WON],
            'lost' => ['lost', DisputeStatus::LOST],
            'accepted' => ['accepted', DisputeStatus::ACCEPTED],
            'accepted_by_client' => ['accepted_by_client', DisputeStatus::ACCEPTED],
            'accepted_automatically' => ['accepted_automatically', DisputeStatus::ACCEPTED],
        ];
    }

    #[DataProvider('statusProvider')]
    public function testMapsEveryIuguChargebackStatusToTheGenericOne(string $iuguStatus, DisputeStatus $expected): void
    {
        $api = new QueuedIuguApiRequest([self::chargebackResponse(['status' => $iuguStatus])]);

        $dispute = new Dispute();
        $dispute->id = 'chb_1';

        $this->assertSame($expected, (new IuguGateway($api))->getDispute($dispute)->status);
        $this->assertSame([], $this->logger->records);
    }

    public function testAnUnknownChargebackStatusBecomesUnknownWithAWarning(): void
    {
        $api = new QueuedIuguApiRequest([self::chargebackResponse(['status' => 'status_novo'])]);

        $dispute = new Dispute();
        $dispute->id = 'chb_1';

        $this->assertSame(DisputeStatus::UNKNOWN, (new IuguGateway($api))->getDispute($dispute)->status);
        $this->assertCount(1, $this->logger->records);
        $this->assertSame('warning', $this->logger->records[0]['level']);
    }

    public function testListDisputesPaginatesAndParsesEachChargeback(): void
    {
        $api = new QueuedIuguApiRequest([
            (object) ['totalItems' => 2, 'items' => [
                self::chargebackResponse(),
                self::chargebackResponse(['id' => 'chb_2', 'status' => 'lost']),
            ]],
        ]);

        $disputes = (new IuguGateway($api))->listDisputes(2, 50);

        $this->assertSame('GET', $api->calls[0]['method']);
        $this->assertStringContainsString('/chargebacks?limit=50&start=50', $api->calls[0]['url']);

        $this->assertCount(2, $disputes);
        $this->assertInstanceOf(Dispute::class, $disputes[0]);
        $this->assertSame(DisputeStatus::OPEN, $disputes[0]->status);
        $this->assertSame(DisputeStatus::LOST, $disputes[1]->status);
    }

    public function testListDisputesValidatesPageAndLimitBeforeAnyRequest(): void
    {
        $api = new QueuedIuguApiRequest([]);

        try {
            (new IuguGateway($api))->listDisputes(0);
            $this->fail('Era esperada ModelAttributeValidationException');
        } catch (ModelAttributeValidationException) {
        }

        try {
            (new IuguGateway($api))->listDisputes(1, 101);
            $this->fail('Era esperada ModelAttributeValidationException');
        } catch (ModelAttributeValidationException) {
        }

        $this->assertSame([], $api->calls);
    }

    /**
     * O `PUT /contest` leva os arquivos em base64; quando a resposta não traz a contestação,
     * ela é relida por GET.
     */
    public function testContestDisputeSendsTheFilesAndRereadsWhenTheResponseIsBare(): void
    {
        $api = new QueuedIuguApiRequest([
            (object) ['success' => true],
            self::chargebackResponse(['status' => 'contested_by_client']),
        ]);

        $result = (new IuguGateway($api))->contestDispute('chb_1', ['file_1' => 'dGVzdGU=']);

        $this->assertCount(2, $api->calls);
        $this->assertSame('PUT', $api->calls[0]['method']);
        $this->assertStringContainsString('/chargebacks/chb_1/contest', $api->calls[0]['url']);
        $this->assertSame(['file_1' => 'dGVzdGU='], $api->calls[0]['data']);
        $this->assertSame('GET', $api->calls[1]['method']);
        $this->assertStringContainsString('/chargebacks/chb_1', $api->calls[1]['url']);

        $this->assertSame(DisputeStatus::UNDER_REVIEW, $result->status);
    }

    public function testContestDisputeParsesTheResponseWhenItBringsTheChargeback(): void
    {
        $api = new QueuedIuguApiRequest([self::chargebackResponse(['status' => 'contested_by_client'])]);

        $result = (new IuguGateway($api))->contestDispute('chb_1', ['file_1' => 'dGVzdGU=']);

        $this->assertCount(1, $api->calls);
        $this->assertSame(DisputeStatus::UNDER_REVIEW, $result->status);
    }

    public function testAcceptDisputeUsesTheAcceptPath(): void
    {
        $api = new QueuedIuguApiRequest([self::chargebackResponse(['status' => 'accepted_by_client'])]);

        $result = (new IuguGateway($api))->acceptDispute('chb_1');

        $this->assertSame('PUT', $api->calls[0]['method']);
        $this->assertStringContainsString('/chargebacks/chb_1/accept', $api->calls[0]['url']);
        $this->assertSame(DisputeStatus::ACCEPTED, $result->status);
        $this->assertTrue($result->status->isLost());
    }

    /**
     * A Iugu não aceita `Idempotency-Key` nos endpoints de contestação: com chave, a operação
     * inteira (ação e releitura) passa pela `IdempotencyStore` e a repetição devolve o
     * resultado guardado sem nova requisição.
     */
    public function testContestDisputeWithAKeyGoesThroughTheStoreOnce(): void
    {
        $api = new QueuedIuguApiRequest([self::chargebackResponse(['status' => 'contested_by_client'])]);
        $gateway = new IuguGateway($api, new InMemoryIdempotencyStore());

        $first = $gateway->contestDispute('chb_1', ['file_1' => 'dGVzdGU='], 'contest-1');
        $second = $gateway->contestDispute('chb_1', ['file_1' => 'dGVzdGU='], 'contest-1');

        $this->assertCount(1, $api->calls);
        $this->assertSame(DisputeStatus::UNDER_REVIEW, $first->status);
        $this->assertSame(DisputeStatus::UNDER_REVIEW, $second->status);
    }

    public function testGetInvoiceFillsTheDisputesOfAContestedInvoice(): void
    {
        $api = new QueuedIuguApiRequest([
            self::invoiceResponse(['status' => 'in_protest']),
            (object) ['totalItems' => 2, 'items' => [
                self::chargebackResponse(),
                self::chargebackResponse(['id' => 'chb_2', 'invoice_id' => 'inv_2']),
            ]],
        ]);

        $invoice = (new IuguGateway($api))->getInvoice(self::invoiceWithId());

        $this->assertSame(InvoiceStatus::DISPUTED, $invoice->status);
        $this->assertStringContainsString('/chargebacks?limit=100', $api->calls[1]['url']);
        // só as contestações da própria fatura entram na lista
        $this->assertCount(1, $invoice->disputes);
        $this->assertSame('chb_1', $invoice->disputes[0]->id);
        $this->assertSame('inv_1', $invoice->disputes[0]->invoiceId);
        $this->assertSame([], $this->logger->records);
    }

    /**
     * A listagem da conta é paginada: página cheia (100 itens) leva à seguinte, e a página
     * incompleta encerra a busca.
     */
    public function testGetInvoiceFollowsThePaginationUntilAnIncompletePage(): void
    {
        $fullPage = array_map(
            fn (int $index) => self::chargebackResponse(['id' => "chb_o{$index}", 'invoice_id' => 'inv_outra']),
            range(1, 100)
        );
        $api = new QueuedIuguApiRequest([
            self::invoiceResponse(['status' => 'in_protest']),
            (object) ['totalItems' => 101, 'items' => $fullPage],
            (object) ['totalItems' => 101, 'items' => [self::chargebackResponse()]],
        ]);

        $invoice = (new IuguGateway($api))->getInvoice(self::invoiceWithId());

        $this->assertCount(3, $api->calls);
        $this->assertStringContainsString('/chargebacks?limit=100&start=0', $api->calls[1]['url']);
        $this->assertStringContainsString('/chargebacks?limit=100&start=100', $api->calls[2]['url']);
        $this->assertCount(1, $invoice->disputes);
        $this->assertSame('chb_1', $invoice->disputes[0]->id);
        $this->assertSame([], $this->logger->records);
    }

    public function testAcceptDisputeWithAKeyGoesThroughTheStoreOnce(): void
    {
        $api = new QueuedIuguApiRequest([self::chargebackResponse(['status' => 'accepted_by_client'])]);
        $gateway = new IuguGateway($api, new InMemoryIdempotencyStore());

        $first = $gateway->acceptDispute('chb_1', 'accept-1');
        $second = $gateway->acceptDispute('chb_1', 'accept-1');

        $this->assertCount(1, $api->calls);
        $this->assertSame(DisputeStatus::ACCEPTED, $first->status);
        $this->assertSame(DisputeStatus::ACCEPTED, $second->status);
    }

    /**
     * A store guarda a operação junto do resultado: a mesma chave em contestar e depois
     * acatar conflita em vez de devolver o resultado da outra operação.
     */
    public function testTheSameKeyOnContestAndThenAcceptConflicts(): void
    {
        $api = new QueuedIuguApiRequest([self::chargebackResponse(['status' => 'contested_by_client'])]);
        $gateway = new IuguGateway($api, new InMemoryIdempotencyStore());

        $gateway->contestDispute('chb_1', ['file_1' => 'dGVzdGU='], 'chave-1');

        $this->expectException(IdempotencyConflictException::class);
        $gateway->acceptDispute('chb_1', 'chave-1');
    }

    public function testGetInvoiceLeavesDisputesNullWhenTheChargebackQueryFails(): void
    {
        $api = new QueuedIuguApiRequest([
            self::invoiceResponse(['status' => 'chargeback']),
            (object) ['errors' => 'internal error'],
        ]);

        $invoice = (new IuguGateway($api))->getInvoice(self::invoiceWithId());

        $this->assertSame(InvoiceStatus::CHARGEBACK, $invoice->status);
        $this->assertNull($invoice->disputes);
        $this->assertCount(1, $this->logger->records);
        $this->assertSame('warning', $this->logger->records[0]['level']);
        $this->assertSame('inv_1', $this->logger->records[0]['context']['invoice_id']);
    }

    public function testGetInvoiceDoesNotQueryChargebacksWhenTheInvoiceIsNotContested(): void
    {
        $api = new QueuedIuguApiRequest([self::invoiceResponse(['status' => 'paid'])]);

        $invoice = (new IuguGateway($api))->getInvoice(self::invoiceWithId());

        $this->assertSame(InvoiceStatus::PAID, $invoice->status);
        $this->assertNull($invoice->disputes);
        $this->assertCount(1, $api->calls);
    }

    private static function invoiceWithId(): Invoice
    {
        $invoice = new Invoice();
        $invoice->id = 'inv_1';

        return $invoice;
    }

    /**
     * Contestação no formato montado da documentação de Listar Contestação; a sandbox não
     * produz uma resposta real.
     */
    private static function chargebackResponse(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 'chb_1',
            'invoice_id' => 'inv_1',
            'status' => 'pending',
            'expires_at' => '2026-09-20T23:59:59-03:00',
            'created_at' => '2026-09-05T10:00:00-03:00',
        ], $overrides);
    }

    /**
     * Fatura no formato de `GET /v1/invoices/{id}`, com os campos que `parseInvoice()` lê.
     */
    private static function invoiceResponse(array $overrides = []): object
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
}
