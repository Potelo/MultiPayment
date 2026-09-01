<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Stripe\Util\LoggerInterface;

/**
 * Captura o que o SDK da Stripe manda para o log, para que o teste possa afirmar que o
 * parse não leu propriedade ausente de um StripeObject.
 */
class RecordingStripeLogger implements LoggerInterface
{
    /** @var string[] */
    public array $messages = [];

    public function error($message, array $context = [])
    {
        $this->messages[] = (string) $message;
    }
}
