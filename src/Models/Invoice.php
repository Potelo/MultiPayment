<?php

namespace Potelo\MultiPayment\Models;

use DateTimeImmutable;

class Invoice extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_AUTHORIZED = 'authorized';

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
     * @var DateTimeImmutable|null
     */
    public ?DateTimeImmutable $createdAt = null;

    /**
     * @inerhitDoc
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'amount' => $this->amount,
            'orderId' => $this->orderId,
            'customer' => $this->customer,
            'items' => $this->items,
            'paymentMethod' => $this->paymentMethod,
            'creditCard' => $this->creditCard,
            'bankSlip' => $this->bankSlip,
            'fee' => $this->fee,
            'gateway' => $this->gateway,
            'url' => $this->url,
            'original' => $this->original,
            'createdAt' => $this->createdAt,
        ];
    }
}
