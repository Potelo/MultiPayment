<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Carbon\Carbon;
use Stripe\ApiRequestor;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Enums\InvoiceOriginType;
use Potelo\MultiPayment\Gateways\StripeGateway;
use Potelo\MultiPayment\Listing\InvoiceFilter;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Listagem de faturas no driver Stripe: a exigência de `originType` no filtro, a tradução do
 * `InvoiceFilter` para `invoices.list` e `paymentIntents.list`, o mapa de status do filtro, a
 * paginação por cursor e o GET a mais por fatura de origem `INVOICE` com PaymentIntent. As
 * respostas em `tests/fixtures/stripe/` foram gravadas na sandbox (ver o README da pasta).
 */
class StripeGatewayListInvoicesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment.gateways.stripe.api_key' => 'sk_test_fake',
        ]));
        Facade::setFacadeApplication($app);

        RecordingStripeHttpClient::withResponses([]);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testTheOriginTypeIsRequiredBeforeTheNetwork(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);

        try {
            (new StripeGateway())->listInvoices(new InvoiceFilter(customerId: 'cus_fake123'));
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::INVOICE_LISTING, $e->capability);
            $this->assertSame(UnsupportedOperationException::REASON_GATEWAY_LIMITATION, $e->reason);
            $this->assertStringContainsString('originType', $e->getMessage());
        }
        $this->assertSame([], $httpClient->calls);
    }

    public function testTheInvoiceOriginMapsTheFilterToInvoicesList(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            [
                'object' => 'list',
                'url' => '/v1/invoices',
                'has_more' => true,
                'data' => [self::fixture('invoices/open_requires_payment_method')],
            ],
        ]);

        $list = (new StripeGateway())->listInvoices(new InvoiceFilter(
            customerId: 'cus_fake123',
            subscriptionId: 'sub_fake1',
            status: InvoiceStatus::PENDING,
            createdAfter: Carbon::createFromTimestamp(1756700000),
            createdBefore: Carbon::createFromTimestamp(1759300000),
            dueAfter: Carbon::parse('2026-09-05 13:45'),
            dueBefore: Carbon::parse('2026-09-20 09:30'),
            originType: InvoiceOriginType::INVOICE,
            limit: 25
        ));

        $params = $httpClient->calls[0][2];
        $this->assertSame('cus_fake123', $params['customer']);
        $this->assertSame('sub_fake1', $params['subscription']);
        $this->assertSame('open', $params['status']);
        $this->assertSame(1756700000, $params['created']['gte']);
        $this->assertSame(1759300000, $params['created']['lte']);
        // o vencimento do filtro é um dia: a hora informada é descartada e a janela cobre os
        // dois dias inteiros, para a fatura que vence neles entrar como entra na Iugu
        $this->assertSame(Carbon::parse('2026-09-05 00:00:00')->getTimestamp(), $params['due_date']['gte']);
        $this->assertSame(Carbon::parse('2026-09-20 23:59:59')->getTimestamp(), $params['due_date']['lte']);
        $this->assertSame(25, $params['limit']);
        $this->assertSame(['data.payments'], $params['expand']);

        $this->assertCount(1, $list);
        $this->assertSame(InvoiceOriginType::INVOICE, $list[0]->originType);
        $this->assertSame(InvoiceStatus::PENDING, $list[0]->status);
        $this->assertNull($list->total);
        $this->assertTrue($list->hasMore);
        $this->assertSame($list[0]->id, $list->nextCursor);
    }

    /**
     * O charge fica fora do limite de níveis do expand na listagem, então a fatura cujo
     * PaymentIntent já tem charge é completada com um GET no PaymentIntent, como em
     * `getInvoice()`.
     */
    public function testAListedInvoiceWithAChargedPaymentIntentCostsOneMoreGet(): void
    {
        $paidInvoice = self::fixture('invoices/paid');
        $httpClient = RecordingStripeHttpClient::withResponses([
            ['object' => 'list', 'url' => '/v1/invoices', 'has_more' => false, 'data' => [$paidInvoice]],
            self::fixture('payment_intents/paid'),
        ]);

        $list = (new StripeGateway())->listInvoices(new InvoiceFilter(originType: InvoiceOriginType::INVOICE));

        $this->assertCount(2, $httpClient->calls);
        $this->assertStringContainsString('/v1/payment_intents/', $httpClient->calls[1][1]);
        $this->assertSame(InvoiceStatus::PAID, $list[0]->status);
    }

    /**
     * O vínculo com a assinatura vem de `parent.subscription_details.subscription`, que a
     * Stripe entrega como id ou como o objeto expandido.
     */
    public function testAListedInvoiceCarriesTheSubscriptionId(): void
    {
        $byId = self::fixture('invoices/open_requires_payment_method');
        $byId['parent'] = ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_fake9']];
        $expanded = self::fixture('invoices/open_requires_payment_method');
        $expanded['id'] = 'in_expandida';
        $expanded['parent'] = [
            'type' => 'subscription_details',
            'subscription_details' => ['subscription' => ['id' => 'sub_fake10', 'object' => 'subscription']],
        ];
        $httpClient = RecordingStripeHttpClient::withResponses([
            ['object' => 'list', 'url' => '/v1/invoices', 'has_more' => false, 'data' => [$byId, $expanded]],
        ]);

        $list = (new StripeGateway())->listInvoices(new InvoiceFilter(originType: InvoiceOriginType::INVOICE));

        $this->assertSame('sub_fake9', $list[0]->subscriptionId);
        $this->assertSame('sub_fake10', $list[1]->subscriptionId);
    }

    #[DataProvider('invoiceStatusFilterProvider')]
    public function testTheInvoiceStatusFilterMapsToTheStripeValue(InvoiceStatus $status, string $stripeValue): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            ['object' => 'list', 'url' => '/v1/invoices', 'has_more' => false, 'data' => []],
        ]);

        (new StripeGateway())->listInvoices(new InvoiceFilter(status: $status, originType: InvoiceOriginType::INVOICE));

        $this->assertSame($stripeValue, $httpClient->calls[0][2]['status']);
    }

    public static function invoiceStatusFilterProvider(): array
    {
        return [
            'pending' => [InvoiceStatus::PENDING, 'open'],
            'paid' => [InvoiceStatus::PAID, 'paid'],
            'canceled' => [InvoiceStatus::CANCELED, 'void'],
            'expired' => [InvoiceStatus::EXPIRED, 'uncollectible'],
        ];
    }

    #[DataProvider('unfilterableStatusProvider')]
    public function testAPaymentRefinementStatusIsRejectedBeforeTheNetwork(InvoiceStatus $status): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);

        try {
            (new StripeGateway())->listInvoices(new InvoiceFilter(status: $status, originType: InvoiceOriginType::INVOICE));
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::INVOICE_LISTING, $e->capability);
        }
        $this->assertSame([], $httpClient->calls);
    }

    public static function unfilterableStatusProvider(): array
    {
        return [
            'refunded' => [InvoiceStatus::REFUNDED],
            'disputed' => [InvoiceStatus::DISPUTED],
            'processing' => [InvoiceStatus::PROCESSING],
            'partially_paid' => [InvoiceStatus::PARTIALLY_PAID],
        ];
    }

    public function testThePaymentIntentOriginMapsTheFilterToPaymentIntentsList(): void
    {
        $pi = self::fixture('payment_intents/paid');
        $httpClient = RecordingStripeHttpClient::withResponses([
            ['object' => 'list', 'url' => '/v1/payment_intents', 'has_more' => true, 'data' => [$pi]],
        ]);

        $list = (new StripeGateway())->listInvoices(new InvoiceFilter(
            customerId: 'cus_fake123',
            createdAfter: Carbon::createFromTimestamp(1756700000),
            originType: InvoiceOriginType::PAYMENT_INTENT,
            limit: 10
        ));

        $params = $httpClient->calls[0][2];
        $this->assertSame('cus_fake123', $params['customer']);
        $this->assertSame(1756700000, $params['created']['gte']);
        $this->assertSame(10, $params['limit']);
        $this->assertSame(
            ['data.latest_charge.balance_transaction', 'data.latest_charge.refunds'],
            $params['expand']
        );

        $this->assertCount(1, $httpClient->calls);
        $this->assertSame(InvoiceOriginType::PAYMENT_INTENT, $list[0]->originType);
        $this->assertSame($pi['id'], $list[0]->id);
        $this->assertTrue($list->hasMore);
        $this->assertSame($pi['id'], $list->nextCursor);
    }

    #[DataProvider('paymentIntentUnsupportedFilterProvider')]
    public function testThePaymentIntentOriginRejectsFiltersItCannotSend(InvoiceFilter $filter): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);

        try {
            (new StripeGateway())->listInvoices($filter);
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::INVOICE_LISTING, $e->capability);
        }
        $this->assertSame([], $httpClient->calls);
    }

    public static function paymentIntentUnsupportedFilterProvider(): array
    {
        return [
            'status' => [new InvoiceFilter(status: InvoiceStatus::PAID, originType: InvoiceOriginType::PAYMENT_INTENT)],
            'assinatura' => [new InvoiceFilter(subscriptionId: 'sub_1', originType: InvoiceOriginType::PAYMENT_INTENT)],
            'vencimento' => [new InvoiceFilter(dueAfter: Carbon::parse('2026-09-01'), originType: InvoiceOriginType::PAYMENT_INTENT)],
        ];
    }

    public function testTheCursorIsSentAsStartingAfter(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            ['object' => 'list', 'url' => '/v1/invoices', 'has_more' => false, 'data' => []],
        ]);

        $list = (new StripeGateway())->listInvoices(new InvoiceFilter(
            originType: InvoiceOriginType::INVOICE,
            cursor: 'in_anterior'
        ));

        $this->assertSame('in_anterior', $httpClient->calls[0][2]['starting_after']);
        $this->assertTrue($list->isEmpty());
        $this->assertFalse($list->hasMore);
        $this->assertNull($list->nextPageFilter());
    }

    /**
     * Resposta gravada em `tests/fixtures/stripe/<caminho>.json`, como array.
     */
    private static function fixture(string $path): array
    {
        return json_decode(file_get_contents(__DIR__ . "/../../fixtures/stripe/{$path}.json"), true);
    }
}
