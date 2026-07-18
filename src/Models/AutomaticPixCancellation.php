<?php

namespace Potelo\MultiPayment\Models;

use Carbon\Carbon;

/**
 * A cancellation requested for an Automatic Pix recurrence or payment.
 */
class AutomaticPixCancellation extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public ?string $id = null;
    public ?string $recurrenceId = null;
    public ?string $paymentId = null;
    public ?string $endToEndId = null;
    public ?string $status = null;
    public int|float|string|null $amount = null;
    public mixed $payerAccount = null;
    public ?Carbon $createdAt = null;
    public ?string $gateway = null;
    public $original = null;
}
