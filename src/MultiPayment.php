<?php

namespace Potelo\MultiPayment;

use Carbon\Carbon;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Exceptions\MultiPaymentException;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Refund;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\Plan;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Models\SubscriptionPlanChange;
use Potelo\MultiPayment\Enums\ProrationBehavior;
use Potelo\MultiPayment\Models\AutomaticPix;
use Potelo\MultiPayment\Models\AutomaticPixCharge;
use Potelo\MultiPayment\Models\AutomaticPixCancellation;
use Potelo\MultiPayment\Models\WebhookEvent;
use Potelo\MultiPayment\Contracts\PlanContract;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Contracts\WebhookContract;
use Potelo\MultiPayment\Contracts\SubscriptionContract;
use Potelo\MultiPayment\Builders\InvoiceBuilder;
use Potelo\MultiPayment\Builders\CustomerBuilder;
use Potelo\MultiPayment\Builders\CreditCardBuilder;
use Potelo\MultiPayment\Builders\SubscriptionBuilder;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\NotFoundException;
use Potelo\MultiPayment\Exceptions\ConfigurationException;
use Potelo\MultiPayment\Capabilities\CapabilityRestriction;
use Potelo\MultiPayment\Helpers\ConfigurationHelper;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Class MultiPayment
 */
class MultiPayment
{

    private GatewayContract $gateway;

    /**
     * MultiPayment constructor.
     *
     * @param  GatewayContract|string|null  $gateway
     */
    public function __construct($gateway = null)
    {
        $this->gateway = ConfigurationHelper::resolveGateway($gateway);
    }

    /**
     * @param  GatewayContract|string|null  $gateway
     * @return MultiPayment
     */
    public function setGateway($gateway): MultiPayment
    {
        $this->gateway = ConfigurationHelper::resolveGateway($gateway);
        return $this;
    }

    /**
     * Devolve o driver do gateway informado, ou o desta instância quando nenhum é informado.
     *
     * @param  GatewayContract|string|null  $gateway
     * @return GatewayContract
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     */
    public function gateway($gateway = null): GatewayContract
    {
        return is_null($gateway) ? $this->gateway : ConfigurationHelper::resolveGateway($gateway);
    }

    /**
     * Diz se o gateway (o desta instância, por padrão) suporta a capability.
     *
     * @param  Capability  $capability
     * @param  GatewayContract|string|null  $gateway
     * @return bool
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     */
    public function supports(Capability $capability, $gateway = null): bool
    {
        return $this->gateway($gateway)->supports($capability);
    }

    /**
     * Capabilities que o gateway (o desta instância, por padrão) oferece e a lib implementa.
     *
     * @param  GatewayContract|string|null  $gateway
     * @return Capability[]
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     */
    public function capabilities($gateway = null): array
    {
        return $this->gateway($gateway)->capabilities();
    }

    /**
     * Capabilities que o gateway (o desta instância, por padrão) oferece mas a lib ainda não
     * implementa.
     *
     * @param  GatewayContract|string|null  $gateway
     * @return Capability[]
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     */
    public function notYetImplemented($gateway = null): array
    {
        return $this->gateway($gateway)->notYetImplemented();
    }

    /**
     * Capabilities que o gateway (o desta instância, por padrão) não oferece mas a lib entrega
     * por emulação.
     *
     * @param  GatewayContract|string|null  $gateway
     * @return Capability[]
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     */
    public function emulated($gateway = null): array
    {
        return $this->gateway($gateway)->emulated();
    }

    /**
     * Diz se a lib entrega a capability por emulação no gateway (o desta instância, por
     * padrão).
     *
     * @param  Capability  $capability
     * @param  GatewayContract|string|null  $gateway
     * @return bool
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     */
    public function isEmulated(Capability $capability, $gateway = null): bool
    {
        return $this->gateway($gateway)->isEmulated($capability);
    }

