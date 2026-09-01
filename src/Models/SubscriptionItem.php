<?php

namespace Potelo\MultiPayment\Models;

use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Item cobrado por uma assinatura, além do valor do plano.
 */
class SubscriptionItem extends Model
{
    /**
     * @var string|null
     */
    public ?string $id = null;

    /**
     * @var string|null
     */
    public ?string $description = null;

    /**
     * Valor unitário em centavos: o total do item é `amount * quantity`.
     *
     * @var int|null
     */
    public ?int $amount = null;

    /**
     * @var int|null
     */
    public ?int $quantity = null;

    /**
     * Se o item é cobrado em todo ciclo ou apenas na próxima fatura.
     *
     * @var bool
     */
    public bool $recurring = true;

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validateAmountAttribute(): void
    {
        if ($this->amount < 0) {
            throw ModelAttributeValidationException::invalid(
                $this->getClassName(),
                'amount',
                'amount must not be negative. Use SubscriptionDiscount to reduce the subscription value.'
            );
        }
    }

    /**
     * @inheritDoc
     */
    protected function attributesExtraValidation(array $attributes): void
    {
        $model = $this->getClassName();

        if (in_array('description', $attributes) && empty($this->description)) {
            throw ModelAttributeValidationException::required($model, 'description');
        }

        if (in_array('amount', $attributes) && is_null($this->amount)) {
            throw ModelAttributeValidationException::required($model, 'amount');
        }

        // validate() só chama validate{Attr}Attribute() para atributo não vazio, então zero
        // e negativo de quantity não chegariam a um validateQuantityAttribute()
        if (in_array('quantity', $attributes) && !is_null($this->quantity) && $this->quantity < 1) {
            throw ModelAttributeValidationException::invalid(
                $model,
                'quantity',
                'quantity must be at least 1.'
            );
        }
    }
}
