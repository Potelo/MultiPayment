<?php

namespace Potelo\MultiPayment\Exceptions;

use Carbon\Carbon;
use Potelo\MultiPayment\Enums\Capability;

/**
 * Estorno recusado pela abstração antes de qualquer requisição ao gateway.
 *
 * É lançada quando a regra do gateway já garante que a API recusaria o estorno: boleto não tem
 * estorno via API em nenhum gateway, Pix na Iugu só aceita estorno integral, fatura já estornada
 * não estorna de novo, o valor pedido não pode passar do restante estornável, a fatura quitada
 * sem cobrança pelo gateway não tem o que estornar e a Iugu fecha a
 * janela de estorno 90 dias após o pagamento. O motivo fica em `$reason`, no vocabulário do
 * pacote, para a aplicação ramificar sem ler a mensagem. `$capability` aponta a capability
 * recusada quando a recusa é limitação do gateway (`REFUND_BANK_SLIP`, `PARTIAL_REFUND_PIX`) e
 * fica nula quando é estado da fatura (já estornada, valor acima do restante, prazo vencido);
 * `isCapabilityLimitation()` separa os dois casos. Herda direto de `MultiPaymentException`:
 * um `catch (UnsupportedOperationException)` não a captura.
 */
class RefundNotSupportedException extends MultiPaymentException
{
    /** Boleto não tem estorno pela API do gateway; devolução manual. */
    public const REASON_BOLETO_NO_REFUND = 'boleto_no_refund';

    /** O gateway só aceita estorno integral de Pix; o valor pedido difere do pago. */
    public const REASON_PIX_PARTIAL_NOT_SUPPORTED = 'pix_partial_not_supported';

    /** A fatura já está integralmente estornada. */
    public const REASON_ALREADY_REFUNDED = 'already_refunded';

    /** O prazo que o gateway dá para estornar após o pagamento já passou; devolução manual. */
    public const REASON_REFUND_WINDOW_EXPIRED = 'refund_window_expired';

    /** O valor pedido passa do que ainda pode ser estornado na fatura. */
    public const REASON_AMOUNT_EXCEEDS_REFUNDABLE = 'amount_exceeds_refundable';

    /** A fatura foi quitada sem cobrança pelo gateway (paga fora dele ou sem valor a cobrar). */
    public const REASON_NO_GATEWAY_CHARGE = 'no_gateway_charge';

    /**
     * Método de pagamento da fatura (`credit_card`, `bank_slip`, `pix`), ou nulo quando o
     * gateway não informou o método.
     *
     * @var string|null
     */
    public ?string $paymentMethod;

    /**
     * Motivo da recusa, uma das constantes `REASON_*` desta classe.
     *
     * @var string
     */
    public string $reason;

    /**
     * Verdadeiro quando a devolução ao cliente precisa acontecer fora do gateway (boleto,
     * prazo vencido); falso quando não há o que devolver (`already_refunded`) ou o pedido
     * pode ser corrigido (`pix_partial_not_supported`: repita sem valor parcial;
     * `amount_exceeds_refundable`: repita com valor até o restante).
     *
     * @var bool
     */
    public bool $manualRefundRequired;

    /**
     * Nome do gateway, como registrado na configuração; vazio quando a exceção foi criada
     * sem ele.
     *
     * @var string
     */
    public string $gateway;

    /**
     * Capability que o gateway não oferece (`REFUND_BANK_SLIP`, `PARTIAL_REFUND_PIX`), ou nulo
     * quando a recusa vem do estado da fatura.
     *
     * @var Capability|null
     */
    public ?Capability $capability;

    /**
     * Cria a exceção com o motivo da recusa e a flag de devolução manual.
     *
     * @param  string  $message
     * @param  string|null  $paymentMethod
     * @param  string  $reason
     * @param  bool  $manualRefundRequired
     * @param  \Throwable|null  $previous
     * @param  string  $gateway
     * @param  Capability|null  $capability
     */
    public function __construct(
        string $message,
        ?string $paymentMethod,
        string $reason,
        bool $manualRefundRequired = false,
        ?\Throwable $previous = null,
        string $gateway = '',
        ?Capability $capability = null
    ) {
        $this->paymentMethod = $paymentMethod;
        $this->reason = $reason;
        $this->manualRefundRequired = $manualRefundRequired;
        $this->gateway = $gateway;
        $this->capability = $capability;

        parent::__construct($message, $previous);
    }

    /**
     * Diz se a recusa é limitação do gateway, descrita por `$capability` (boleto sem estorno,
     * Pix sem estorno parcial). Falso quando a recusa vem do estado da fatura: já estornada,
     * valor acima do restante ou prazo vencido.
     *
     * @return bool
     */
    public function isCapabilityLimitation(): bool
    {
        return !is_null($this->capability);
    }