    /**
     * Diz se o gateway desta instância suporta todas as capabilities informadas. Para outro
     * gateway, use `gateway($nome)->supportsAll(...)`.
     *
     * @param  Capability  ...$capabilities
     * @return bool
     */
    public function supportsAll(Capability ...$capabilities): bool
    {
        return $this->gateway->supportsAll(...$capabilities);
    }

    /**
     * Restrição que o gateway (o desta instância, por padrão) impõe a uma capability que
     * suporta, ou nulo quando ela vale em todos os casos.
     *
     * @param  Capability  $capability
     * @param  GatewayContract|string|null  $gateway
     * @return CapabilityRestriction|null
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     */
    public function restriction(Capability $capability, $gateway = null): ?CapabilityRestriction
    {
        return $this->gateway($gateway)->restriction($capability);
    }

    /**
     * Restrições do gateway (o desta instância, por padrão), com o valor da capability como
     * chave.
     *
     * @param  GatewayContract|string|null  $gateway
     * @return array<string, CapabilityRestriction>
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     */
    public function restrictions($gateway = null): array
    {
        return $this->gateway($gateway)->restrictions();
    }

    /**
     * Cria e cobra uma fatura a partir de um array em `snake_case` (as chaves aceitas estão no
     * README, no apêndice "Chaves do array de charge()"). `customer` é obrigatório e é
     * conferido antes de qualquer conversão.
     *
     * @param  array  $attributes
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     *
     * @return Invoice
     * @throws GatewayException|ModelAttributeValidationException|GatewayNotAvailableException
     */
    public function charge(array $attributes, ?string $idempotencyKey = null): Invoice
    {
        if (empty($attributes['customer'])) {
            throw ModelAttributeValidationException::required('Invoice', 'customer');
        }

        $invoice = new Invoice();
        $invoice->fill($attributes);

        $invoice->save($this->gateway, true, $idempotencyKey);
        return $invoice;
    }

    /**
     * Return an InvoiceBuilder instance
     *
     * @return InvoiceBuilder
     */
    public function newInvoice(): InvoiceBuilder
    {
        return new InvoiceBuilder($this->gateway);
    }

    /**
     * Return a CustomerBuilder instance
     *
     * @return CustomerBuilder
     */
    public function newCustomer(): CustomerBuilder
    {
        return new CustomerBuilder($this->gateway);
    }

    /**
     * Return a CreditCardBuilder instance
     *
     * @return CreditCardBuilder
     */
    public function newCreditCard(): CreditCardBuilder
    {
        return new CreditCardBuilder($this->gateway);
    }

    /**
     * Return a SubscriptionBuilder instance
     *
     * @return SubscriptionBuilder
     */
    public function newSubscription(): SubscriptionBuilder
    {
        return new SubscriptionBuilder($this->gateway);
    }

    /**
     * List the subscriptions of a customer
     *
     * @param  Customer|string  $customer
     * @param  int  $page
     * @param  int  $limit
     *
     * @return Subscription[]
     * @throws GatewayException|GatewayNotAvailableException|UnsupportedOperationException
     */
    public function listSubscriptions(Customer|string $customer, int $page = 1, int $limit = 100): array
    {
        if (is_string($customer)) {
            $customerModel = new Customer();
            $customerModel->id = $customer;
            $customer = $customerModel;
        }

        return $this->gatewayImplementing(SubscriptionContract::class, Capability::SUBSCRIPTIONS)
            ->listSubscriptions($customer, $page, $limit);
    }

