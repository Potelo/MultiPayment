<?php

namespace Potelo\MultiPayment\Models;

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
     * @var \DateTimeImmutable|null
     */
    public ?\DateTimeImmutable $createdAt = null;
    /**
     * @inerhitDoc
     */
    public function create(array $data)
    {

        if (array_key_exists('address', $data)
        ) {
            if (! array_key_exists('type', $data['address'])) {
                $data['address']['type'] = Address::TYPE_BILLING;
            }
            $address = new Address();
            $address->create($data['address']);
            $data['address'] = $address;
        }

        foreach ($data as $key => $value) {
            $key = lcfirst(str_replace('_', '', ucwords($key, '_')));
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }

    public function toArray()
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
