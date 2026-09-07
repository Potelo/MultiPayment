<?php

namespace Potelo\MultiPayment\Listing;

/**
 * Uma página de resultado de listagem. Itera, conta e indexa como um array de models
 * (`foreach`, `count()`, `$list[0]`), e carrega a paginação: `hasMore` diz se existe página
 * seguinte, `nextCursor` é o cursor dela e `total` é o total de registros que casam com o
 * filtro, quando o gateway o informa. A lista é só de leitura; a página seguinte vem de uma
 * nova chamada com o filtro de `nextPageFilter()`.
 */
abstract class PaginatedList implements \ArrayAccess, \Countable, \IteratorAggregate
{
    /** @var array models da página, na ordem devolvida pelo gateway. */
    public array $items;

    /** @var int|null total de registros que casam com o filtro; nulo quando o gateway não informa. */
    public ?int $total;

    /** @var string|null cursor da página seguinte; nulo na última página. */
    public ?string $nextCursor;

    /** @var bool existe página seguinte. */
    public bool $hasMore;

    /**
     * Cria a página com os itens e a paginação.
     *
     * @param  array  $items
     * @param  int|null  $total
     * @param  string|null  $nextCursor
     * @param  bool  $hasMore
     */
    public function __construct(array $items, ?int $total, ?string $nextCursor, bool $hasMore)
    {
        $this->items = array_values($items);
        $this->total = $total;
        $this->nextCursor = $nextCursor;
        $this->hasMore = $hasMore;
    }

    /**
     * Quantidade de itens desta página.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Diz se a página veio vazia.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Itera sobre os models da página.
     *
     * @return \ArrayIterator
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * Diz se existe item na posição.
     *
     * @param  mixed  $offset
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    /**
     * Item na posição, ou nulo quando não existe.
     *
     * @param  mixed  $offset
     * @return mixed
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    /**
     * Sempre lança: a lista é só de leitura.
     *
     * @param  mixed  $offset
     * @param  mixed  $value
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('A lista de resultado é só de leitura');
    }

    /**
     * Sempre lança: a lista é só de leitura.
     *
     * @param  mixed  $offset
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('A lista de resultado é só de leitura');
    }
}
