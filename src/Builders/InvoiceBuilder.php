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
 */
class InvoiceBuilder
{

    /**
     * @var Gateway $gateway
     */
    private Gateway $gateway;

    /**
     * @var Invoice $invoice
     */
    private Invoice $invoice;

    /**
     * InvoiceBuilder constructor.
     *
     * @param  Gateway|string  $gateway
     *
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function __construct($gateway)
    {
        $this->gateway = $gateway;
        $this->invoice = new Invoice($gateway);
    }

    /**
     * @param  string  $paymentMethod
     *
     * @return InvoiceBuilder
     */
    public function setPaymentMethod(string $paymentMethod): InvoiceBuilder
    {
        $this->invoice->paymentMethod = $paymentMethod;
        return $this;
    }

    /**
     * @param  string  $expirationDate Format: Y-m-d
     *
     * @return InvoiceBuilder
     */
    public function setExpirationDate(string $expirationDate): InvoiceBuilder
    {
        $this->invoice->expirationDate = Carbon::createFromFormat('Y-m-d', $expirationDate);
        return $this;
    }

    /**
     * @param  InvoiceItem[]  $items
     *
     * @return InvoiceBuilder
     */
    public function setItems(array $items): InvoiceBuilder
    {
        $this->invoice->items = $items;
        return $this;
    }

    /**
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
        $this->invoice->items[] = $invoiceItem;
        return $this;
    }

    /**
     * @param  Customer  $customer
     *
     * @return $this
     */
    public function setCustomer(Customer $customer): InvoiceBuilder
    {
        $this->invoice->customer = $customer;
        return $this;
    }

    /**
     * @param  string|null  $name
     * @param  string|null  $email
     * @param  string|null  $taxDocument
     * @param  string|null  $phoneArea
     * @param  string|null  $phoneNumber
     * @param  string|null  $phoneCountryCode
     *
     * @return $this
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function addCustomer(
        ?string $name = null,
        ?string $email = null,
        ?string $taxDocument = null,
        ?string $phoneArea = null,
        ?string $phoneNumber = null,
        ?string $phoneCountryCode = '55'
    ): InvoiceBuilder
    {
        if (empty($this->invoice->customer)) {
            $this->invoice->customer = new Customer($this->gateway);
        }
        $this->invoice->customer->name = $name;
        $this->invoice->customer->email = $email;
        $this->invoice->customer->taxDocument = $taxDocument;
        $this->invoice->customer->phoneArea = $phoneArea;
        $this->invoice->customer->phoneNumber = $phoneNumber;
        $this->invoice->customer->phoneCountryCode = $phoneCountryCode;
        return $this;
    }

    /**
     * @param  Address  $address
     *
     * @return $this
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function setCustomerAddress(Address $address): InvoiceBuilder
    {
        if (empty($this->invoice->customer)) {
            $this->invoice->customer = new Customer($this->gateway);
        }
        $this->invoice->customer->address = $address;
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
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
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
        if (empty($this->invoice->customer)) {
            $this->invoice->customer = new Customer($this->gateway);
        }
        $this->invoice->customer->address = new Address();
        $this->invoice->customer->address->zipCode = $zipCode;
        $this->invoice->customer->address->street = $street;
        $this->invoice->customer->address->number = $number;
        $this->invoice->customer->address->complement = $complement;
        $this->invoice->customer->address->district = $district;
        $this->invoice->customer->address->city = $city;
        $this->invoice->customer->address->state = $state;
        $this->invoice->customer->address->country = $country;
        return $this;
    }

    /**
     * @param  CreditCard  $creditCard
     *
     * @return $this
     */
    public function setCreditCard(CreditCard $creditCard): InvoiceBuilder
    {
        $this->invoice->creditCard = $creditCard;
        return $this;
    }

    /**
     * @param $id
     *
     * @return $this
     */
    public function addCreditCardId($id): InvoiceBuilder
    {
        $this->invoice->creditCard = new CreditCard();
        $this->invoice->creditCard->id = $id;
        return $this;
    }

    /**
     * @param $token
     *
     * @return $this
     */
    public function addCreditCardToken($token): InvoiceBuilder
    {
        $this->invoice->creditCard = new CreditCard();
        $this->invoice->creditCard->token = $token;
        return $this;
    }

    /**
     * @param  string  $description
     * @param  string  $number
     * @param  string  $brand
     * @param  string  $month
     * @param  string  $year
     * @param  string  $cvv
     * @param  string  $firstName
     * @param  string  $lastName
     * @param  Customer|mixed|null  $customer
     *
     * @return $this
     */
    public function addCreditCard(
        string $description,
        string $number,
        string $brand,
        string $month,
        string $year,
        string $cvv,
        string $firstName,
        string $lastName,
        $customer = null
    ): InvoiceBuilder
    {
        $this->invoice->creditCard = new CreditCard();
        $this->invoice->creditCard->description = $description;
        $this->invoice->creditCard->number = $number;
        $this->invoice->creditCard->brand = $brand;
        $this->invoice->creditCard->month = $month;
        $this->invoice->creditCard->year = $year;
        $this->invoice->creditCard->cvv = $cvv;
        $this->invoice->creditCard->firstName = $firstName;
        $this->invoice->creditCard->lastName = $lastName;
        if ($customer instanceof Customer) {
            $this->invoice->creditCard->customer = $customer;
        } else {
            $this->invoice->creditCard->customer = new Customer();
            $this->invoice->creditCard->customer->id = $customer;
        }
        return $this;
    }

    /**
     * @return Invoice
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     */
    public function create(): Invoice
    {
        $this->invoice->validate();
        if (empty($this->invoice->customer->id)) {
            $this->invoice->customer->save(false);
        }
        if (!empty($this->invoice->creditCard) && empty($this->invoice->creditCard->id)) {
            $this->invoice->creditCard->customer = $this->invoice->customer;
        }

        $this->invoice->save(false);
        return $this->invoice;
    }

    /**
     * @return Invoice
     */
    public function get(): Invoice
    {
        return $this->invoice;
    }
}
