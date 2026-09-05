<?php

namespace Potelo\MultiPayment\Enums;

/**
 * Momento em que o valor autorizado no cartão é capturado. Com `MANUAL` a fatura nasce em
 * `InvoiceStatus::AUTHORIZED` (valor reservado no cartão) e a captura acontece depois, por
 * `captureInvoice()`; `cancelInvoice()` sobre a fatura autorizada libera a reserva.
 */
enum CaptureMethod: string
{
    /** O gateway captura o valor na própria cobrança. */
    case AUTOMATIC = 'automatic';

    /** O gateway só reserva o valor; a captura é pedida depois por `captureInvoice()`. */
    case MANUAL = 'manual';
}
