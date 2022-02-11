<?php

namespace Potelo\MultiPayment\Models;

/**
 * Class Pix
 */
class Pix extends Model
{

    /**
     * @var string|null
     */
    public ?string $qrCodeImageUrl;

    /**
     * @var string|null
     */
    public ?string $qrCodeText;
}