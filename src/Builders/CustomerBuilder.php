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
     * CustomerBuilder constructor.
     *
     * @param  Gateway  $gateway
     *
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function __construct(Gateway $gateway)
    {
        $this->model = new Customer($gateway);
    }

    /**
     * @param  string  $email
     *
     * @return $this
     */
    public function setEmail(string $email): CustomerBuilder
    {
        $this->model->email = $email;
        $this->addValidationAttribute('email');
        return $this;
    }

    /**
     * @param  string  $name
     *
     * @return $this
     */
    public function setName(string $name): CustomerBuilder
    {
        $this->model->name = $name;
        $this->addValidationAttribute('name');
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
        $this->model->phoneNumber = $phoneNumber;
        $this->model->phoneArea = $phoneArea;
        $this->model->phoneCountryCode = $phoneCountryCode;
        $this->addValidationAttribute('phoneNumber');
        $this->addValidationAttribute('phoneArea');
        $this->addValidationAttribute('phoneCountryCode');
        return $this;
    }

    /**
     * @param  string  $taxDocument
     *
     * @return $this
     */
    public function setTaxDocument(string $taxDocument): CustomerBuilder
    {
        $this->model->taxDocument = $taxDocument;
        $this->addValidationAttribute('taxDocument');
        return $this;
    }

    /**
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
    public function setAddress(
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
        $this->addValidationAttribute('address');
        return $this;
    }

    /**
     * @return Customer
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     */
    public function create(): Customer
    {
        $this->model->validate($this->validationAttributes);
        $this->model->save(false);
        return $this->model;

    }
}