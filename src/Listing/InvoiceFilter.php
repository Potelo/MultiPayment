<?php

namespace Potelo\MultiPayment\Listing;

use Carbon\Carbon;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Enums\InvoiceOriginType;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Filtro de `listInvoices()`: campo nulo não filtra. O driver traduz cada campo para o
 * parâmetro do gateway e recusa antes da rede, com `UnsupportedOperationException`, o filtro
 * que o gateway não oferece; a restrição consultável de `Capability::INVOICE_LISTING`
 * descreve o que cada gateway aceita. No Stripe, `originType` escolhe qual origem listar
 * (`INVOICE` para fatura de assinatura, `PAYMENT_INTENT` para venda avulsa) e é obrigatório;
 * na Iugu toda fatura tem origem `INVOICE` e o campo pode ficar nulo.
 *
 * A paginação usa `page` com `limit`, ou `cursor` com `limit`; `cursor` preenchido tem
 * precedência sobre `page`. O cursor vem de `InvoiceList::$nextCursor` (ou de
 * `nextPageFilter()`) e é opaco: o formato varia por gateway e não deve ser montado à mão.
 */
class InvoiceFilter
{
    /** @var string|null id do cliente no gateway. */
    public ?string $customerId;

    /** @var string|null id da assinatura que gerou a fatura. */
    public ?string $subscriptionId;

    /** @var InvoiceStatus|null status genérico que a fatura deve ter. */
    public ?InvoiceStatus $status;

    /** @var Carbon|null só faturas criadas neste instante ou depois. */
    public ?Carbon $createdAfter;

    /** @var Carbon|null só faturas criadas neste instante ou antes. */
    public ?Carbon $createdBefore;

    /** @var Carbon|null só faturas com vencimento neste dia ou depois; a hora é descartada. */
    public ?Carbon $dueAfter;

    /** @var Carbon|null só faturas com vencimento neste dia ou antes; a hora é descartada. */
    public ?Carbon $dueBefore;

    /** @var InvoiceOriginType|null origem da fatura a listar; obrigatório no Stripe. */
    public ?InvoiceOriginType $originType;

    /** @var int tamanho da página, de 1 a 100. */
    public int $limit;

    /** @var int página pedida, a partir de 1; ignorada quando `cursor` está preenchido. */
    public int $page;

    /** @var string|null cursor de continuação devolvido pela página anterior. */
    public ?string $cursor;

    /**
     * Cria o filtro; todo campo é opcional e nulo não filtra.
     *
     * @param  string|null  $customerId
     * @param  string|null  $subscriptionId
     * @param  InvoiceStatus|string|null  $status  caso do enum ou o valor dele em string
     * @param  Carbon|null  $createdAfter
     * @param  Carbon|null  $createdBefore
     * @param  Carbon|null  $dueAfter
     * @param  Carbon|null  $dueBefore
     * @param  InvoiceOriginType|string|null  $originType  origem da fatura; obrigatório no Stripe
     * @param  int  $limit  tamanho da página, de 1 a 100
     * @param  int  $page  página pedida, a partir de 1
     * @param  string|null  $cursor  cursor devolvido pela página anterior
     * @throws ModelAttributeValidationException  status, origem, limite ou página inválidos
     */
    public function __construct(
        ?string $customerId = null,
        ?string $subscriptionId = null,
        InvoiceStatus|string|null $status = null,
        ?Carbon $createdAfter = null,
        ?Carbon $createdBefore = null,
        ?Carbon $dueAfter = null,
        ?Carbon $dueBefore = null,
        InvoiceOriginType|string|null $originType = null,
        int $limit = 100,
        int $page = 1,
        ?string $cursor = null
    ) {
        if ($limit < 1 || $limit > 100) {
            throw ModelAttributeValidationException::invalid(
                'InvoiceFilter',
                'limit',
                'InvoiceFilter limit must be between 1 and 100'
            );
        }
        if ($page < 1) {
            throw ModelAttributeValidationException::invalid(
                'InvoiceFilter',
                'page',
                'InvoiceFilter page must be at least 1'
            );
        }

        $this->customerId = $customerId;
        $this->subscriptionId = $subscriptionId;
        $this->status = self::normalizeStatus($status);
        $this->createdAfter = $createdAfter;
        $this->createdBefore = $createdBefore;
        $this->dueAfter = $dueAfter;
        $this->dueBefore = $dueBefore;
        $this->originType = self::normalizeOriginType($originType);
        $this->limit = $limit;
        $this->page = $page;
        $this->cursor = $cursor;
    }

    /**
     * Cópia do filtro apontando para o cursor informado, para pedir a página seguinte.
     *
     * @param  string  $cursor
     * @return static
     */
    public function withCursor(string $cursor): static
    {
        $next = clone $this;
        $next->cursor = $cursor;
        $next->page = 1;

        return $next;
    }

    /**
     * Converte o status em caso do enum. String fora do enum, e o caso `UNKNOWN`, são
     * recusados: não há o que filtrar por um status que a lib não reconhece.
     *
     * @param  InvoiceStatus|string|null  $status
     * @return InvoiceStatus|null
     * @throws ModelAttributeValidationException
     */
    private static function normalizeStatus(InvoiceStatus|string|null $status): ?InvoiceStatus
    {
        if (is_string($status)) {
            $status = InvoiceStatus::tryFrom($status)
                ?? throw ModelAttributeValidationException::invalid(
                    'InvoiceFilter',
                    'status',
                    "[{$status}] is not a valid invoice status"
                );
        }

        if ($status === InvoiceStatus::UNKNOWN) {
            throw ModelAttributeValidationException::invalid(
                'InvoiceFilter',
                'status',
                'UNKNOWN is not a filterable invoice status'
            );
        }

        return $status;
    }

    /**
     * Converte a origem em caso do enum; string fora do enum é recusada.
     *
     * @param  InvoiceOriginType|string|null  $originType
     * @return InvoiceOriginType|null
     * @throws ModelAttributeValidationException
     */
    private static function normalizeOriginType(InvoiceOriginType|string|null $originType): ?InvoiceOriginType
    {
        if (is_string($originType)) {
            return InvoiceOriginType::tryFrom($originType)
                ?? throw ModelAttributeValidationException::invalid(
                    'InvoiceFilter',
                    'originType',
                    "[{$originType}] is not a valid invoice origin type"
                );
        }

        return $originType;
    }
}
