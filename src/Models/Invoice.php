<?php

namespace Potelo\MultiPayment\Models;

use Carbon\Carbon;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Enums\InvoiceOriginType;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Helpers\ConfigurationHelper;
use Potelo\MultiPayment\Idempotency\IdempotencyKey;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Fatura.
 *
 * As quatro propriedades abaixo são enums: aceitam na escrita a string do valor ou o caso do
 * enum e devolvem sempre o enum (ver `Model::ENUM_CASTS`).
 *
 * @property InvoiceStatus|null $status Status genérico; `UNKNOWN` para status que a lib não reconhece.
 * @property PaymentMethod|null $paymentMethod Método com que a fatura foi (ou será) paga.
 * @property PaymentMethod[]|null $availablePaymentMethods Métodos aceitos pela fatura.
 * @property InvoiceOriginType|null $originType Objeto do gateway de onde a fatura foi lida (`PAYMENT_INTENT` ou `INVOICE`); `original` guarda esse objeto.
 * @property-read int|null $refundedAmount Total já estornado, em centavos, preenchido na leitura. Escrever nela é o caminho antigo de pedir um estorno parcial, obsoleto desde 2026-09-02: use `refund(amount:)`.
 * @property Carbon|null $expiresAt Obsoleto desde 2026-09-02, use `$dueDate`. Alias que lê e escreve a mesma data, com aviso de deprecação.
 */
class Invoice extends Model
{
    /** @deprecated desde 2026-09-02, use `InvoiceStatus::PENDING`. */
    public const STATUS_PENDING = 'pending';

    /** @deprecated desde 2026-09-02, use `InvoiceStatus::PAID`. */
    public const STATUS_PAID = 'paid';

    /** @deprecated desde 2026-09-02, use `InvoiceStatus::CANCELED`. */
    public const STATUS_CANCELED = 'canceled';

    /** @deprecated desde 2026-09-02, use `InvoiceStatus::REFUNDED`. */
    public const STATUS_REFUNDED = 'refunded';

    /** @deprecated desde 2026-09-02, use `InvoiceStatus::PARTIALLY_REFUNDED`. */
    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

    /** @deprecated desde 2026-09-02, use `InvoiceStatus::DISPUTED`. */
    public const STATUS_DISPUTED = 'disputed';

    /** @deprecated desde 2026-09-02, use `InvoiceStatus::CHARGEBACK`. */
    public const STATUS_CHARGEBACK = 'chargeback';

    /** @deprecated desde 2026-09-02, use `PaymentMethod::CREDIT_CARD`. */
    public const PAYMENT_METHOD_CREDIT_CARD = 'credit_card';

    /** @deprecated desde 2026-09-02, use `PaymentMethod::BANK_SLIP`. */
    public const PAYMENT_METHOD_BANK_SLIP = 'bank_slip';

    /** @deprecated desde 2026-09-02, use `PaymentMethod::PIX`. */
    public const PAYMENT_METHOD_PIX = 'pix';

    protected const ENUM_CASTS = [
        'status' => InvoiceStatus::class,
        'paymentMethod' => PaymentMethod::class,
        'availablePaymentMethods' => [PaymentMethod::class],
        'originType' => InvoiceOriginType::class,
    ];

    protected const MAGIC_PROPERTIES = ['refundedAmount'];

    /**
     * @var string|null
     */
    public ?string $id = null;

    /**
     * @var InvoiceStatus|null
     */
    protected ?InvoiceStatus $status = null;

    /**
     * @var Carbon|null
     */
    public ?Carbon $paidAt = null;

    /**
     * @var int|null
     */
    public ?int $amount = null;

    /**
     * @var int|null
     */
    public ?int $paidAmount = null;

    /**
     * Total já estornado, em centavos, como o gateway informa na leitura (`refunded_cents` na
     * Iugu, `amount_refunded` do charge na Stripe). Só os drivers escrevem aqui, por
     * `setRefundedAmountFromGateway()`.
     *
     * @var int|null
     */
    protected ?int $refundedAmount = null;

