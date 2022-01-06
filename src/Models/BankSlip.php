<?php

namespace Potelo\MultiPayment\Models;

use DateTime;

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
     * @var DateTime|null
     */
    public ?DateTime $expirationDate = null;

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
     * @inerhitDoc
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'expiration_date' => $this->expirationDate,
            'number' => $this->number,
            'barcode_data' => $this->barcodeData,
            'barcode_image' => $this->barcodeImage,
        ];
    }
}
