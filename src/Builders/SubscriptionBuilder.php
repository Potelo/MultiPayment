<?php

namespace Potelo\MultiPayment\Builders;

use Carbon\Carbon;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Models\SubscriptionItem;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Models\SubscriptionDiscount;

/**
 * SubscriptionBuilder class
 *
 * @method Subscription get()
 */
class SubscriptionBuilder extends Builder
{
    /**
     * SubscriptionBuilder constructor.
     *
     * @param  GatewayContract|string|null  $gateway
     */
    public function __construct($gateway = null)
    {
        parent::__construct($gateway);
        $this->model = new Subscription();
    }

    /**
     * @return Subscription
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     */
    public function create(): Subscription
    {
        return parent::create();
    }

    /**
     * Define o plano da assinatura.
     *
     * @param  string  $planId
     *
     * @return $this
     */
    public function setPlanId(string $planId): SubscriptionBuilder
    {
        $this->model->planId = $planId;

        return $this;
    }

    /**
     * Define o cliente da assinatura.
     *
     * @param  Customer  $customer
     *
     * @return $this
     */
    public function setCustomer(Customer $customer): SubscriptionBuilder
    {
        $this->model->customer = $customer;

        return $this;
    }

    /**
     * Define o cliente da assinatura pelo id que ele tem no gateway.
     *
     * @param  string  $customerId
     *
     * @return $this
     */
    public function setCustomerId(string $customerId): SubscriptionBuilder
    {
        $this->model->customer = new Customer();
        $this->model->customer->id = $customerId;

        return $this;
    }

    /**
     * Define a data da próxima cobrança.
     *
     * @param  Carbon|string  $nextBillingAt
     *
     * @return $this
     */
    public function setNextBillingAt(Carbon|string $nextBillingAt): SubscriptionBuilder
    {
        $this->model->nextBillingAt = $nextBillingAt instanceof Carbon
            ? $nextBillingAt
            : Carbon::parse($nextBillingAt);

        return $this;
    }

    /**
     * Define até quando vai o período de teste.
     *
     * @param  Carbon|string  $trialEndsAt
     *
     * @return $this
     */
    public function setTrialEndsAt(Carbon|string $trialEndsAt): SubscriptionBuilder
    {
        $this->model->trialEndsAt = $trialEndsAt instanceof Carbon
            ? $trialEndsAt
            : Carbon::parse($trialEndsAt);

        return $this;
    }

    /**
     * Define a duração do período de teste em dias, contada do momento em que a assinatura é
     * criada; o driver calcula a data de fim na hora da requisição (ver
     * `Subscription::$trialDays`).
     *
     * @param  int  $trialDays
     *
     * @return $this
     */
    public function setTrialDays(int $trialDays): SubscriptionBuilder
    {
        $this->model->trialDays = $trialDays;

        return $this;
    }

    /**
     * Define o método de pagamento da assinatura (ver `Subscription::$paymentMethod`).
     *
     * @param  PaymentMethod|string  $paymentMethod
     *
     * @return $this
     */
    public function setPaymentMethod(PaymentMethod|string $paymentMethod): SubscriptionBuilder
    {
        $this->model->paymentMethod = $paymentMethod;

        return $this;
    }

    /**
     * Define o cartão que a assinatura cobra, pelo model ou pelo id de um cartão já salvo no
     * cliente, e o método de pagamento como cartão (ver `Subscription::$creditCard`).
     *
     * @param  CreditCard|string  $creditCard
     *
     * @return $this
     */
    public function setCreditCard(CreditCard|string $creditCard): SubscriptionBuilder
    {
        if (is_string($creditCard)) {
            $id = $creditCard;
            $creditCard = new CreditCard();
            $creditCard->id = $id;
        }

        $this->model->creditCard = $creditCard;
        $this->model->paymentMethod = PaymentMethod::CREDIT_CARD;

        return $this;
    }

    /**
     * Define os métodos de pagamento aceitos pela assinatura.
     *
     * @param  \Potelo\MultiPayment\Enums\PaymentMethod[]|string[]  $paymentMethods
     *
     * @return $this
     */
    public function setAvailablePaymentMethods(array $paymentMethods): SubscriptionBuilder
    {
        $this->model->availablePaymentMethods = $paymentMethods;

        return $this;
    }

    /**
     * Substitui a lista de itens da assinatura.
     *
     * @param  SubscriptionItem[]  $items
     *
     * @return $this
     */
    public function setItems(array $items): SubscriptionBuilder
    {
        $this->model->items = $items;

        return $this;
    }

    /**
     * Acrescenta um item à assinatura.
     *
     * @param  string  $description
     * @param  int  $amount
     * @param  int  $quantity
     * @param  bool  $recurring
     *
     * @return $this
     */
    public function addItem(
        string $description,
        int $amount,
        int $quantity = 1,
        bool $recurring = true
    ): SubscriptionBuilder {
        $item = new SubscriptionItem();
        $item->description = $description;
        $item->amount = $amount;
        $item->quantity = $quantity;
        $item->recurring = $recurring;
        $this->model->items[] = $item;

        return $this;
    }

    /**
     * Substitui a lista de descontos da assinatura.
     *
     * @param  SubscriptionDiscount[]  $discounts
     *
     * @return $this
     */
    public function setDiscounts(array $discounts): SubscriptionBuilder
    {
        $this->model->discounts = $discounts;

        return $this;
    }

    /**
     * Acrescenta um desconto de valor fixo à assinatura.
     *
     * @param  string  $description
     * @param  int  $amountOff  Valor abatido, em centavos e positivo
     * @param  int|null  $cycles  null enquanto não for removido, 1 só na próxima fatura
     *
     * @return $this
     */
    public function addAmountDiscount(
        string $description,
        int $amountOff,
        ?int $cycles = null
    ): SubscriptionBuilder {
        $discount = new SubscriptionDiscount();
        $discount->description = $description;
        $discount->amountOff = $amountOff;
        $discount->cycles = $cycles;
        $this->model->discounts[] = $discount;

        return $this;
    }

    /**
     * Acrescenta um desconto percentual à assinatura.
     *
     * @param  string  $description
     * @param  float  $percentOff  Percentual abatido, entre 0 e 100
     * @param  int|null  $cycles  null enquanto não for removido, 1 só na próxima fatura
     *
     * @return $this
     */
    public function addPercentDiscount(
        string $description,
        float $percentOff,
        ?int $cycles = null
    ): SubscriptionBuilder {
        $discount = new SubscriptionDiscount();
        $discount->description = $description;
        $discount->percentOff = $percentOff;
        $discount->cycles = $cycles;
        $this->model->discounts[] = $discount;

        return $this;
    }

    /**
     * Define os metadados enviados ao gateway junto da assinatura.
     *
     * @param  array  $metadata
     *
     * @return $this
     */
    public function setMetadata(array $metadata): SubscriptionBuilder
    {
        $this->model->metadata = $metadata;

        return $this;
    }
}