    /**
     * Valor escrito em `refundedAmount` de fora do model pelo caminho antigo de pedir estorno
     * parcial, ainda não enviado ao gateway; nulo quando `refundedAmount` só reflete a leitura.
     *
     * @var int|null
     */
    private ?int $requestedRefundAmount = null;

    /**
     * Estornos da fatura, preenchidos na leitura. No Stripe é um `Refund` por estorno feito,
     * com id; na Iugu, que só informa o total estornado, é um único `Refund` sem id com o
     * acumulado, ou lista vazia quando nada foi estornado.
     *
     * @var Refund[]|null
     */
    public ?array $refunds = null;

    /**
     * @var Customer|null
     */
    public ?Customer $customer = null;

    /**
     * @var InvoiceItem[]|null
     */
    public ?array $items = null;

    /**
     * @var PaymentMethod|null
     */
    protected ?PaymentMethod $paymentMethod = null;

    /**
     * @var PaymentMethod[]|null
     */
    protected ?array $availablePaymentMethods = null;

    /**
     * Preenchido pelo driver na leitura. Na Iugu é sempre `INVOICE`; na Stripe é
     * `PAYMENT_INTENT` na cobrança avulsa e `INVOICE` na fatura de assinatura.
     *
     * @var InvoiceOriginType|null
     */
    protected ?InvoiceOriginType $originType = null;

    /**
     * @var CreditCard|null
     */
    public ?CreditCard $creditCard = null;

    /**
     * @var BankSlip|null
     */
    public ?BankSlip $bankSlip = null;

    /**
     * @var Pix|null
     */
    public ?Pix $pix = null;

    /**
     * @var AutomaticPix|null
     */
    public ?AutomaticPix $automaticPix = null;

    /**
     * @var AutomaticPixCharge|null
     */
    public ?AutomaticPixCharge $automaticPixCharge = null;

    /**
     * Data de vencimento da fatura. Na Iugu é o `due_date` (o dia; a fatura vencida continua
     * pagável); no Stripe é o `due_date` da fatura de assinatura e, na venda avulsa por Pix sem
     * `pixExpiresAt`, o fim desse dia vira a expiração do QR Code.
     *
     * @var Carbon|null
     */
    public ?Carbon $dueDate = null;

    /**
     * Instante em que o QR Code do Pix deixa de aceitar pagamento. No Stripe vai em
     * `payment_method_options.pix.expires_at` (entre 10 segundos e 14 dias no futuro) e volta
     * na leitura; na Iugu vai em `pix_qr_code_expires_at` e a leitura só o preenche quando a
     * fatura o devolve.
     *
     * @var Carbon|null
     */
    public ?Carbon $pixExpiresAt = null;

    /**
     * @var int|null
     */
    public ?int $fee = null;

    /**
     * @var string|null
     */
    public ?string $gateway = null;

    /**
     * @var string|null
     */
    public ?string $url = null;

    /**
     * The original invoice response of the gateway, in case need additional information.
     *
     * @var mixed|null
     */
    public $original = null;

    /**
     * @var Carbon|null
     */
    public ?Carbon $createdAt = null;

