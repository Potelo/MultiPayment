<?php

namespace Potelo\MultiPayment\Exceptions;

use Carbon\Carbon;

/**
 * Estorno recusado pela abstração antes de qualquer requisição ao gateway.
 *
 * É lançada quando a regra do gateway já garante que a API recusaria o estorno: boleto não tem
 * estorno via API em nenhum gateway, Pix na Iugu só aceita estorno integral, fatura já estornada
 * não estorna de novo e a Iugu fecha a janela de estorno 90 dias após o pagamento. O motivo fica
 * em `$reason`, no vocabulário do pacote, para a aplicação ramificar sem ler a mensagem.
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

    /**
     * Método de pagamento da fatura (`credit_card`, `bank_slip`, `pix`), ou nulo quando o
     * gateway não informou o método.
     *
     * @var string|null
     */
    public ?string $paymentMethod;

    /**
     * Motivo da recusa, uma das constantes `REASON_*`.
     *
     * @var string
     */
    public string $reason;

    /**
     * Verdadeiro quando a devolução ao cliente precisa acontecer fora do gateway (boleto,
     * prazo vencido); falso quando não há o que devolver (`already_refunded`) ou o pedido
     * pode ser corrigido (`pix_partial_not_supported`: repita sem valor parcial).
     *
     * @var bool
     */
    public bool $manualRefundRequired;

    /**
     * Cria a exceção com o motivo da recusa e a flag de devolução manual.
     *
     * @param  string  $message
     * @param  string|null  $paymentMethod
     * @param  string  $reason
     * @param  bool  $manualRefundRequired
     */
    public function __construct(string $message, ?string $paymentMethod, string $reason, bool $manualRefundRequired = false)
    {
        $this->paymentMethod = $paymentMethod;
        $this->reason = $reason;
        $this->manualRefundRequired = $manualRefundRequired;

        parent::__construct($message);
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
            true
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
            self::REASON_PIX_PARTIAL_NOT_SUPPORTED
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
            self::REASON_ALREADY_REFUNDED
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
            true
        );
    }
}
