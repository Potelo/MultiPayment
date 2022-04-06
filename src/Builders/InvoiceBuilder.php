<?php

namespace Potelo\MultiPayment\Builders;

use Carbon\Carbon;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Address;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Contracts\Gateway;
use Potelo\MultiPayment\Models\InvoiceItem;

/**
 * invoice builder
 *
 * @method Invoice create()
 * @method Invoice get()
 */
class InvoiceBuilder extends Builder
{

    protected bool $useFallback = true;

    /**
     * InvoiceBuilder constructor.
     *
     * @param  Gateway|string  $gateway
     *
     */
    public function __construct($gateway = null)
    {
        parent::__construct($gateway);
        $this->model = new Invoice($this->gateway);
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
     * set invoice expiresAt
     *
     * @param  Carbon|string  $expiresAt Carbon or string in Y-m-d format
     *
     * @return InvoiceBuilder
     */
    public function setExpiresAt($expiresAt): InvoiceBuilder
    {
        if (is_string($expiresAt)) {
            $expiresAt = Carbon::parse($expiresAt);
        }
        $this->model->expiresAt = $expiresAt;
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
        $invoiceItem = new InvoiceItem($this->gateway);
        $invoiceItem->description = $description;
        $invoiceItem->price = $price;
        $invoiceItem->quantity = $quantity;
        $this->model->items[] = $invoiceItem;
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
    ): InvoiceBuilder
    {
        if (empty($this->model->customer)) {
            $this->model->customer = new Customer($this->gateway);
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
            $this->model->customer = new Customer($this->gateway);
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
            $this->model->customer = new Customer($this->gateway);
        }
        $this->model->customer->address = new Address($this->gateway);
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
        $this->model->creditCard = new CreditCard($this->gateway);
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
        $this->model->creditCard = new CreditCard($this->gateway);
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
    ): InvoiceBuilder
    {
        $this->model->creditCard = new CreditCard($this->gateway);
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
            $this->model->creditCard->customer = new Customer($this->gateway);
            $this->model->creditCard->customer->id = $customer;
        }
        return $this;
    }
}
