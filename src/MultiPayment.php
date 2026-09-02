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
use Potelo\MultiPayment\Models\AutomaticPix;
use Potelo\MultiPayment\Models\AutomaticPixCharge;
use Potelo\MultiPayment\Models\AutomaticPixCancellation;
use Potelo\MultiPayment\Contracts\PlanContract;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Contracts\SubscriptionContract;
use Potelo\MultiPayment\Builders\InvoiceBuilder;
use Potelo\MultiPayment\Builders\CustomerBuilder;
use Potelo\MultiPayment\Builders\CreditCardBuilder;
use Potelo\MultiPayment\Builders\SubscriptionBuilder;
use Potelo\MultiPayment\Exceptions\GatewayException;
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
     * Charge a customer
     *
     * @param  array  $attributes
     * @param  string|null  $idempotencyKey  idempotency key of the operation; null disables deduplication
     *
     * @return Invoice
     * @throws GatewayException|ModelAttributeValidationException|GatewayNotAvailableException
     */
    public function charge(array $attributes, ?string $idempotencyKey = null): Invoice
    {
        $invoice = new Invoice();
        $invoice->fill($attributes);
        $invoice->customer = new Customer();
        $invoice->customer->fill($attributes['customer']);

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
     * @throws GatewayException
     */
    private function gatewayImplementing(string $contract, Capability $capability): GatewayContract
    {
        if (!$this->gateway->supports($capability)) {
            throw UnsupportedOperationException::forGateway($this->gateway, $capability);
        }

        if (!$this->gateway instanceof $contract) {
            $contractName = substr(strrchr($contract, '\\'), 1);
            throw new GatewayException(
                'Gateway [' . get_class($this->gateway) . "] declares the {$capability->value} capability"
                . " but does not implement {$contractName}"
            );
        }

        return $this->gateway;
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
     * Estorna uma fatura pelo id: integral sem valor, parcial com o valor em centavos.
     * Devolve o `Refund` criado; a fatura relida após o estorno está em `$refund->invoice()`.
     *
     * @param  string  $id
     * @param  int|null  $partialValueCents
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     *
     * @return \Potelo\MultiPayment\Models\Refund
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\RefundNotSupportedException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException  valor parcial zero ou negativo
     */
    public function refundInvoice(string $id, ?int $partialValueCents = null, ?string $idempotencyKey = null): Refund
    {
        if (!is_null($partialValueCents) && $partialValueCents <= 0) {
            throw ModelAttributeValidationException::invalid(
                'Invoice',
                'refundedAmount',
                'The partial refund value must be a positive amount in cents; omit it for a full refund.'
            );
        }

        $invoice = new Invoice();
        $invoice->id = $id;
        $invoice->gateway = $this->gateway;

        if (!is_null($partialValueCents)) {
            $invoice->refundedAmount = $partialValueCents;
        }

        return $invoice->refund($idempotencyKey);
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
