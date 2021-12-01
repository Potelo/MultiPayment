<?php

namespace Potelo\MultiPayment\Models;

/**
 * Class CreditCard
 */
class CreditCard extends Model
{

    /**
     * @var string|null
     */
    public ?string $number = null;

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
     * @inerhitDoc
     */
    public function toArray()
    {
        return [
            'number' => $this->number,
            'month' => $this->month,
            'year' => $this->year,
            'cvv' => $this->cvv,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'token' => $this->token,
        ];
    }
}
