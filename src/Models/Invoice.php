<?php

namespace Potelo\MultiPayment\Models;

use Carbon\Carbon;
use Potelo\MultiPayment\Exceptions\GatewayException;

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
    public ?string $orderId;

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

    public function fill(array $data): void
    {
        $this->items = [];

        foreach ($data['items'] as $item) {
            if (!empty($item) && is_array($item)) {
                $invoiceItem = new InvoiceItem();
                $invoiceItem->fill($item);
            } else {
                $invoiceItem = $item;
            }
            $this->items[] = $invoiceItem;
        }
        unset($data['items']);

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
    public function save(): bool
    {
        if (!$this->validate()){
            return false;
        }
        return parent::save();
    }

    /**
     * Validate the model.
     *
     * @return bool
     */
    private function validate(): bool
    {
        if (!$this->hasPaymentMethod($this->paymentMethod)) {
            $this->errors = new GatewayException('Invalid payment method.');
            return false;
        }
        return true;
    }

    /**
     * Verify if it has the payment method
     *
     * @param $paymentMethod
     *
     * @return bool
     */
    public static function hasPaymentMethod($paymentMethod): bool
    {
        return in_array($paymentMethod, [
            Invoice::PAYMENT_METHOD_CREDIT_CARD,
            Invoice::PAYMENT_METHOD_BANK_SLIP,
            Invoice::PAYMENT_METHOD_PIX,
        ]);
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
