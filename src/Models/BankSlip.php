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

}
