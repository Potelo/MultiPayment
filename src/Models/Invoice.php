<?php

namespace Potelo\MultiPayment\Models;

use Carbon\Carbon;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Invoice class
 */
class Invoice extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_AUTHORIZED = 'authorized';

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
     * @var int|null
     */
    public ?int $amount = null;

    /**
     * @var string|null
     */
    public ?string $orderId = null;

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
    public ?Carbon $expirationDate = null;

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
        }
        elseif (!empty($data['items'])) {
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

        if (!empty($data['expiration_date'])) {
            $this->expirationDate = Carbon::createFromFormat('Y-m-d', $data['expiration_date']);
            unset($data['expiration_date']);
        }

        if (!empty($data['credit_card']) && is_array($data['credit_card'])) {
            $this->creditCard = new CreditCard();
            $this->creditCard->fill($data['credit_card']);
            unset($data['credit_card']);
        }
        parent::fill($data);
    }

    /**
     * @param  array  $attributes
     *
     * @return void
     * @throws ModelAttributeValidationException
     */
    public function validate(array $attributes = []): void
    {
        parent::validate($attributes);

        $model = 'Invoice';

        if (empty($this->customer)) {
            throw ModelAttributeValidationException::required($model, 'customer');
        }

        if (empty($this->amount) && empty($this->items)) {
            throw ModelAttributeValidationException::required($model, 'amount or items');
        }
        if (empty($this->paymentMethod)) {
            throw ModelAttributeValidationException::required($model, 'paymentMethod');
        }
        if ($this->paymentMethod == Invoice::PAYMENT_METHOD_CREDIT_CARD) {
            if (empty($this->creditCard)) {
                throw new ModelAttributeValidationException('The `creditCard` attribute is required for credit_card payment method.');
            }
        }
        if (($this->paymentMethod == Invoice::PAYMENT_METHOD_BANK_SLIP) && empty($this->customer->address)) {
            throw new ModelAttributeValidationException('The customer address is required for bank_slip payment method');
        }
    }

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    public function validateCustomerAttribute()
    {
        if ($this->customer instanceof Customer) {
            $this->customer->validate();
        } else {
            throw ModelAttributeValidationException::invalid('Invoice', 'customer', 'customer must be an instance of Customer');
        }
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
    public function validatePaymentMethodAttribute()
    {
        if (!in_array($this->paymentMethod, [
            Invoice::PAYMENT_METHOD_CREDIT_CARD,
            Invoice::PAYMENT_METHOD_BANK_SLIP,
            Invoice::PAYMENT_METHOD_PIX,
        ])) {
            throw ModelAttributeValidationException::invalid(
                'Invoice',
                'paymentMethod' ,
                'paymentMethod must be one of: ' . implode(', ', [
                    Invoice::PAYMENT_METHOD_CREDIT_CARD,
                    Invoice::PAYMENT_METHOD_BANK_SLIP,
                    Invoice::PAYMENT_METHOD_PIX,
                ])
            );
        }
    }

    /**
     * @return void
     * @throws ModelAttributeValidationException
     */
    public function validateCreditCardAttribute()
    {
        if ($this->creditCard instanceof CreditCard) {
            $this->creditCard->validate();
        } else {
            throw ModelAttributeValidationException::invalid('Invoice', 'creditCard', 'creditCard must be an instance of CreditCard');
        }
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'amount' => $this->amount,
            'order_id' => $this->orderId,
            'customer' => $this->customer,
            'items' => $this->items,
            'payment_method' => $this->paymentMethod,
            'credit_card' => $this->creditCard,
            'bank_slip' => $this->bankSlip,
            'pix' => $this->pix,
            'expiration_date' => $this->expirationDate,
            'fee' => $this->fee,
            'gateway' => $this->gateway,
            'url' => $this->url,
            'original' => $this->original,
            'created_at' => $this->createdAt,
        ];
    }
}
