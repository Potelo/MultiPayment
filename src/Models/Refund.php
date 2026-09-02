<?php

namespace Potelo\MultiPayment\Models;

use Carbon\Carbon;
use Potelo\MultiPayment\Enums\RefundStatus;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Estorno de uma fatura.
 *
 * É o que `refundInvoice()` devolve e o que `Invoice::$refunds` lista. `status` é um enum:
 * aceita na escrita a string do valor ou o caso do enum e devolve sempre o enum (ver
 * `Model::ENUM_CASTS`).
 *
 * @property RefundStatus|null $status Status genérico; `UNKNOWN` para status que a lib não reconhece.
 */
class Refund extends Model
{
    protected const ENUM_CASTS = [
        'status' => RefundStatus::class,
    ];

    /**
     * Id do estorno no gateway. Nulo quando o gateway não identifica o estorno (a Iugu só
     * informa o total estornado da fatura).
     *
     * @var string|null
     */
    public ?string $id = null;

    /**
     * Id da fatura estornada.
     *
     * @var string|null
     */
    public ?string $invoiceId = null;

    /**
     * Valor estornado, em centavos.
     *
     * @var int|null
     */
    public ?int $amount = null;

    /**
     * @var RefundStatus|null
     */
    protected ?RefundStatus $status = null;

    /**
     * Motivo do estorno, em texto livre. Preenchido quando o gateway devolve um; a lib não o
     * envia ao gateway.
     *
     * @var string|null
     */
    public ?string $reason = null;

    /**
     * @var Carbon|null
     */
    public ?Carbon $createdAt = null;

    /**
     * @var string|null
     */
    public ?string $gateway = null;

    /**
     * Objeto de estorno devolvido pelo gateway; nulo quando o gateway não tem um.
     *
     * @var mixed
     */
    public mixed $original = null;

    /**
     * Fatura com o estado posterior ao estorno. Preenchida pela operação de estorno; fica
     * nula num `Refund` lido de `Invoice::$refunds`.
     *
     * @var Invoice|null
     */
    public ?Invoice $invoice = null;

    /**
     * Devolve a fatura estornada. Num `Refund` devolvido pela operação de estorno é a fatura
     * já relida, sem requisição; num `Refund` lido de `Invoice::$refunds` custa um GET na
     * primeira chamada, e o resultado fica guardado em `$invoice`.
     *
     * @return Invoice
     * @throws ModelAttributeValidationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     */
    public function invoice(): Invoice
    {
        if (!empty($this->invoice)) {
            return $this->invoice;
        }

        if (empty($this->invoiceId)) {
            throw ModelAttributeValidationException::required('Refund', 'invoiceId');
        }

        $invoice = new Invoice();
        $invoice->id = $this->invoiceId;
        $invoice->gateway = $this->gateway;

        return $this->invoice = $invoice->get($this->gateway);
    }
}
