<?php

namespace Potelo\MultiPayment\Models;

use Carbon\Carbon;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Class Customer
 */
class Customer extends Model
{

    /**
     * @var string|null
     */
    public ?string $id = null;

    /**
     * @var string|null
     */
    public ?string $name = null;

    /**
     * @var string|null
     */
    public ?string $email = null;

    /**
     * @var string|null
     */
    public ?string $taxDocument = null;

    /**
     * @var Carbon|null
     */
    public ?Carbon $birthDate = null;

    /**
     * @var string|null
     */
    public ?string $phoneCountryCode = null;

    /**
     * @var string|null
     */
    public ?string $phoneArea = null;

    /**
     * @var string|null
     */
    public ?string $phoneNumber = null;

    /**
     * @var Address|null
     */
    public ?Address $address = null;

    /**
     * @var string|null
     */
    public ?string $gateway = null;

    /**
     * @var mixed|null
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
    protected function validateEmailAttribute(): void
    {
        if (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            throw ModelAttributeValidationException::invalid($this->getClassName(), 'email', 'The email must be a valid email.');
        }
    }

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validatePhoneCountryCodeAttribute(): void
    {
        $pattern = '/^[0-9]{2}$/';
        if (!preg_match($pattern, $this->phoneCountryCode)) {
            throw ModelAttributeValidationException::invalid($this->getClassName(), 'phoneCountryCode', 'The phoneCountryCode must be a string with 2 digits.');
        }
    }

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validatePhoneAreaAttribute(): void
    {
        $pattern = '/^[0-9]{2}$/';
        if (!preg_match($pattern, $this->phoneArea)) {
            throw ModelAttributeValidationException::invalid($this->getClassName(), 'phoneArea', 'The phoneArea must be a string with 2 digits.');
        }
    }

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validatePhoneNumberAttribute(): void
    {
        $pattern = '/^[0-9]{8,9}$/';
        if (!preg_match($pattern, $this->phoneNumber)) {
            throw ModelAttributeValidationException::invalid($this->getClassName(), 'phoneNumber', 'The phoneNumber must be a string with 8 or 9 digits.');
        }
    }

    /**
     * @inheritDoc
     */
    protected function attributesExtraValidation(array $attributes): void
    {
        if (in_array('phone_area', $attributes) &&
            empty($this->phoneArea) && !empty($this->phoneNumber)) {
            throw ModelAttributeValidationException::invalid($this->getClassName(), 'phone_area', 'The phone_area is required when phone_number is informed.');
        }
    }

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validateAddressAttribute(): void
    {
        $this->address->validate();
    }

    /**
     * @inheritDoc
     */
    public function fill(array $data): void
    {
        if (!empty($data['address']) && is_array($data['address'])) {
            $address = new Address();
            $address->fill($data['address']);
            $data['address'] = $address;
        }
        parent::fill($data);
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        if (isset($array['address'])) {
            $array['address'] = $array['address']->toArray();
        }
        return $array;
    }
}