    /**
     * @inheritDoc
     */
    public function fill(array $data): void
    {
        if (empty($data['items']) && !empty($data['amount'])) {
            $invoiceItem = new InvoiceItem();
            $invoiceItem->fill([
                'description' => 'Nova cobrança',
                'quantity' => 1,
                'price' => $data['amount'],
            ]);
            $this->items[] = $invoiceItem;
            // a chave items não pode chegar ao parent::fill(), que sobrescreveria a lista
            unset($data['amount'], $data['items']);
        } elseif (!empty($data['items'])) {
            $this->items = [];
            foreach ($data['items'] as $item) {
                $invoiceItem = $item;
                if (!empty($item) && is_array($item)) {
                    $invoiceItem = new InvoiceItem();
                    $invoiceItem->fill($item);
                }
                $this->items[] = $invoiceItem;
            }
            unset($data['items']);
        }

        if (!empty($data['customer']) && is_array($data['customer'])) {
            $this->customer = new Customer();
            $this->customer->fill($data['customer']);
            unset($data['customer']);
        }

        if (!empty($data['expires_at'])) {
            self::warnExpiresAtDeprecated();
            $data['due_date'] = $data['due_date'] ?? $data['expires_at'];
            unset($data['expires_at']);
        }

        foreach (['refunded_amount', 'refundedAmount'] as $key) {
            if (array_key_exists($key, $data)) {
                $this->__set('refundedAmount', $data[$key]);
                unset($data[$key]);
            }
        }

        foreach (['due_date' => 'dueDate', 'pix_expires_at' => 'pixExpiresAt'] as $key => $attribute) {
            if (!empty($data[$key])) {
                $this->{$attribute} = $data[$key] instanceof Carbon
                    ? $data[$key]
                    : Carbon::parse($data[$key]);
                unset($data[$key]);
            }
        }

        if (!empty($data['credit_card']) && is_array($data['credit_card'])) {
            $this->creditCard = new CreditCard();
            $this->creditCard->fill($data['credit_card']);
            unset($data['credit_card']);
        }

        if (!empty($data['automatic_pix']) && is_array($data['automatic_pix'])) {
            $this->automaticPix = new AutomaticPix();
            $this->automaticPix->fill($data['automatic_pix']);
            unset($data['automatic_pix']);
        }

        if (!empty($data['automatic_pix_charge']) && is_array($data['automatic_pix_charge'])) {
            $this->automaticPixCharge = new AutomaticPixCharge();
            $this->automaticPixCharge->fill($data['automatic_pix_charge']);
            unset($data['automatic_pix_charge']);
        }

        parent::fill($data);
    }

    /**
     * @inheritDoc
     */
    public function attributesExtraValidation($attributes): void
    {
        $model = $this->getClassName();

        if (in_array('customer', $attributes) && empty($this->customer)) {
            throw ModelAttributeValidationException::required($model, 'customer');
        }

        if (in_array('amount', $attributes) && in_array('items', $attributes) && empty($this->amount) && empty($this->items)) {
            throw ModelAttributeValidationException::required($model, 'amount or items');
        }

        if (in_array('paymentMethod', $attributes) && in_array('creditCard', $attributes)) {
            $this->resolvedPaymentMethods();
        }

        if (
            in_array('amount', $attributes)
            && in_array('items', $attributes)
            && !empty($this->amount)
            && !empty($this->items)
            && $this->amount !== $this->itemsTotal()
        ) {
            throw ModelAttributeValidationException::invalid(
                $model,
                'amount',
                "amount [{$this->amount}] must equal the sum of the items [{$this->itemsTotal()}]; omit it when items are given"
            );
        }
    }

    /**
     * Soma dos itens (`price` vezes `quantity`, com quantidade 1 quando ausente), em centavos.
     *
     * @return int
     */
    public function itemsTotal(): int
    {
        $total = 0;
        foreach ($this->items ?? [] as $item) {
            if ($item instanceof InvoiceItem) {
                $total += (int) $item->price * (int) ($item->quantity ?? 1);
            }
        }

        return $total;
    }

