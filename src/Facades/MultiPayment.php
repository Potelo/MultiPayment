<?php

namespace Potelo\MultiPayment\Facades;

use Illuminate\Support\Facades\Facade;

class MultiPayment extends Facade
{

    /**
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'multiPayment';
    }
}