    /**
     * Simula a troca de plano de uma assinatura sem aplicá-la, com a política de pró-rata
     * informada. Aceita o model ou só o id da assinatura; `CREDIT` num gateway sem
     * `Capability::PLAN_CHANGE_PRORATION` lança `UnsupportedOperationException` antes de
     * qualquer requisição.
     *
     * @param  Subscription|string  $subscription
     * @param  string  $planId
     * @param  ProrationBehavior  $proration  política de pró-rata simulada
     *
     * @return SubscriptionPlanChange
     * @throws GatewayException|GatewayNotAvailableException|UnsupportedOperationException
     * @throws ConfigurationException|ModelAttributeValidationException
     */
    public function previewSubscriptionPlanChange(
        Subscription|string $subscription,
        string $planId,
        ProrationBehavior $proration = ProrationBehavior::CHARGE_DIFFERENCE
    ): SubscriptionPlanChange {
        if (is_string($subscription)) {
            $subscriptionModel = new Subscription();
            $subscriptionModel->id = $subscription;
            $subscription = $subscriptionModel;
        }

        /** @var SubscriptionContract $gateway */
        $gateway = $this->gatewayImplementing(SubscriptionContract::class, Capability::SUBSCRIPTIONS);

        return $gateway->previewSubscriptionPlanChange($subscription, $planId, $proration);
    }

    /**
     * List the gateway plans
     *
     * @param  int  $page
     * @param  int  $limit
     *
     * @return Plan[]
     * @throws GatewayException|GatewayNotAvailableException|UnsupportedOperationException
     */
    public function listPlans(int $page = 1, int $limit = 100): array
    {
        return $this->gatewayImplementing(PlanContract::class, Capability::PLANS)->listPlans($page, $limit);
    }

    /**
     * Ensure this instance's gateway declares the capability and implements the contract behind it.
     *
     * @param  class-string  $contract
     * @param  Capability  $capability
     *
     * @return GatewayContract
     * @throws UnsupportedOperationException
     * @throws ConfigurationException  driver que declara a capability sem implementar o contract
     */
    private function gatewayImplementing(string $contract, Capability $capability): GatewayContract
    {
        if (!$this->gateway->supports($capability)) {
            throw UnsupportedOperationException::forGateway($this->gateway, $capability);
        }

        if (!$this->gateway instanceof $contract) {
            throw ConfigurationException::GatewayMissingContract($this->gateway, $capability, $contract);
        }

        return $this->gateway;
    }

    /**
     * Verifica a autenticidade de uma entrega de webhook e a traduz num `WebhookEvent`
     * normalizado, pelo driver do gateway desta instância. Recebe o corpo cru, byte a byte
     * como entregue, e os cabeçalhos da requisição; para um `Request` do Laravel, use
     * `parseWebhookRequest()`.
     *
     * @param  string  $rawBody  corpo cru da requisição
     * @param  array  $headers  cabeçalhos da requisição, como `nome => valor` ou `nome => [valores]`
     * @return WebhookEvent
     * @throws \Potelo\MultiPayment\Exceptions\WebhookSignatureException
     * @throws UnsupportedOperationException|ConfigurationException
     */
    public function parseWebhook(string $rawBody, array $headers): WebhookEvent
    {
        /** @var WebhookContract $gateway */
        $gateway = $this->gatewayImplementing(WebhookContract::class, Capability::WEBHOOKS);

        return $gateway->parseWebhook($rawBody, $headers);
    }

    /**
     * Adaptador de `parseWebhook()` para um `Request` do Laravel: extrai o corpo cru e os
     * cabeçalhos da requisição recebida.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return WebhookEvent
     * @throws \Potelo\MultiPayment\Exceptions\WebhookSignatureException
     * @throws UnsupportedOperationException|ConfigurationException
     */
    public function parseWebhookRequest(\Illuminate\Http\Request $request): WebhookEvent
    {
        return $this->parseWebhook((string) $request->getContent(), $request->headers->all());
    }

    /**
     * Devolve o pipeline de consumo de webhooks (verificar, deduplicar, despachar os eventos
     * do Laravel e responder), o mesmo da rota pronta do pacote. O gateway desta instância
     * vale quando a requisição não traz o parâmetro de rota `gateway`.
     *
     * @return \Potelo\MultiPayment\Webhooks\WebhookHandler
     */
    public function webhooks(): \Potelo\MultiPayment\Webhooks\WebhookHandler
    {
        return new \Potelo\MultiPayment\Webhooks\WebhookHandler($this->gateway);
    }

