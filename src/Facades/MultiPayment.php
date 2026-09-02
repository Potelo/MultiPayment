<?php

namespace Potelo\MultiPayment\Facades;

use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Builders\InvoiceBuilder;
use Potelo\MultiPayment\Builders\CustomerBuilder;
use Potelo\MultiPayment\Builders\CreditCardBuilder;


/**
 * @method static Invoice charge(array $attributes, ?string $idempotencyKey = null)
 * @method static InvoiceBuilder newInvoice()
 * @method static CustomerBuilder newCustomer()
 * @method static CreditCardBuilder newCreditCard()
 * @method static \Potelo\MultiPayment\Builders\SubscriptionBuilder newSubscription()
 * @method static \Potelo\MultiPayment\Models\Subscription[] listSubscriptions(\Potelo\MultiPayment\Models\Customer|string $customer, int $page = 1, int $limit = 100)
 * @method static \Potelo\MultiPayment\Models\Plan[] listPlans(int $page = 1, int $limit = 100)
 * @method static Invoice getInvoice(string $id)
 * @method static \Potelo\MultiPayment\Models\Subscription getSubscription(string $id)
 * @method static \Potelo\MultiPayment\Models\Plan getPlan(string $idOrIdentifier)
 * @method static \Potelo\MultiPayment\Models\Customer getCustomer(string $id)
 * @method static \Potelo\MultiPayment\Models\Refund refundInvoice(string $id, ?int $partialValueCents = null, ?string $idempotencyKey = null)
 * @method static int refundableAmount(string $id)
 * @method static Invoice duplicateInvoice(Invoice|string $invoice, \Carbon\Carbon $expiresAt, array $gatewayOptions = [], ?string $idempotencyKey = null)
 * @method static CreditCard getCard(string $customerId, string $creditCardId)
 * @method static CreditCard confirmCreditCardSetup(string $setupId, ?string $idempotencyKey = null)
 * @method static void deleteCard(string $customerId, string $creditCardId, ?string $idempotencyKey = null)
 * @method static \Potelo\MultiPayment\MultiPayment setGateway($gateway)
 * @method static \Potelo\MultiPayment\Contracts\GatewayContract gateway($gateway = null)
 * @method static bool supports(\Potelo\MultiPayment\Enums\Capability $capability, $gateway = null)
 * @method static \Potelo\MultiPayment\Enums\Capability[] capabilities($gateway = null)
 * @method static \Potelo\MultiPayment\Enums\Capability[] notYetImplemented($gateway = null)
 * @method static bool supportsAll(\Potelo\MultiPayment\Enums\Capability ...$capabilities)
 * @method static \Potelo\MultiPayment\Capabilities\CapabilityRestriction|null restriction(\Potelo\MultiPayment\Enums\Capability $capability, $gateway = null)
 * @method static array<string, \Potelo\MultiPayment\Capabilities\CapabilityRestriction> restrictions($gateway = null)
 * @method static Invoice chargeInvoiceWithCreditCard($invoice, ?string $creditCardToken = null, ?string $creditCardId = null, ?string $idempotencyKey = null)
 * @method static \Potelo\MultiPayment\Models\Customer setDefaultCard(string $customerId, string $creditCardId, ?string $idempotencyKey = null)
 * @method static Invoice cancelInvoice(Invoice|string $invoice, ?string $idempotencyKey = null)
 * @method static Invoice rescheduleAutomaticPixPayment(Invoice|string $invoice, ?string $idempotencyKey = null)
 * @method static \Potelo\MultiPayment\Models\AutomaticPixCancellation cancelAutomaticPixRecurrence(\Potelo\MultiPayment\Models\AutomaticPix|string $automaticPix, ?string $idempotencyKey = null)
 * @method static \Potelo\MultiPayment\Models\AutomaticPixCancellation cancelAutomaticPixScheduledPayment(\Potelo\MultiPayment\Models\AutomaticPixCharge|string $charge, ?string $endToEndId = null, ?string $idempotencyKey = null)
 * @method static \Potelo\MultiPayment\Models\AutomaticPixCancellation getAutomaticPixCancellation(\Potelo\MultiPayment\Models\AutomaticPixCancellation|string $cancellation, ?string $cancellationId = null)
 * @method static \Potelo\MultiPayment\Models\AutomaticPixCancellation[] listAutomaticPixCancellations(\Potelo\MultiPayment\Models\AutomaticPix|string $automaticPix, int $page = 1, int $limit = 100)
 */
class MultiPayment extends Facade
{

    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'multiPayment';
    }
}
