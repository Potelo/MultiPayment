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
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_REFUNDED = 'refunded';

    public const PAYMENT_METHOD_CREDIT_CARD = 'credit_card';
    public const PAYMENT_METHOD_BANK_SLIP = 'bank_slip';
    public const PAYMENT_METHOD_PIX = 'pix';

    /**
     * @var string|null
     */
    public ?string $id;

    /**
     * @var string|null
     */
    public ?string $status;

    /**
     * @var int|null
     */
    public ?int $amount;

    /**
     * @var Customer|null
     */
    public ?Customer $customer;

    /**
     * @var InvoiceItem[]|null
     */
    public ?array $items;

    /**
     * @var string|null
     */
    public ?string $paymentMethod;

    /**
     * @var CreditCard|null
     */
    public ?CreditCard $creditCard;

    /**
     * @var BankSlip|null
     */
    public ?BankSlip $bankSlip;

    /**
     * @var Pix|null
     */
    public ?Pix $pix;

    /**
     * @var Carbon|null
     */
    public ?Carbon $expirationDate;

    /**
     * @var int|null
     */
    public ?int $fee;

    /**
     * @var string|null
     */
    public ?string $gateway;

    /**
     * @var string|null
     */
    public ?string $url;

    /**
     * The original invoice response of the gateway, in case need additional information.
     *
     * @var mixed|null
     */
    public $original;

    /**
     * @var Carbon|null
     */
    public ?Carbon $createdAt;

    /**
     * @inheritDoc
     */
    public function fill(array $data): void
    {
        if (empty($data['items']) && !empty($data['amount'])) {
            $invoiceItem = new InvoiceItem($this->gatewayClass);
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
                    $invoiceItem = new InvoiceItem($this->gatewayClass);
                    $invoiceItem->fill($item);
                }
                $this->items[] = $invoiceItem;
            }
            unset($data['items']);
        }

        if (!empty($data['customer']) && is_array($data['customer'])) {
            $this->customer = new Customer($this->gatewayClass);
            $this->customer->fill($data['customer']);
            unset($data['customer']);
        }

        if (!empty($data['expiration_date'])) {
            $this->expirationDate = Carbon::createFromFormat('Y-m-d', $data['expiration_date']);
            unset($data['expiration_date']);
        }

        if (!empty($data['credit_card']) && is_array($data['credit_card'])) {
            $this->creditCard = new CreditCard($this->gatewayClass);
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

        if (
            in_array('paymentMethod', $attributes) &&
            !empty($this->paymentMethod) &&
            $this->paymentMethod == Invoice::PAYMENT_METHOD_CREDIT_CARD &&
            empty($this->creditCard)
        ) {
            throw new ModelAttributeValidationException('The `creditCard` attribute is required for credit_card payment method.');
        }

        if (in_array('paymentMethod', $attributes) &&
            !empty($this->paymentMethod) &&
            (
                $this->paymentMethod == Invoice::PAYMENT_METHOD_BANK_SLIP ||
                $this->paymentMethod == Invoice::PAYMENT_METHOD_PIX
            ) &&
            empty($this->expirationDate)
        ) {
            throw new ModelAttributeValidationException('The `expirationDate` attribute is required for bank_slip or pix payment method.');
        }

        if (in_array('paymentMethod', $attributes) &&
            !empty($this->paymentMethod) &&
            $this->paymentMethod == Invoice::PAYMENT_METHOD_BANK_SLIP &&
            empty($this->customer->address)
        ) {
            throw new ModelAttributeValidationException('The customer address is required for bank_slip payment method');
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
        $this->creditCard->validate();
    }
    
}
