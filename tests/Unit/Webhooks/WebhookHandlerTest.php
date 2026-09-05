<?php

namespace Potelo\MultiPayment\Tests\Unit\Webhooks;

use PHPUnit\Framework\TestCase;
use Potelo\MultiPayment\Models\WebhookEvent;
use Potelo\MultiPayment\Enums\WebhookEventType;
use Potelo\MultiPayment\Events\WebhookReceived;
use Potelo\MultiPayment\Webhooks\WebhookHandler;

/**
 * Mapa de tipo comum para classe de evento do Laravel: todo tipo fora de `UNKNOWN` tem uma
 * classe própria que carrega o `WebhookEvent`, e `UNKNOWN` fica só com o `WebhookReceived`
 * genérico. Um caso novo no enum sem classe correspondente derruba este teste.
 */
class WebhookHandlerTest extends TestCase
{
    public function testEveryTypeExceptUnknownHasALaravelEventClassCarryingTheWebhookEvent(): void
    {
        foreach (WebhookEventType::cases() as $type) {
            $class = WebhookHandler::eventClassFor($type);

            if ($type === WebhookEventType::UNKNOWN) {
                $this->assertNull($class);
                continue;
            }

            $this->assertNotNull($class, "Tipo {$type->value} sem classe de evento do Laravel.");
            $this->assertTrue(class_exists($class), "Classe {$class} não existe.");

            $webhook = new WebhookEvent();
            $instance = new $class($webhook);
            $this->assertSame($webhook, $instance->webhook);
            $this->assertNotInstanceOf(WebhookReceived::class, $instance);
        }
    }
}
