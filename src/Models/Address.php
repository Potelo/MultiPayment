<?php

namespace Potelo\MultiPayment\Models;

use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

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
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validateTypeAttribute(): void
    {
        if (! in_array($this->type, [self::TYPE_BILLING, self::TYPE_SHIPPING], true)) {
            throw ModelAttributeValidationException::invalid('Address', 'Must be either "BILLING" or "SHIPPING"');
        }
    }

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validateNumberAttribute(): void
    {
        //regex for number and letter or the string "S/N"
        $pattern = '/^([0-9]+[a-zA-Z]*|S/N)$/';
        if (! preg_match($pattern, $this->number)) {
            throw ModelAttributeValidationException::invalid('Address', 'number', 'Must be a valid number or "S/N"');
        }

    }

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validateZipCodeAttribute(): void
    {
        $pattern = '/^[0-9]{8}$/';
        if (! preg_match($pattern, $this->zipCode)) {
            throw ModelAttributeValidationException::invalid('Address', 'zipCode', 'Must contain 8 digits without spaces or dashes');
        }
    }

    /**
     * @inheritDoc
     */
    public function fill(array $data): void
    {
        if (empty($data['type'])) {
            $data['type'] = Address::TYPE_BILLING;
        }
        parent::fill($data);
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
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
