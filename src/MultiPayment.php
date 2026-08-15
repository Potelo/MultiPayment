<?php

namespace Potelo\MultiPayment;

use Carbon\Carbon;
use Potelo\MultiPayment\Exceptions\MultiPaymentException;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\AutomaticPix;
use Potelo\MultiPayment\Models\AutomaticPixCharge;
use Potelo\MultiPayment\Models\AutomaticPixCancellation;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Builders\InvoiceBuilder;
use Potelo\MultiPayment\Builders\CustomerBuilder;
use Potelo\MultiPayment\Builders\CreditCardBuilder;
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
     * Charge a customer
     *
     * @param  array  $attributes
     *
     * @return Invoice
     * @throws GatewayException|ModelAttributeValidationException|GatewayNotAvailableException
     */
    public function charge(array $attributes): Invoice
    {
        $invoice = new Invoice();
        $invoice->fill($attributes);
        $invoice->customer = new Customer();
        $invoice->customer->fill($attributes['customer']);

        $invoice->save($this->gateway);
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
     *
     * @return \Potelo\MultiPayment\Models\Invoice
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function duplicateInvoice(Invoice|string $invoice, Carbon $expiresAt, array $gatewayOptions = []): Invoice
    {
        if (is_string($invoice)) {
            $invoiceInstance = new Invoice();
            $invoiceInstance->id = $invoice;
            $invoice = $invoiceInstance;
        }

        return $invoice->duplicate($expiresAt, $gatewayOptions);
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
     * Refund an invoice
     *
     * @param  string  $id
     * @param  int|null  $partialValueCents
     *
     * @return void
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function refundInvoice(string $id, ?int $partialValueCents = null): Invoice
    {
        $invoice = new Invoice();
        $invoice->id = $id;
        $invoice->gateway = $this->gateway;

        if ($partialValueCents) {
            $invoice->refundedAmount = $partialValueCents;
        }

        return $invoice->refund();

    }

    /**
     * Cancel an invoice.
     *
     * @param  Invoice|string  $invoice
     * @return Invoice
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayNotAvailableException
     */
    public function cancelInvoice(Invoice|string $invoice): Invoice
    {
        if (is_string($invoice)) {
            $invoiceInstance = new Invoice();
            $invoiceInstance->id = $invoice;
            $invoice = $invoiceInstance;
        }

        return $invoice->cancel($this->gateway);
    }

    /**
     * Charge invoice with credit card
     *
     * @param  Invoice|string  $invoice
     * @param  string|null  $creditCardToken
     * @param  string|null  $creditCardId
     *
     * @return \Potelo\MultiPayment\Models\Invoice
     *
     * @throws \Potelo\MultiPayment\Exceptions\ChargingException
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     * @throws \Potelo\MultiPayment\Exceptions\MultiPaymentException
     */
    public function chargeInvoiceWithCreditCard($invoice, ?string $creditCardToken = null, ?string $creditCardId = null): Invoice
    {
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

        return $invoice->chargeInvoiceWithCreditCard();
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
     * @return void
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    public function deleteCard(string $customerId, string $creditCardId): void
    {
        $creditCard = new CreditCard();
        $creditCard->customer = new Customer();
        $creditCard->customer->id = $customerId;
        $creditCard->id = $creditCardId;

        $creditCard->delete($this->gateway);
    }

    /**
     * Set a credit card as default for a customer
     *
     * @param  string  $customerId
     * @param  string  $creditCardId
     * @return \Potelo\MultiPayment\Models\Customer
     */
    public function setDefaultCard(string $customerId, string $creditCardId): Customer
    {
        $customer = new Customer();
        $customer->id = $customerId;
        // sem isso o model resolveria o gateway default, ignorando o setGateway() desta instância
        $customer->gateway = $this->gateway;
        return $customer->setDefaultCard($creditCardId);
    }

    /**
     * Cancela uma recorrência de Pix Automático no gateway.
     *
     * @param  AutomaticPix|string  $automaticPix
     * @throws GatewayException
     * @throws GatewayNotAvailableException
     */
    public function cancelAutomaticPixRecurrence(
        AutomaticPix|string $automaticPix
    ): AutomaticPixCancellation
    {
        if (is_string($automaticPix)) {
            $automaticPixModel = new AutomaticPix();
            $automaticPixModel->id = $automaticPix;
            $automaticPix = $automaticPixModel;
        }

        return $this->gateway->cancelAutomaticPixRecurrence($automaticPix);
    }

    /**
     * Cancela um pagamento agendado de Pix Automático no gateway.
     *
     * @param  AutomaticPixCharge|string  $charge
     * @param  string|null  $endToEndId
     * @throws GatewayException
     * @throws GatewayNotAvailableException
     */
    public function cancelAutomaticPixScheduledPayment(
        AutomaticPixCharge|string $charge,
        ?string $endToEndId = null
    ): AutomaticPixCancellation {
        if (is_string($charge)) {
            $chargeModel = new AutomaticPixCharge();
            $chargeModel->id = $charge;
            $chargeModel->endToEndId = $endToEndId;
            $charge = $chargeModel;
        }

        return $this->gateway->cancelAutomaticPixScheduledPayment($charge);
    }

    /**
     * Request a new Automatic Pix debit schedule for an expired invoice.
     */
    public function rescheduleAutomaticPixPayment(Invoice|string $invoice): Invoice
    {
        if (is_string($invoice)) {
            $invoiceModel = new Invoice();
            $invoiceModel->id = $invoice;
            $invoice = $invoiceModel;
        }

        return $invoice->rescheduleAutomaticPixPayment($this->gateway);
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
