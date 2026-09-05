<?php

namespace Potelo\MultiPayment\Tests\Unit\Enums;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Potelo\MultiPayment\Enums\WebhookEventType;

/**
 * A matriz completa dos helpers `concernsInvoice()` e `concernsSubscription()`: caso novo no
 * enum sem linha aqui falha em `testMatrixCoversEveryCase`.
 */
class WebhookEventTypeTest extends TestCase
{
    #[DataProvider('concernsProvider')]
    public function testEachCaseAnswersTheConcernHelpers(
        WebhookEventType $type,
        bool $concernsInvoice,
        bool $concernsSubscription
    ): void {
        $this->assertSame($concernsInvoice, $type->concernsInvoice());
        $this->assertSame($concernsSubscription, $type->concernsSubscription());
    }

    /**
     * Uma linha por caso: [caso, concernsInvoice, concernsSubscription]. `SUBSCRIPTION_RENEWED`
     * responde aos dois, porque a renovação carrega a fatura paga do ciclo.
     */
    public static function concernsProvider(): array
    {
        $matrix = [
            [WebhookEventType::SUBSCRIPTION_CREATED, false, true],
            [WebhookEventType::SUBSCRIPTION_RENEWED, true, true],
            [WebhookEventType::SUBSCRIPTION_UPDATED, false, true],
            [WebhookEventType::SUBSCRIPTION_CANCELED, false, true],
            [WebhookEventType::SUBSCRIPTION_SUSPENDED, false, true],
            [WebhookEventType::INVOICE_CREATED, true, false],
            [WebhookEventType::INVOICE_UPDATED, true, false],
            [WebhookEventType::INVOICE_PAID, true, false],
            [WebhookEventType::INVOICE_PAYMENT_FAILED, true, false],
            [WebhookEventType::INVOICE_CANCELED, true, false],
            [WebhookEventType::REFUND_CREATED, true, false],
            [WebhookEventType::DISPUTE_OPENED, true, false],
            [WebhookEventType::DISPUTE_CLOSED, true, false],
            [WebhookEventType::PAYMENT_METHOD_UPDATED, false, false],
            [WebhookEventType::PIX_MANDATE_CHANGED, false, false],
            [WebhookEventType::UNKNOWN, false, false],
        ];

        $cases = [];
        foreach ($matrix as $row) {
            $cases[$row[0]->name] = $row;
        }

        return $cases;
    }

    public function testMatrixCoversEveryCase(): void
    {
        $this->assertCount(count(WebhookEventType::cases()), self::concernsProvider());
    }
}
