<?php

namespace Potelo\MultiPayment\Models;

use Carbon\Carbon;

/**
 * Simulação de uma troca de plano, sem que ela tenha sido aplicada.
 */
class SubscriptionPlanChange extends Model
{
    /**
     * Valor da troca segundo o gateway, em centavos. Nem todo gateway o devolve já líquido de
     * créditos e descontos — o que sobra fica em `original`.
     *
     * @var int|null
     */
    public ?int $amount = null;

    /**
     * Linhas da fatura que a troca geraria, quando o gateway as devolve — a Iugu não devolve, e
     * lá fica `null`. Crédito de período não usado vem com price negativo.
     *
     * @var InvoiceItem[]|null
     */
    public ?array $items = null;

    /**
     * Quando a próxima cobrança aconteceria caso a troca fosse aplicada.
     *
     * @var Carbon|null
     */
    public ?Carbon $effectiveAt = null;

    /**
     * @var string|null
     */
    public ?string $gateway = null;

    /**
     * A resposta original do gateway, caso seja necessária alguma informação adicional.
     *
     * @var mixed|null
     */
    public $original = null;

    /**
     * @inheritDoc
     */
    public function fill(array $data): void
    {
        if (!empty($data['effective_at']) && !$data['effective_at'] instanceof Carbon) {
            $data['effective_at'] = Carbon::parse($data['effective_at']);
        }

        if (!empty($data['items']) && is_array($data['items'])) {
            $data['items'] = array_map(function ($item) {
                if ($item instanceof InvoiceItem) {
                    return $item;
                }

                $invoiceItem = new InvoiceItem();
                $invoiceItem->fill($item);

                return $invoiceItem;
            }, $data['items']);
        }

        parent::fill($data);
    }
}
