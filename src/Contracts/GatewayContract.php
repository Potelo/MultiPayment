<?php

namespace  Potelo\MultiPayment\Contracts;

interface GatewayContract extends CreditCardContract, CustomerContract, InvoiceContract, AutomaticPixContract
{
    public function __toString();
}