    /**
     * Return an invoice based on the invoice ID
     *
     * @param  string  $id
     *
     * @return \Potelo\MultiPayment\Models\Invoice
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function getInvoice(string $id): Invoice
    {
        $invoice = new Invoice();
        $invoice->id = $id;
        return $invoice->get($this->gateway);
    }

    /**
     * Busca a assinatura pelo id no gateway desta instância.
     *
     * @param  string  $id
     *
     * @return Subscription
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws GatewayException|GatewayNotAvailableException|UnsupportedOperationException
     */
    public function getSubscription(string $id): Subscription
    {
        $subscription = new Subscription();
        $subscription->id = $id;

        return $subscription->get($this->gateway);
    }

    /**
     * Busca o plano pelo identificador definido por quem o criou ou pelo id do gateway. A
     * primeira busca usa o valor como `identifier`; se o gateway responder que não existe
     * (`NotFoundException`), a segunda usa o valor como `id`. Plano inexistente nos dois
     * lança a `NotFoundException` da segunda busca.
     *
     * @param  string  $idOrIdentifier
     *
     * @return Plan
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws GatewayException|GatewayNotAvailableException|UnsupportedOperationException
     */
    public function getPlan(string $idOrIdentifier): Plan
    {
        $plan = new Plan();
        $plan->identifier = $idOrIdentifier;

        try {
            return $plan->get($this->gateway);
        } catch (NotFoundException) {
            $plan = new Plan();
            $plan->id = $idOrIdentifier;

            return $plan->get($this->gateway);
        }
    }

    /**
     * Duplicate an invoice
     *
     * @param  \Potelo\MultiPayment\Models\Invoice|string  $invoice
     * @param  \Carbon\Carbon  $expiresAt
     * @param  array  $gatewayOptions
     * @param  string|null  $idempotencyKey  idempotency key of the operation; null disables deduplication
     *
     * @return \Potelo\MultiPayment\Models\Invoice
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function duplicateInvoice(
        Invoice|string $invoice,
        Carbon $expiresAt,
        array $gatewayOptions = [],
        ?string $idempotencyKey = null
    ): Invoice {
        if (is_string($invoice)) {
            $invoiceInstance = new Invoice();
            $invoiceInstance->id = $invoice;
            $invoice = $invoiceInstance;
        }

        // sem isso o model resolveria o gateway default, ignorando o setGateway() desta instância
        if (empty($invoice->gateway)) {
            $invoice->gateway = $this->gateway;
        }

        return $invoice->duplicate($expiresAt, $gatewayOptions, $idempotencyKey);
    }

    /**
     * Return an Customer based on the customer ID
     *
     * @param  string  $id
     *
     * @return \Potelo\MultiPayment\Models\Customer
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function getCustomer(string $id): Customer
    {
        $customer = new Customer();
        $customer->id = $id;
        return $customer->get($this->gateway);
    }

    /**
     * Estorna uma fatura pelo id: o restante estornável sem valor, ou o valor em centavos.
     * Devolve o `Refund` criado; a fatura relida após o estorno está em `$refund->invoice()`.
     *
     * @param  string  $id
     * @param  int|null  $partialValueCents  valor em centavos; nulo estorna o restante
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     *
     * @return \Potelo\MultiPayment\Models\Refund
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\RefundNotSupportedException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException  valor zero ou negativo
     */
    public function refundInvoice(string $id, ?int $partialValueCents = null, ?string $idempotencyKey = null): Refund
    {
        $invoice = new Invoice();
        $invoice->id = $id;
        $invoice->gateway = $this->gateway;

        return $invoice->refund($partialValueCents, $idempotencyKey);
    }

