<?php

namespace Potelo\MultiPayment\Models;

use Carbon\Carbon;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Recurring Pix authorization shared by gateways that support Pix Automático.
 */
class AutomaticPix extends Model
{
    public const AUTHORIZATION_TYPE_QR_CODE_WITH_PAYMENT = 'qr_code_with_payment';
    public const AUTHORIZATION_TYPE_QR_CODE_WITH_RECURRENCE_OFFER = 'qr_code_with_recurrence_offer';

    public const FREQUENCY_WEEKLY = 'weekly';
    public const FREQUENCY_MONTHLY = 'monthly';
    public const FREQUENCY_QUARTERLY = 'quarterly';
    public const FREQUENCY_SEMIANNUAL = 'semiannual';
    public const FREQUENCY_ANNUAL = 'annual';

    public const RETRY_POLICY_ALLOWED = 'allowed';
    public const RETRY_POLICY_NOT_ALLOWED = 'not_allowed';

    public ?string $id = null;
    public ?string $authorizationType = null;
    public ?string $frequency = null;
    public ?Carbon $startsAt = null;
    public ?string $contractReference = null;
    public ?Carbon $endsAt = null;
    public string $retryPolicy = self::RETRY_POLICY_NOT_ALLOWED;
    public ?string $status = null;
    public ?string $gateway = null;
    public $original = null;

    /** @inheritDoc */
    public function fill(array $data): void
    {
        foreach (['starts_at' => 'startsAt', 'ends_at' => 'endsAt'] as $key => $attribute) {
            if (!empty($data[$key])) {
                $this->{$attribute} = $data[$key] instanceof Carbon
                    ? $data[$key]
                    : Carbon::parse($data[$key]);
                unset($data[$key]);
            }
        }

        parent::fill($data);
    }

    /**
     * Validate the fields required when an invoice creates or schedules a recurrence.
     */
    public function validateForInvoice(): void
    {
        if (!empty($this->id)) {
            return;
        }

        foreach (['authorizationType', 'frequency', 'startsAt', 'contractReference'] as $attribute) {
            if (empty($this->{$attribute})) {
                throw ModelAttributeValidationException::required($this->getClassName(), $attribute);
            }
        }

        $this->validate();
    }

    protected function validateFrequencyAttribute(): void
    {
        $frequencies = [
            self::FREQUENCY_WEEKLY,
            self::FREQUENCY_MONTHLY,
            self::FREQUENCY_QUARTERLY,
            self::FREQUENCY_SEMIANNUAL,
            self::FREQUENCY_ANNUAL,
        ];

        if (!in_array($this->frequency, $frequencies, true)) {
            throw ModelAttributeValidationException::invalid(
                $this->getClassName(),
                'frequency',
                'frequency must be one of: ' . implode(', ', $frequencies)
            );
        }
    }

    protected function validateRetryPolicyAttribute(): void
    {
        $policies = [self::RETRY_POLICY_ALLOWED, self::RETRY_POLICY_NOT_ALLOWED];

        if (!in_array($this->retryPolicy, $policies, true)) {
            throw ModelAttributeValidationException::invalid(
                $this->getClassName(),
                'retryPolicy',
                'retryPolicy must be one of: ' . implode(', ', $policies)
            );
        }
    }
}
