<?php

namespace Potelo\MultiPayment\Models;

use Carbon\Carbon;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Helpers\ConfigurationHelper;
use Potelo\MultiPayment\Idempotency\IdempotencyKey;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Fatura.
 *
 * As três propriedades abaixo são enums: aceitam na escrita a string do valor ou o caso do
 * enum e devolvem sempre o enum (ver `Model::ENUM_CASTS`).
 *
 * @property InvoiceStatus|null $status Status genérico; `UNKNOWN` para status que a lib não reconhece.
 * @property PaymentMethod|null $paymentMethod Método com que a fatura foi (ou será) paga.
 * @property PaymentMethod[]|null $availablePaymentMethods Métodos aceitos pela fatura.
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
    ];

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
     * @var int|null
     */
    public ?int $refundedAmount = null;

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
     * @var Carbon|null
     */
    public ?Carbon $expiresAt = null;

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
            $this->expiresAt = Carbon::createFromFormat('Y-m-d', $data['expires_at']);
            unset($data['expires_at']);
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
     * Na criação, além do que o `Model` exige, a fatura precisa da capability de cada método
     * selecionável em `availablePaymentMethods` (ou de cartão, quando só `creditCard` foi
     * informado), de `MULTIPLE_PAYMENT_METHODS` quando há mais de um método, de
     * `AUTOMATIC_PIX` quando `automaticPix` está preenchido e de `RAW_CARD_DATA` quando o
     * cartão vem com os dados crus (sem `id` nem `token`). Valor fora de
     * `PaymentMethod::selectable()` fica para a validação. Com `id` preenchido, só o que o
     * `Model` exige.
     *
     * @return Capability[]
     */
    public function requiredCapabilities(): array
    {
        $capabilities = parent::requiredCapabilities();
        if (!empty($this->id)) {
            return $capabilities;
        }

        $methods = [];
        foreach ((array) ($this->availablePaymentMethods ?? []) as $method) {
            $case = $method instanceof PaymentMethod ? $method : (is_string($method) ? PaymentMethod::tryFrom($method) : null);
            if (!is_null($case) && in_array($case, PaymentMethod::selectable(), true)) {
                $methods[] = $case;
            }
        }

        if (empty($methods) && !empty($this->creditCard)) {
            $methods[] = PaymentMethod::CREDIT_CARD;
        }

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
     * Estorna a fatura: integral quando `refundedAmount` está vazio, parcial quando preenchido
     * com o valor em centavos. Devolve o `Refund` criado e atualiza esta instância com o
     * estado posterior ao estorno (`$refund->invoice()` é esta instância).
     *
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     * @return Refund
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\RefundNotSupportedException
     */
    public function refund(?string $idempotencyKey = null): Refund
    {
        $gateway = ConfigurationHelper::resolveGateway($this->gateway);
        return $gateway->refundInvoice($this, $idempotencyKey);
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
