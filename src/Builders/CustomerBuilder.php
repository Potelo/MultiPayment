<?php

namespace Potelo\MultiPayment\Builders;

use Potelo\MultiPayment\Models\Address;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Contracts\Gateway;

/**
 * CustomerBuilder class
 */
class CustomerBuilder
{
    /**
     * @var Customer $customer
     */
    private Customer $customer;

    /**
     * CustomerBuilder constructor.
     *
     * @param  Gateway  $gateway
     *
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function __construct(Gateway $gateway)
    {
        $this->customer = new Customer($gateway);
    }

    /**
     * @param  string  $email
     *
     * @return $this
     */
    public function setEmail(string $email): CustomerBuilder
    {
        $this->customer->email = $email;
        return $this;
    }

    /**
     * @param  string  $name
     *
     * @return $this
     */
    public function setName(string $name): CustomerBuilder
    {
        $this->customer->name = $name;
        return $this;
    }

    /**
     * @param  string  $phoneNumber
     * @param  string  $phoneArea
     * @param  string  $phoneCountryCode
     *
     * @return $this
     */
    public function setPhone(string $phoneNumber, string $phoneArea, string $phoneCountryCode = '55'): CustomerBuilder
    {
        $this->customer->phoneNumber = $phoneNumber;
        $this->customer->phoneArea = $phoneArea;
        $this->customer->phoneCountryCode = $phoneCountryCode;
        return $this;
    }

    /**
     * @param  string  $taxDocument
     *
     * @return $this
     */
    public function setTaxDocument(string $taxDocument): CustomerBuilder
    {
        $this->customer->taxDocument = $taxDocument;
        return $this;
    }

    /**
     * @param  string  $birthDate Format: Y-m-d
     *
     * @return $this
     */
    public function setBirthDate(string $birthDate): CustomerBuilder
    {
        $this->customer->birthDate = $birthDate;
        return $this;
    }

    /**
     * @param  Address  $address
     *
     * @return $this
     */
    public function setAddress(Address $address): CustomerBuilder
    {
        $this->customer->address = $address;
        return $this;
    }

    /**
     * @param  string  $zipCode
     * @param  string  $street
     * @param  string  $number
     * @param  string|null  $complement
     * @param  string|null  $district
     * @param  string|null  $city
     * @param  string|null  $state
     * @param  string|null  $country
     *
     * @return $this
     */
    public function addAddress(
        string $zipCode,
        string $street,
        string $number = 'S/N',
        ?string $complement = null,
        ?string $district = null,
        ?string $city = null,
        ?string $state = null,
        ?string $country = null
    ): CustomerBuilder {
        $this->customer->address = new Address();
        $this->customer->address->zipCode = $zipCode;
        $this->customer->address->street = $street;
        $this->customer->address->number = $number;
        $this->customer->address->complement = $complement;
        $this->customer->address->district = $district;
        $this->customer->address->city = $city;
        $this->customer->address->state = $state;
        $this->customer->address->country = $country;
        return $this;
    }

    /**
     * @return Customer
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     */
    public function create(): Customer
    {
        $this->customer->save();
        return $this->customer;

    }

    /**
     * @return Customer
     */
    public function get(): Customer
    {
        return $this->customer;
    }
}
