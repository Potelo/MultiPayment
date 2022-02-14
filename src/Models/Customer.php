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
    public ?string $id;

    /**
     * @var string|null
     */
    public ?string $name;

    /**
     * @var string|null
     */
    public ?string $email;

    /**
     * @var string|null
     */
    public ?string $taxDocument;

    /**
     * @var string|null
     */
    public ?string $birthDate;

    /**
     * @var string|null
     */
    public ?string $phoneCountryCode;

    /**
     * @var string|null
     */
    public ?string $phoneArea;

    /**
     * @var string|null
     */
    public ?string $phoneNumber;

    /**
     * @var Address|null
     */
    public ?Address $address;

    /**
     * @var string|null
     */
    public ?string $gateway;

    /**
     * @var mixed|null
     */
    public $original;

    /**
     * @var Carbon|null
     */
    public ?Carbon $createdAt;

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
    protected function validateTaxDocumentAttribute(): void
    {
        //regex for 11 or 14 digits
        $pattern = '/^[0-9]{11,14}$/';
        if (!preg_match($pattern, $this->taxDocument)) {
            throw ModelAttributeValidationException::invalid($this->getClassName(), 'taxDocument', 'The taxDocument must be a string with 11 or 14 digits.');
        }
    }

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validateBirthDateAttribute(): void
    {
        $pattern = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/';
        if (!preg_match($pattern, $this->birthDate)) {
            throw ModelAttributeValidationException::invalid($this->getClassName(), 'birthDate', 'The birthDate must be a string with the format YYYY-MM-DD.');
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
