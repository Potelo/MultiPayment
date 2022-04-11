<?php

namespace Potelo\MultiPayment\Builders;

use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Contracts\Gateway;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Helpers\ConfigurationHelper;

/**
 * CustomerBuilder class
 *
 * @method CreditCard create()
 * @method CreditCard get()
 */
class CreditCardBuilder extends Builder
{

    /**
     * CustomerBuilder constructor.
     *
     * @param  Gateway  $gateway
     */
    public function __construct($gateway = null)
    {
        parent::__construct($gateway);
        $this->model = new CreditCard();
    }

    /**
     * Set the first name.
     *
     * @param  string  $firstName
     *
     * @return $this
     */
    public function setFirstName(string $firstName): self
    {
        $this->model->firstName = $firstName;

        return $this;
    }

    /**
     * Set the last name.
     *
     * @param  string  $lastName
     *
     * @return $this
     */
    public function setLastName(string $lastName): self
    {
        $this->model->lastName = $lastName;

        return $this;
    }

    /**
     * Set the customer
     *
     * @param  Customer  $customer
     *
     * @return $this
     */
    public function setCustomer(Customer $customer): self
    {
        $this->model->customer = $customer;

        return $this;
    }

    /**
     * Set the customer id
     *
     * @param  string  $id
     *
     * @return $this
     */
    public function setCustomerId(string $id): self
    {
        $this->model->customer = new Customer();
        $this->model->customer->id = $id;

        return $this;
    }

    /**
     * Set the card number.
     *
     * @param  string  $number
     *
     * @return $this
     */
    public function setNumber(string $number): self
    {
        $this->model->number = $number;

        return $this;
    }

    /**
     * Set the expiration month.
     *
     * @param  string  $month
     *
     * @return $this
     */
    public function setMonth(string $month): self
    {
        $this->model->month = $month;

        return $this;
    }

    /**
     * Set the expiration year.
     *
     * @param  string  $year
     *
     * @return $this
     */
    public function setYear(string $year): self
    {
        $this->model->year = $year;

        return $this;
    }

    /**
     * Set the cvv.
     *
     * @param  string  $cvv
     *
     * @return $this
     */
    public function setCvv(string $cvv): self
    {
        $this->model->cvv = $cvv;

        return $this;
    }

    /**
     * Set the cardholder name.
     *
     * @param  string  $description
     *
     * @return $this
     */
    public function setDescription(string $description): self
    {
        $this->model->description = $description;

        return $this;
    }

    /**
     * Set the token.
     *
     * @param  string  $token
     * @param  Gateway|string|null  $gateway
     *
     * @return $this
     */
    public function setToken(string $token, $gateway = null): self
    {
        $gateway = ConfigurationHelper::resolveGateway($gateway ?? $this->gateway);
        $this->model->setToken($token, $gateway);
        return $this;
    }

    /**
     * @param  array|null  $tokens
     *
     * @return $this
     */
    public function setTokens(?array $tokens): self
    {
        foreach ($tokens as $gateway => $token) {
            $gateway = ConfigurationHelper::resolveGateway($gateway);
            $this->setToken($token, $gateway);
        }
        return $this;
    }
}
