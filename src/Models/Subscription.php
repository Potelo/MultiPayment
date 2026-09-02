<?php

namespace Potelo\MultiPayment\Models;

use Carbon\Carbon;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Enums\ProrationBehavior;
use Potelo\MultiPayment\Enums\SubscriptionStatus;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Contracts\SubscriptionContract;
use Potelo\MultiPayment\Helpers\ConfigurationHelper;
use Potelo\MultiPayment\Idempotency\IdempotencyKey;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Assinatura recorrente de um cliente a um plano.
 *
 * As três propriedades abaixo são enums: aceitam na escrita a string do valor ou o caso do
 * enum e devolvem sempre o enum (ver `Model::ENUM_CASTS`).
 *
 * @property SubscriptionStatus|null $status Status genérico; `UNKNOWN` para status que a lib não reconhece.
 * @property PaymentMethod|null $paymentMethod Método de pagamento da assinatura.
 * @property PaymentMethod[]|null $availablePaymentMethods Métodos aceitos pela assinatura.
 */
class Subscription extends Model
{
    /** @deprecated desde 2026-09-02, use `SubscriptionStatus::TRIALING`. */
    public const STATUS_TRIALING = 'trialing';

    /** @deprecated desde 2026-09-02, use `SubscriptionStatus::ACTIVE`. */
    public const STATUS_ACTIVE = 'active';

    /** @deprecated desde 2026-09-02, use `SubscriptionStatus::SUSPENDED`. */
    public const STATUS_SUSPENDED = 'suspended';

    /** @deprecated desde 2026-09-02, use `SubscriptionStatus::PENDING`. */
    public const STATUS_PENDING = 'pending';

    /** @deprecated desde 2026-09-02, use `SubscriptionStatus::PAST_DUE`. */
    public const STATUS_PAST_DUE = 'past_due';

    /** @deprecated desde 2026-09-02, use `SubscriptionStatus::EXPIRED`. */
    public const STATUS_EXPIRED = 'expired';

    /** @deprecated desde 2026-09-02, use `SubscriptionStatus::CANCELED`. */
    public const STATUS_CANCELED = 'canceled';

    protected const ENUM_CASTS = [
        'status' => SubscriptionStatus::class,
        'paymentMethod' => PaymentMethod::class,
        'availablePaymentMethods' => [PaymentMethod::class],
    ];

    protected const REQUIRED_CAPABILITY = Capability::SUBSCRIPTIONS;

    /**
     * Além de `SUBSCRIPTIONS`, a assinatura precisa da capability de cada método de
     * `resolvedPaymentMethods()` (e de `MULTIPLE_PAYMENT_METHODS` quando há mais de um), de
     * `RAW_CARD_DATA` quando o cartão vem com os dados crus (sem `id` nem `token`) e de
     * `NATIVE_COUPONS` quando algum desconto é percentual ou limitado a mais de um ciclo.
     *
     * @return Capability[]
     * @throws ModelAttributeValidationException  método de pagamento fora de `PaymentMethod::selectable()`
     */
    public function requiredCapabilities(): array
    {
        $capabilities = parent::requiredCapabilities();

        $methods = $this->resolvedPaymentMethods();
        foreach ($methods as $method) {
            $capabilities[] = Capability::forPaymentMethod($method);
        }
        if (count($methods) > 1) {
            $capabilities[] = Capability::MULTIPLE_PAYMENT_METHODS;
        }
        if (!empty($this->creditCard) && empty($this->creditCard->id) && empty($this->creditCard->token)) {
            $capabilities[] = Capability::RAW_CARD_DATA;
        }

        foreach ($this->discounts ?? [] as $discount) {
            if (
                $discount instanceof SubscriptionDiscount
                && (!is_null($discount->percentOff) || (!is_null($discount->cycles) && $discount->cycles > 1))
            ) {
                $capabilities[] = Capability::NATIVE_COUPONS;
                break;
            }
        }

        return $capabilities;
    }

