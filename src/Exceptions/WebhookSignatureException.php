<?php

namespace Potelo\MultiPayment\Exceptions;

/**
 * Entrega de webhook recusada na verificação de autenticidade, antes de qualquer parse do
 * conteúdo. O motivo fica em `$reason`: cabeçalho de assinatura ausente, assinatura que não
 * confere, timestamp assinado fora da tolerância ou secret de verificação sem configuração.
 */
class WebhookSignatureException extends MultiPaymentException
{
    /** A requisição chegou sem o cabeçalho de assinatura do gateway. */
    public const REASON_MISSING_HEADER = 'missing_header';

    /** A assinatura enviada não confere com a calculada sobre o corpo recebido. */
    public const REASON_INVALID_SIGNATURE = 'invalid_signature';

    /** O timestamp assinado da entrega está fora da tolerância configurada. */
    public const REASON_TIMESTAMP_OUT_OF_TOLERANCE = 'timestamp_out_of_tolerance';

    /** O secret de verificação do gateway não está configurado. */
    public const REASON_MISSING_SECRET = 'missing_secret';

    /**
     * Nome do gateway, como registrado na configuração.
     *
     * @var string
     */
    public string $gateway;

    /**
     * Motivo da recusa: `missing_header`, `invalid_signature`, `timestamp_out_of_tolerance` ou
     * `missing_secret`.
     *
     * @var string
     */
    public string $reason;

    /**
     * Cria a exceção com o gateway e o motivo da recusa.
     *
     * @param  string  $message
     * @param  string  $gateway
     * @param  string  $reason
     */
    public function __construct(string $message, string $gateway, string $reason)
    {
        $this->gateway = $gateway;
        $this->reason = $reason;

        parent::__construct($message);
    }

    /**
     * A requisição chegou sem o cabeçalho de assinatura do gateway.
     *
     * @param  string  $gateway
     * @param  string  $header  nome do cabeçalho esperado
     * @return static
     */
    public static function missingHeader(string $gateway, string $header): static
    {
        return new static(
            "A entrega de webhook do gateway {$gateway} chegou sem o cabeçalho {$header}.",
            $gateway,
            self::REASON_MISSING_HEADER
        );
    }

    /**
     * A assinatura enviada não confere com a calculada sobre o corpo recebido.
     *
     * @param  string  $gateway
     * @return static
     */
    public static function invalidSignature(string $gateway): static
    {
        return new static(
            "A assinatura da entrega de webhook do gateway {$gateway} não confere com o corpo recebido.",
            $gateway,
            self::REASON_INVALID_SIGNATURE
        );
    }

    /**
     * O timestamp assinado da entrega está fora da tolerância configurada.
     *
     * @param  string  $gateway
     * @param  int  $toleranceSeconds
     * @return static
     */
    public static function timestampOutOfTolerance(string $gateway, int $toleranceSeconds): static
    {
        return new static(
            "O timestamp assinado da entrega de webhook do gateway {$gateway} está fora da tolerância"
                . " de {$toleranceSeconds} segundos.",
            $gateway,
            self::REASON_TIMESTAMP_OUT_OF_TOLERANCE
        );
    }

    /**
     * O secret de verificação do gateway não está configurado.
     *
     * @param  string  $gateway
     * @param  string  $configKey  chave de configuração onde o secret é esperado
     * @return static
     */
    public static function missingSecret(string $gateway, string $configKey): static
    {
        return new static(
            "O secret de verificação de webhook do gateway {$gateway} não está configurado"
                . " ({$configKey}).",
            $gateway,
            self::REASON_MISSING_SECRET
        );
    }
}
