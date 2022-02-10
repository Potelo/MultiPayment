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
     * @var string|null
     */
    public ?string $birthDate = null;

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
    protected function validateNameAttribute(): void
    {
        $regex = '/^[A-z\s.-]+$/';
        preg_match($regex, $this->name, $matches);
        if (count($matches) === 0) {
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
        $regex = '/^[0-9]{11,14}$/';
        preg_match($regex, $this->taxDocument, $matches);
        if (count($matches) === 0) {
            throw ModelAttributeValidationException::invalid('Customer', 'taxDocument', 'The taxDocument must be a string with 11 or 14 digits.');
        }
    }

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validateBirthDateAttribute(): void
    {
        $regex = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/';
        preg_match($regex, $this->birthDate, $matches);
        if (count($matches) === 0) {
            throw ModelAttributeValidationException::invalid('Customer', 'birthDate', 'The birthDate must be a string with the format YYYY-MM-DD.');
        }
    }

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validatePhoneCountryCodeAttribute(): void
    {
        $regex = '/^[0-9]{2}$/';
        preg_match($regex, $this->phoneCountryCode, $matches);
        if (count($matches) === 0) {
            throw ModelAttributeValidationException::invalid('Customer', 'phoneCountryCode', 'The phoneCountryCode must be a string with 2 digits.');
        }
    }

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validatePhoneAreaAttribute(): void
    {
        $regex = '/^[0-9]{2}$/';
        preg_match($regex, $this->phoneArea, $matches);
        if (count($matches) === 0) {
            throw ModelAttributeValidationException::invalid('Customer', 'phoneArea', 'The phoneArea must be a string with 2 digits.');
        }
    }

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validatePhoneNumberAttribute(): void
    {
        $regex = '/^[0-9]{8,9}$/';
        preg_match($regex, $this->phoneNumber, $matches);
        if (count($matches) === 0) {
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
            'address' => !is_null($this->address) ? $this->address->toArray() : null,
            'gateway' => $this->gateway,
            'original' => $this->original,
            'created_at' => $this->createdAt
        ];
    }
}
