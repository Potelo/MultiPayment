<?php

namespace Potelo\MultiPayment\Testing;

use Carbon\Carbon;
use Potelo\MultiPayment\Contracts\DisputeContract;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Contracts\PlanContract;
use Potelo\MultiPayment\Contracts\SubscriptionContract;
use Potelo\MultiPayment\Contracts\SubscriptionSyncContract;
use Potelo\MultiPayment\Contracts\WebhookContract;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Enums\CaptureMethod;
use Potelo\MultiPayment\Enums\DeclineCode;
use Potelo\MultiPayment\Enums\DisputeStatus;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Enums\ProrationBehavior;
use Potelo\MultiPayment\Enums\RefundStatus;
use Potelo\MultiPayment\Enums\SubscriptionStatus;
use Potelo\MultiPayment\Enums\WebhookEventType;
use Potelo\MultiPayment\Exceptions\ChargingException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;
use Potelo\MultiPayment\Exceptions\NotFoundException;
use Potelo\MultiPayment\Exceptions\RefundNotSupportedException;
use Potelo\MultiPayment\Gateways\Concerns\ChecksCapabilities;
use Potelo\MultiPayment\Listing\InvoiceList;
use Potelo\MultiPayment\Listing\InvoiceFilter;
use Potelo\MultiPayment\Listing\SubscriptionList;
use Potelo\MultiPayment\Listing\SubscriptionFilter;
use Potelo\MultiPayment\Models\AutomaticPix;
use Potelo\MultiPayment\Models\AutomaticPixCancellation;
use Potelo\MultiPayment\Models\AutomaticPixCharge;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\Dispute;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\InvoiceItem;
use Potelo\MultiPayment\Models\Plan;
use Potelo\MultiPayment\Models\Refund;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Models\SubscriptionPlanChange;
use Potelo\MultiPayment\Models\WebhookEvent;

/**
 * Gateway em memória para testes da aplicação, sem rede: implementa todos os contracts da lib
 * com estado por instância (clientes, cartões, faturas, assinaturas, planos, estornos e
 * contestações), ids `fake_*` e o mesmo vocabulário de exceções dos drivers reais. A forma
 * comum de uso é `MultiPayment::fake()`, que registra um fake por gateway configurado no
 * container e habilita as asserções da facade; o fake também pode ser construído e registrado
 * à mão (`app()->instance('multi-payment.gateway.iugu', new FakeGateway('iugu'))`).
 *
 * O comportamento das operações segue o caminho feliz: fatura de cartão nasce paga (ou
 * autorizada com `CaptureMethod::MANUAL`), Pix e boleto nascem pendentes, assinatura nasce
 * ativa (ou em trial com `trialDays`). Os desvios são programados por `willDecline()`,
 * `willRequireAction()` e `willFail()`, cada um consumido pela operação seguinte a que se
 * aplica.
 */
