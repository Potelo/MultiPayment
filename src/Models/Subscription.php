<?php

namespace Potelo\MultiPayment\Models;

use Carbon\Carbon;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Contracts\SubscriptionContract;
use Potelo\MultiPayment\Helpers\ConfigurationHelper;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Assinatura recorrente de um cliente a um plano.
 */
class Subscription extends Model
{
    public const STATUS_TRIALING = 'trialing';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELED = 'canceled';

    /**
     * @var string|null
     */
    public ?string $id = null;

    /**
     * @var string|null
     */
    public ?string $status = null;

    /**
     * @var Customer|null
     */
    public ?Customer $customer = null;

    /**
     * @var string|null
     */
    public ?string $planId = null;

    /**
     * @var SubscriptionItem[]|null
     */
    public ?array $items = null;

    /**
     * Descontos aplicados sobre o valor da assinatura. `amountOff` é o total abatido, ao
     * contrário de `SubscriptionItem::$amount`, que é unitário.
     *
     * @var SubscriptionDiscount[]|null
     */
    public ?array $discounts = null;

    /**
     * @var int|null
     */
    public ?int $amount = null;

    /**
     * @var string|null
     */
    public ?string $paymentMethod = null;

    /**
     * @var string[]|null
     */
    public ?array $availablePaymentMethods = null;

    /**
     * @var Carbon|null
     */
    public ?Carbon $trialEndsAt = null;

    /**
     * Data da próxima cobrança. Preenchida ao ler a assinatura e, quando informada, aplicada na
     * criação e na troca de plano.
     *
     * @var Carbon|null
     */
    public ?Carbon $nextBillingAt = null;

    /**
     * @var bool|null
     */
    public ?bool $cancelAtPeriodEnd = null;

    /**
     * @var Carbon|null
     */
    public ?Carbon $canceledAt = null;

    /**
     * @var Carbon|null
     */
    public ?Carbon $createdAt = null;

    /**
     * Fatura mais recente gerada pela assinatura. Não é necessariamente a que está em aberto:
     * assinatura em `past_due` pode trazer aqui uma fatura já quitada. Pode vir resumida, porque
     * nem todo gateway devolve a fatura inteira junto da assinatura.
     *
     * @var Invoice|null
     */
    public ?Invoice $latestInvoice = null;

    /**
     * @var array|null
     */
    public ?array $metadata = null;

    /**
     * @var string|null
     */
    public ?string $gateway = null;

    /**
     * A resposta original do gateway, caso seja necessária alguma informação adicional.
     *
     * @var mixed|null
     */
    public $original = null;

    /**
     * @inheritDoc
     */
    public function fill(array $data): void
    {
        foreach (
            [
                'trial_ends_at' => 'trialEndsAt',
                'next_billing_at' => 'nextBillingAt',
                'canceled_at' => 'canceledAt',
                'created_at' => 'createdAt',
            ] as $key => $attribute
        ) {
            if (!empty($data[$key])) {
                $this->{$attribute} = $data[$key] instanceof Carbon
                    ? $data[$key]
                    : Carbon::parse($data[$key]);
                unset($data[$key]);
            }
        }

        if (!empty($data['customer']) && is_array($data['customer'])) {
            $customer = new Customer();
            $customer->fill($data['customer']);
            $data['customer'] = $customer;
        }

        if (!empty($data['latest_invoice']) && is_array($data['latest_invoice'])) {
            $invoice = new Invoice();
            $invoice->fill($data['latest_invoice']);
            $data['latest_invoice'] = $invoice;
        }

        $data['items'] = $this->fillCollection($data['items'] ?? null, SubscriptionItem::class);
        $data['discounts'] = $this->fillCollection($data['discounts'] ?? null, SubscriptionDiscount::class);

        foreach (['items', 'discounts'] as $key) {
            if (is_null($data[$key])) {
                unset($data[$key]);
            }
        }

        parent::fill($data);
    }

    /**
     * Converte cada entrada de uma lista em instância da classe informada, mantendo as que já
     * são instâncias. Devolve null quando a lista não é um array.
     *
     * @param  mixed  $values
     * @param  class-string<Model>  $class
     *
     * @return array|null
     */
    private function fillCollection($values, string $class): ?array
    {
        if (!is_array($values)) {
            return null;
        }

        return array_map(function ($value) use ($class) {
            if ($value instanceof $class) {
                return $value;
            }

            /** @var Model $model */
            $model = new $class();
            $model->fill($value);

            return $model;
        }, $values);
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        $array = parent::toArray();

        foreach (['items', 'discounts'] as $key) {
            if (!empty($array[$key])) {
                $array[$key] = array_map(fn(Model $model) => $model->toArray(), $array[$key]);
            }
        }

        foreach (['customer', 'latest_invoice'] as $key) {
            if (!empty($array[$key])) {
                $array[$key] = $array[$key]->toArray();
            }
        }

        return $array;
    }

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validateCustomerAttribute(): void
    {
        $this->customer->validate();
    }

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validateItemsAttribute(): void
    {
        foreach ($this->items as $item) {
            if (!$item instanceof SubscriptionItem) {
                throw ModelAttributeValidationException::invalid(
                    $this->getClassName(),
                    'items',
                    'items must be an array of SubscriptionItem'
                );
            }

            $item->validate();
        }
    }

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validateDiscountsAttribute(): void
    {
        foreach ($this->discounts as $discount) {
            if (!$discount instanceof SubscriptionDiscount) {
                throw ModelAttributeValidationException::invalid(
                    $this->getClassName(),
                    'discounts',
                    'discounts must be an array of SubscriptionDiscount'
                );
            }

            $discount->validate();
        }
    }

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validateAvailablePaymentMethodsAttribute(): void
    {
        $methods = [
            Invoice::PAYMENT_METHOD_CREDIT_CARD,
            Invoice::PAYMENT_METHOD_BANK_SLIP,
            Invoice::PAYMENT_METHOD_PIX,
        ];

        if (!is_array($this->availablePaymentMethods)) {
            throw ModelAttributeValidationException::invalid(
                $this->getClassName(),
                'availablePaymentMethods',
                'availablePaymentMethods must be an array of payment methods'
            );
        }

        foreach ($this->availablePaymentMethods as $method) {
            if (!in_array($method, $methods, true)) {
                throw ModelAttributeValidationException::invalid(
                    $this->getClassName(),
                    'availablePaymentMethods',
                    'availablePaymentMethods must be one of: ' . implode(', ', $methods)
                );
            }
        }
    }

