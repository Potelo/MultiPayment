<?php

namespace Potelo\MultiPayment\Models;

use Carbon\Carbon;
use Potelo\MultiPayment\Enums\DeclineCode;

/**
 * Último erro de pagamento registrado pelo gateway numa fatura, preenchido pelos drivers na
 * leitura (`Invoice::$lastPaymentError`). Usa o mesmo `DeclineCode` da recusa síncrona
 * (`CardDeclinedException`).
 *
 * @property DeclineCode|null $declineCode Motivo normalizado da recusa; `UNKNOWN` para código que a lib não mapeia.
 */
class PaymentError extends Model
{
    protected const ENUM_CASTS = [
        'declineCode' => DeclineCode::class,
    ];

    /**
     * @var DeclineCode|null
     */
    protected ?DeclineCode $declineCode = null;

    /**
     * Código original do gateway: `decline_code` (ou, na falta dele, `code`) na Stripe, LR na
     * Iugu.
     *
     * @var string|null
     */
    public ?string $gatewayCode = null;

    /**
     * Mensagem do gateway sobre a recusa, quando ele a informa.
     *
     * @var string|null
     */
    public ?string $message = null;

    /**
     * Momento da tentativa recusada, quando o gateway o informa.
     *
     * @var Carbon|null
     */
    public ?Carbon $occurredAt = null;

    /**
     * Orientação de nova tentativa enviada pelo gateway junto com a recusa (`advice_code` da
     * Stripe); nula quando o gateway não orienta e vale o padrão do `declineCode` (ver
     * `retryable()`).
     *
     * @var bool|null
     */
    public ?bool $retryable = null;

    /**
     * @var string|null
     */
    public ?string $gateway = null;

    /**
     * O objeto de erro original do gateway, caso seja necessária alguma informação adicional.
     *
     * @var mixed|null
     */
    public $original = null;

    /**
     * Diz se uma nova tentativa com o mesmo método de pagamento tem chance de aprovação: a
     * orientação do gateway (`$retryable`) quando existe, senão `DeclineCode::isRetryable()`;
     * falso sem `declineCode`.
     *
     * @return bool
     */
    public function retryable(): bool
    {
        return $this->retryable ?? $this->declineCode?->isRetryable() ?? false;
    }

    /**
     * @inheritDoc
     */
    public function fill(array $data): void
    {
        if (!empty($data['occurred_at']) && !$data['occurred_at'] instanceof Carbon) {
            $data['occurred_at'] = Carbon::parse($data['occurred_at']);
        }

        parent::fill($data);
    }
}
