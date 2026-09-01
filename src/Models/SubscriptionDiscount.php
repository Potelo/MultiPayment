<?php

namespace Potelo\MultiPayment\Models;

use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Abatimento aplicado sobre o valor de uma assinatura.
 */
class SubscriptionDiscount extends Model
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
     * Valor total abatido, em centavos e positivo — não é valor unitário, ao contrário de
     * `SubscriptionItem::$amount`. Mutuamente exclusivo com percentOff.
     *
     * @var int|null
     */
    public ?int $amountOff = null;

    /**
     * Percentual abatido, entre 0 e 100. Mutuamente exclusivo com amountOff.
     *
     * @var float|null
     */
    public ?float $percentOff = null;

    /**
     * Quantos ciclos o desconto vale: null enquanto não for removido, 1 só na próxima fatura,
     * N para N ciclos.
     *
     * @var int|null
     */
    public ?int $cycles = null;

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validateAmountOffAttribute(): void
    {
        if ($this->amountOff <= 0) {
            throw ModelAttributeValidationException::invalid(
                $this->getClassName(),
                'amountOff',
                'amountOff must be a positive amount in cents.'
            );
        }
    }

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validatePercentOffAttribute(): void
    {
        if ($this->percentOff <= 0 || $this->percentOff > 100) {
            throw ModelAttributeValidationException::invalid(
                $this->getClassName(),
                'percentOff',
                'percentOff must be greater than 0 and at most 100.'
            );
        }
    }

    /**
     * @inheritDoc
     */
    protected function attributesExtraValidation(array $attributes): void
    {
        $model = $this->getClassName();

        if (in_array('amountOff', $attributes) && in_array('percentOff', $attributes)) {
            if (is_null($this->amountOff) && is_null($this->percentOff)) {
                throw ModelAttributeValidationException::required($model, 'amountOff or percentOff');
            }

            if (!is_null($this->amountOff) && !is_null($this->percentOff)) {
                throw ModelAttributeValidationException::invalid(
                    $model,
                    'amountOff',
                    'amountOff and percentOff are mutually exclusive.'
                );
            }
        }

        if (in_array('description', $attributes) && empty($this->description)) {
            throw ModelAttributeValidationException::required($model, 'description');
        }

        // validate() pula atributo vazio, então zero e negativo de cycles não chegariam a um
        // validateCyclesAttribute()
        if (in_array('cycles', $attributes) && !is_null($this->cycles) && $this->cycles < 1) {
            throw ModelAttributeValidationException::invalid(
                $model,
                'cycles',
                'cycles must be null or at least 1.'
            );
        }
    }
}
