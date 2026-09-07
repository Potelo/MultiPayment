<?php

namespace Potelo\MultiPayment\Listing;

use Potelo\MultiPayment\Models\Subscription;

/**
 * Página de assinaturas devolvida por `listSubscriptions()`.
 */
class SubscriptionList extends PaginatedList
{
    /** @var Subscription[] assinaturas da página, na ordem devolvida pelo gateway. */
    public array $items;

    /** @var SubscriptionFilter filtro que produziu esta página. */
    private SubscriptionFilter $filter;

    /**
     * Cria a página com as assinaturas, a paginação e o filtro de origem.
     *
     * @param  Subscription[]  $items
     * @param  int|null  $total
     * @param  string|null  $nextCursor
     * @param  bool  $hasMore
     * @param  SubscriptionFilter  $filter
     */
    public function __construct(
        array $items,
        ?int $total,
        ?string $nextCursor,
        bool $hasMore,
        SubscriptionFilter $filter
    ) {
        parent::__construct($items, $total, $nextCursor, $hasMore);
        $this->filter = $filter;
    }

    /**
     * Filtro da página seguinte (o mesmo filtro apontando para `nextCursor`), ou nulo quando
     * esta é a última página.
     *
     * @return SubscriptionFilter|null
     */
    public function nextPageFilter(): ?SubscriptionFilter
    {
        if (!$this->hasMore || is_null($this->nextCursor)) {
            return null;
        }

        return $this->filter->withCursor($this->nextCursor);
    }
}
