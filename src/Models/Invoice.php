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
     * @var string
     */
    public string $id;

    /**
     * @var string
     */
    public string $status;

    /**
     * @var int
     */
    public int $amount;

    /**
     * @var string
     */
    public string $orderId;

    /**
     * @var Customer
     */
    public Customer $customer;

    /**
     * @var InvoiceItem[]
     */
    public ?array$items;

    /**
     * @var string
     */
    public string $paymentMethod;

    /**
     * @var CreditCard
     */
    public CreditCard $creditCard;

    /**
     * @var BankSlip
     */
    public BankSlip $bankSlip;

    /**
     * @var Pix
     */
    public Pix $pix;

    /**
     * @var Carbon
     */
    public Carbon $expirationDate;

    /**
     * @var int
     */
    public int $fee;

    /**
     * @var string
     */
    public string $gateway;

    /**
     * @var string
     */
    public string $url;

    /**
     * The original invoice response of the gateway, in case need additional information.
     *
     * @var mixed
     */
    public $original;

    /**
     * @var Carbon
     */
    public Carbon $createdAt;

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
     * @inheritDoc
     */
    public function validate(array $attributes = [], array $excludedAttributes = []): void
    {
        parent::validate($attributes);
        if (empty($attributes)) {
            $attributes = array_keys(get_object_vars($this));
        }
        $attributes = array_diff_key($attributes, array_flip($excludedAttributes));

        $model = 'Invoice';

        if (in_array('customer', $attributes) && empty($this->customer)) {
            throw ModelAttributeValidationException::required($model, 'customer');
        }

        if (in_array('amount', $attributes) && in_array('items', $attributes) && empty($this->amount) && empty($this->items)) {
            throw ModelAttributeValidationException::required($model, 'amount or items');
        }
        if (in_array('paymentMethod', $attributes) && empty($this->paymentMethod)) {
            throw ModelAttributeValidationException::required($model, 'paymentMethod');
        }
        if (in_array('paymentMethod', $attributes) && $this->paymentMethod == Invoice::PAYMENT_METHOD_CREDIT_CARD) {
            if (empty($this->creditCard)) {
                throw new ModelAttributeValidationException('The `creditCard` attribute is required for credit_card payment method.');
            }
        }
        if (in_array('paymentMethod', $attributes) && $this->paymentMethod == Invoice::PAYMENT_METHOD_BANK_SLIP && empty($this->customer->address)) {
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

}
