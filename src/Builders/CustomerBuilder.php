<?php

namespace Potelo\MultiPayment\Builders;

use Potelo\MultiPayment\Models\Address;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Contracts\Gateway;

/**
 * CustomerBuilder class
 *
 * @method Customer create()
 * @method Customer get()
 */
class CustomerBuilder extends Builder
{

    /**
     * CustomerBuilder constructor.
     *
     * @param  Gateway  $gateway
     */
    public function __construct($gateway = null)
    {
        parent::__construct($gateway);
        $this->model = new Customer();
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
        $this->model->email = $email;
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
        $this->model->name = $name;
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
        $this->model->phoneNumber = $phoneNumber;
        $this->model->phoneArea = $phoneArea;
        $this->model->phoneCountryCode = $phoneCountryCode;
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
        $this->model->taxDocument = $taxDocument;
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
        $this->model->birthDate = $birthDate;
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
        $this->model->address = $address;
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
        $this->model->address = new Address();
        $this->model->address->zipCode = $zipCode;
        $this->model->address->street = $street;
        $this->model->address->number = $number;
        $this->model->address->complement = $complement;
        $this->model->address->district = $district;
        $this->model->address->city = $city;
        $this->model->address->state = $state;
        $this->model->address->country = $country;
        return $this;
    }
}
