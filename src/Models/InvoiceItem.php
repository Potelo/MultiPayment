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

    /**
     * @inerhitDoc
     */
    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'price' => $this->price,
            'quantity' => $this->quantity,
        ];
    }
}
