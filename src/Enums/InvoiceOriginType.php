<?php

namespace Potelo\MultiPayment\Enums;

/**
 * Objeto do gateway do qual a fatura foi lida. O model `Invoice` é o mesmo nas duas origens;
 * `Invoice::$original` guarda o objeto cru e este enum diz qual é.
 */
enum InvoiceOriginType: string
{
    /** PaymentIntent da Stripe: cobrança avulsa. */
    case PAYMENT_INTENT = 'payment_intent';

    /** Fatura do gateway: toda fatura da Iugu e a fatura de assinatura da Stripe (objeto Invoice). */
    case INVOICE = 'invoice';
}