    /**
     * Valor que ainda pode ser estornado na fatura, em centavos; lê a fatura no gateway. Fatura
     * paga com boleto devolve zero, porque `refundInvoice()` a recusa.
     *
     * @param  string  $id
     * @return int
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function refundableAmount(string $id): int
    {
        $invoice = new Invoice();
        $invoice->id = $id;
        $invoice->gateway = $this->gateway;

        return $invoice->refundableAmount();
    }

    /**
     * Cancel an invoice.
     *
     * @param  Invoice|string  $invoice
     * @param  string|null  $idempotencyKey  idempotency key of the operation; null disables deduplication
     * @return Invoice
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayNotAvailableException
     */
    public function cancelInvoice(Invoice|string $invoice, ?string $idempotencyKey = null): Invoice
    {
        if (is_string($invoice)) {
            $invoiceInstance = new Invoice();
            $invoiceInstance->id = $invoice;
            $invoice = $invoiceInstance;
        }

        return $invoice->cancel($this->gateway, $idempotencyKey);
    }

    /**
     * Charge invoice with credit card
     *
     * @param  Invoice|string  $invoice
     * @param  string|null  $creditCardToken
     * @param  string|null  $creditCardId
     * @param  string|null  $idempotencyKey  idempotency key of the operation; null disables deduplication
     *
     * @return \Potelo\MultiPayment\Models\Invoice
     *
     * @throws \Potelo\MultiPayment\Exceptions\ChargingException
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     * @throws \Potelo\MultiPayment\Exceptions\MultiPaymentException
     */
    public function chargeInvoiceWithCreditCard(
        $invoice,
        ?string $creditCardToken = null,
        ?string $creditCardId = null,
        ?string $idempotencyKey = null
    ): Invoice {
        if (is_string($invoice)) {
            $invoiceInstance = new Invoice();
            $invoiceInstance->id = $invoice;
            $invoice = $invoiceInstance;
        }

        if (!empty($creditCardToken) && !empty($creditCardId)) {
            throw new MultiPaymentException('"creditCardToken" and "creditCardId" are mutually exclusive');
        }

        if (!empty($creditCardToken)) {
            $invoice->creditCard = new CreditCard();
            $invoice->creditCard->token = $creditCardToken;
        } elseif (!empty($creditCardId)) {
            $invoice->creditCard = new CreditCard();
            $invoice->creditCard->id = $creditCardId;
        }

        if (empty($invoice->creditCard)) {
            throw new MultiPaymentException('"invoice->creditCard" or "creditCardToken" or "creditCardId" must be provided');
        }

        $invoice->gateway = $this->gateway;
        $invoice->creditCard->gateway = $this->gateway;

        return $invoice->chargeInvoiceWithCreditCard(null, $idempotencyKey);
    }

    /**
     * Get a credit card
     *
     * @param  string  $customerId
     * @param  string  $creditCardId
     * @return CreditCard
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function getCard(string $customerId, string $creditCardId): CreditCard
    {
        $creditCard = new CreditCard();
        $creditCard->customer = new Customer();
        $creditCard->customer->id = $customerId;
        $creditCard->id = $creditCardId;

        return $creditCard->get($this->gateway);
    }

    /**
     * Conclui o salvamento de um cartão que voltou de `newCreditCard()->create()` com
     * `requiresAction`, depois que o pagador autenticou (ver
     * `CreditCardContract::confirmCreditCardSetup()`).
     *
     * @param  string  $setupId  `CreditCard::$setupId`
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     * @return CreditCard
     * @throws GatewayException|GatewayNotAvailableException|UnsupportedOperationException
     * @throws \Potelo\MultiPayment\Exceptions\CardDeclinedException
     */
    public function confirmCreditCardSetup(string $setupId, ?string $idempotencyKey = null): CreditCard
    {
        return $this->gateway->confirmCreditCardSetup($setupId, $idempotencyKey);
    }

