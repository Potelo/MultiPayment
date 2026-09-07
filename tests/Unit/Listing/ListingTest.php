<?php

namespace Potelo\MultiPayment\Tests\Unit\Listing;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Enums\InvoiceOriginType;
use Potelo\MultiPayment\Enums\SubscriptionStatus;
use Potelo\MultiPayment\Listing\InvoiceList;
use Potelo\MultiPayment\Listing\InvoiceFilter;
use Potelo\MultiPayment\Listing\SubscriptionList;
use Potelo\MultiPayment\Listing\SubscriptionFilter;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Filtros e listas de `listSubscriptions()`/`listInvoices()`: a validação local dos filtros
 * (limite, página, status e origem), a conversão de string para enum, o comportamento de
 * array das listas e o filtro da página seguinte.
 */
class ListingTest extends TestCase
{
    public function testTheSubscriptionFilterAcceptsTheStatusAsString(): void
    {
        $filter = new SubscriptionFilter(status: 'active');

        $this->assertSame(SubscriptionStatus::ACTIVE, $filter->status);
    }

    public function testTheInvoiceFilterAcceptsStatusAndOriginAsStrings(): void
    {
        $filter = new InvoiceFilter(status: 'paid', originType: 'invoice');

        $this->assertSame(InvoiceStatus::PAID, $filter->status);
        $this->assertSame(InvoiceOriginType::INVOICE, $filter->originType);
    }

    public function testAnUnknownStatusStringIsRejected(): void
    {
        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/not a valid subscription status/');

        new SubscriptionFilter(status: 'nao-existe');
    }

    public function testTheUnknownCaseIsNotFilterable(): void
    {
        try {
            new InvoiceFilter(status: InvoiceStatus::UNKNOWN);
            $this->fail('Esperava ModelAttributeValidationException');
        } catch (ModelAttributeValidationException $e) {
            $this->assertStringContainsString('UNKNOWN is not a filterable invoice status', $e->getMessage());
        }

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/UNKNOWN is not a filterable subscription status/');

        new SubscriptionFilter(status: SubscriptionStatus::UNKNOWN);
    }

    public function testAnUnknownOriginTypeStringIsRejected(): void
    {
        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/not a valid invoice origin type/');

        new InvoiceFilter(originType: 'boleto');
    }

    public function testPaginationBoundsAreValidated(): void
    {
        foreach ([
            fn () => new SubscriptionFilter(limit: 0),
            fn () => new SubscriptionFilter(limit: 101),
            fn () => new SubscriptionFilter(page: 0),
            fn () => new InvoiceFilter(limit: 0),
            fn () => new InvoiceFilter(limit: 101),
            fn () => new InvoiceFilter(page: 0),
        ] as $build) {
            try {
                $build();
                $this->fail('Esperava ModelAttributeValidationException');
            } catch (ModelAttributeValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheListBehavesLikeAnArray(): void
    {
        $first = new Subscription();
        $first->id = 'sub_1';
        $second = new Subscription();
        $second->id = 'sub_2';

        $list = new SubscriptionList([$first, $second], 5, 'cursor-2', true, new SubscriptionFilter());

        $this->assertCount(2, $list);
        $this->assertFalse($list->isEmpty());
        $this->assertSame('sub_1', $list[0]->id);
        $this->assertTrue(isset($list[1]));
        $this->assertNull($list[2]);
        $this->assertSame(['sub_1', 'sub_2'], array_map(fn (Subscription $item) => $item->id, iterator_to_array($list)));
        $this->assertSame(5, $list->total);
    }

    public function testTheListIsReadOnly(): void
    {
        $list = new InvoiceList([], null, null, false, new InvoiceFilter());

        $this->expectException(\LogicException::class);

        $list[0] = new Invoice();
    }

    public function testTheNextPageFilterKeepsTheFiltersAndPointsToTheCursor(): void
    {
        $filter = new InvoiceFilter(customerId: 'cus_1', status: InvoiceStatus::PAID, limit: 10, page: 4);
        $list = new InvoiceList([], 30, '40', true, $filter);

        $next = $list->nextPageFilter();

        $this->assertSame('40', $next->cursor);
        $this->assertSame(1, $next->page);
        $this->assertSame('cus_1', $next->customerId);
        $this->assertSame(InvoiceStatus::PAID, $next->status);
        $this->assertSame(10, $next->limit);
        // o filtro original não muda
        $this->assertNull($filter->cursor);
        $this->assertSame(4, $filter->page);
    }

    public function testTheLastPageHasNoNextPageFilter(): void
    {
        $list = new SubscriptionList([], null, null, false, new SubscriptionFilter());

        $this->assertNull($list->nextPageFilter());
    }

    public function testWithCursorCopiesTheFilter(): void
    {
        $filter = new SubscriptionFilter(
            customerId: 'cus_1',
            planIdentifier: 'plano',
            status: SubscriptionStatus::ACTIVE,
            createdAfter: Carbon::parse('2026-09-01'),
            limit: 20,
            page: 3
        );

        $next = $filter->withCursor('60');

        $this->assertNotSame($filter, $next);
        $this->assertSame('60', $next->cursor);
        $this->assertSame(1, $next->page);
        $this->assertSame('cus_1', $next->customerId);
        $this->assertSame('plano', $next->planIdentifier);
        $this->assertSame(SubscriptionStatus::ACTIVE, $next->status);
        $this->assertSame(20, $next->limit);
    }
}
