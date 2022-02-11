<?php

namespace Potelo\MultiPayment\Models;

use Carbon\Carbon;

/**
 * Class BankSlip
 */
class BankSlip extends Model
{

    /**
     * @var string
     */
    public string $url;

    /**
     * @var string
     */
    public string $number;

    /**
     * @var string
     */
    public string $barcodeData;

    /**
     * @var string
     */
    public string $barcodeImage;

}