    /**
     * Delete a credit card
     *
     * @param  string  $customerId
     * @param  string  $creditCardId
     * @param  string|null  $idempotencyKey  idempotency key of the operation; null disables deduplication
     * @return void
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function deleteCard(string $customerId, string $creditCardId, ?string $idempotencyKey = null): void
    {
        $creditCard = new CreditCard();
        $creditCard->customer = new Customer();
        $creditCard->customer->id = $customerId;
        $creditCard->id = $creditCardId;

        $creditCard->delete($this->gateway, $idempotencyKey);
    }

    /**
     * Set a credit card as default for a customer
     *
     * @param  string  $customerId
     * @param  string  $creditCardId
     * @param  string|null  $idempotencyKey  idempotency key of the operation; null disables deduplication
     * @return \Potelo\MultiPayment\Models\Customer
     */
    public function setDefaultCard(string $customerId, string $creditCardId, ?string $idempotencyKey = null): Customer
    {
        $customer = new Customer();
        $customer->id = $customerId;
        // sem isso o model resolveria o gateway default, ignorando o setGateway() desta instância
        $customer->gateway = $this->gateway;
        return $customer->setDefaultCard($creditCardId, $idempotencyKey);
    }

    /**
     * Cancela uma recorrência de Pix Automático no gateway.
     *
     * @param  AutomaticPix|string  $automaticPix
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     * @return AutomaticPixCancellation
     * @throws GatewayException
     * @throws GatewayNotAvailableException
     */
    public function cancelAutomaticPixRecurrence(
        AutomaticPix|string $automaticPix,
        ?string $idempotencyKey = null
    ): AutomaticPixCancellation {
        if (is_string($automaticPix)) {
            $automaticPixModel = new AutomaticPix();
            $automaticPixModel->id = $automaticPix;
            $automaticPix = $automaticPixModel;
        }

        return $this->gateway->cancelAutomaticPixRecurrence($automaticPix, $idempotencyKey);
    }

    /**
     * Cancela um pagamento agendado de Pix Automático no gateway.
     *
     * @param  AutomaticPixCharge|string  $charge
     * @param  string|null  $endToEndId
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     * @return AutomaticPixCancellation
     * @throws GatewayException
     * @throws GatewayNotAvailableException
     */
    public function cancelAutomaticPixScheduledPayment(
        AutomaticPixCharge|string $charge,
        ?string $endToEndId = null,
        ?string $idempotencyKey = null
    ): AutomaticPixCancellation {
        if (is_string($charge)) {
            $chargeModel = new AutomaticPixCharge();
            $chargeModel->id = $charge;
            $chargeModel->endToEndId = $endToEndId;
            $charge = $chargeModel;
        }

        return $this->gateway->cancelAutomaticPixScheduledPayment($charge, $idempotencyKey);
    }

    /**
     * Pede um novo agendamento de débito de Pix Automático para uma fatura que expirou.
     *
     * @param  Invoice|string  $invoice
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     * @return Invoice
     * @throws GatewayException
     * @throws GatewayNotAvailableException
     */
    public function rescheduleAutomaticPixPayment(Invoice|string $invoice, ?string $idempotencyKey = null): Invoice
    {
        if (is_string($invoice)) {
            $invoiceModel = new Invoice();
            $invoiceModel->id = $invoice;
            $invoice = $invoiceModel;
        }

        return $invoice->rescheduleAutomaticPixPayment($this->gateway, $idempotencyKey);
    }

    /**
     * Get one cancellation from an Automatic Pix recurrence.
     */
    public function getAutomaticPixCancellation(
        AutomaticPixCancellation|string $cancellation,
        ?string $cancellationId = null
    ): AutomaticPixCancellation {
        if (is_string($cancellation)) {
            $recurrenceId = $cancellation;
            $cancellation = new AutomaticPixCancellation();
            $cancellation->recurrenceId = $recurrenceId;
            $cancellation->id = $cancellationId;
        }

        return $this->gateway->getAutomaticPixCancellation($cancellation);
    }

    /**
     * List cancellations from an Automatic Pix recurrence.
     *
     * @return AutomaticPixCancellation[]
     */
    public function listAutomaticPixCancellations(
        AutomaticPix|string $automaticPix,
        int $page = 1,
        int $limit = 100
    ): array {
        if (is_string($automaticPix)) {
            $automaticPixModel = new AutomaticPix();
            $automaticPixModel->id = $automaticPix;
            $automaticPix = $automaticPixModel;
        }

        return $this->gateway->listAutomaticPixCancellations($automaticPix, $page, $limit);
    }

}
