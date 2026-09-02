<?php

namespace Potelo\MultiPayment\Models;

use Carbon\Carbon;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Helpers\ConfigurationHelper;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Class CreditCard
 */
class CreditCard extends Model
{

    /**
     * @var mixed
     */
    public $id = null;

    /**
     * @var Customer|null
     */
    public ?Customer $customer = null;

    /**
     * @var string|null
     */
    public ?string $description = null;

    /**
     * @var string|null
     */
    public ?string $number = null;

    /**
     * @var string|null
     */
    public ?string $brand = null;

    /**
     * @var string|null
     */
    public ?string $month = null;

    /**
     * @var string|null
     */
    public ?string $year = null;

    /**
     * @var string|null
     */
    public ?string $cvv = null;

    /**
     * @var string|null
     */
    public ?string $lastDigits = null;

    /**
     * @var string|null
     */
    public ?string $firstName = null;

    /**
     * @var string|null
     */
    public ?string $lastName = null;

    /**
     * @var string|null
     */
    public ?string $token = null;

    /**
     * @var bool|null
     */
    public ?bool $default = null;

    /**
     * Verdadeiro quando o gateway exige ação do pagador (autenticação com o emissor) antes de
     * o cartão ficar cobrável: `id` fica nulo, `setupId` e `clientSecret` (e `actionUrl`,
     * quando há) dizem como concluir, e `confirmCreditCardSetup()` termina o salvamento.
     * Falso por padrão e sempre falso na Iugu.
     *
     * @var bool|null
     */
    public ?bool $requiresAction = false;

    /**
     * Página hospedada pelo gateway para o pagador autenticar o cartão, quando ele a oferece
     * (no Stripe, só com `return_url` em `gatewayOptions`). Nula quando a autenticação é feita
     * pelo SDK do gateway no navegador, com `clientSecret`.
     *
     * @var string|null
     */
    public ?string $actionUrl = null;

    /**
     * Segredo do setup para o SDK do gateway no navegador concluir a autenticação (no Stripe,
     * `stripe.confirmCardSetup(clientSecret)`). Preenchido só quando `requiresAction`.
     *
     * @var string|null
     */
    public ?string $clientSecret = null;

    /**
     * Id do setup que salva o cartão no gateway (SetupIntent `seti_` no Stripe), argumento de
     * `confirmCreditCardSetup()`. Nulo na Iugu.
     *
     * @var string|null
     */
    public ?string $setupId = null;

    /**
     * @var string|null
     */
    public ?string $gateway = null;

    /**
     * @var mixed The original object that was received from the gateway
     */
    public $original = null;

    /**
     * @var Carbon|null
     */
    public ?Carbon $createdAt = null;

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validateNumberAttribute()
    {
        $pattern = '/^[0-9]{16}$/';
        if (!preg_match($pattern, $this->number)) {
            throw ModelAttributeValidationException::invalid($this->getClassName(), 'number', 'CreditCard number must contain only numbers and must be 16 digits long.');
        }
    }

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validateMonthAttribute()
    {
        $pattern = '/^[0-9]{2}$/';
        if (!preg_match($pattern, $this->month)) {
            throw ModelAttributeValidationException::invalid($this->getClassName(), 'month', 'CreditCard month must contain only numbers and must be 2 digits long.');
        }
    }

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validateYearAttribute()
    {
        $pattern = '/^[0-9]{4}$/';
        if (!preg_match($pattern, $this->year)) {
            throw ModelAttributeValidationException::invalid($this->getClassName(), 'year', 'CreditCard year must contain only numbers and must be 4 digits long.');
        }
    }

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validateCvvAttribute()
    {
        $pattern = '/^[0-9]{3,4}$/';
        if (!preg_match($pattern, $this->cvv)) {
            throw ModelAttributeValidationException::invalid($this->getClassName(), 'cvv', 'CreditCard cvv must contain only numbers and must be 3 or 4 digits long.');
        }
    }

    /**
     * @inheritDoc
     */
    public function attributesExtraValidation(array $attributes): void
    {
        if (in_array('id', $attributes) &&
            in_array('token', $attributes) &&
            in_array('year', $attributes) &&
            in_array('month', $attributes) &&
            in_array('number', $attributes) &&
            in_array('cvv', $attributes) &&
            in_array('firstName', $attributes) &&
            in_array('lastName', $attributes) &&
            empty($this->id) &&
            empty($this->token) &&
            (
                empty($this->year) ||
                empty($this->month) ||
                empty($this->number) ||
                empty($this->cvv) ||
                empty($this->firstName) ||
                empty($this->lastName)
            )
        ) {
            throw new ModelAttributeValidationException('The `id` or `token` or [`number`, `month`, `year`, `cvv`, `firstName` and `lastName`] are required.');
        }
        if (in_array('month', $attributes) && in_array('year', $attributes) && !empty($this->month) && !empty($this->year)) {
            $date = Carbon::createFromFormat('m/Y', $this->month . '/' . $this->year)->lastOfMonth();
            if ($date->isPast()) {
                throw ModelAttributeValidationException::invalid($this->getClassName(), 'month and year', 'CreditCard month and year must be in the future.');
            }
        }
    }

    /**
     * Conclui o salvamento deste cartão depois que o pagador autenticou, pelo `setupId` que
     * `create()` devolveu com `requiresAction`. Atualiza este model com o que o gateway
     * devolveu (cartão cobrável, ou ainda com `requiresAction`) e o devolve; o `customer` já
     * preenchido é mantido. Recusa do gateway é `CardDeclinedException` (ver
     * `CreditCardContract::confirmCreditCardSetup()`).
     *
     * @param  GatewayContract|string|null  $gateway
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     * @return $this
     * @throws ModelAttributeValidationException  `setupId` vazio
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\CardDeclinedException
     * @throws \Potelo\MultiPayment\Exceptions\UnsupportedOperationException
     */
    public function confirmSetup(GatewayContract|string|null $gateway = null, ?string $idempotencyKey = null): static
    {
        if (empty($this->setupId)) {
            throw ModelAttributeValidationException::required($this->getClassName(), 'setupId');
        }
        $gateway = ConfigurationHelper::resolveGateway($gateway ?? $this->gateway);

        $confirmed = $gateway->confirmCreditCardSetup($this->setupId, $idempotencyKey);
        foreach (get_object_vars($confirmed) as $property => $value) {
            if ($property === 'customer' && !empty($this->customer)) {
                continue;
            }
            $this->{$property} = $value;
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function fill(array $data): void
    {
        if (!empty($data['customer']) && is_array($data['customer'])) {
            $customer = new Customer();
            $customer->fill($data['customer']);
            $data['customer'] = $customer;
        }
        if (!empty($data['token']) && is_string($data['token'])) {
            $this->token = $data['token'];
            unset($data['token']);
        }

        parent::fill($data);

        if (
            !empty($this->customer) &&
            !empty($this->customer->name) &&
            is_string($this->customer->name) &&
            empty($this->firstName) &&
            empty($this->lastName)
        ) {
            $names = explode(' ', $this->customer->name);
            $this->firstName = $names[0];
            $this->lastName = $names[array_key_last($names)];
        }
    }
}
