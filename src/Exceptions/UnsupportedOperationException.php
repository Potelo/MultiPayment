<?php

namespace Potelo\MultiPayment\Exceptions;

use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Contracts\GatewayContract;

/**
 * Operação recusada pela lib antes de qualquer requisição, porque o gateway não oferece a
 * capability (`gateway_limitation`) ou porque o gateway oferece e a lib ainda não a implementou
 * para ele (`not_implemented`).
 */
class UnsupportedOperationException extends MultiPaymentException
{
    /** O gateway não oferece o recurso. */
    public const REASON_GATEWAY_LIMITATION = 'gateway_limitation';

    /** O gateway oferece o recurso e a lib ainda não o implementou para ele. */
    public const REASON_NOT_IMPLEMENTED = 'not_implemented';

    /**
     * Capability recusada, ou nulo quando a recusa vem de uma regra que nenhuma capability
     * descreve.
     *
     * @var Capability|null
     */
    public ?Capability $capability;

    /**
     * Nome do gateway, como registrado na configuração.
     *
     * @var string
     */
    public string $gateway;

    /**
     * Motivo da recusa: `gateway_limitation` ou `not_implemented`.
     *
     * @var string
     */
    public string $reason;

    /**
     * Cria a exceção com a capability recusada, o gateway e o motivo.
     *
     * @param  string  $message
     * @param  string  $gateway
     * @param  Capability|null  $capability
     * @param  string  $reason
     * @param  \Throwable|null  $previous
     * @param  int|null  $httpStatus
     */
    public function __construct(
        string $message,
        string $gateway,
        ?Capability $capability,
        string $reason,
        ?\Throwable $previous = null,
        ?int $httpStatus = null
    ) {
        $this->gateway = $gateway;
        $this->capability = $capability;
        $this->reason = $reason;

        parent::__construct($message, $previous, $httpStatus);
    }

    /**
     * O gateway oferece o recurso e a lib ainda não o implementou para ele.
     *
     * @param  string  $gateway
     * @param  Capability  $capability
     * @param  string  $detail  orientação acrescentada ao fim da mensagem
     * @return static
     */
    public static function notImplemented(string $gateway, Capability $capability, string $detail = ''): static
    {
        $message = "A capability [{$capability->value}] ainda não está implementada nesta lib para o gateway"
            . " {$gateway}; o gateway oferece o recurso.";

        return new static(self::appendDetail($message, $detail), $gateway, $capability, self::REASON_NOT_IMPLEMENTED);
    }

    /**
     * O gateway não oferece o recurso.
     *
     * @param  string  $gateway
     * @param  Capability  $capability
     * @param  string  $detail  orientação acrescentada ao fim da mensagem
     * @return static
     */
    public static function gatewayLimitation(string $gateway, Capability $capability, string $detail = ''): static
    {
        $message = "O gateway {$gateway} não oferece a capability [{$capability->value}].";

        return new static(self::appendDetail($message, $detail), $gateway, $capability, self::REASON_GATEWAY_LIMITATION);
    }

    /**
     * O gateway oferece a capability só numa parte dos casos e este pedido está fora dela; a
     * mensagem descreve a restrição. O motivo é `gateway_limitation`.
     *
     * @param  string  $gateway
     * @param  Capability  $capability
     * @param  string  $message
     * @return static
     */
    public static function restricted(string $gateway, Capability $capability, string $message): static
    {
        return new static($message, $gateway, $capability, self::REASON_GATEWAY_LIMITATION);
    }

    /**
     * Decide o motivo pela declaração do gateway: capability em `notYetImplemented()` é
     * `not_implemented`; fora das duas listas é `gateway_limitation`.
     *
     * @param  GatewayContract  $gateway
     * @param  Capability  $capability
     * @param  string  $detail  orientação acrescentada ao fim da mensagem
     * @return static
     */
    public static function forGateway(GatewayContract $gateway, Capability $capability, string $detail = ''): static
    {
        if (in_array($capability, $gateway->notYetImplemented(), true)) {
            return self::notImplemented((string) $gateway, $capability, $detail);
        }

        return self::gatewayLimitation((string) $gateway, $capability, $detail);
    }

    /**
     * Diz se o motivo é `not_implemented`: o gateway oferece o recurso e a lib ainda não o
     * implementou para ele.
     *
     * @return bool
     */
    public function isNotImplemented(): bool
    {
        return $this->reason === self::REASON_NOT_IMPLEMENTED;
    }

    /**
     * Acrescenta a orientação à mensagem, separada por espaço.
     *
     * @param  string  $message
     * @param  string  $detail
     * @return string
     */
    private static function appendDetail(string $message, string $detail): string
    {
        return $detail === '' ? $message : "{$message} {$detail}";
    }
}
