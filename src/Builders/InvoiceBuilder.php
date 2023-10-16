<?php

namespace Potelo\MultiPayment\Builders;

use Carbon\Carbon;
use Potelo\MultiPayment\Models\Model;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Address;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Contracts\Gateway;
use Potelo\MultiPayment\Models\InvoiceItem;
use Potelo\MultiPayment\Models\InvoiceCustomVariable;

/**
 * invoice builder
 *
 * @method Invoice get()
 */
class InvoiceBuilder extends Builder
{

    /**
     * InvoiceBuilder constructor.
     *
     * @param  Gateway|string  $gateway
     *
     */
    public function __construct($gateway = null)
    {
        parent::__construct($gateway);
        $this->model = new Invoice();
    }

    /**
     * @return \Potelo\MultiPayment\Models\Invoice
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ChargingException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     */
    public function create(): Invoice
    {
        return parent::create();
    }

    /**
     * Set the invoice payment method
     *
     * @param  string  $paymentMethod
     *
     * @return InvoiceBuilder
     */
    public function setPaymentMethod(string $paymentMethod): InvoiceBuilder
    {
        $this->model->paymentMethod = $paymentMethod;
        return $this;
    }

    /**
     * set invoice DueAt
     *
     * @param  Carbon|string  $dueAt Carbon or string in Y-m-d format
     *
     * @return InvoiceBuilder
     */
    public function setDueAt($dueAt): InvoiceBuilder
    {
        if (is_string($dueAt)) {
            $dueAt = Carbon::parse($dueAt);
        }
        $this->model->dueAt = $dueAt;
        return $this;
    }

    /**
     * Set the invoice items
     *
     * @param  InvoiceItem[]  $items
     *
     * @return InvoiceBuilder
     */
    public function setItems(array $items): InvoiceBuilder
    {
        $this->model->items = $items;
        return $this;
    }

    /**
     * Add an item to the invoice
     *
     * @param  string  $description
     * @param  int  $price
     * @param  int  $quantity
     *
     * @return $this
     */
    public function addItem(string $description, int $price, int $quantity): InvoiceBuilder
    {
        $invoiceItem = new InvoiceItem();
        $invoiceItem->description = $description;
        $invoiceItem->price = $price;
        $invoiceItem->quantity = $quantity;
        $this->model->items[] = $invoiceItem;
        return $this;
    }

    /**
     * Set the invoice custom variables
     *
     * @param  InvoiceCustomVariable[]  $customVariables
     *
     * @return InvoiceBuilder
     */
    public function setCustomVariables(array $customVariables): InvoiceBuilder
    {
        $this->model->customVariables = $customVariables;
        return $this;
    }

    /**
     * Add an custom variable to the invoice
     *
     * @param  string  $name
     * @param  string  $value
     *
     * @return $this
     */
    public function addCustomVariable(string $name, string $value): InvoiceBuilder
    {
        $invoiceCustomVariable = new InvoiceCustomVariable();
        $invoiceCustomVariable->name = $name;
        $invoiceCustomVariable->value = $value;
        $this->model->customVariables[] = $invoiceCustomVariable;
        return $this;
    }

    /**
     * Add a Customer instance to the invoice
     * @param  Customer  $customer
     *
     * @return $this
     */
    public function setCustomer(Customer $customer): InvoiceBuilder
    {
        $this->model->customer = $customer;
        return $this;
    }

    /**
     * Add the customer data to the invoice
     *
     * @param  string|null  $name
     * @param  string|null  $email
     * @param  string|null  $taxDocument
     * @param  string|null  $birthDate
     * @param  string|null  $phoneArea
     * @param  string|null  $phoneNumber
     * @param  string|null  $phoneCountryCode
     *
     * @return $this
     */
    public function addCustomer(
        ?string $name = null,
        ?string $email = null,
        ?string $taxDocument = null,
        ?string $birthDate = null,
        ?string $phoneArea = null,
        ?string $phoneNumber = null,
        ?string $phoneCountryCode = '55'
    ): InvoiceBuilder {
        if (empty($this->model->customer)) {
            $this->model->customer = new Customer();
        }
        $this->model->customer->name = $name;
        $this->model->customer->email = $email;
        $this->model->customer->taxDocument = $taxDocument;
        $this->model->customer->birthDate = $birthDate;
        $this->model->customer->phoneArea = $phoneArea;
        $this->model->customer->phoneNumber = $phoneNumber;
        $this->model->customer->phoneCountryCode = $phoneCountryCode;
        return $this;
    }

    /**
     * Add an Address instance to the Customer
     *
     * @param  Address  $address
     *
     * @return $this
     */
    public function setCustomerAddress(Address $address): InvoiceBuilder
    {
        if (empty($this->model->customer)) {
            $this->model->customer = new Customer();
        }
        $this->model->customer->address = $address;
        return $this;
    }

    /**
     * Add the customer address
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
    public function addCustomerAddress(
        string $zipCode,
        string $street,
        string $number = 'S/N',
        ?string $complement = null,
        ?string $district = null,
        ?string $city = null,
        ?string $state = null,
        ?string $country = null
    ): InvoiceBuilder {
        if (empty($this->model->customer)) {
            $this->model->customer = new Customer();
        }
        $this->model->customer->address = new Address();
        $this->model->customer->address->zipCode = $zipCode;
        $this->model->customer->address->street = $street;
        $this->model->customer->address->number = $number;
        $this->model->customer->address->complement = $complement;
        $this->model->customer->address->district = $district;
        $this->model->customer->address->city = $city;
        $this->model->customer->address->state = $state;
        $this->model->customer->address->country = $country;
        return $this;
    }

    /**
     * Add a CreditCard instance to the invoice.
     *
     * @param  CreditCard  $creditCard
     *
     * @return $this
     */
    public function setCreditCard(CreditCard $creditCard): InvoiceBuilder
    {
        $this->model->creditCard = $creditCard;
        return $this;
    }

    /**
     * Add id of an existing credit card.
     *
     * @param $id
     *
     * @return $this
     */
    public function addCreditCardId($id): InvoiceBuilder
    {
        $this->model->creditCard = new CreditCard();
        $this->model->creditCard->id = $id;
        return $this;
    }

    /**
     * Add a credit card token.
     *
     * @param $token
     *
     * @return $this
     */
    public function addCreditCardToken($token): InvoiceBuilder
    {
        $this->model->creditCard = new CreditCard();
        $this->model->creditCard->token = $token;
        return $this;
    }

    /**
     * Add credit card data to create a new credit card on gateway.
     *
     * @param  string  $number
     * @param  string  $month
     * @param  string  $year
     * @param  string  $cvv
     * @param  string  $firstName
     * @param  string  $lastName
     * @param  Customer|mixed|null  $customer
     * @param  string  $description
     *
     * @return $this
     */
    public function addCreditCard(
        string $number,
        string $month,
        string $year,
        string $cvv,
        string $firstName,
        string $lastName,
        $customer = null,
        string $description = 'Cartão de crédito'
    ): InvoiceBuilder {
        $this->model->creditCard = new CreditCard();
        $this->model->creditCard->number = $number;
        $this->model->creditCard->month = $month;
        $this->model->creditCard->year = $year;
        $this->model->creditCard->cvv = $cvv;
        $this->model->creditCard->firstName = $firstName;
        $this->model->creditCard->lastName = $lastName;
        $this->model->creditCard->description = $description;
        if ($customer instanceof Customer) {
            $this->model->creditCard->customer = $customer;
        } else {
            $this->model->creditCard->customer = new Customer();
            $this->model->creditCard->customer->id = $customer;
        }
        return $this;
    }
}
