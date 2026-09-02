<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Gateways\IuguGateway;
use Potelo\MultiPayment\Exceptions\GatewayException;
use PHPUnit\Framework\Attributes\DataProvider;

class IuguGatewayInvoiceStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment.gateways.iugu.api_key' => 'test-api-key',
        ]));
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    /**
     * Mapa completo dos onze status oficiais da fatura Iugu, mais `partially_refunded` e
     * `authorized`, que o gateway já conhecia, para o status genérico.
     */
    public static function statusProvider(): array
    {
        return [
            'pending' => ['pending', Invoice::STATUS_PENDING],
            'in_analysis' => ['in_analysis', Invoice::STATUS_PENDING],
            'draft' => ['draft', Invoice::STATUS_PENDING],
            'partially_paid' => ['partially_paid', Invoice::STATUS_PENDING],
            'paid' => ['paid', Invoice::STATUS_PAID],
            'externally_paid' => ['externally_paid', Invoice::STATUS_PAID],
            'authorized' => ['authorized', Invoice::STATUS_PAID],
            'in_protest' => ['in_protest', Invoice::STATUS_DISPUTED],
            'canceled' => ['canceled', Invoice::STATUS_CANCELED],
            'expired' => ['expired', Invoice::STATUS_CANCELED],
            'refunded' => ['refunded', Invoice::STATUS_REFUNDED],
            'partially_refunded' => ['partially_refunded', Invoice::STATUS_PARTIALLY_REFUNDED],
            'chargeback' => ['chargeback', Invoice::STATUS_CHARGEBACK],
        ];
    }

    #[DataProvider('statusProvider')]
    public function testMapsEveryIuguInvoiceStatusToTheGenericOne(string $iuguStatus, string $expected): void
    {
        $this->assertSame($expected, $this->mapStatus($iuguStatus));
    }

    public function testInProtestNoLongerReadsAsPaid(): void
    {
        $status = $this->mapStatus('in_protest');

        $this->assertNotSame(Invoice::STATUS_PAID, $status);
        $this->assertSame(Invoice::STATUS_DISPUTED, $status);
        $this->assertFalse(Invoice::isSettled($status));
        $this->assertTrue(Invoice::isContested($status));
    }

    public function testChargebackNoLongerReadsAsRefunded(): void
    {
        $status = $this->mapStatus('chargeback');

        $this->assertNotSame(Invoice::STATUS_REFUNDED, $status);
        $this->assertSame(Invoice::STATUS_CHARGEBACK, $status);
        $this->assertFalse(Invoice::isSettled($status));
        $this->assertTrue(Invoice::isContested($status));
    }

    public function testUnknownStatusStillThrows(): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('Unexpected Iugu status: status_novo');

        $this->mapStatus('status_novo');
    }

    /**
     * Caminho público: a fatura resumida em `recent_invoices` da assinatura passa pelo mesmo
     * mapa, então uma resposta real da Iugu com `in_protest` chega como `disputed`.
     */
    public function testDisputedInvoiceFromTheGatewayResponseIsParsedAsDisputed(): void
    {
        $subscription = $this->readSubscriptionWithLatestInvoiceStatus('in_protest');

        $this->assertSame(Invoice::STATUS_DISPUTED, $subscription->latestInvoice->status);
        $this->assertSame('in_protest', $subscription->latestInvoice->original->status);
    }

    public function testChargebackInvoiceFromTheGatewayResponseIsParsedAsChargeback(): void
    {
        $subscription = $this->readSubscriptionWithLatestInvoiceStatus('chargeback');

        $this->assertSame(Invoice::STATUS_CHARGEBACK, $subscription->latestInvoice->status);
        $this->assertSame('chargeback', $subscription->latestInvoice->original->status);
    }

    public static function contestedStatusProvider(): array
    {
        return [
            'in_protest' => ['in_protest'],
            'chargeback' => ['chargeback'],
        ];
    }

    /**
     * Disputa e chargeback não são dívida em aberto: a assinatura não vira `past_due` por
     * causa deles, mesmo com a data de cobrança no passado.
     */
    #[DataProvider('contestedStatusProvider')]
    public function testContestedInvoicesDoNotMakeTheSubscriptionPastDue(string $iuguStatus): void
    {
        $subscription = $this->readSubscriptionWithLatestInvoiceStatus($iuguStatus, '2026-08-01');

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
    }

    private function mapStatus(string $iuguStatus): string
    {
        $method = new \ReflectionMethod(IuguGateway::class, 'iuguStatusToMultiPayment');

        return $method->invoke(null, $iuguStatus);
    }

    private function readSubscriptionWithLatestInvoiceStatus(string $iuguStatus, string $expiresAt = '2026-10-01'): Subscription
    {
        $api = new QueuedIuguApiRequest([
            (object) [
                'id' => 'sub_1',
                'customer_id' => 'cus_1',
                'plan_identifier' => 'plano_mensal',
                'price_cents' => 10000,
                'expires_at' => $expiresAt,
                'created_at' => '2026-09-01T10:00:00-03:00',
                'active' => true,
                'suspended' => false,
                'in_trial' => false,
                'recent_invoices' => [
                    (object) ['id' => 'inv_1', 'status' => $iuguStatus, 'due_date' => '2026-09-01'],
                ],
            ],
        ]);

        $subscription = new Subscription();
        $subscription->id = 'sub_1';

        return (new IuguGateway($api))->getSubscription($subscription);
    }
}
