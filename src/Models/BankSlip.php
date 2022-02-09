<?php

namespace Potelo\MultiPayment\Models;

use Carbon\Carbon;

/**
 * Class BankSlip
 */
class BankSlip extends Model
{

    /**
     * @var string|null
     */
    public ?string $url = null;

    /**
     * @var string|null
     */
    public ?string $number = null;

    /**
     * @var string|null
     */
    public ?string $barcodeData = null;

    /**
     * @var string|null
     */
    public ?string $barcodeImage = null;

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'number' => $this->number,
            'barcode_data' => $this->barcodeData,
            'barcode_image' => $this->barcodeImage,
        ];
    }
}
