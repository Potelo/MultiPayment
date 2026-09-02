<?php

namespace Potelo\MultiPayment\Models;

use Carbon\Carbon;

/**
 * Simulação de uma troca de plano, sem que ela tenha sido aplicada.
 */
class SubscriptionPlanChange extends Model
{
    /**
     * Valor que a troca cobraria agora, em centavos, segundo o gateway; quando há linhas, é a
     * soma de `items`.
     *
     * @var int|null
     */
    public ?int $amount = null;

    /**
     * Linhas da fatura que a troca geraria. Crédito de período não usado vem com `price`
     * negativo. Gateway que não devolve linhas recebe linhas montadas pela lib a partir dos
     * totais da simulação (na Iugu: uma de cobrança do plano novo e, quando há crédito, uma
     * negativa do plano antigo), então a lista nunca é nula; o payload cru fica em `original`.
     *
     * @var InvoiceItem[]
     */
    public array $items = [];

    /**
     * Data em que a próxima cobrança acontece após a troca.
     *
     * @var Carbon|null
     */
    public ?Carbon $effectiveAt = null;

    /**
     * Diz se o plano novo passa a valer assim que a troca for aplicada. Falso quando o gateway
     * só efetiva a troca depois que o pagador quitar a fatura gerada por ela (na Iugu,
     * assinatura paga por boleto ou Pix).
     *
     * @var bool
     */
    public bool $appliesImmediately = false;

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

        // as duas propriedades não aceitam nulo; chave nula mantém o valor atual
        foreach (['items', 'applies_immediately'] as $key) {
            if (array_key_exists($key, $data) && is_null($data[$key])) {
                unset($data[$key]);
            }
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
