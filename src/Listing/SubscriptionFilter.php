<?php

namespace Potelo\MultiPayment\Listing;

use Carbon\Carbon;
use Potelo\MultiPayment\Enums\SubscriptionStatus;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Filtro de `listSubscriptions()`: campo nulo não filtra. O driver traduz cada campo para o
 * parâmetro do gateway e recusa antes da rede, com `UnsupportedOperationException`, o filtro
 * que o gateway não oferece; a restrição consultável de `Capability::SUBSCRIPTIONS` descreve
 * o que cada gateway aceita.
 *
 * A paginação usa `page` com `limit`, ou `cursor` com `limit`; `cursor` preenchido tem
 * precedência sobre `page`. O cursor vem de `SubscriptionList::$nextCursor` (ou de
 * `nextPageFilter()`) e é opaco: o formato varia por gateway e não deve ser montado à mão.
 */
class SubscriptionFilter
{
    /** @var string|null id do cliente no gateway. */
    public ?string $customerId;

    /** @var string|null identificador do plano (o mesmo aceito em `Subscription::$planId`). */
    public ?string $planIdentifier;

    /**
     * Status genérico da assinatura. O filtro do gateway pode ser mais largo que o caso
     * pedido (a restrição consultável de `Capability::SUBSCRIPTIONS` diz quanto por gateway),
     * então confira `Subscription::$status` de cada item quando precisar do estado exato.
     *
     * @var SubscriptionStatus|null
     */
    public ?SubscriptionStatus $status;

    /** @var Carbon|null só assinaturas criadas neste instante ou depois. */
    public ?Carbon $createdAfter;

    /** @var Carbon|null só assinaturas criadas neste instante ou antes. */
    public ?Carbon $createdBefore;

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
     * @param  string|null  $planIdentifier
     * @param  SubscriptionStatus|string|null  $status  caso do enum ou o valor dele em string
     * @param  Carbon|null  $createdAfter
     * @param  Carbon|null  $createdBefore
     * @param  int  $limit  tamanho da página, de 1 a 100
     * @param  int  $page  página pedida, a partir de 1
     * @param  string|null  $cursor  cursor devolvido pela página anterior
     * @throws ModelAttributeValidationException  status, limite ou página inválidos
     */
    public function __construct(
        ?string $customerId = null,
        ?string $planIdentifier = null,
        SubscriptionStatus|string|null $status = null,
        ?Carbon $createdAfter = null,
        ?Carbon $createdBefore = null,
        int $limit = 100,
        int $page = 1,
        ?string $cursor = null
    ) {
        if ($limit < 1 || $limit > 100) {
            throw ModelAttributeValidationException::invalid(
                'SubscriptionFilter',
                'limit',
                'SubscriptionFilter limit must be between 1 and 100'
            );
        }
        if ($page < 1) {
            throw ModelAttributeValidationException::invalid(
                'SubscriptionFilter',
                'page',
                'SubscriptionFilter page must be at least 1'
            );
        }

        $this->customerId = $customerId;
        $this->planIdentifier = $planIdentifier;
        $this->status = self::normalizeStatus($status);
        $this->createdAfter = $createdAfter;
        $this->createdBefore = $createdBefore;
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
     * @param  SubscriptionStatus|string|null  $status
     * @return SubscriptionStatus|null
     * @throws ModelAttributeValidationException
     */
    private static function normalizeStatus(SubscriptionStatus|string|null $status): ?SubscriptionStatus
    {
        if (is_string($status)) {
            $status = SubscriptionStatus::tryFrom($status)
                ?? throw ModelAttributeValidationException::invalid(
                    'SubscriptionFilter',
                    'status',
                    "[{$status}] is not a valid subscription status"
                );
        }

        if ($status === SubscriptionStatus::UNKNOWN) {
            throw ModelAttributeValidationException::invalid(
                'SubscriptionFilter',
                'status',
                'UNKNOWN is not a filterable subscription status'
            );
        }

        return $status;
    }
}
