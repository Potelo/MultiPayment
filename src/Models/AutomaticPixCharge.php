<?php

namespace Potelo\MultiPayment\Models;

use Carbon\Carbon;

/**
 * A charge issued for one cycle of an Automatic Pix recurrence.
 */
class AutomaticPixCharge extends Model
{
    public ?string $id = null;
    public ?string $recurrenceId = null;
    public ?string $endToEndId = null;
    public ?string $description = null;
    public int|float|string|null $amount = null;
    public ?Carbon $scheduledAt = null;
    public ?string $status = null;
    public ?string $gateway = null;
    public $original = null;

    /** @inheritDoc */
    public function fill(array $data): void
    {
        if (!empty($data['scheduled_at'])) {
            $this->scheduledAt = $data['scheduled_at'] instanceof Carbon
                ? $data['scheduled_at']
                : Carbon::parse($data['scheduled_at']);
            unset($data['scheduled_at']);
        }

        parent::fill($data);
    }
}
