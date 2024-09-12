<?php

namespace Potelo\MultiPayment\Facades;

use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Builders\InvoiceBuilder;
use Potelo\MultiPayment\Builders\CustomerBuilder;
use Potelo\MultiPayment\Builders\CreditCardBuilder;


/**
 * @method static invoice charge(array $attributes)
 * @method static InvoiceBuilder newInvoice()
 * @method static CustomerBuilder newCustomer()
 * @method static CreditCardBuilder newCreditCard()
 * @method static \Potelo\MultiPayment\MultiPayment setGateway($gateway)
 * @method static Invoice chargeInvoiceWithCreditCard($invoice, ?string $creditCardToken = null, ?string $creditCardId = null)
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
