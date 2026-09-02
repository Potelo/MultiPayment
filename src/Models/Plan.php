<?php

namespace Potelo\MultiPayment\Models;

use Potelo\MultiPayment\Enums\PlanInterval;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Plano recorrente ao qual uma assinatura se vincula.
 *
 * @property PlanInterval|null $interval Unidade do intervalo de cobrança; aceita a string do
 *                                       valor ou o caso do enum na escrita (ver `Model::ENUM_CASTS`).
 */
class Plan extends Model
{
    /** @deprecated desde 2026-09-02, use `PlanInterval::WEEK`. */
    public const INTERVAL_WEEK = 'week';

    /** @deprecated desde 2026-09-02, use `PlanInterval::MONTH`. */
    public const INTERVAL_MONTH = 'month';

    /** @deprecated desde 2026-09-02, use `PlanInterval::YEAR`. */
    public const INTERVAL_YEAR = 'year';

    protected const ENUM_CASTS = [
        'interval' => PlanInterval::class,
    ];

    /**
     * @var string|null
     */
    public ?string $id = null;

    /**
     * Identificador do plano no gateway, quando ele aceita um definido por quem cria.
     *
     * @var string|null
     */
    public ?string $identifier = null;

    /**
     * @var string|null
     */
    public ?string $name = null;

    /**
     * @var int|null
     */
    public ?int $amount = null;

    /**
     * @var PlanInterval|null
     */
    protected ?PlanInterval $interval = null;

    /**
     * @var int|null
     */
    public ?int $intervalCount = null;

    /**
     * @var string|null
     */
    public ?string $currency = null;

    /**
     * @var bool|null
     */
    public ?bool $active = null;

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
     * Cria o plano no gateway. Plano com `id` preenchido lança `GatewayException`: plano não é
     * atualizável.
     *
     * @param  GatewayContract|string|null  $gateway
     * @param  bool  $validate
     *
     * @return void
     * @throws GatewayException|\Potelo\MultiPayment\Exceptions\GatewayNotAvailableException
     * @throws ModelAttributeValidationException|\Potelo\MultiPayment\Exceptions\ConfigurationException
     */
    public function save(GatewayContract|string|null $gateway = null, bool $validate = true): void
    {
        if (!empty($this->id)) {
            throw new GatewayException(
                'A plan cannot be updated. Create a new plan instead.'
            );
        }

        parent::save($gateway, $validate);
    }

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
                'amount must not be negative.'
            );
        }
    }

    /**
     * @inheritDoc
     */
    protected function attributesExtraValidation(array $attributes): void
    {
        $model = $this->getClassName();

        foreach (['name', 'amount', 'interval'] as $attribute) {
            if (in_array($attribute, $attributes) && is_null($this->{$attribute})) {
                throw ModelAttributeValidationException::required($model, $attribute);
            }
        }

        // validate() pula atributo vazio, então zero e negativo de intervalCount não chegariam
        // a um validateIntervalCountAttribute()
        if (
            in_array('intervalCount', $attributes)
            && !is_null($this->intervalCount)
            && $this->intervalCount < 1
        ) {
            throw ModelAttributeValidationException::invalid(
                $model,
                'intervalCount',
                'intervalCount must be at least 1.'
            );
        }
    }
}
