<?php

namespace Potelo\MultiPayment\Facades;

use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Builders\InvoiceBuilder;
use Potelo\MultiPayment\Builders\CustomerBuilder;
use Potelo\MultiPayment\Builders\CreditCardBuilder;


/**
 * @method static invoice charge(array $attributes)
 * @method static InvoiceBuilder newInvoice()
 * @method static CustomerBuilder newCustomer()
 * @method static CreditCardBuilder newCreditCard()
 * @method static CreditCard getCard(string $customerId, string $creditCardId)
 * @method static void deleteCard(string $customerId, string $creditCardId)
 * @method static \Potelo\MultiPayment\MultiPayment setGateway($gateway)
 * @method static Invoice chargeInvoiceWithCreditCard($invoice, ?string $creditCardToken = null, ?string $creditCardId = null)
 * @method static \Potelo\MultiPayment\Models\Customer setDefaultCard(string $customerId, string $creditCardId)
 * @method static Invoice cancelInvoice(Invoice|string $invoice)
 * @method static Invoice rescheduleAutomaticPixPayment(Invoice|string $invoice)
 * @method static \Potelo\MultiPayment\Models\AutomaticPixCancellation cancelAutomaticPixRecurrence(\Potelo\MultiPayment\Models\AutomaticPix|string $automaticPix)
 * @method static \Potelo\MultiPayment\Models\AutomaticPixCancellation cancelAutomaticPixScheduledPayment(string $paymentId, string $endToEndId)
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
