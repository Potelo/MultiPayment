<?php

namespace Potelo\MultiPayment\Listing;

use Potelo\MultiPayment\Models\Invoice;

/**
 * Página de faturas devolvida por `listInvoices()`.
 */
class InvoiceList extends PaginatedList
{
    /** @var Invoice[] faturas da página, na ordem devolvida pelo gateway. */
    public array $items;

    /** @var InvoiceFilter filtro que produziu esta página. */
    private InvoiceFilter $filter;

    /**
     * Cria a página com as faturas, a paginação e o filtro de origem.
     *
     * @param  Invoice[]  $items
     * @param  int|null  $total
     * @param  string|null  $nextCursor
     * @param  bool  $hasMore
     * @param  InvoiceFilter  $filter
     */
    public function __construct(array $items, ?int $total, ?string $nextCursor, bool $hasMore, InvoiceFilter $filter)
    {
        parent::__construct($items, $total, $nextCursor, $hasMore);
        $this->filter = $filter;
    }

    /**
     * Filtro da página seguinte (o mesmo filtro apontando para `nextCursor`), ou nulo quando
     * esta é a última página.
     *
     * @return InvoiceFilter|null
     */
    public function nextPageFilter(): ?InvoiceFilter
    {
        if (!$this->hasMore || is_null($this->nextCursor)) {
            return null;
        }

        return $this->filter->withCursor($this->nextCursor);
    }
}
