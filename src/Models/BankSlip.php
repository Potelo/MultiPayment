<?php

namespace Potelo\MultiPayment\Models;

/**
 * Class BankSlip
 */
class BankSlip extends Model
{

    /**
     * @var string|null
     */
    public ?string $url;

    /**
     * @var string|null
     */
    public ?string $number;

    /**
     * @var string|null
     */
    public ?string $barcodeData;

    /**
     * @var string|null
     */
    public ?string $barcodeImage;

}