    /**
     * @var string|null
     */
    public ?string $id = null;

    /**
     * @var SubscriptionStatus|null
     */
    protected ?SubscriptionStatus $status = null;

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
     * Método com que a assinatura é cobrada. Na escrita, quando `availablePaymentMethods` fica
     * vazia, o driver a deriva dele; na leitura é preenchido quando a assinatura aceita um
     * único método.
     *
     * @var PaymentMethod|null
     */
    protected ?PaymentMethod $paymentMethod = null;

    /**
     * @var PaymentMethod[]|null
     */
    protected ?array $availablePaymentMethods = null;

    /**
     * Cartão que a assinatura cobra. Informar o cartão implica `paymentMethod` de cartão.
     * Cartão sem `id` (token ou dados crus) é salvo no cliente ao criar a assinatura. Na Iugu
     * a assinatura cobra o cartão padrão do cliente, então o cartão informado passa a ser o
     * padrão; a leitura não o preenche.
     *
     * @var CreditCard|null
     */
    public ?CreditCard $creditCard = null;

    /**
     * Duração do período de teste em dias, contada do momento da requisição. O driver a
     * converte em `trialEndsAt` e a zera, então o model devolvido traz a data; incompatível
     * com `trialEndsAt` preenchido.
     *
     * @var int|null
     */
    public ?int $trialDays = null;

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
     * Diz se há cancelamento agendado para o fim do período corrente. Preenchido na leitura
     * por gateway que oferece o recurso; na Iugu fica nulo.
     *
     * @var bool|null
     */
    public ?bool $cancelAtPeriodEnd = null;

    /**
     * Momento em que a assinatura foi cancelada. Na Iugu vem da marca `mp_canceled_at` que
     * `cancel()` grava em `custom_variables`.
     *
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

        if (!empty($data['credit_card']) && is_array($data['credit_card'])) {
            $creditCard = new CreditCard();
            $creditCard->fill($data['credit_card']);
            $data['credit_card'] = $creditCard;
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

        foreach (['customer', 'credit_card', 'latest_invoice'] as $key) {
            if (!empty($array[$key])) {
                $array[$key] = $array[$key]->toArray();
            }
        }

        return $array;
    }

    /**
     * Método de pagamento com que a assinatura será criada quando `availablePaymentMethods`
     * está vazia: `paymentMethod` quando informado; senão cartão, quando `creditCard` foi
     * informado; senão nulo (o gateway usa o padrão dele).
     *
     * @return PaymentMethod|null
     */
    public function resolvedPaymentMethod(): ?PaymentMethod
    {
        if (!is_null($this->paymentMethod)) {
            return $this->paymentMethod;
        }

        return !empty($this->creditCard) ? PaymentMethod::CREDIT_CARD : null;
    }

    /**
     * Métodos de pagamento com que a assinatura será criada, na ordem de precedência que os
     * drivers seguem: `availablePaymentMethods` quando preenchida (normalizada, porque uma
     * string apensada por `[]=` entra no array sem conversão); senão o método de
     * `resolvedPaymentMethod()`; senão lista vazia. Lança `ModelAttributeValidationException`
     * para valor fora de `PaymentMethod::selectable()`, para `paymentMethod` fora da lista
     * informada e para `creditCard` sem cartão entre os métodos resultantes (num model lido do
     * gateway a lista vem preenchida: para trocar o método, troque a lista ou a zere).
     *
     * @return PaymentMethod[]
     * @throws ModelAttributeValidationException
     */
    public function resolvedPaymentMethods(): array
    {
        if (!is_null($this->paymentMethod)) {
            $this->validatePaymentMethodAttribute();
        }

        if (!empty($this->availablePaymentMethods)) {
            $methods = array_values(array_unique(
                PaymentMethod::normalizeSelectable($this->availablePaymentMethods, $this->getClassName()),
                SORT_REGULAR
            ));
            Invoice::assertPaymentMethodIsListed($this->getClassName(), $this->paymentMethod, $methods);
        } else {
            $method = $this->resolvedPaymentMethod();
            $methods = is_null($method) ? [] : [$method];
        }

        Invoice::assertCreditCardIsPayable($this->getClassName(), $this->creditCard, $methods);

        return $methods;
    }

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validateCreditCardAttribute(): void
    {
        $this->creditCard->validate();
    }

