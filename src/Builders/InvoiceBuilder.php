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
class InvoiceBuilder extends Builder
{

    /**
     * @var bool
     */
    private bool $createCustomer = false;

    /**
     * @var bool
     */
    private bool $createCreditCard = false;


    /**
     * InvoiceBuilder constructor.
     *
     * @param  Gateway  $gateway
     *
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function __construct(Gateway $gateway)
    {
        $this->model = new Invoice($gateway);
    }

    /**
     * @param  string  $paymentMethod
     *
     * @return InvoiceBuilder
     */
    public function setPaymentMethod(string $paymentMethod): InvoiceBuilder
    {
        $this->model->paymentMethod = $paymentMethod;
        $this->addValidationAttribute('paymentMethod');
        return $this;
    }

    /**
     * @param  string  $expirationDate Format: Y-m-d
     *
     * @return InvoiceBuilder
     */
    public function setExpirationDate(string $expirationDate): InvoiceBuilder
    {
        $this->model->expirationDate = Carbon::createFromFormat('Y-m-d', $expirationDate);
        $this->addValidationAttribute('expirationDate');
        return $this;
    }

    /**
     * @param  InvoiceItem[]  $items
     *
     * @return InvoiceBuilder
     */
    public function setItems(array $items): InvoiceBuilder
    {
        $this->model->items = $items;
        $this->addValidationAttribute('items');
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
        $this->model->items[] = $invoiceItem;
        $this->addValidationAttribute('items');
        return $this;
    }

    /**
     * @param  Customer  $customer
     * @param  bool  $save
     *
     * @return $this
     */
    public function setCustomer(Customer $customer, bool $save = true): InvoiceBuilder
    {
        $this->model->customer = $customer;
        if ($save) {
            $this->createCustomer = true;
        }
        $this->addValidationAttribute('customer');
        return $this;
    }

    /**
     * @param  string|null  $name
     * @param  string|null  $email
     * @param  string|null  $taxDocument
     * @param  Address|null  $address
     * @param  string|null  $phoneArea
     * @param  string|null  $phoneNumber
     * @param  string|null  $phoneCountryCode
     * @param  bool  $save
     *
     * @return $this
     */
    public function addCustomer(
        ?string $name = null,
        ?string $email = null,
        ?string $taxDocument = null,
        ?Address $address = null,
        ?string $phoneArea = null,
        ?string $phoneNumber = null,
        ?string $phoneCountryCode = '55',
        bool $save = true

    ): InvoiceBuilder
    {
        $this->model->customer = new Customer();
        $this->model->customer->name = $name;
        $this->model->customer->email = $email;
        $this->model->customer->taxDocument = $taxDocument;
        $this->model->customer->address = $address;
        $this->model->customer->phoneArea = $phoneArea;
        $this->model->customer->phoneNumber = $phoneNumber;
        $this->model->customer->phoneCountryCode = $phoneCountryCode;
        $this->addValidationAttribute('customer');
        $this->createCustomer = $save;
        return $this;
    }

    /**
     * @param  CreditCard  $creditCard
     *
     * @return $this
     */
    public function setCreditCard(CreditCard $creditCard): InvoiceBuilder
    {
        $this->model->creditCard = $creditCard;
        $this->addValidationAttribute('creditCard');
        return $this;
    }

    /**
     * @param $id
     *
     * @return $this
     */
    public function addCreditCardId($id): InvoiceBuilder
    {
        $this->model->creditCard = new CreditCard();
        $this->model->creditCard->id = $id;
        $this->addValidationAttribute('creditCard');
        return $this;
    }

    /**
     * @param $token
     *
     * @return $this
     */
    public function addCreditCardToken($token): InvoiceBuilder
    {
        $this->model->creditCard = new CreditCard();
        $this->model->creditCard->token = $token;
        $this->addValidationAttribute('creditCard');
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
     * @param  bool  $save
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
        $customer = null,
        bool $save = true
    ): InvoiceBuilder
    {
        $this->model->creditCard = new CreditCard();
        $this->model->creditCard->description = $description;
        $this->model->creditCard->number = $number;
        $this->model->creditCard->brand = $brand;
        $this->model->creditCard->month = $month;
        $this->model->creditCard->year = $year;
        $this->model->creditCard->cvv = $cvv;
        $this->model->creditCard->firstName = $firstName;
        $this->model->creditCard->lastName = $lastName;
        if ($customer instanceof Customer) {
            $this->model->creditCard->customer = $customer;
        } else {
            $this->model->creditCard->customer = new Customer();
            $this->model->creditCard->customer->id = $customer;
        }
        $this->addValidationAttribute('creditCard');
        $this->createCreditCard = $save;
        return $this;
    }

    /**
     * @return Invoice
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     */
    public function create(): Invoice
    {
        $this->model->validate($this->validationAttributes);
        if ($this->createCustomer) {
            $this->model->customer->save(false);
        }
        if ($this->createCreditCard) {
            if (empty($this->model->creditCard->customer) || empty($this->model->creditCard->customer->id)) {
                $this->model->creditCard->customer = $this->model->customer;
            }
        }
        $this->model->save(false);
        return $this->model;
    }
}