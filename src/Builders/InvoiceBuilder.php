<?php

namespace Potelo\MultiPayment\Builders;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Address;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\InvoiceItem;
use Potelo\MultiPayment\Models\AutomaticPix;
use Potelo\MultiPayment\Models\AutomaticPixCharge;
use Potelo\MultiPayment\Enums\CaptureMethod;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Contracts\GatewayContract;

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
     * @param  GatewayContract|string  $gateway
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
     * Set the invoice available payment methods
     *
     * @param  PaymentMethod[]|string[]  $paymentMethods
     *
     * @return InvoiceBuilder
     */
    public function setAvailablePaymentMethods(array $paymentMethods): InvoiceBuilder
    {
        $this->model->availablePaymentMethods = $paymentMethods;
        return $this;
    }

    /**
     * Add the invoice available payment methods
     *
     * @param  PaymentMethod|string  $paymentMethod
     *
     * @return InvoiceBuilder
     */
    public function addAvailablePaymentMethod(PaymentMethod|string $paymentMethod): InvoiceBuilder
    {
        $paymentMethods = is_array($this->model->availablePaymentMethods) ? $this->model->availablePaymentMethods : [];
        $paymentMethods[] = $paymentMethod;
        $this->model->availablePaymentMethods = $paymentMethods;
        return $this;
    }

    /**
     * Define o método de pagamento da fatura. Quando `availablePaymentMethods` fica vazia, os
     * drivers criam a fatura com este método (ver `Invoice::resolvedPaymentMethods()`).
     *
     * @param  PaymentMethod|string  $paymentMethod
     *
     * @return InvoiceBuilder
     */
    public function setPaymentMethod(PaymentMethod|string $paymentMethod): InvoiceBuilder
    {
        $this->model->paymentMethod = $paymentMethod;

        return $this;
    }

    /**
     * Define o momento da captura no cartão (ver `Invoice::$captureMethod`): `MANUAL` cria a
     * fatura em duas etapas, com o valor reservado até `capture()`.
     *
     * @param  CaptureMethod|string  $captureMethod
     *
     * @return InvoiceBuilder
     */
    public function setCaptureMethod(CaptureMethod|string $captureMethod): InvoiceBuilder
    {
        $this->model->captureMethod = $captureMethod;

        return $this;
    }

    /**
     * Define a data de vencimento da fatura (ver `Invoice::$dueDate`).
     *
     * @param  CarbonInterface|string  $dueDate  data, ou string em `Y-m-d` ou ISO 8601
     *
     * @return InvoiceBuilder
     */
    public function setDueDate(CarbonInterface|string $dueDate): InvoiceBuilder
    {
        $this->model->dueDate = self::toCarbon($dueDate);

        return $this;
    }

    /**
     * Define o instante em que o QR Code do Pix expira (ver `Invoice::$pixExpiresAt`).
     *
     * @param  CarbonInterface|string  $pixExpiresAt  data e hora, ou string em ISO 8601
     *
     * @return InvoiceBuilder
     */
    public function setPixExpiresAt(CarbonInterface|string $pixExpiresAt): InvoiceBuilder
    {
        $this->model->pixExpiresAt = self::toCarbon($pixExpiresAt);

        return $this;
    }

    /**
     * Define a data de vencimento. Nome antigo de setDueDate().
     *
     * @deprecated desde 2026-09-02, use setDueDate() (vencimento) ou setPixExpiresAt() (expiração do QR Code)
     *
     * @param  CarbonInterface|string  $expiresAt
     *
     * @return InvoiceBuilder
     */
    public function setExpiresAt($expiresAt): InvoiceBuilder
    {
        trigger_error(
            'InvoiceBuilder::setExpiresAt() está obsoleto desde 2026-09-02; use setDueDate() ou setPixExpiresAt()',
            E_USER_DEPRECATED
        );

        return $this->setDueDate($expiresAt);
    }

    /**
     * Converte data ou string numa instância de `Carbon`.
     *
     * @param  CarbonInterface|string  $date
     *
     * @return Carbon
     */
    private static function toCarbon(CarbonInterface|string $date): Carbon
    {
        if ($date instanceof Carbon) {
            return $date;
        }

        return $date instanceof CarbonInterface ? Carbon::instance($date) : Carbon::parse($date);
    }

    /**
     * Set an Automatic Pix recurrence on the invoice.
     */
    public function setAutomaticPix(AutomaticPix $automaticPix): InvoiceBuilder
    {
        $this->model->automaticPix = $automaticPix;

        return $this;
    }

    /**
     * Set the charge associated with this Automatic Pix invoice.
     */
    public function setAutomaticPixCharge(AutomaticPixCharge $charge): InvoiceBuilder
    {
        $this->model->automaticPixCharge = $charge;

        return $this;
    }

    /**
     * Add data for the charge associated with this Automatic Pix invoice.
     */
    public function addAutomaticPixCharge(
        ?string $description = null,
        ?string $id = null,
        ?string $endToEndId = null
    ): InvoiceBuilder {
        $charge = new AutomaticPixCharge();
        $charge->description = $description;
        $charge->id = $id;
        $charge->endToEndId = $endToEndId;
        $this->model->automaticPixCharge = $charge;

        return $this;
    }

    /**
     * Add Automatic Pix recurrence data to the invoice.
     *
     * @param  Carbon|string  $startsAt
     * @param  Carbon|string|null  $endsAt
     */
    public function addAutomaticPix(
        string $authorizationType,
        string $frequency,
        Carbon|string $startsAt,
        string $contractReference,
        Carbon|string|null $endsAt = null,
        string $retryPolicy = AutomaticPix::RETRY_POLICY_NOT_ALLOWED,
        ?string $id = null
    ): InvoiceBuilder {
        $automaticPix = new AutomaticPix();
        $automaticPix->authorizationType = $authorizationType;
        $automaticPix->frequency = $frequency;
        $automaticPix->startsAt = $startsAt instanceof Carbon ? $startsAt : Carbon::parse($startsAt);
        $automaticPix->contractReference = $contractReference;
        $automaticPix->endsAt = is_string($endsAt) ? Carbon::parse($endsAt) : $endsAt;
        $automaticPix->retryPolicy = $retryPolicy;
        $automaticPix->id = $id;
        $this->model->automaticPix = $automaticPix;

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
     * @param  string|\Carbon\Carbon|null  $birthDate
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
        string|Carbon|null $birthDate = null,
        ?string $phoneArea = null,
        ?string $phoneNumber = null,
        ?string $phoneCountryCode = '55'
    ): InvoiceBuilder
    {
        if (empty($this->model->customer)) {
            $this->model->customer = new Customer();
        }
        $this->model->customer->name = $name;
        $this->model->customer->email = $email;
        $this->model->customer->taxDocument = $taxDocument;
        if (!is_null($birthDate)) {
            $this->model->customer->birthDate = $birthDate instanceof Carbon ? $birthDate : Carbon::parse($birthDate);
        }
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
    ): InvoiceBuilder
    {
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
