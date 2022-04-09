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
    public ?string $description;

    /**
     * @var string|null
     */
    public ?string $number;

    /**
     * @var string|null
     */
    public ?string $brand;

    /**
     * @var string|null
     */
    public ?string $month;

    /**
     * @var string|null
     */
    public ?string $year;

    /**
     * @var string|null
     */
    public ?string $cvv;

    /**
     * @var string|null
     */
    public ?string $lastDigits;

    /**
     * @var string|null
     */
    public ?string $firstName;

    /**
     * @var string|null
     */
    public ?string $lastName;

    /**
     * @var string|null
     */
    public ?string $token;

    /**
     * @var string|null
     */
    public ?string $gateway;

    /**
     * @var Carbon|null
     */
    public ?Carbon $createdAt;

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
            throw new ModelAttributeValidationException('The `id` or `token` or [`number`, `month`, `year`, `cvv`, `firstName` and `lastName` are required.');
        }
        if (in_array('month', $attributes) && in_array('year', $attributes) && !empty($this->month) && !empty($this->year)) {
            $date = Carbon::createFromFormat('m/Y', $this->month . '/' . $this->year)->lastOfMonth();
            if ($date->isPast()) {
                throw ModelAttributeValidationException::invalid($this->getClassName(), 'month and year', 'CreditCard month and year must be in the future.');
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
            empty($this->firstName) &&
            empty($this->lastName)
        ) {
            $names = explode(' ', $this->customer->name);
            $this->firstName = $names[0];
            $this->lastName = $names[array_key_last($names)];
        }
    }

}
