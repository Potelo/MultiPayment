<?php

namespace Potelo\MultiPayment\Models;

/**
 * Class InvoiceItem
 */
class InvoiceItem extends Model
{
    /**
     * @var string|null
     */
    public ?string $description;

    /**
     * @var int|null
     */
    public ?int $price;

    /**
     * @var int|null
     */
    public ?int $quantity;
}
