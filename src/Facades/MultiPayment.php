<?php

namespace Potelo\MultiPayment\Facades;

use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Resources\Response;


/**
 * @method static Response charge(array $attributes)
 * @method static Invoice newInvoice()
 * @method static Customer newCustomer()
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