    /**
     * @inheritDoc
     */
    protected function attributesExtraValidation(array $attributes): void
    {
        // com id preenchido é update, que aceita atributo parcial: cliente e plano não vão no
        // payload e não são exigidos
        if (!empty($this->id)) {
            return;
        }

        $model = $this->getClassName();

        if (in_array('customer', $attributes) && empty($this->customer)) {
            throw ModelAttributeValidationException::required($model, 'customer');
        }

        if (in_array('planId', $attributes) && empty($this->planId)) {
            throw ModelAttributeValidationException::required($model, 'planId');
        }
    }

    /**
     * Salva a assinatura, criando antes o cliente quando ele ainda não tem id.
     *
     * Com `id` preenchido é update: o cliente não é tocado, e cliente e plano deixam de ser
     * obrigatórios — as demais validações continuam valendo.
     *
     * @param  GatewayContract|string|null  $gateway
     * @param  bool  $validate
     *
     * @return void
     * @throws GatewayException|\Potelo\MultiPayment\Exceptions\GatewayNotAvailableException
     * @throws ModelAttributeValidationException|\Potelo\MultiPayment\Exceptions\ConfigurationException
     */
    public function save(GatewayContract|string|null $gateway = null, bool $validate = true): void
    {
        if ($validate) {
            $this->validate();
        }

        if (empty($this->id) && !empty($this->customer) && empty($this->customer->id)) {
            $this->customer->save($gateway, $validate);
        }

        parent::save($gateway, false);
    }

    /**
     * Resolve o gateway e garante que ele implementa as operações de assinatura.
     *
     * @param  GatewayContract|string|null  $gateway
     *
     * @return GatewayContract&SubscriptionContract
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws GatewayException
     */
    private function resolveSubscriptionGateway(GatewayContract|string|null $gateway)
    {
        $resolved = ConfigurationHelper::resolveGateway($gateway ?? $this->gateway);

        if (!$resolved instanceof SubscriptionContract) {
            throw new GatewayException(
                'Gateway [' . get_class($resolved) . '] does not implement SubscriptionContract;'
                . ' subscriptions are not yet implemented in this library for that gateway'
            );
        }

        return $resolved;
    }

    /**
     * Suspende a cobrança da assinatura, mantendo-a reativável por resume().
     *
     * @param  GatewayContract|string|null  $gateway
     *
     * @return Subscription
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     */
    public function suspend(GatewayContract|string|null $gateway = null): Subscription
    {
        return $this->resolveSubscriptionGateway($gateway)->suspendSubscription($this);
    }

    /**
     * Volta a cobrar uma assinatura suspensa.
     *
     * @param  GatewayContract|string|null  $gateway
     *
     * @return Subscription
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     */
    public function resume(GatewayContract|string|null $gateway = null): Subscription
    {
        return $this->resolveSubscriptionGateway($gateway)->resumeSubscription($this);
    }

    /**
     * Cancela a assinatura, imediatamente ou ao fim do período corrente.
     *
     * @param  bool  $atPeriodEnd
     * @param  GatewayContract|string|null  $gateway
     *
     * @return Subscription
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     */
    public function cancel(bool $atPeriodEnd = false, GatewayContract|string|null $gateway = null): Subscription
    {
        return $this->resolveSubscriptionGateway($gateway)->cancelSubscription($this, $atPeriodEnd);
    }

    /**
     * Troca o plano da assinatura.
     *
     * Com $charge, a troca gera a cobrança na hora e a fatura resultante volta em
     * `latestInvoice`; sem ele, nada é cobrado.
     *
     * @param  string  $planId
     * @param  bool  $charge
     * @param  GatewayContract|string|null  $gateway
     *
     * @return Subscription
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     */
    public function changePlan(
        string $planId,
        bool $charge = true,
        GatewayContract|string|null $gateway = null
    ): Subscription {
        return $this->resolveSubscriptionGateway($gateway)
            ->changeSubscriptionPlan($this, $planId, $charge);
    }

    /**
     * Simula a troca de plano sem aplicá-la.
     *
     * @param  string  $planId
     * @param  GatewayContract|string|null  $gateway
     *
     * @return SubscriptionPlanChange
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     */
    public function previewPlanChange(
        string $planId,
        GatewayContract|string|null $gateway = null
    ): SubscriptionPlanChange {
        return $this->resolveSubscriptionGateway($gateway)
            ->previewSubscriptionPlanChange($this, $planId);
    }
}
