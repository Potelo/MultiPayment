<?php

namespace  Potelo\MultiPayment\Contracts;

interface GatewayContract extends CreditCardContract, CustomerContract, InvoiceContract, AutomaticPixContract, DeclaresCapabilities
{
    public function __toString();
}
