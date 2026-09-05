<?php

namespace Potelo\MultiPayment\Models;

use Carbon\Carbon;
use Potelo\MultiPayment\Enums\DeclineCode;
use Potelo\MultiPayment\Enums\WebhookEventType;

/**
 * Entrega de webhook normalizada, devolvida por `parseWebhook()` depois da verificação de
 * autenticidade. O payload nunca é fonte de status: decisão de negócio usa `invoice()` ou
 * `subscription()`, que releem o recurso no gateway na primeira chamada e guardam o resultado
 * no objeto.
 *
 * @property WebhookEventType|null $type Tipo comum do evento; `UNKNOWN` para evento que o driver não mapeia.
 * @property DeclineCode|null $declineCode Motivo normalizado da recusa num evento de falha de pagamento, quando
 *                                         o payload o traz; a hidratação por `invoice()` o completa a partir de
 *                                         `Invoice::$lastPaymentError`.
 */
class WebhookEvent extends Model
{
    protected const ENUM_CASTS = [
        'type' => WebhookEventType::class,
        'declineCode' => DeclineCode::class,
    ];

    /**
     * Id estável da entrega, usado na deduplicação (`WebhookDeduplicator`). Nulo quando o
     * gateway não identifica a entrega e o driver não consegue derivar um id.
     *
     * @var string|null
     */
    public ?string $id = null;

    /**
     * @var WebhookEventType|null
     */
    protected ?WebhookEventType $type = null;

    /**
     * Momento do evento no gateway. Quando a entrega não traz o instante do evento (o corpo da
     * Iugu não o tem), o driver preenche com o momento do parse.
     *
     * @var Carbon|null
     */
    public ?Carbon $occurredAt = null;

    /**
     * @var string|null
     */
    public ?string $gateway = null;

    /**
     * Tipo do objeto do gateway que o evento referencia, no vocabulário do gateway (na Stripe,
     * o campo `object` de `data.object`).
     *
     * @var string|null
     */
    public ?string $resourceType = null;

    /**
     * Id do objeto do gateway que o evento referencia.
     *
     * @var string|null
     */
    public ?string $resourceId = null;

    /**
     * Id da fatura da lib que o evento referencia, aceito por `getInvoice()`; nulo quando o
     * evento não aponta uma fatura.
     *
     * @var string|null
     */
    public ?string $invoiceId = null;

    /**
     * Id da assinatura que o evento referencia; nulo quando o evento não aponta uma assinatura.
     *
     * @var string|null
     */
    public ?string $subscriptionId = null;

    /**
     * Id da contestação no gateway, quando o evento é de contestação.
     *
     * @var string|null
     */
    public ?string $disputeId = null;

    /**
     * @var DeclineCode|null
     */
    protected ?DeclineCode $declineCode = null;

    /**
     * Diz se a deduplicação já viu o `id` desta entrega. Preenchido por
     * `WebhookDeduplicator::flagReplay()`; o parse sozinho o deixa falso.
     *
     * @var bool
     */
    public bool $isReplay = false;

    /**
     * Payload original da entrega, decodificado; o corpo cru quando ele não decodifica.
     *
     * @var mixed
     */
    public mixed $raw = null;

    /**
     * @var Invoice|null
     */
    private ?Invoice $hydratedInvoice = null;

    /**
     * @var Subscription|null
     */
    private ?Subscription $hydratedSubscription = null;

    /**
     * @var Dispute|null
     */
    private ?Dispute $hydratedDispute = null;

    /**
     * Devolve a fatura que o evento referencia, relida no gateway. A primeira chamada custa a
     * leitura de `getInvoice()` e o resultado fica guardado no objeto; quando a fatura relida
     * traz `lastPaymentError`, o `declineCode` do evento é completado a partir dele. Nulo
     * quando o evento não aponta uma fatura.
     *
     * @return Invoice|null
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     */
    public function invoice(): ?Invoice
    {
        if (!is_null($this->hydratedInvoice)) {
            return $this->hydratedInvoice;
        }

        if (empty($this->invoiceId)) {
            return null;
        }

        $invoice = new Invoice();
        $invoice->id = $this->invoiceId;
        $invoice->gateway = $this->gateway;
        $this->setHydratedInvoice($invoice->get($this->gateway));

        return $this->hydratedInvoice;
    }

    /**
     * Guarda uma fatura já relida no gateway como a fatura hidratada do evento, para
     * `invoice()` reaproveitar a leitura; quando ela traz `lastPaymentError`, o `declineCode`
     * do evento é completado a partir dele.
     *
     * @param  Invoice  $invoice
     * @return void
     */
    public function setHydratedInvoice(Invoice $invoice): void
    {
        $this->hydratedInvoice = $invoice;

        if (!empty($invoice->lastPaymentError?->declineCode)) {
            $this->declineCode = $invoice->lastPaymentError->declineCode;
        }
    }

    /**
     * Devolve a assinatura que o evento referencia, relida no gateway. A primeira chamada custa
     * a leitura de `getSubscription()` e o resultado fica guardado no objeto. Nulo quando o
     * evento não aponta uma assinatura.
     *
     * @return Subscription|null
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\UnsupportedOperationException
     */
    public function subscription(): ?Subscription
    {
        if (!is_null($this->hydratedSubscription)) {
            return $this->hydratedSubscription;
        }

        if (empty($this->subscriptionId)) {
            return null;
        }

        $subscription = new Subscription();
        $subscription->id = $this->subscriptionId;
        $subscription->gateway = $this->gateway;

        return $this->hydratedSubscription = $subscription->get($this->gateway);
    }

    /**
     * Devolve o estorno mais recente da fatura que o evento referencia, lido de
     * `Invoice::$refunds` da fatura relida por `invoice()` (a releitura é compartilhada e
     * guardada). Nulo quando o evento não aponta uma fatura ou ela não tem estorno.
     *
     * @return Refund|null
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     */
    public function refund(): ?Refund
    {
        return $this->invoice()?->refunds[0] ?? null;
    }

    /**
     * Devolve a contestação que o evento referencia, relida no gateway. Com `disputeId` no
     * payload (Stripe), a primeira chamada custa a leitura de `getDispute()` e o resultado
     * fica guardado no objeto. Sem `disputeId` (Iugu), num evento de contestação a fatura é
     * hidratada por `invoice()` e a primeira contestação de `Invoice::$disputes` é devolvida.
     * Nulo quando o evento não aponta uma contestação.
     *
     * @return Dispute|null
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\UnsupportedOperationException
     */
    public function dispute(): ?Dispute
    {
        if (!is_null($this->hydratedDispute)) {
            return $this->hydratedDispute;
        }

        if (!empty($this->disputeId)) {
            $dispute = new Dispute();
            $dispute->id = $this->disputeId;
            $dispute->gateway = $this->gateway;

            return $this->hydratedDispute = $dispute->get($this->gateway);
        }

        if ($this->type === WebhookEventType::DISPUTE_OPENED || $this->type === WebhookEventType::DISPUTE_CLOSED) {
            return $this->hydratedDispute = $this->invoice()?->disputes[0] ?? null;
        }

        return null;
    }
}
