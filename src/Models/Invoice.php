<?php

namespace Potelo\MultiPayment\Models;

use Carbon\Carbon;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Helpers\ConfigurationHelper;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Invoice class
 */
class Invoice extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';

    public const PAYMENT_METHOD_CREDIT_CARD = 'credit_card';
    public const PAYMENT_METHOD_BANK_SLIP = 'bank_slip';
    public const PAYMENT_METHOD_PIX = 'pix';

    /**
     * @var string|null
     */
    public ?string $id = null;

    /**
     * @var string|null
     */
    public ?string $status = null;

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
     * @var Customer|null
     */
    public ?Customer $customer = null;

    /**
     * @var InvoiceItem[]|null
     */
    public ?array $items = null;

    /**
     * @var string|null
     */
    public ?string $paymentMethod = null;

    /**
     * @var string[]|null
     */
    public ?array $availablePaymentMethods = null;

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
            $data['items'] = [];
            $invoiceItem->fill([
                'description' => 'Nova cobrança',
                'quantity' => 1,
                'price' => $data['amount'],
            ]);
            $this->items[] = $invoiceItem;
            unset($data['amount']);
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
     * @return void
     * @throws ModelAttributeValidationException
     */
    public function validateAvailablePaymentMethodsAttribute()
    {
        $meethods = [
            self::PAYMENT_METHOD_CREDIT_CARD,
            self::PAYMENT_METHOD_BANK_SLIP,
            self::PAYMENT_METHOD_PIX,
        ];

        if (!is_array($this->availablePaymentMethods)) {
            throw ModelAttributeValidationException::invalid('Invoice', 'availablePaymentMethods', 'availablePaymentMethods must be an array of payment methods');
        }
        foreach ($this->availablePaymentMethods as $method) {
            if (!in_array($method, $meethods)) {
                throw ModelAttributeValidationException::invalid('Invoice', 'availablePaymentMethods', 'availablePaymentMethods must be one of: ' . implode(', ', $meethods));
            }
        }
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
     * @inheritDoc
     */
    public function save(GatewayContract|string $gateway = null, bool $validate = true): void
    {
        if ($validate) {
            $this->validate();
        }
        if (empty($this->customer->id)) {
            $this->customer->save($gateway, $validate);
        }
        if (!empty($this->creditCard) && empty($this->creditCard->id)) {
            $this->creditCard->customer = $this->customer;
        }
        parent::save($gateway, false);
    }

    /**
     * Refund the invoice
     *
     * @return \Potelo\MultiPayment\Models\Invoice
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function refund(): Invoice
    {
        $gateway = ConfigurationHelper::resolveGateway($this->gateway);
        return $gateway->refundInvoice($this);
    }

    /**
     * Charge invoice with credit card
     *
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     * @throws \Potelo\MultiPayment\Exceptions\ChargingException
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     */
    public function chargeInvoiceWithCreditCard(?CreditCard $creditCard = null): Invoice
    {
        if (!empty($creditCard)) {
            $this->creditCard = $creditCard;
        }

        $gateway = ConfigurationHelper::resolveGateway($this->gateway);

        return $gateway->chargeInvoiceWithCreditCard($this);
    }

    /**
     * Duplicate the invoice
     *
     * @param  \Carbon\Carbon  $expiresAt
     * @param  array  $gatewayOptions
     * @return \Potelo\MultiPayment\Models\Invoice
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function duplicate(Carbon $expiresAt, array $gatewayOptions = []): Invoice
    {
        $gateway = ConfigurationHelper::resolveGateway($this->gateway);
        return $gateway->duplicateInvoice($this, $expiresAt, $gatewayOptions);
    }
}