class FakeGateway implements
    GatewayContract,
    SubscriptionContract,
    PlanContract,
    WebhookContract,
    DisputeContract,
    SubscriptionSyncContract
{
    use ChecksCapabilities {
        supports as private declaredSupports;
    }

    /** @var string nome da chave do gateway que este fake substitui */
    private string $name;

    /** @var Capability[] */
    private array $capabilities;

    /** @var Capability[] */
    private array $notYetImplemented = [];

    /** @var Capability[] */
    private array $emulated = [];

    /** @var array<string, Customer> */
    private array $customers = [];

    /** @var array<string, CreditCard> */
    private array $creditCards = [];

    /** @var array<string, CreditCard> cartões com autenticação pendente, por `setupId` */
    private array $pendingCardSetups = [];

    /** @var array<string, Invoice> */
    private array $invoices = [];

    /** @var array<string, Subscription> */
    private array $subscriptions = [];

    /** @var array<string, Plan> */
    private array $plans = [];

    /** @var array<string, Dispute> */
    private array $disputes = [];

    /** @var Invoice[] toda fatura criada por este fake, na ordem */
    private array $createdInvoices = [];

    /** @var Subscription[] toda assinatura criada por este fake, na ordem */
    private array $createdSubscriptions = [];

    /** @var array<int, array{invoice_id: string, amount: int}> estornos feitos, na ordem */
    private array $refundsMade = [];

    /** @var array<string, int> acumulado estornado por fatura, o razão que as guardas de estorno usam */
    private array $refundedTotals = [];

    /** @var array<int, array{operation: string, invoice_id: string|null, amount: int|null}> cobranças feitas, na ordem */
    private array $charges = [];

    /** @var Capability[] capabilities consultadas por `supports()`, na ordem */
    private array $checkedCapabilities = [];

    /** @var array{code: DeclineCode, gateway_code: string|null}|null recusa programada para a próxima cobrança de cartão */
    private ?array $nextDecline = null;

    /** @var bool o próximo cartão salvo volta com `requiresAction` */
    private bool $nextCardRequiresAction = false;

    /** @var \Throwable|null falha programada para a próxima operação */
    private ?\Throwable $nextFailure = null;

    /** @var int */
    private int $sequence = 0;

    /**
     * Cria o fake com o nome da chave de gateway que ele substitui; os models devolvidos saem
     * com esse nome em `gateway`, então releituras pelos models voltam para este fake enquanto
     * ele estiver registrado no container.
     *
     * @param  string  $name  nome da chave em `multi-payment.gateways`
     * @param  array|null  $config  aceito por compatibilidade com a resolução por container; o fake não o usa
     */
    public function __construct(string $name = 'fake', ?array $config = null)
    {
        $this->name = $name;
        $this->capabilities = Capability::cases();
    }

    /**
     * Nome da chave de gateway que este fake substitui.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->name;
    }

    /**
     * Redefine as três listas de capabilities do fake, para o teste exercitar a recusa por
     * capability (`UnsupportedOperationException`) como num driver real. Por padrão o fake
     * declara todas as capabilities em `capabilities()`.
     *
     * @param  Capability[]  $capabilities
     * @param  Capability[]  $notYetImplemented
     * @param  Capability[]  $emulated
     * @return $this
     */
    public function declareCapabilities(array $capabilities, array $notYetImplemented = [], array $emulated = []): static
    {
        $this->capabilities = $capabilities;
        $this->notYetImplemented = $notYetImplemented;
        $this->emulated = $emulated;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    /**
     * @inheritDoc
     */
    public function notYetImplemented(): array
    {
        return $this->notYetImplemented;
    }

    /**
     * @inheritDoc
     */
    public function emulated(): array
    {
        return $this->emulated;
    }

    /**
     * @inheritDoc
     */
    public function supports(Capability $capability): bool
    {
        $this->checkedCapabilities[] = $capability;

        return $this->declaredSupports($capability);
    }

    /**
     * Programa a recusa da próxima cobrança de cartão (fatura criada com cartão, pagamento de
     * fatura com cartão ou assinatura criada com cartão): a operação lança
     * `CardDeclinedException` com o motivo informado, como um driver real.
     *
     * @param  DeclineCode  $declineCode
     * @param  string|null  $gatewayCode  código cru atribuído à recusa
     * @return $this
     */
    public function willDecline(DeclineCode $declineCode, ?string $gatewayCode = null): static
    {
        $this->nextDecline = ['code' => $declineCode, 'gateway_code' => $gatewayCode];

        return $this;
    }

    /**
     * Programa o próximo `createCreditCard()` para devolver o cartão com `requiresAction`
     * verdadeiro, `setupId` e `clientSecret` preenchidos e `id` nulo, como o driver do Stripe
     * quando o emissor exige autenticação; `confirmCreditCardSetup()` conclui e devolve o
     * cartão cobrável.
     *
     * @return $this
     */
    public function willRequireAction(): static
    {
        $this->nextCardRequiresAction = true;

        return $this;
    }

    /**
     * Programa a próxima operação para lançar a exceção informada: uma instância é lançada
     * como veio; uma classe é instanciada com uma mensagem padrão do fake.
     *
     * @param  \Throwable|string  $exception  instância ou nome de classe de exceção
     * @return $this
     */
    public function willFail(\Throwable|string $exception): static
    {
        $this->nextFailure = $exception instanceof \Throwable
            ? $exception
            : new $exception("Falha simulada pelo FakeGateway {$this->name}");

        return $this;
    }

    /**
     * Devolve um `WebhookEvent` válido deste fake, com id próprio, `occurredAt` de agora e os
     * campos de `$overrides` aplicados (chaves em snake_case, como `invoice_id`). Quando o
     * evento aponta uma fatura que existe no estado do fake, ela já sai hidratada no evento;
     * assinatura e contestação são hidratadas sob demanda por `subscription()` e `dispute()`,
     * que funcionam enquanto o fake estiver registrado no container.
     *
     * @param  WebhookEventType  $type
     * @param  array  $overrides  campos do evento, em snake_case
     * @return WebhookEvent
     */
    public function fakeWebhook(WebhookEventType $type, array $overrides = []): WebhookEvent
    {
        $event = new WebhookEvent();
        $event->id = $this->nextId('evt');
        $event->gateway = $this->name;
        $event->occurredAt = Carbon::now();
        $event->type = $type;
        $event->raw = ['type' => $type->value] + $overrides;
        $event->fill($overrides);

        if (!empty($event->invoiceId) && isset($this->invoices[$event->invoiceId])) {
            $event->setHydratedInvoice($this->invoices[$event->invoiceId]);
        }

        return $event;
    }

    /**
     * @inheritDoc
     */
    public function parseWebhook(string $rawBody, array $headers): WebhookEvent
    {
        $this->maybeFail();

        $payload = json_decode($rawBody, true);
        $payload = is_array($payload) ? $payload : [];
        $type = WebhookEventType::tryFrom((string) ($payload['type'] ?? '')) ?? WebhookEventType::UNKNOWN;

        return $this->fakeWebhook($type, array_intersect_key($payload, array_flip([
            'id', 'resource_type', 'resource_id', 'invoice_id', 'subscription_id', 'dispute_id', 'decline_code',
        ])));
    }

    /**
     * @inheritDoc
     */
    public function createCustomer(Customer $customer, ?string $idempotencyKey = null): Customer
    {
        $this->maybeFail();

        $customer->id = $customer->id ?? $this->nextId('cus');
        $customer->gateway = $this->name;
        $customer->createdAt = $customer->createdAt ?? Carbon::now();
        $this->customers[$customer->id] = $customer;

        return $customer;
    }

    /**
     * @inheritDoc
     */
    public function getCustomer(Customer $customer): Customer
    {
        $this->maybeFail();

        return $this->customers[$customer->id]
            ?? throw new NotFoundException("Cliente [{$customer->id}] não existe no FakeGateway {$this->name}", null, null, 404);
    }

    /**
     * @inheritDoc
     */
    public function updateCustomer(Customer $customer, ?string $idempotencyKey = null): Customer
    {
        $this->maybeFail();

        if (empty($customer->id) || !isset($this->customers[$customer->id])) {
            throw new NotFoundException("Cliente [{$customer->id}] não existe no FakeGateway {$this->name}", null, null, 404);
        }
        $this->customers[$customer->id] = $customer;

        return $customer;
    }

    /**
     * @inheritDoc
     */
    public function setCustomerDefaultCard(Customer $customer, string $cardId, ?string $idempotencyKey = null): Customer
    {
        $this->maybeFail();

        $stored = $this->getCustomer($customer);
        $stored->defaultCard = $this->creditCards[$cardId]
            ?? throw new NotFoundException("Cartão [{$cardId}] não existe no FakeGateway {$this->name}", null, null, 404);

        return $stored;
    }

    /**
     * @inheritDoc
     */
    public function createCreditCard(CreditCard $creditCard, ?string $idempotencyKey = null): CreditCard
    {
        $this->maybeFail();

        if (!empty($creditCard->customer) && empty($creditCard->customer->id)) {
            $this->createCustomer($creditCard->customer);
        }

        if ($this->nextCardRequiresAction) {
            $this->nextCardRequiresAction = false;
            $creditCard->id = null;
            $creditCard->requiresAction = true;
            $creditCard->setupId = $this->nextId('setup');
            $creditCard->clientSecret = "{$creditCard->setupId}_secret";
            $creditCard->gateway = $this->name;
            $this->pendingCardSetups[$creditCard->setupId] = $creditCard;

            return $creditCard;
        }

        return $this->storeCreditCard($creditCard);
    }

    /**
     * @inheritDoc
     */
    public function confirmCreditCardSetup(string $setupId, ?string $idempotencyKey = null): CreditCard
    {
        $this->maybeFail();

        $creditCard = $this->pendingCardSetups[$setupId]
            ?? throw new NotFoundException("Setup [{$setupId}] não existe no FakeGateway {$this->name}", null, null, 404);
        unset($this->pendingCardSetups[$setupId]);
        $creditCard->requiresAction = false;

        return $this->storeCreditCard($creditCard);
    }

    /**
     * @inheritDoc
     */
    public function getCreditCard(CreditCard $creditCard): CreditCard
    {
        $this->maybeFail();

        return $this->creditCards[$creditCard->id]
            ?? throw new NotFoundException("Cartão [{$creditCard->id}] não existe no FakeGateway {$this->name}", null, null, 404);
    }

    /**
     * @inheritDoc
     */
    public function deleteCreditCard(CreditCard $creditCard, ?string $idempotencyKey = null): void
    {
        $this->maybeFail();

        if (empty($creditCard->id) || !isset($this->creditCards[$creditCard->id])) {
            throw new NotFoundException("Cartão [{$creditCard->id}] não existe no FakeGateway {$this->name}", null, null, 404);
        }
        unset($this->creditCards[$creditCard->id]);
    }

    /**
     * @inheritDoc
     */
    public function createInvoice(Invoice $invoice, ?string $idempotencyKey = null): Invoice
    {
        $this->maybeFail();

        $methods = $invoice->resolvedPaymentMethods();
        $chargesCardNow = in_array(PaymentMethod::CREDIT_CARD, $methods, true) && count($methods) === 1;
        if ($chargesCardNow) {
            $this->maybeDecline();
        }

        if (!empty($invoice->customer) && empty($invoice->customer->id)) {
            $this->createCustomer($invoice->customer);
        }

        $invoice->id = $this->nextId('inv');
        $invoice->gateway = $this->name;
        $invoice->createdAt = Carbon::now();
        $invoice->amount = $invoice->amount ?? $this->sumItems($invoice->items ?? []);
        $invoice->currency = $invoice->currency ?? 'BRL';
        $invoice->refunds = [];

        if ($chargesCardNow && $invoice->captureMethod === CaptureMethod::MANUAL) {
            $invoice->status = InvoiceStatus::AUTHORIZED;
        } elseif ($chargesCardNow) {
            $this->markPaid($invoice, 'createInvoice');
        } else {
            $invoice->status = InvoiceStatus::PENDING;
        }

        $this->invoices[$invoice->id] = $invoice;
        $this->createdInvoices[] = $invoice;

        return $invoice;
    }

    /**
     * @inheritDoc
     */
    public function getInvoice(Invoice $invoice): Invoice
    {
        $this->maybeFail();

        return $this->invoices[$invoice->id]
            ?? throw new NotFoundException("Fatura [{$invoice->id}] não existe no FakeGateway {$this->name}", null, null, 404);
    }

    /**
     * @inheritDoc
     *
     * Filtra as faturas do estado em memória, paginando por `page` ou pelo cursor da página
     * anterior. `originType` é ignorado (o fake tem uma origem só) e `total` vem nulo, como
     * nas listagens reais que não informam total. O fake não reproduz as restrições de filtro
     * dos gateways (o `originType` obrigatório do Stripe, o filtro por assinatura recusado na
     * Iugu): consulte `restriction(Capability::INVOICE_LISTING)` do driver real.
     */
    public function listInvoices(InvoiceFilter $filter): InvoiceList
    {
        $this->maybeFail();

        $matches = array_values(array_filter(
            $this->invoices,
            static fn (Invoice $invoice) => (is_null($filter->customerId) || $invoice->customer?->id === $filter->customerId)
                && (is_null($filter->subscriptionId) || $invoice->subscriptionId === $filter->subscriptionId)
                && (is_null($filter->status) || $invoice->status === $filter->status)
                && (is_null($filter->createdAfter)
                    || (!is_null($invoice->createdAt) && $invoice->createdAt->greaterThanOrEqualTo($filter->createdAfter)))
                && (is_null($filter->createdBefore)
                    || (!is_null($invoice->createdAt) && $invoice->createdAt->lessThanOrEqualTo($filter->createdBefore)))
                && (is_null($filter->dueAfter)
                    || (!is_null($invoice->dueDate) && $invoice->dueDate->greaterThanOrEqualTo($filter->dueAfter)))
                && (is_null($filter->dueBefore)
                    || (!is_null($invoice->dueDate) && $invoice->dueDate->lessThanOrEqualTo($filter->dueBefore)))
        ));

        [$items, $total, $nextCursor, $hasMore] = $this->paginateInMemory($matches, $filter->cursor, $filter->page, $filter->limit);

        return new InvoiceList($items, $total, $nextCursor, $hasMore, $filter);
    }

    /**
     * Fatia uma lista filtrada em memória na página pedida, devolvendo
     * `[itens, total, nextCursor, hasMore]`; o cursor é o deslocamento da página seguinte. O
     * total é sempre nulo, como na maior parte das listagens reais (só a listagem de faturas
     * da Iugu informa um total), para o teste exercitar o caminho que vale em produção.
     *
     * @param  array  $matches
     * @param  string|null  $cursor
     * @param  int  $page
     * @param  int  $limit
     * @return array{0: array, 1: null, 2: string|null, 3: bool}
     */
    private function paginateInMemory(array $matches, ?string $cursor, int $page, int $limit): array
    {
        $start = is_null($cursor) ? ($page - 1) * $limit : (int) $cursor;
        $items = array_slice($matches, $start, $limit);
        $hasMore = $start + count($items) < count($matches);

        return [
            $items,
            null,
            $hasMore ? (string) ($start + count($items)) : null,
            $hasMore,
        ];
    }

    /**
     * @inheritDoc
     */
    public function refundInvoice(Invoice $invoice, ?int $amount = null, ?string $idempotencyKey = null): Refund
    {
        $this->maybeFail();

        // como nos drivers reais, o argumento vence e o caminho antigo (escrita em
        // refundedAmount) vale como valor pedido
        $amount = $invoice->resolveRefundAmount($amount);

        $stored = $this->getInvoice($invoice);
        if (empty($stored->paidAmount)) {
            throw RefundNotSupportedException::noGatewayCharge($this->name, $stored->paymentMethod?->value, false);
        }
        $refundable = $this->storedRefundableAmount($stored);
        if ($refundable <= 0) {
            throw RefundNotSupportedException::alreadyRefunded($this->name, $stored->paymentMethod?->value);
        }
        $amount = $amount ?? $refundable;
        if ($amount > $refundable) {
            throw RefundNotSupportedException::amountExceedsRefundable(
                $this->name,
                $stored->paymentMethod?->value,
                $amount,
                $refundable
            );
        }

        $accumulated = ($this->refundedTotals[$stored->id] ?? 0) + $amount;
        $this->refundedTotals[$stored->id] = $accumulated;
        $stored->setRefundedAmountFromGateway($accumulated);
        $stored->status = $accumulated >= ($stored->paidAmount ?? 0)
            ? InvoiceStatus::REFUNDED
            : InvoiceStatus::PARTIALLY_REFUNDED;

        $refund = new Refund();
        $refund->id = $this->nextId('ref');
        $refund->invoiceId = $stored->id;
        $refund->amount = $amount;
        $refund->status = RefundStatus::SUCCEEDED;
        $refund->createdAt = Carbon::now();
        $refund->gateway = $this->name;
        $refund->invoice = $stored;

        $stored->refunds = array_merge($stored->refunds ?? [], [$refund]);
        $this->refundsMade[] = ['invoice_id' => $stored->id, 'amount' => $amount];

        return $refund;
    }

    /**
     * @inheritDoc
     */
    public function refundableAmount(Invoice $invoice): int
    {
        $this->maybeFail();

        return $this->storedRefundableAmount($this->getInvoice($invoice));
    }

    /**
     * @inheritDoc
     */
    public function captureInvoice(Invoice $invoice, ?int $amount = null, ?string $idempotencyKey = null): Invoice
    {
        $this->maybeFail();

        $stored = $this->getInvoice($invoice);
        if ($stored->status !== InvoiceStatus::AUTHORIZED) {
            throw ModelAttributeValidationException::invalid(
                'Invoice',
                'status',
                'só fatura autorizada pode ser capturada'
            );
        }
        $this->markPaid($stored, 'captureInvoice', $amount ?? $stored->amount);

        return $stored;
    }

    /**
     * @inheritDoc
     */
    public function chargeInvoiceWithCreditCard(Invoice $invoice, ?string $idempotencyKey = null): Invoice
    {
        $this->maybeFail();
        $this->maybeDecline();

        $stored = $this->getInvoice($invoice);
        $this->markPaid($stored, 'chargeInvoiceWithCreditCard');

        return $stored;
    }

    /**
     * @inheritDoc
     */
    public function duplicateInvoice(
        Invoice $invoice,
        Carbon $expiresAt,
        array $gatewayOptions = [],
        ?string $idempotencyKey = null
    ): Invoice {
        $this->maybeFail();

        $original = $this->getInvoice($invoice);
        $original->status = InvoiceStatus::CANCELED;

        $duplicate = clone $original;
        $duplicate->id = $this->nextId('inv');
        $duplicate->status = InvoiceStatus::PENDING;
        $duplicate->dueDate = $expiresAt;
        $duplicate->paidAt = null;
        $duplicate->paidAmount = null;
        $duplicate->refunds = [];
        $duplicate->setRefundedAmountFromGateway(null);
        $duplicate->createdAt = Carbon::now();
        $this->invoices[$duplicate->id] = $duplicate;
        $this->createdInvoices[] = $duplicate;

        return $duplicate;
    }

    /**
     * @inheritDoc
     */
    public function cancelInvoice(Invoice $invoice, ?string $idempotencyKey = null): Invoice
    {
        $this->maybeFail();

        $stored = $this->getInvoice($invoice);
        $stored->status = InvoiceStatus::CANCELED;

        return $stored;
    }

    /**
     * @inheritDoc
     */
    public function rescheduleAutomaticPixPayment(Invoice $invoice, ?string $idempotencyKey = null): Invoice
    {
        $this->maybeFail();

        return $this->getInvoice($invoice);
    }

    /**
     * @inheritDoc
     */
    public function cancelAutomaticPixScheduledPayment(
        AutomaticPixCharge $charge,
        ?string $idempotencyKey = null
    ): AutomaticPixCancellation {
        $this->maybeFail();

        return $this->automaticPixCancellation($charge->recurrenceId, $charge->id);
    }

    /**
     * @inheritDoc
     */
    public function cancelAutomaticPixRecurrence(
        AutomaticPix $automaticPix,
        ?string $idempotencyKey = null
    ): AutomaticPixCancellation {
        $this->maybeFail();

        return $this->automaticPixCancellation($automaticPix->id, null);
    }

    /**
     * @inheritDoc
     */
    public function getAutomaticPixCancellation(AutomaticPixCancellation $cancellation): AutomaticPixCancellation
    {
        $this->maybeFail();

        $cancellation->status = $cancellation->status ?? AutomaticPixCancellation::STATUS_COMPLETED;
        $cancellation->gateway = $this->name;

        return $cancellation;
    }

    /**
     * @inheritDoc
     */
    public function listAutomaticPixCancellations(AutomaticPix $automaticPix, int $page = 1, int $limit = 100): array
    {
        $this->maybeFail();

        return [];
    }

    /**
     * @inheritDoc
     */
    public function createSubscription(Subscription $subscription, ?string $idempotencyKey = null): Subscription
    {
        $this->maybeFail();

        $chargesCardNow = !empty($subscription->creditCard) && empty($subscription->trialDays);
        if ($chargesCardNow) {
            $this->maybeDecline();
        }

        if (!empty($subscription->customer) && empty($subscription->customer->id)) {
            $this->createCustomer($subscription->customer);
        }

        $subscription->id = $this->nextId('sub');
        $subscription->gateway = $this->name;
        $subscription->createdAt = Carbon::now();
        if (!empty($subscription->trialDays)) {
            $subscription->status = SubscriptionStatus::TRIALING;
            $subscription->trialEndsAt = Carbon::now()->addDays($subscription->trialDays);
        } else {
            $subscription->status = SubscriptionStatus::ACTIVE;
        }
        $subscription->amount = $subscription->amount ?? $this->planAmount($subscription->planId);

        if ($chargesCardNow) {
            $this->charges[] = [
                'operation' => 'createSubscription',
                'invoice_id' => null,
                'amount' => $subscription->amount,
            ];
        }

        $this->subscriptions[$subscription->id] = $subscription;
        $this->createdSubscriptions[] = $subscription;

        return $subscription;
    }

    /**
     * @inheritDoc
     */
    public function getSubscription(Subscription $subscription): Subscription
    {
        $this->maybeFail();

        return $this->subscriptions[$subscription->id]
            ?? throw new NotFoundException("Assinatura [{$subscription->id}] não existe no FakeGateway {$this->name}", null, null, 404);
    }

    /**
     * @inheritDoc
     */
    public function updateSubscription(Subscription $subscription, ?string $idempotencyKey = null): Subscription
    {
        $this->maybeFail();

        if (empty($subscription->id) || !isset($this->subscriptions[$subscription->id])) {
            throw new NotFoundException("Assinatura [{$subscription->id}] não existe no FakeGateway {$this->name}", null, null, 404);
        }
        $this->subscriptions[$subscription->id] = $subscription;

        return $subscription;
    }

    /**
     * @inheritDoc
     */
    public function suspendSubscription(Subscription $subscription, ?string $idempotencyKey = null): Subscription
    {
        $this->maybeFail();

        $stored = $this->getSubscription($subscription);
        $stored->status = SubscriptionStatus::SUSPENDED;

        return $stored;
    }

    /**
     * @inheritDoc
     */
    public function resumeSubscription(Subscription $subscription, ?string $idempotencyKey = null): Subscription
    {
        $this->maybeFail();

        $stored = $this->getSubscription($subscription);
        $stored->status = SubscriptionStatus::ACTIVE;
        $stored->cancelAtPeriodEnd = false;

        return $stored;
    }

    /**
     * @inheritDoc
     */
    public function cancelSubscription(
        Subscription $subscription,
        bool $atPeriodEnd = false,
        ?string $idempotencyKey = null
    ): Subscription {
        $this->maybeFail();

        $stored = $this->getSubscription($subscription);
        if ($atPeriodEnd) {
            $stored->cancelAtPeriodEnd = true;
        } else {
            $stored->status = SubscriptionStatus::CANCELED;
            $stored->canceledAt = Carbon::now();
        }

        return $stored;
    }

    /**
     * @inheritDoc
     */
    public function changeSubscriptionPlan(
        Subscription $subscription,
        string $planId,
        ProrationBehavior|bool $proration = ProrationBehavior::CHARGE_DIFFERENCE,
        ?string $idempotencyKey = null,
        ?bool $charge = null
    ): Subscription {
        $this->maybeFail();

        $stored = $this->getSubscription($subscription);
        $stored->planId = $planId;
        $stored->amount = $this->planAmount($planId) ?? $stored->amount;

        return $stored;
    }

    /**
     * @inheritDoc
     */
    public function previewSubscriptionPlanChange(
        Subscription $subscription,
        string $planId,
        ProrationBehavior $proration = ProrationBehavior::CHARGE_DIFFERENCE
    ): SubscriptionPlanChange {
        $this->maybeFail();

        $this->getSubscription($subscription);

        $planChange = new SubscriptionPlanChange();
        $planChange->gateway = $this->name;
        $planChange->amount = $this->planAmount($planId) ?? 0;
        $planChange->appliesImmediately = true;

        $item = new InvoiceItem();
        $item->description = "Troca para o plano {$planId}";
        $item->price = $planChange->amount;
        $item->quantity = 1;
        $planChange->items = [$item];

        return $planChange;
    }

    /**
     * @inheritDoc
     *
     * Filtra as assinaturas do estado em memória, paginando por `page` ou pelo cursor da
     * página anterior; `total` vem nulo, como nas listagens reais de assinatura. O filtro por
     * `status` casa o status exato, enquanto o gateway real pode ser mais largo (ver a
     * restrição de `Capability::SUBSCRIPTIONS`). A forma antiga, com o `Customer`, devolve
     * todas as assinaturas do cliente sem paginar, com aviso `E_USER_DEPRECATED`.
     */
    public function listSubscriptions(
        SubscriptionFilter|Customer $filter,
        int $page = 1,
        int $limit = 100
    ): SubscriptionList|array {
        $this->maybeFail();

        if ($filter instanceof Customer) {
            trigger_error(
                'listSubscriptions() com Customer está obsoleto desde 2026-09-07; use um SubscriptionFilter',
                E_USER_DEPRECATED
            );

            return array_values(array_filter(
                $this->subscriptions,
                fn (Subscription $subscription) => $subscription->customer?->id === $filter->id
            ));
        }

        $matches = array_values(array_filter(
            $this->subscriptions,
            static fn (Subscription $subscription) => (is_null($filter->customerId) || $subscription->customer?->id === $filter->customerId)
                && (is_null($filter->planIdentifier) || $subscription->planId === $filter->planIdentifier)
                && (is_null($filter->status) || $subscription->status === $filter->status)
                && (is_null($filter->createdAfter)
                    || (!is_null($subscription->createdAt) && $subscription->createdAt->greaterThanOrEqualTo($filter->createdAfter)))
                && (is_null($filter->createdBefore)
                    || (!is_null($subscription->createdAt) && $subscription->createdAt->lessThanOrEqualTo($filter->createdBefore)))
        ));

        [$items, $total, $nextCursor, $hasMore] = $this->paginateInMemory($matches, $filter->cursor, $filter->page, $filter->limit);

        return new SubscriptionList($items, $total, $nextCursor, $hasMore, $filter);
    }

    /**
     * @inheritDoc
     */
    public function createPlan(Plan $plan, ?string $idempotencyKey = null): Plan
    {
        $this->maybeFail();

        $plan->id = $plan->id ?? $this->nextId('plan');
        $plan->gateway = $this->name;
        $plan->active = $plan->active ?? true;
        $this->plans[$plan->id] = $plan;

        return $plan;
    }

    /**
     * @inheritDoc
     */
    public function getPlan(Plan $plan): Plan
    {
        $this->maybeFail();

        if (!empty($plan->id) && isset($this->plans[$plan->id])) {
            return $this->plans[$plan->id];
        }
        foreach ($this->plans as $stored) {
            if (!empty($plan->identifier) && $stored->identifier === $plan->identifier) {
                return $stored;
            }
        }

        $reference = $plan->id ?? $plan->identifier;

        throw new NotFoundException("Plano [{$reference}] não existe no FakeGateway {$this->name}", null, null, 404);
    }

    /**
     * @inheritDoc
     */
    public function listPlans(int $page = 1, int $limit = 100): array
    {
        $this->maybeFail();

        return array_values($this->plans);
    }

    /**
     * @inheritDoc
     */
    public function deactivatePlan(Plan $plan, ?string $idempotencyKey = null): Plan
    {
        $this->maybeFail();

        $stored = $this->getPlan($plan);
        $stored->active = false;

        return $stored;
    }

    /**
     * @inheritDoc
     */
    public function getDispute(Dispute $dispute): Dispute
    {
        $this->maybeFail();

        return $this->disputes[$dispute->id]
            ?? throw new NotFoundException("Contestação [{$dispute->id}] não existe no FakeGateway {$this->name}", null, null, 404);
    }

    /**
     * @inheritDoc
     */
    public function listDisputes(int $page = 1, int $limit = 100): array
    {
        $this->maybeFail();

        return array_values($this->disputes);
    }

    /**
     * @inheritDoc
     */
    public function contestDispute(string $id, array $evidence, ?string $idempotencyKey = null): Dispute
    {
        $this->maybeFail();

        $dispute = $this->disputes[$id]
            ?? throw new NotFoundException("Contestação [{$id}] não existe no FakeGateway {$this->name}", null, null, 404);
        $dispute->status = DisputeStatus::UNDER_REVIEW;

        return $dispute;
    }

    /**
     * @inheritDoc
     */
    public function acceptDispute(string $id, ?string $idempotencyKey = null): Dispute
    {
        $this->maybeFail();

        $dispute = $this->disputes[$id]
            ?? throw new NotFoundException("Contestação [{$id}] não existe no FakeGateway {$this->name}", null, null, 404);
        $dispute->status = DisputeStatus::ACCEPTED;
        $dispute->closedAt = Carbon::now();

        return $dispute;
    }

    /**
     * Abre uma contestação sobre uma fatura do fake, para o teste exercitar o fluxo de
     * contestação sem depender de gateway: a fatura passa a `DISPUTED` com a contestação em
     * `disputes`, e `getDispute()`, `contestDispute()` e `acceptDispute()` a encontram.
     *
     * @param  string  $invoiceId
     * @return Dispute
     * @throws NotFoundException  fatura inexistente no fake
     */
    public function openDispute(string $invoiceId): Dispute
    {
        $invoice = $this->invoices[$invoiceId]
            ?? throw new NotFoundException("Fatura [{$invoiceId}] não existe no FakeGateway {$this->name}", null, null, 404);

        $dispute = new Dispute();
        $dispute->id = $this->nextId('dp');
        $dispute->invoiceId = $invoiceId;
        $dispute->amount = $invoice->paidAmount ?? $invoice->amount;
        $dispute->status = DisputeStatus::OPEN;
        $dispute->openedAt = Carbon::now();
        $dispute->dueBy = Carbon::now()->addDays(7);
        $dispute->gateway = $this->name;

        $this->disputes[$dispute->id] = $dispute;
        $invoice->status = InvoiceStatus::DISPUTED;
        $invoice->disputes = array_merge($invoice->disputes ?? [], [$dispute]);

        return $dispute;
    }

    /**
     * @inheritDoc
     */
    public function syncSubscriptions(bool $dryRun = false): array
    {
        $this->maybeFail();

        return [];
    }

    /**
     * Faturas criadas por este fake, na ordem de criação.
     *
     * @return Invoice[]
     */
    public function createdInvoices(): array
    {
        return $this->createdInvoices;
    }

    /**
     * Assinaturas criadas por este fake, na ordem de criação.
     *
     * @return Subscription[]
     */
    public function createdSubscriptions(): array
    {
        return $this->createdSubscriptions;
    }

    /**
     * Estornos feitos neste fake, na ordem, como `{invoice_id, amount}`.
     *
     * @return array<int, array{invoice_id: string, amount: int}>
     */
    public function refundsMade(): array
    {
        return $this->refundsMade;
    }

    /**
     * Cobranças feitas neste fake, na ordem, como `{operation, invoice_id, amount}`: fatura de
     * cartão paga na criação, pagamento de fatura com cartão, captura e assinatura criada
     * cobrando o cartão.
     *
     * @return array<int, array{operation: string, invoice_id: string|null, amount: int|null}>
     */
    public function charges(): array
    {
        return $this->charges;
    }

    /**
     * Capabilities consultadas por `supports()` neste fake, na ordem, incluindo as guardas
     * internas dos models.
     *
     * @return Capability[]
     */
    public function checkedCapabilities(): array
    {
        return $this->checkedCapabilities;
    }

    /**
     * Guarda o cartão como cobrável, com id, bandeira e últimos dígitos preenchidos quando
     * faltam.
     *
     * @param  CreditCard  $creditCard
     * @return CreditCard
     */
    private function storeCreditCard(CreditCard $creditCard): CreditCard
    {
        $creditCard->id = $creditCard->id ?? $this->nextId('card');
        $creditCard->gateway = $this->name;
        $creditCard->brand = $creditCard->brand ?? 'visa';
        $creditCard->lastDigits = $creditCard->lastDigits
            ?? (empty($creditCard->number) ? '4242' : substr($creditCard->number, -4));
        $creditCard->createdAt = $creditCard->createdAt ?? Carbon::now();
        $this->creditCards[$creditCard->id] = $creditCard;

        return $creditCard;
    }

    /**
     * Marca a fatura como paga agora e registra a cobrança para as asserções.
     *
     * @param  Invoice  $invoice
     * @param  string  $operation
     * @param  int|null  $amount
     * @return void
     */
    private function markPaid(Invoice $invoice, string $operation, ?int $amount = null): void
    {
        $invoice->status = InvoiceStatus::PAID;
        $invoice->paidAt = Carbon::now();
        $invoice->paidAmount = $amount ?? $invoice->amount;
        $this->charges[] = [
            'operation' => $operation,
            'invoice_id' => $invoice->id,
            'amount' => $invoice->paidAmount,
        ];
    }

    /**
     * Restante estornável da fatura guardada: o pago menos o acumulado do razão de estornos do
     * fake, nunca negativo. O razão interno vale mesmo quando o consumidor escreveu em
     * `refundedAmount` pelo caminho antigo, que é pedido de estorno e ainda não aconteceu.
     *
     * @param  Invoice  $invoice
     * @return int
     */
    private function storedRefundableAmount(Invoice $invoice): int
    {
        return max(0, ($invoice->paidAmount ?? 0) - ($this->refundedTotals[$invoice->id] ?? 0));
    }

    /**
     * Valor do plano guardado, ou nulo quando o plano não existe no fake.
     *
     * @param  string|null  $planId
     * @return int|null
     */
    private function planAmount(?string $planId): ?int
    {
        if (is_null($planId)) {
            return null;
        }

        return ($this->plans[$planId] ?? null)?->amount;
    }

    /**
     * Monta um cancelamento de Pix Automático com status de pedido aceito.
     *
     * @param  string|null  $recurrenceId
     * @param  string|null  $paymentId
     * @return AutomaticPixCancellation
     */
    private function automaticPixCancellation(?string $recurrenceId, ?string $paymentId): AutomaticPixCancellation
    {
        $cancellation = new AutomaticPixCancellation();
        $cancellation->id = $this->nextId('apixcan');
        $cancellation->recurrenceId = $recurrenceId;
        $cancellation->paymentId = $paymentId;
        $cancellation->status = AutomaticPixCancellation::STATUS_REQUESTED;
        $cancellation->createdAt = Carbon::now();
        $cancellation->gateway = $this->name;

        return $cancellation;
    }

    /**
     * Lança a falha programada por `willFail()`, consumindo-a.
     *
     * @return void
     * @throws \Throwable
     */
    private function maybeFail(): void
    {
        if (is_null($this->nextFailure)) {
            return;
        }

        $failure = $this->nextFailure;
        $this->nextFailure = null;

        throw $failure;
    }

    /**
     * Lança a recusa de cartão programada por `willDecline()`, consumindo-a.
     *
     * @return void
     * @throws ChargingException
     */
    private function maybeDecline(): void
    {
        if (is_null($this->nextDecline)) {
            return;
        }

        $decline = $this->nextDecline;
        $this->nextDecline = null;

        throw ChargingException::declined(
            $this->name,
            $decline['code'],
            $decline['gateway_code'],
            'Recusa simulada pelo FakeGateway'
        );
    }

    /**
     * Gera o próximo id do tipo informado, único nesta instância.
     *
     * @param  string  $type
     * @return string
     */
    private function nextId(string $type): string
    {
        return 'fake_' . $type . '_' . (++$this->sequence);
    }

    /**
     * Soma dos itens da fatura em centavos, aceitando `InvoiceItem` ou array.
     *
     * @param  array  $items
     * @return int|null
     */
    private function sumItems(array $items): ?int
    {
        if (empty($items)) {
            return null;
        }

        $total = 0;
        foreach ($items as $item) {
            $item = is_array($item) ? (object) $item : $item;
            $total += (int) ($item->price ?? 0) * (int) ($item->quantity ?? 1);
        }

        return $total;
    }
}
