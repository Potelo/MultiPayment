<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Enums\InvoiceOriginType;
use Potelo\MultiPayment\Gateways\IuguGateway;
use Potelo\MultiPayment\Listing\InvoiceFilter;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Listagem de faturas no driver Iugu: a tradução do `InvoiceFilter` para os parâmetros de
 * `GET /v1/invoices`, o mapa de status do filtro, a paginação por deslocamento com o total da
 * resposta e as recusas antes da rede (filtro por assinatura, origem `PAYMENT_INTENT`, status
 * sem equivalente).
 */
class IuguGatewayListInvoicesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment' => [
                'default' => 'iugu',
                'gateways' => ['iugu' => ['api_key' => 'test-api-key', 'class' => IuguGateway::class]],
            ],
        ]));
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    private function invoiceResponse(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 'inv_1',
            'status' => 'pending',
            'total_cents' => 10000,
            'paid_cents' => null,
            'customer_id' => 'cus_1',
            'subscription_id' => null,
            'due_date' => '2026-09-10',
            'created_at_iso' => '2026-09-01T10:00:00-03:00',
            'currency' => 'BRL',
        ], $overrides);
    }

    public function testListInvoicesMapsTheFilterToTheIuguQuery(): void
    {
        $api = new QueuedIuguApiRequest([
            (object) ['totalItems' => 7, 'items' => [
                $this->invoiceResponse(),
                $this->invoiceResponse(['id' => 'inv_2', 'subscription_id' => 'sub_9']),
            ]],
        ]);

        $list = (new IuguGateway($api))->listInvoices(new InvoiceFilter(
            customerId: 'cus_1',
            status: InvoiceStatus::PENDING,
            createdAfter: Carbon::parse('2026-09-01T00:00:00-03:00'),
            createdBefore: Carbon::parse('2026-09-30T23:59:59-03:00'),
            dueAfter: Carbon::parse('2026-09-05'),
            dueBefore: Carbon::parse('2026-09-20'),
            limit: 2,
            page: 3
        ));

        parse_str((string) parse_url($api->calls[0]['url'], PHP_URL_QUERY), $query);
        $this->assertSame([
            'customer_id' => 'cus_1',
            'status_filter' => 'pending',
            'created_at_from' => '2026-09-01T00:00:00-03:00',
            'created_at_to' => '2026-09-30T23:59:59-03:00',
            'due_date_from' => '2026-09-05',
            'due_date_to' => '2026-09-20',
            'limit' => '2',
            'start' => '4',
        ], $query);

        $this->assertSame(7, $list->total);
        $this->assertTrue($list->hasMore);
        $this->assertSame('6', $list->nextCursor);
        $this->assertSame('6', $list->nextPageFilter()->cursor);
        $this->assertCount(2, $list);
        $this->assertSame(InvoiceStatus::PENDING, $list[0]->status);
        $this->assertSame(InvoiceOriginType::INVOICE, $list[0]->originType);
        $this->assertSame('sub_9', $list[1]->subscriptionId);
    }

    /**
     * Resposta sem `totalItems` deixa o total nulo e deduz a página seguinte de uma página
     * cheia, como na listagem de assinaturas.
     */
    public function testAResponseWithoutTheTotalFallsBackToTheFullPageHeuristic(): void
    {
        $api = new QueuedIuguApiRequest([
            (object) ['items' => [$this->invoiceResponse(), $this->invoiceResponse(['id' => 'inv_2'])]],
        ]);

        $list = (new IuguGateway($api))->listInvoices(new InvoiceFilter(limit: 2));

        $this->assertNull($list->total);
        $this->assertTrue($list->hasMore);
        $this->assertSame('2', $list->nextCursor);
    }

    public function testTheLastPageHasNoNextCursor(): void
    {
        $api = new QueuedIuguApiRequest([
            (object) ['totalItems' => 3, 'items' => [$this->invoiceResponse(['id' => 'inv_3'])]],
        ]);

        $list = (new IuguGateway($api))->listInvoices(new InvoiceFilter(limit: 2, cursor: '2'));

        $this->assertStringContainsString('start=2', $api->calls[0]['url']);
        $this->assertSame(3, $list->total);
        $this->assertFalse($list->hasMore);
        $this->assertNull($list->nextCursor);
        $this->assertNull($list->nextPageFilter());
    }

    /**
     * Cada status filtrável vira o valor homônimo de `status_filter`; `AUTHORIZED` filtra
     * `in_analysis` e `PENDING` filtra `pending`.
     */
    #[DataProvider('statusFilterProvider')]
    public function testTheStatusFilterMapsToTheIuguValue(InvoiceStatus $status, string $iuguValue): void
    {
        $api = new QueuedIuguApiRequest([(object) ['totalItems' => 0, 'items' => []]]);

        (new IuguGateway($api))->listInvoices(new InvoiceFilter(status: $status));

        $this->assertStringContainsString("status_filter={$iuguValue}", $api->calls[0]['url']);
    }

    public static function statusFilterProvider(): array
    {
        return [
            'pending' => [InvoiceStatus::PENDING, 'pending'],
            'authorized' => [InvoiceStatus::AUTHORIZED, 'in_analysis'],
            'paid' => [InvoiceStatus::PAID, 'paid'],
            'partially_paid' => [InvoiceStatus::PARTIALLY_PAID, 'partially_paid'],
            'externally_paid' => [InvoiceStatus::EXTERNALLY_PAID, 'externally_paid'],
            'partially_refunded' => [InvoiceStatus::PARTIALLY_REFUNDED, 'partially_refunded'],
            'refunded' => [InvoiceStatus::REFUNDED, 'refunded'],
            'disputed' => [InvoiceStatus::DISPUTED, 'in_protest'],
            'chargeback' => [InvoiceStatus::CHARGEBACK, 'chargeback'],
            'canceled' => [InvoiceStatus::CANCELED, 'canceled'],
            'expired' => [InvoiceStatus::EXPIRED, 'expired'],
        ];
    }

    public function testProcessingStatusIsRejectedBeforeTheNetwork(): void
    {
        $api = new QueuedIuguApiRequest([]);

        try {
            (new IuguGateway($api))->listInvoices(new InvoiceFilter(status: InvoiceStatus::PROCESSING));
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::INVOICE_LISTING, $e->capability);
            $this->assertSame(UnsupportedOperationException::REASON_GATEWAY_LIMITATION, $e->reason);
        }
        $this->assertCount(0, $api->calls);
    }

    public function testTheSubscriptionFilterIsRejectedBeforeTheNetwork(): void
    {
        $api = new QueuedIuguApiRequest([]);

        try {
            (new IuguGateway($api))->listInvoices(new InvoiceFilter(subscriptionId: 'sub_1'));
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::INVOICE_LISTING, $e->capability);
            $this->assertStringContainsString('não filtra fatura por assinatura', $e->getMessage());
        }
        $this->assertCount(0, $api->calls);
    }

    public function testThePaymentIntentOriginIsRejectedBeforeTheNetwork(): void
    {
        $api = new QueuedIuguApiRequest([]);

        try {
            (new IuguGateway($api))->listInvoices(new InvoiceFilter(originType: InvoiceOriginType::PAYMENT_INTENT));
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::INVOICE_LISTING, $e->capability);
        }
        $this->assertCount(0, $api->calls);
    }

    public function testTheInvoiceOriginIsAcceptedWithoutEffect(): void
    {
        $api = new QueuedIuguApiRequest([(object) ['totalItems' => 0, 'items' => []]]);

        $list = (new IuguGateway($api))->listInvoices(new InvoiceFilter(originType: InvoiceOriginType::INVOICE));

        $this->assertTrue($list->isEmpty());
        $this->assertCount(1, $api->calls);
    }

    public function testAForeignCursorIsRejectedBeforeTheNetwork(): void
    {
        $api = new QueuedIuguApiRequest([]);

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/not a cursor produced by the iugu gateway/');

        (new IuguGateway($api))->listInvoices(new InvoiceFilter(cursor: 'in_abc'));
    }
}
