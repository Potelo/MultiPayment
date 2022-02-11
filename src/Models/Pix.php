<?php

namespace Potelo\MultiPayment\Models;

/**
 * Class Pix
 */
class Pix extends Model
{

    /**
     * @var string
     */
    public string $qrCodeImageUrl;

    /**
     * @var string
     */
    public string $qrCodeText;
}