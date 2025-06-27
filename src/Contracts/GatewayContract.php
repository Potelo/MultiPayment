<?php

namespace  Potelo\MultiPayment\Contracts;

interface GatewayContract extends CreditCardContract, CustomerContract, InvoiceContract
{
    public function __toString();
}