    /**
     * Sempre falso: nenhuma recusa de estorno é uma capability que a lib ainda não implementou.
     *
     * @deprecated desde 2026-09-02, sem substituto; responde sempre falso.
     * @return bool
     */
    public function isNotImplemented(): bool
    {
        trigger_error(
            'RefundNotSupportedException::isNotImplemented() está obsoleto desde 2026-09-02 e responde sempre falso',
            E_USER_DEPRECATED
        );

        return false;
    }

    /**
     * Boleto não tem estorno via API no gateway informado.
     *
     * @param  string  $gateway
     * @return static
     */
    public static function boletoNoRefund(string $gateway): static
    {
        return new static(
            "O gateway {$gateway} não estorna boleto pela API; faça a devolução manualmente ao cliente.",
            'bank_slip',
            self::REASON_BOLETO_NO_REFUND,
            true,
            null,
            $gateway,
            Capability::REFUND_BANK_SLIP
        );
    }

    /**
     * O gateway só aceita estorno integral de Pix e o valor pedido difere do valor pago.
     *
     * @param  string  $gateway
     * @param  int  $requestedAmount  valor pedido, em centavos
     * @param  int|null  $paidAmount  valor pago, em centavos
     * @return static
     */
    public static function pixPartialNotSupported(string $gateway, int $requestedAmount, ?int $paidAmount): static
    {
        $paid = is_null($paidAmount) ? 'desconhecido' : (string) $paidAmount;

        return new static(
            "O gateway {$gateway} só estorna Pix integralmente (pedido: {$requestedAmount} centavos, pago: {$paid}); repita sem valor parcial.",
            'pix',
            self::REASON_PIX_PARTIAL_NOT_SUPPORTED,
            false,
            null,
            $gateway,
            Capability::PARTIAL_REFUND_PIX
        );
    }

    /**
     * A fatura já está integralmente estornada.
     *
     * @param  string  $gateway
     * @param  string|null  $paymentMethod
     * @return static
     */
    public static function alreadyRefunded(string $gateway, ?string $paymentMethod): static
    {
        return new static(
            "A fatura já foi integralmente estornada no gateway {$gateway}.",
            $paymentMethod,
            self::REASON_ALREADY_REFUNDED,
            false,
            null,
            $gateway
        );
    }

    /**
     * O prazo de estorno do gateway, contado a partir do pagamento, já passou.
     *
     * @param  string  $gateway
     * @param  string|null  $paymentMethod
     * @param  \Carbon\Carbon  $paidAt
     * @param  int  $windowDays
     * @return static
     */
    public static function refundWindowExpired(string $gateway, ?string $paymentMethod, Carbon $paidAt, int $windowDays): static
    {
        return new static(
            "O gateway {$gateway} só estorna até {$windowDays} dias após o pagamento (pago em {$paidAt->toDateString()}); faça a devolução manualmente ao cliente.",
            $paymentMethod,
            self::REASON_REFUND_WINDOW_EXPIRED,
            true,
            null,
            $gateway
        );
    }

    /**
     * A fatura foi quitada sem uma cobrança feita pelo gateway (pagamento registrado fora dele,
     * ou fatura sem valor a cobrar), então a API não tem o que estornar.
     *
     * @param  string  $gateway
     * @param  string|null  $paymentMethod
     * @param  bool  $manualRefundRequired  verdadeiro quando houve pagamento fora do gateway a devolver
     * @return static
     */
    public static function noGatewayCharge(string $gateway, ?string $paymentMethod, bool $manualRefundRequired): static
    {
        return new static(
            "A fatura foi quitada sem cobrança pelo gateway {$gateway} (pagamento fora dele ou sem valor a cobrar);"
            . ' a API não tem o que estornar'
            . ($manualRefundRequired ? '; devolva o pagamento recebido fora do gateway manualmente.' : '.'),
            $paymentMethod,
            self::REASON_NO_GATEWAY_CHARGE,
            $manualRefundRequired,
            null,
            $gateway
        );
    }

    /**
     * O valor pedido passa do que ainda pode ser estornado na fatura.
     *
     * @param  string  $gateway
     * @param  string|null  $paymentMethod
     * @param  int  $requestedAmount  valor pedido, em centavos
     * @param  int  $refundableAmount  valor que ainda pode ser estornado, em centavos
     * @return static
     */
    public static function amountExceedsRefundable(string $gateway, ?string $paymentMethod, int $requestedAmount, int $refundableAmount): static
    {
        return new static(
            "O valor pedido ({$requestedAmount} centavos) passa do que ainda pode ser estornado na fatura ({$refundableAmount} centavos) no gateway {$gateway}; repita com valor até o restante.",
            $paymentMethod,
            self::REASON_AMOUNT_EXCEEDS_REFUNDABLE,
            false,
            null,
            $gateway
        );
    }
}
