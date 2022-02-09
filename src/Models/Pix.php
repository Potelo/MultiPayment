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

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'qr_code_image_url' => $this->qrCodeImageUrl,
            'qr_code_text' => $this->qrCodeText,
        ];
    }
}