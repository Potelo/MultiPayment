<?php

namespace Potelo\MultiPayment\Models;

use Carbon\Carbon;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Class CreditCard
 */
class CreditCard extends Model
{

    /**
     * @var mixed
     */
    public $id;

    /**
     * @var Customer|null
     */
    public ?Customer $customer;

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
     * @var string|null
     */
    public ?string $gateway = null;

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
            throw ModelAttributeValidationException::invalid('CreditCard', 'number', 'CreditCard number must contain only numbers and must be 16 digits long.');
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
            throw ModelAttributeValidationException::invalid('CreditCard', 'month', 'CreditCard month must contain only numbers and must be 2 digits long.');
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
            throw ModelAttributeValidationException::invalid('CreditCard', 'year', 'CreditCard year must contain only numbers and must be 4 digits long.');
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
            throw ModelAttributeValidationException::invalid('CreditCard', 'cvv', 'CreditCard cvv must contain only numbers and must be 3 or 4 digits long.');
        }
    }

    /**
     * @inheritDoc
     */
    public function validate(array $attributes = []): void
    {
        parent::validate($attributes);
        if (empty($this->id) &&
            empty($this->token) &&
            (
                empty($this->year) ||
                empty($this->month) ||
                empty($this->number) ||
                empty($this->cvv)
            )
        ) {
            throw new ModelAttributeValidationException('The id or token or number, month, year, cvv are required.');
        }
        if (!empty($this->month) && !empty($this->year)) {
            $date = Carbon::createFromFormat('m/Y', $this->month . '/' . $this->year)->lastOfMonth();
            if ($date->isPast()) {
                throw ModelAttributeValidationException::invalid('CreditCard', 'month', 'CreditCard month and year must be in the future.');
            }
        }
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

        parent::fill($data);

        if (
            !empty($this->customer) &&
            !empty($this->customer->name) &&
            is_string($this->customer->name) &&
            is_null($this->firstName) &&
            is_null($this->lastName)
        ) {
            $names = explode(' ', $this->customer->name);
            $this->firstName = $names[0];
            $this->lastName = $names[array_key_last($names)];
        }
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'customer' => $this->customer,
            'description' => $this->description,
            'number' => $this->number,
            'brand' => $this->brand,
            'month' => $this->month,
            'year' => $this->year,
            'cvv' => $this->cvv,
            'last_digits' => $this->lastDigits,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'token' => $this->token,
            'gateway' => $this->gateway,
            'created_at' => $this->createdAt,
        ];
    }
}