    /**
     * Na escrita, `paymentMethod` precisa ser um método selecionável
     * (`PaymentMethod::selectable()`); Pix Automático entra pela lista com `PIX` e por
     * `automaticPix`.
     *
     * @return void
     * @throws ModelAttributeValidationException
     */
    public function validatePaymentMethodAttribute(): void
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
    public function validateCustomerAttribute()
    {
        $this->customer->validate();
    }

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    public function validateItemsAttribute()
    {
        foreach ($this->items as $item) {
            if ($item instanceof InvoiceItem) {
                $item->validate();
            } else {
                throw ModelAttributeValidationException::invalid('Invoice', 'items', 'items must be an array of InvoiceItem');
            }
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
    public function validateAvailablePaymentMethodsAttribute()
    {
        $this->availablePaymentMethods = PaymentMethod::normalizeSelectable(
            $this->availablePaymentMethods,
            $this->getClassName()
        );
    }

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    public function validateCreditCardAttribute()
    {
        $this->creditCard->validate();
    }

    /**
     * @throws ModelAttributeValidationException
     */
    public function validateAutomaticPixAttribute(): void
    {
        $this->automaticPix->validateForInvoice();
    }

    /**
     * @inheritDoc
     */
    public function save(GatewayContract|string|null $gateway = null, bool $validate = true, ?string $idempotencyKey = null): void
    {
        if ($validate) {
            $this->validate();
        }
        // resolvido e verificado antes de salvar o cliente, para nenhuma requisição sair
        // quando o gateway não suporta a fatura; no update vale a regra do Model (o gateway
        // gravado no model prevalece)
        $gateway = ConfigurationHelper::resolveGateway($this->gatewayForSave($gateway));
        $this->assertGatewaySupports($gateway);
        if (empty($this->customer->id)) {
            $this->customer->save($gateway, $validate, IdempotencyKey::derive($idempotencyKey, 'customer'));
        }
        if (!empty($this->creditCard) && empty($this->creditCard->id)) {
            $this->creditCard->customer = $this->customer;
        }
        parent::save($gateway, false, $idempotencyKey);
    }

    /**
     * Métodos de pagamento com que a fatura será criada, na ordem de precedência que os
     * drivers seguem: `availablePaymentMethods` quando preenchida (normalizada, porque uma
     * string apensada por `[]=` entra no array sem conversão); senão `paymentMethod`; senão
     * cartão, quando só `creditCard` foi informado. Lista vazia quando nada foi informado (a
     * Iugu abre a fatura a todos os métodos da conta; o Stripe exige um). Lança
     * `ModelAttributeValidationException` para valor fora de `PaymentMethod::selectable()`,
     * para `paymentMethod` fora da lista informada e para `creditCard` sem cartão entre os
     * métodos resultantes.
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
            self::assertPaymentMethodIsListed($this->getClassName(), $this->paymentMethod, $methods);
        } elseif (!is_null($this->paymentMethod)) {
            $methods = [$this->paymentMethod];
        } else {
            $methods = !empty($this->creditCard) ? [PaymentMethod::CREDIT_CARD] : [];
        }

        self::assertCreditCardIsPayable($this->getClassName(), $this->creditCard, $methods);

        return $methods;
    }

    /**
     * Lança `ModelAttributeValidationException` quando `paymentMethod` está preenchido e não
     * consta da lista de métodos informada.
     *
     * @param  string  $model
     * @param  PaymentMethod|null  $paymentMethod
     * @param  PaymentMethod[]  $methods
     * @return void
     * @throws ModelAttributeValidationException
     */
    public static function assertPaymentMethodIsListed(string $model, ?PaymentMethod $paymentMethod, array $methods): void
    {
        if (!is_null($paymentMethod) && !in_array($paymentMethod, $methods, true)) {
            throw ModelAttributeValidationException::invalid(
                $model,
                'paymentMethod',
                "paymentMethod [{$paymentMethod->value}] must be one of availablePaymentMethods; change the list or leave it empty"
            );
        }
    }

    /**
     * Lança `ModelAttributeValidationException` quando há cartão informado e cartão não está
     * entre os métodos com que a fatura ou a assinatura será criada.
     *
     * @param  string  $model
     * @param  CreditCard|null  $creditCard
     * @param  PaymentMethod[]  $methods
     * @return void
     * @throws ModelAttributeValidationException
     */
    public static function assertCreditCardIsPayable(string $model, ?CreditCard $creditCard, array $methods): void
    {
        if (!empty($creditCard) && !in_array(PaymentMethod::CREDIT_CARD, $methods, true)) {
            throw ModelAttributeValidationException::invalid(
                $model,
                'creditCard',
                'creditCard was given but credit_card is not among the payment methods; add it or remove the card'
            );
        }
    }

    /**
     * Na criação, além do que o `Model` exige, a fatura precisa da capability de cada método
     * de `resolvedPaymentMethods()`, de `MULTIPLE_PAYMENT_METHODS` quando há mais de um, de
     * `AUTOMATIC_PIX` quando `automaticPix` está preenchido e de `RAW_CARD_DATA` quando o
     * cartão vem com os dados crus (sem `id` nem `token`). Com `id` preenchido, só o que o
     * `Model` exige.
     *
     * @return Capability[]
     * @throws ModelAttributeValidationException  método de pagamento fora de `PaymentMethod::selectable()`
     */
    public function requiredCapabilities(): array
    {
        $capabilities = parent::requiredCapabilities();
        if (!empty($this->id)) {
            return $capabilities;
        }

        $methods = $this->resolvedPaymentMethods();

        foreach ($methods as $method) {
            $capabilities[] = Capability::forPaymentMethod($method);
        }
        if (count($methods) > 1) {
            $capabilities[] = Capability::MULTIPLE_PAYMENT_METHODS;
        }
        if (!empty($this->automaticPix)) {
            $capabilities[] = Capability::AUTOMATIC_PIX;
        }
        if (!empty($this->creditCard) && empty($this->creditCard->id) && empty($this->creditCard->token)) {
            $capabilities[] = Capability::RAW_CARD_DATA;
        }

        return array_values(array_unique($capabilities, SORT_REGULAR));
    }

    /**
     * Diz se o dinheiro da fatura foi recebido; delega a `InvoiceStatus::isSettled()`. String
     * fora do enum devolve falso.
     *
     * @deprecated desde 2026-09-02, use `$invoice->status->isSettled()`.
     * @param  InvoiceStatus|string  $status
     * @return bool
     */
    public static function isSettled(InvoiceStatus|string $status): bool
    {
        trigger_error(
            'Invoice::isSettled() está obsoleto desde 2026-09-02; use $invoice->status->isSettled()',
            E_USER_DEPRECATED
        );

        return self::statusFromHelperArgument($status)?->isSettled() ?? false;
    }

    /**
     * Diz se existe contestação sobre a fatura; delega a `InvoiceStatus::isContested()`.
     * String fora do enum devolve falso.
     *
     * @deprecated desde 2026-09-02, use `$invoice->status->isContested()`.
     * @param  InvoiceStatus|string  $status
     * @return bool
     */
    public static function isContested(InvoiceStatus|string $status): bool
    {
        trigger_error(
            'Invoice::isContested() está obsoleto desde 2026-09-02; use $invoice->status->isContested()',
            E_USER_DEPRECATED
        );

        return self::statusFromHelperArgument($status)?->isContested() ?? false;
    }

    /**
     * Resolve a leitura do nome antigo `expiresAt` para `dueDate`, com aviso de deprecação;
     * os demais nomes seguem o `Model`.
     *
     * @param  string  $name
     * @return mixed
     */
    public function &__get(string $name): mixed
    {
        if ($name === 'expiresAt') {
            self::warnExpiresAtDeprecated();

            return $this->dueDate;
        }

        if ($name === 'refundedAmount') {
            return $this->refundedAmount;
        }

        $value = &parent::__get($name);

        return $value;
    }

    /**
     * Resolve a escrita no nome antigo `expiresAt` para `dueDate`, com aviso de deprecação, e a
     * escrita em `refundedAmount`, que é o caminho antigo de pedir um estorno parcial: o valor
     * fica guardado como pedido para o próximo `refund()` sem valor, com aviso de deprecação.
     * Os demais nomes seguem o `Model`.
     *
     * @param  string  $name
     * @param  mixed  $value
     * @return void
     */
    public function __set(string $name, mixed $value): void
    {
        if ($name === 'expiresAt') {
            self::warnExpiresAtDeprecated();
            $this->dueDate = $value;

            return;
        }

        if ($name === 'refundedAmount') {
            trigger_error(
                'Invoice::$refundedAmount é só de leitura desde 2026-09-02; passe o valor do estorno em refund(amount:) ou refundInvoice($id, $amount)',
                E_USER_DEPRECATED
            );
            $this->refundedAmount = is_null($value) ? null : (int) $value;
            $this->requestedRefundAmount = $this->refundedAmount ?: null;

            return;
        }

        parent::__set($name, $value);
    }

    /**
     * Mantém `isset()` e `empty()` funcionando sobre o nome antigo `expiresAt` e sobre
     * `refundedAmount`.
     *
     * @param  string  $name
     * @return bool
     */
    public function __isset(string $name): bool
    {
        if ($name === 'expiresAt') {
            return isset($this->dueDate);
        }

        if ($name === 'refundedAmount') {
            return isset($this->refundedAmount);
        }

        return parent::__isset($name);
    }

    /**
     * Grava o total já estornado informado pelo gateway e apaga o valor pedido pelo caminho
     * antigo (`requestedRefundAmount()` volta a nulo). É a escrita que os drivers fazem ao
     * parsear a fatura.
     *
     * @internal usado pelos drivers ao parsear a fatura
     * @param  int|null  $refundedAmount  total estornado, em centavos
     * @return void
     */
    public function setRefundedAmountFromGateway(?int $refundedAmount): void
    {
        $this->refundedAmount = $refundedAmount;
        $this->requestedRefundAmount = null;
    }

    /**
     * Valor de estorno parcial pedido pelo caminho antigo (escrita em `refundedAmount`) e ainda
     * não enviado ao gateway; nulo quando `refundedAmount` só reflete a leitura do gateway.
     * Enquanto não é nulo, `refundedAmount` não é o total já estornado.
     *
     * @internal usado pelos drivers
     * @return int|null
     */
    public function requestedRefundAmount(): ?int
    {
        return $this->requestedRefundAmount;
    }

    /**
     * Resolve o valor de um estorno como os drivers o usam: o argumento, senão o valor pedido
     * pelo caminho antigo (`requestedRefundAmount()`), senão nulo, que é estorno do restante.
     * Zero ou negativo lança `ModelAttributeValidationException`.
     *
     * @internal usado pelos drivers
     * @param  int|null  $amount  valor em centavos
     * @return int|null
     * @throws ModelAttributeValidationException
     */
    public function resolveRefundAmount(?int $amount): ?int
    {
        $amount ??= $this->requestedRefundAmount;

        if (!is_null($amount) && $amount <= 0) {
            throw ModelAttributeValidationException::invalid(
                $this->getClassName(),
                'amount',
                'The refund amount must be a positive number of cents; omit it to refund the remainder.'
            );
        }

        return $amount;
    }

    /**
     * Emite o aviso de deprecação do nome antigo `expiresAt`.
     *
     * @return void
     */
    private static function warnExpiresAtDeprecated(): void
    {
        trigger_error(
            'Invoice::$expiresAt está obsoleto desde 2026-09-02; use $dueDate (vencimento) ou $pixExpiresAt (expiração do QR Code)',
            E_USER_DEPRECATED
        );
    }

    /**
     * Copia também os objetos aninhados que os drivers preenchem na leitura (`customer` e seu
     * `address`, `creditCard`, `bankSlip`, `pix`, `automaticPix`, `automaticPixCharge`), para
     * que parsear a cópia não altere o model original.
     *
     * @return void
     */
    public function __clone(): void
    {
        foreach (['customer', 'creditCard', 'bankSlip', 'pix', 'automaticPix', 'automaticPixCharge'] as $property) {
            if (is_object($this->{$property})) {
                $this->{$property} = clone $this->{$property};
            }
        }

        if (is_object($this->customer?->address)) {
            $this->customer->address = clone $this->customer->address;
        }
    }

    /**
     * Converte o argumento dos helpers estáticos obsoletos em `InvoiceStatus`, sem log para
     * string fora do enum.
     *
     * @param  InvoiceStatus|string  $status
     * @return InvoiceStatus|null
     */
    private static function statusFromHelperArgument(InvoiceStatus|string $status): ?InvoiceStatus
    {
        return $status instanceof InvoiceStatus ? $status : InvoiceStatus::tryFrom($status);
    }

    /**
     * Estorna a fatura: o restante estornável quando `$amount` é nulo, ou o valor informado em
     * centavos. Devolve o `Refund` criado e atualiza esta instância com o estado posterior ao
     * estorno (`$refund->invoice()` é esta instância). Zero ou negativo lança
     * `ModelAttributeValidationException` antes da requisição.
     *
     * @param  int|null  $amount  valor em centavos; nulo estorna o restante
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     * @return Refund
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\RefundNotSupportedException
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws ModelAttributeValidationException
     */
    public function refund(?int $amount = null, ?string $idempotencyKey = null): Refund
    {
        $gateway = ConfigurationHelper::resolveGateway($this->gateway);
        return $gateway->refundInvoice($this, $amount, $idempotencyKey);
    }

    /**
     * Valor que ainda pode ser estornado na fatura, em centavos, calculado pelo driver: zero para
     * fatura não paga ou já integralmente estornada. Lê a fatura (um GET) quando o model não traz
     * o valor pago. É o teto aritmético de `refund()`; as guardas de boleto, Pix parcial e prazo
     * continuam valendo.
     *
     * @return int
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\UnsupportedOperationException  fatura que o driver não estorna (fatura de assinatura no Stripe)
     * @throws ModelAttributeValidationException  `id` ausente
     */
    public function refundableAmount(): int
    {
        $gateway = ConfigurationHelper::resolveGateway($this->gateway);
        return $gateway->refundableAmount($this);
    }

    /**
     * Cobra a fatura com o cartão informado (ou com o já preenchido em `creditCard`).
     *
     * @param  CreditCard|null  $creditCard
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     * @return Invoice
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     * @throws \Potelo\MultiPayment\Exceptions\ChargingException
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     */
    public function chargeInvoiceWithCreditCard(?CreditCard $creditCard = null, ?string $idempotencyKey = null): Invoice
    {
        if (!empty($creditCard)) {
            $this->creditCard = $creditCard;
        }

        $gateway = ConfigurationHelper::resolveGateway($this->gateway);

        return $gateway->chargeInvoiceWithCreditCard($this, $idempotencyKey);
    }

    /**
     * Duplicate the invoice
     *
     * @param  \Carbon\Carbon  $expiresAt
     * @param  array  $gatewayOptions
     * @param  string|null  $idempotencyKey  idempotency key of the operation; null disables deduplication
     * @return \Potelo\MultiPayment\Models\Invoice
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function duplicate(Carbon $expiresAt, array $gatewayOptions = [], ?string $idempotencyKey = null): Invoice
    {
        $gateway = ConfigurationHelper::resolveGateway($this->gateway);
        return $gateway->duplicateInvoice($this, $expiresAt, $gatewayOptions, $idempotencyKey);
    }

    /**
     * Cancela a fatura.
     *
     * @param  GatewayContract|string|null  $gateway
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     * @return Invoice
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function cancel(GatewayContract|string|null $gateway = null, ?string $idempotencyKey = null): Invoice
    {
        $gateway = ConfigurationHelper::resolveGateway($gateway ?? $this->gateway);

        return $gateway->cancelInvoice($this, $idempotencyKey);
    }

    /**
     * Pede um novo agendamento de débito depois de um pagamento de Pix Automático que falhou.
     *
     * @param  GatewayContract|string|null  $gateway
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     * @return Invoice
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function rescheduleAutomaticPixPayment(GatewayContract|string|null $gateway = null, ?string $idempotencyKey = null): Invoice
    {
        $gateway = ConfigurationHelper::resolveGateway($gateway ?? $this->gateway);

        return $gateway->rescheduleAutomaticPixPayment($this, $idempotencyKey);
    }
}
