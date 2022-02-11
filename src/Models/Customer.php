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
     * @var string
     */
    public string $id;

    /**
     * @var string
     */
    public string $name;

    /**
     * @var string
     */
    public string $email;

    /**
     * @var string
     */
    public string $taxDocument;

    /**
     * @var string
     */
    public string $birthDate;

    /**
     * @var string
     */
    public string $phoneCountryCode;

    /**
     * @var string
     */
    public string $phoneArea;

    /**
     * @var string
     */
    public string $phoneNumber;

    /**
     * @var Address
     */
    public Address $address;

    /**
     * @var string
     */
    public string $gateway;

    /**
     * @var mixed
     */
    public $original;

    /**
     * @var Carbon
     */
    public Carbon $createdAt;

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validateNameAttribute(): void
    {
        $pattern = '/^[A-z\s.-]+$/';
        if (!preg_match($pattern, $this->name)) {
            throw ModelAttributeValidationException::invalid('Customer', 'name', 'The name must be a string with only letters, spaces, dots and dashes.');
        }
    }

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validateEmailAttribute(): void
    {
        if (!filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            throw ModelAttributeValidationException::invalid('Customer', 'email', 'The email must be a valid email.');
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
            throw ModelAttributeValidationException::invalid('Customer', 'taxDocument', 'The taxDocument must be a string with 11 or 14 digits.');
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
            throw ModelAttributeValidationException::invalid('Customer', 'birthDate', 'The birthDate must be a string with the format YYYY-MM-DD.');
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
            throw ModelAttributeValidationException::invalid('Customer', 'phoneCountryCode', 'The phoneCountryCode must be a string with 2 digits.');
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
            throw ModelAttributeValidationException::invalid('Customer', 'phoneArea', 'The phoneArea must be a string with 2 digits.');
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
            throw ModelAttributeValidationException::invalid('Customer', 'phoneNumber', 'The phoneNumber must be a string with 8 or 9 digits.');
        }
    }

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validateAddressAttribute(): void
    {
        if ($this->address instanceof Address) {
            $this->address->validate();
        } else {
            throw ModelAttributeValidationException::invalid('Customer', 'address', 'The address must be an instance of Address.');
        }
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
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'tax_document' => $this->taxDocument,
            'birth_date' => $this->birthDate,
            'phone_country_code' => $this->phoneCountryCode,
            'phone_area' => $this->phoneArea,
            'phone_number' => $this->phoneNumber,
            'address' => !empty($this->address) ? $this->address->toArray() : null,
            'gateway' => $this->gateway,
            'original' => $this->original,
            'created_at' => $this->createdAt
        ];
    }
}
