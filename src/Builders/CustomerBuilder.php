<?php

namespace Potelo\MultiPayment\Builders;

use Potelo\MultiPayment\Models\Address;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Contracts\Gateway;

/**
 * CustomerBuilder class
 */
class CustomerBuilder extends Builder
{
    /**
     * @var Customer $customer
     */
    private Customer $customer;

    /**
     * CustomerBuilder constructor.
     *
     * @param  Gateway  $gateway
     */
    public function __construct($gateway = null)
    {
        parent::__construct($gateway);
        $this->customer = new Customer($this->gateway);
    }

    /**
     * Set the customer email
     *
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
     * Set the customer name
     *
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
     * Set the customer phone
     *
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
     * Set the customer tax document
     *
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
     * Set the customer birthdate
     *
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
     * Set an Address instance
     *
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
     * Add a new address to the customer
     *
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
        $this->customer->address = new Address($this->gateway);
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
     * Create a new customer on gateway and return the MultiPayment customer
     *
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
     * Return Customer instance
     *
     * @return Customer
     */
    public function get(): Customer
    {
        return $this->customer;
    }
}
