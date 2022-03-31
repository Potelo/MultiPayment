<?php

namespace Potelo\MultiPayment\Builders;

use Potelo\MultiPayment\Helpers\ConfigurationHelper;

class Builder
{
    protected \Potelo\MultiPayment\Contracts\Gateway $gateway;

    public function __construct($gateway = null)
    {
        $this->gateway = ConfigurationHelper::resolveGateway($gateway);
    }
}