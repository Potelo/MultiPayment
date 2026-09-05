<?php

namespace Potelo\MultiPayment\Models;

use Carbon\Carbon;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Enums\DisputeStatus;
use Potelo\MultiPayment\Contracts\DisputeContract;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Helpers\ConfigurationHelper;
use Potelo\MultiPayment\Exceptions\ConfigurationException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Contestação (chargeback) de uma cobrança paga.
 *
 * É o que `getDispute()` e `listDisputes()` devolvem, o que `Invoice::$disputes` lista e o que
 * `WebhookEvent::dispute()` hidrata. `status` é um enum: aceita na escrita a string do valor ou
 * o caso do enum e devolve sempre o enum (ver `Model::ENUM_CASTS`).
 *
 * @property DisputeStatus|null $status Status genérico; `UNKNOWN` para status que a lib não reconhece.
 */
class Dispute extends Model
{
    protected const ENUM_CASTS = [
        'status' => DisputeStatus::class,
    ];

    protected const REQUIRED_CAPABILITY = Capability::DISPUTES;

    /**
     * Id da contestação no gateway.
     *
     * @var string|null
     */
    public ?string $id = null;

    /**
     * Id da fatura contestada, aceito por `getInvoice()`. No Stripe, a contestação lida por
     * `getDispute()` ou `listDisputes()` aponta o PaymentIntent da cobrança (`pi_`); a de
     * `Invoice::$disputes` aponta a fatura lida (`pi_` ou, na fatura de assinatura, `in_`).
     *
     * @var string|null
     */
    public ?string $invoiceId = null;

    /**
     * Valor contestado, em centavos. Nulo quando o gateway não o informa na contestação.
     *
     * @var int|null
     */
    public ?int $amount = null;

    /**
     * @var DisputeStatus|null
     */
    protected ?DisputeStatus $status = null;

    /**
     * Motivo da contestação, no vocabulário do gateway. Nulo quando o gateway não o informa.
     *
     * @var string|null
     */
    public ?string $reason = null;

    /**
     * Prazo para responder à contestação (contestar ou acatar), quando o gateway o informa.
     *
     * @var Carbon|null
     */
    public ?Carbon $dueBy = null;

    /**
     * Momento em que a contestação foi aberta, quando o gateway o informa.
     *
     * @var Carbon|null
     */
    public ?Carbon $openedAt = null;

    /**
     * Momento em que a contestação foi encerrada. Nulo enquanto ela está em curso e nos
     * gateways que não informam a data do desfecho.
     *
     * @var Carbon|null
     */
    public ?Carbon $closedAt = null;

    /**
     * @var string|null
     */
    public ?string $gateway = null;

    /**
     * Objeto de contestação devolvido pelo gateway.
     *
     * @var mixed
     */
    public mixed $original = null;

    /**
     * Fatura contestada, hidratada por `invoice()`.
     *
     * @var Invoice|null
     */
    private ?Invoice $hydratedInvoice = null;

    /**
     * Responde à contestação com as evidências informadas, no formato que o gateway aceita
     * (ver `DisputeContract::contestDispute()`). Devolve a contestação com o estado posterior
     * à resposta; esta instância não é alterada.
     *
     * @param  array  $evidence  evidências no formato do gateway
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     * @return Dispute
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\UnsupportedOperationException
     * @throws ModelAttributeValidationException  `id` ausente
     */
    public function contest(array $evidence, ?string $idempotencyKey = null): Dispute
    {
        return $this->resolveDisputeGateway()->contestDispute($this->requireId(), $evidence, $idempotencyKey);
    }

    /**
     * Acata a contestação: o valor volta ao pagador sem disputa. Devolve a contestação com o
     * estado posterior; esta instância não é alterada.
     *
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     * @return Dispute
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\UnsupportedOperationException
     * @throws ModelAttributeValidationException  `id` ausente
     */
    public function accept(?string $idempotencyKey = null): Dispute
    {
        return $this->resolveDisputeGateway()->acceptDispute($this->requireId(), $idempotencyKey);
    }

    /**
     * Devolve a fatura contestada, relida no gateway. A primeira chamada custa a leitura de
     * `getInvoice()` e o resultado fica guardado no objeto.
     *
     * @return Invoice
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws ModelAttributeValidationException  `invoiceId` ausente
     */
    public function invoice(): Invoice
    {
        if (!empty($this->hydratedInvoice)) {
            return $this->hydratedInvoice;
        }

        if (empty($this->invoiceId)) {
            throw ModelAttributeValidationException::required('Dispute', 'invoiceId');
        }

        $invoice = new Invoice();
        $invoice->id = $this->invoiceId;
        $invoice->gateway = $this->gateway;

        return $this->hydratedInvoice = $invoice->get($this->gateway);
    }

    /**
     * Resolve o gateway desta contestação e garante que ele declara `Capability::DISPUTES` e
     * implementa `DisputeContract`.
     *
     * @return GatewayContract&DisputeContract
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException  driver que declara a capability sem implementar o contract
     * @throws \Potelo\MultiPayment\Exceptions\UnsupportedOperationException
     */
    private function resolveDisputeGateway(): GatewayContract
    {
        $gateway = ConfigurationHelper::resolveGateway($this->gateway);
        $this->assertGatewaySupports($gateway);

        if (!$gateway instanceof DisputeContract) {
            throw ConfigurationException::GatewayMissingContract($gateway, Capability::DISPUTES, DisputeContract::class);
        }

        return $gateway;
    }

    /**
     * Devolve o `id` da contestação, ou lança quando ele não está preenchido.
     *
     * @return string
     * @throws ModelAttributeValidationException
     */
    private function requireId(): string
    {
        if (empty($this->id)) {
            throw ModelAttributeValidationException::required('Dispute', 'id');
        }

        return (string) $this->id;
    }
}
