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
    public ?string $description = null;

    /**
     * @var int|null
     */
    public ?int $price = null;

    /**
     * @var int|null
     */
    public ?int $quantity = null;
}
