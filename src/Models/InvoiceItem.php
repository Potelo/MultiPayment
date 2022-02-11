<?php

namespace Potelo\MultiPayment\Models;

/**
 * Class InvoiceItem
 */
class InvoiceItem extends Model
{
    /**
     * @var string
     */
    public string $description;

    /**
     * @var int
     */
    public int $price;

    /**
     * @var int
     */
    public int $quantity;
}
