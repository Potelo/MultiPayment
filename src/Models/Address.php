<?php

namespace Potelo\MultiPayment\Models;

/**
 * Class Address
 */
class Address extends Model
{
    public const TYPE_BILLING = 'BILLING';
    public const TYPE_SHIPPING = 'SHIPPING';

    /**
     * @var string|null
     */
    public ?string $type = self::TYPE_BILLING;

    /**
     * @var string|null
     */
    public ?string $street = null;

    /**
     * @var string|null
     */
    public ?string $number = null;

    /**
     * @var string|null
     */
    public ?string $district = null;

    /**
     * @var string|null
     */
    public ?string $city = null;

    /**
     * @var string|null
     */
    public ?string $state = null;

    /**
     * @var int|null
     */
    public ?int $zipCode = null;

    /**
     * @var string|null
     */
    public ?string $complement = null;

    /**
     * @var string|null
     */
    public ?string $country = null;

    /**
     * to array.
     */

    public function toArray()
    {
        return [
            'type' => $this->type,
            'street' => $this->street,
            'number' => $this->number,
            'district' => $this->district,
            'city' => $this->city,
            'state' => $this->state,
            'zip_code' => $this->zipCode,
            'complement' => $this->complement,
            'country' => $this->country,
        ];
    }
}
