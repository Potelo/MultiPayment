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
     * @var string|null
     */
    public ?string $street;

    /**
     * @var string|null
     */
    public ?string $number;

    /**
     * @var string|null
     */
    public ?string $district;

    /**
     * @var string|null
     */
    public ?string $city;

    /**
     * @var string|null
     */
    public ?string $state;

    /**
     * @var string|null
     */
    public ?string $zipCode;

    /**
     * @var string|null
     */
    public ?string $complement;

    /**
     * @var string|null
     */
    public ?string $country;

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validateTypeAttribute(): void
    {
        if (! in_array($this->type, [self::TYPE_BILLING, self::TYPE_SHIPPING], true)) {
            throw ModelAttributeValidationException::invalid($this->getClassName(), 'Must be either "BILLING" or "SHIPPING"');
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
            throw ModelAttributeValidationException::invalid($this->getClassName(), 'number', 'Must be a valid number or "S/N"');
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
            throw ModelAttributeValidationException::invalid($this->getClassName(), 'zipCode', 'Must contain 8 digits without spaces or dashes');
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
