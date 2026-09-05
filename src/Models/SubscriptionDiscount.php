<?php

namespace Potelo\MultiPayment\Models;

use Carbon\Carbon;
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
     * N para N ciclos. Mutuamente exclusivo com validUntil.
     *
     * @var int|null
     */
    public ?int $cycles = null;

    /**
     * Data até a qual o desconto vale, inclusive. Mutuamente exclusivo com cycles. Na leitura,
     * um desconto criado com `cycles` volta com a data equivalente aqui.
     *
     * @var Carbon|null
     */
    public ?Carbon $validUntil = null;

    /**
     * @inheritDoc
     */
    public function fill(array $data): void
    {
        // valor vazio conta como ausente, para não cair como string na propriedade de data
        if (array_key_exists('valid_until', $data)) {
            if (!empty($data['valid_until'])) {
                $this->validUntil = $data['valid_until'] instanceof Carbon
                    ? $data['valid_until']
                    : Carbon::parse($data['valid_until']);
            }
            unset($data['valid_until']);
        }

        parent::fill($data);
    }

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

        if (
            in_array('cycles', $attributes)
            && in_array('validUntil', $attributes)
            && !is_null($this->cycles)
            && !empty($this->validUntil)
        ) {
            throw ModelAttributeValidationException::invalid(
                $model,
                'cycles',
                'cycles and validUntil are mutually exclusive.'
            );
        }
    }
}
