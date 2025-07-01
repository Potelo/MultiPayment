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
    public ?string $qrCodeImageUrl = null;

    /**
     * @var string|null
     */
    public ?string $qrCodeText = null;
}
