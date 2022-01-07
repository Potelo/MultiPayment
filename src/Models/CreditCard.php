<?php

namespace Potelo\MultiPayment\Models;

use DateTimeImmutable;

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
     * @var DateTimeImmutable|null
     */
    public ?DateTimeImmutable $createdAt = null;

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