    /**
     * Na escrita, `paymentMethod` precisa ser um método selecionável
     * (`PaymentMethod::selectable()`).
     *
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validatePaymentMethodAttribute(): void
    {
        if (!in_array($this->paymentMethod, PaymentMethod::selectable(), true)) {
            $accepted = implode(', ', array_column(PaymentMethod::selectable(), 'value'));
            throw ModelAttributeValidationException::invalid(
                $this->getClassName(),
                'paymentMethod',
                "paymentMethod must be one of: {$accepted}"
            );
        }
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
     * Garante que `availablePaymentMethods` é uma lista de métodos selecionáveis
     * (`PaymentMethod::selectable()`), convertendo string que tenha entrado por escrita
     * indireta no array.
     *
     * @return void
     * @throws ModelAttributeValidationException
     */
    protected function validateAvailablePaymentMethodsAttribute(): void
    {
        $this->availablePaymentMethods = PaymentMethod::normalizeSelectable(
            $this->availablePaymentMethods,
            $this->getClassName()
        );
    }

    /**
     * @inheritDoc
     */
    protected function attributesExtraValidation(array $attributes): void
    {
        $model = $this->getClassName();

        // zero é vazio para o validate() do Model, então o mínimo não pode depender de
        // validateTrialDaysAttribute()
        if (in_array('trialDays', $attributes) && !is_null($this->trialDays) && $this->trialDays < 1) {
            throw ModelAttributeValidationException::invalid($model, 'trialDays', 'trialDays must be at least 1');
        }

        if (
            in_array('trialDays', $attributes)
            && in_array('trialEndsAt', $attributes)
            && !is_null($this->trialDays)
            && !empty($this->trialEndsAt)
        ) {
            throw ModelAttributeValidationException::invalid(
                $model,
                'trialDays',
                'trialDays and trialEndsAt are mutually exclusive'
            );
        }

        if (in_array('paymentMethod', $attributes) && in_array('creditCard', $attributes)) {
            $this->resolvedPaymentMethods();
        }

        // com id preenchido é update, que aceita atributo parcial: cliente e plano não vão no
        // payload e não são exigidos
        if (!empty($this->id)) {
            return;
        }

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
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     *
     * @return void
     * @throws GatewayException|\Potelo\MultiPayment\Exceptions\GatewayNotAvailableException
     * @throws ModelAttributeValidationException|\Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws UnsupportedOperationException
     */
    public function save(GatewayContract|string|null $gateway = null, bool $validate = true, ?string $idempotencyKey = null): void
    {
        if ($validate) {
            $this->validate();
        }

        // resolvido e verificado antes de salvar o cliente, para nenhuma requisição sair
        // quando o gateway não suporta assinatura; no update vale a regra do Model (o gateway
        // gravado no model prevalece)
        $gateway = $this->resolveSubscriptionGateway($this->gatewayForSave($gateway));

        if (empty($this->id) && !empty($this->customer) && empty($this->customer->id)) {
            $this->customer->save($gateway, $validate, IdempotencyKey::derive($idempotencyKey, 'customer'));
        }

        parent::save($gateway, false, $idempotencyKey);
    }

    /**
     * Resolve o gateway e garante que ele declara `Capability::SUBSCRIPTIONS` e implementa
     * `SubscriptionContract`.
     *
     * @param  GatewayContract|string|null  $gateway
     *
     * @return GatewayContract&SubscriptionContract
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws UnsupportedOperationException
     * @throws GatewayException
     */
    private function resolveSubscriptionGateway(GatewayContract|string|null $gateway)
    {
        $resolved = ConfigurationHelper::resolveGateway($gateway ?? $this->gateway);
        $this->assertGatewaySupports($resolved);

        if (!$resolved instanceof SubscriptionContract) {
            throw new GatewayException(
                'Gateway [' . get_class($resolved) . '] declares the subscriptions capability'
                . ' but does not implement SubscriptionContract'
            );
        }

        return $resolved;
    }

    /**
     * Suspende a cobrança da assinatura, mantendo-a reativável por resume().
     *
     * @param  GatewayContract|string|null  $gateway
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     *
     * @return Subscription
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     * @throws UnsupportedOperationException
     */
    public function suspend(GatewayContract|string|null $gateway = null, ?string $idempotencyKey = null): Subscription
    {
        return $this->resolveSubscriptionGateway($gateway)->suspendSubscription($this, $idempotencyKey);
    }

    /**
     * Volta a cobrar uma assinatura suspensa; na Iugu, também uma cancelada por `cancel()` (a
     * marca de cancelamento é removida).
     *
     * @param  GatewayContract|string|null  $gateway
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     *
     * @return Subscription
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     * @throws UnsupportedOperationException
     */
    public function resume(GatewayContract|string|null $gateway = null, ?string $idempotencyKey = null): Subscription
    {
        return $this->resolveSubscriptionGateway($gateway)->resumeSubscription($this, $idempotencyKey);
    }

    /**
     * Cancela a assinatura, imediatamente ou ao fim do período corrente.
     *
     * @param  bool  $atPeriodEnd
     * @param  GatewayContract|string|null  $gateway
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     *
     * @return Subscription
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     * @throws UnsupportedOperationException
     */
    public function cancel(
        bool $atPeriodEnd = false,
        GatewayContract|string|null $gateway = null,
        ?string $idempotencyKey = null
    ): Subscription {
        return $this->resolveSubscriptionGateway($gateway)->cancelSubscription($this, $atPeriodEnd, $idempotencyKey);
    }

    /**
     * Troca o plano da assinatura com a política de pró-rata informada.
     *
     * Com `ProrationBehavior::CHARGE_DIFFERENCE` (o padrão) a troca gera a cobrança na hora e a
     * fatura resultante volta em `latestInvoice`; com `NONE` nada é cobrado nem creditado agora;
     * com `CREDIT` o gateway calcula o crédito do período não usado, e gateway sem
     * `Capability::PLAN_CHANGE_PRORATION` lança `UnsupportedOperationException` antes de
     * qualquer requisição. O booleano antigo continua aceito na mesma posição, e o argumento
     * nomeado `charge` também; os dois são traduzidos (`true` é `CHARGE_DIFFERENCE`, `false` é
     * `NONE`) com aviso `E_USER_DEPRECATED`, e `charge` informado prevalece sobre `$proration`.
     *
     * @param  string  $planId
     * @param  ProrationBehavior|bool  $proration  política de pró-rata; o booleano é o `$charge` antigo, obsoleto
     * @param  GatewayContract|string|null  $gateway
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     * @param  bool|null  $charge  obsoleto desde 2026-09-02; use `$proration`
     *
     * @return Subscription
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     * @throws UnsupportedOperationException
     */
    public function changePlan(
        string $planId,
        ProrationBehavior|bool $proration = ProrationBehavior::CHARGE_DIFFERENCE,
        GatewayContract|string|null $gateway = null,
        ?string $idempotencyKey = null,
        ?bool $charge = null
    ): Subscription {
        $proration = ProrationBehavior::resolve($charge ?? $proration);

        return $this->resolveSubscriptionGateway($gateway)
            ->changeSubscriptionPlan($this, $planId, $proration, $idempotencyKey);
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
     * @throws UnsupportedOperationException
     */
    public function previewPlanChange(
        string $planId,
        GatewayContract|string|null $gateway = null
    ): SubscriptionPlanChange {
        return $this->resolveSubscriptionGateway($gateway)
            ->previewSubscriptionPlanChange($this, $planId);
    }
}
