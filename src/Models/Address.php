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
     * @var string
     */
    public string $type = self::TYPE_BILLING;

    /**
     * @var string
     */
    public string $street;

    /**
     * @var string
     */
    public string $number;

    /**
     * @var string
     */
    public string $district;

    /**
     * @var string
     */
    public string $city;

    /**
     * @var string
     */
    public string $state;

    /**
     * @var int
     */
    public?int $zipCode;

    /**
     * @var string
     */
    public string $complement;

    /**
     * @var string
     */
    public string $country;

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
        $pattern = '/^([0-9]+[a-zA-Z]*|S\/N)$/';
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
}
