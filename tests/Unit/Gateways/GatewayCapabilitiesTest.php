<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Gateways\IuguGateway;
use Potelo\MultiPayment\Gateways\StripeGateway;
use Potelo\MultiPayment\Contracts\PlanContract;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Helpers\CapabilitiesTable;
use Potelo\MultiPayment\Contracts\SubscriptionContract;
use Potelo\MultiPayment\Contracts\DeclaresCapabilities;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Matriz completa driver versus capability, com o esperado por célula, mais a coerência entre
 * a declaração e os contracts e entre a declaração e a tabela publicada no README.
 */
class GatewayCapabilitiesTest extends TestCase
{
    private const SUPPORTED = CapabilitiesTable::SUPPORTED;
    private const NOT_IMPLEMENTED = CapabilitiesTable::NOT_IMPLEMENTED;
    private const LIMITATION = CapabilitiesTable::GATEWAY_LIMITATION;

    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment.gateways.iugu.api_key' => 'iugu-key',
            'multi-payment.gateways.stripe.api_key' => 'sk_test_fake',
        ]));
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    #[DataProvider('matrixProvider')]
    public function testDriverDeclaresTheExpectedSupport(string $gateway, Capability $capability, string $expected): void
    {
        $driver = self::driver($gateway);

        $this->assertSame($expected, CapabilitiesTable::cell($driver, $capability));
        $this->assertSame($expected === self::SUPPORTED, $driver->supports($capability));
    }

    /**
     * Uma célula por driver e capability. Linha nova em `Capability` sem entrada aqui falha
     * em `testMatrixCoversEveryCapabilityForEveryDriver`.
     */
    public static function matrixProvider(): array
    {
        $matrix = [
            //                                            iugu                  stripe
            Capability::CREDIT_CARD->name =>              [self::SUPPORTED,      self::SUPPORTED],
            Capability::PIX->name =>                      [self::SUPPORTED,      self::SUPPORTED],
            Capability::BANK_SLIP->name =>                [self::SUPPORTED,      self::NOT_IMPLEMENTED],
            Capability::AUTOMATIC_PIX->name =>            [self::SUPPORTED,      self::NOT_IMPLEMENTED],
            Capability::MULTIPLE_PAYMENT_METHODS->name => [self::SUPPORTED,      self::NOT_IMPLEMENTED],
            Capability::RAW_CARD_DATA->name =>            [self::SUPPORTED,      self::LIMITATION],
            Capability::INSTALLMENTS->name =>             [self::SUPPORTED,      self::LIMITATION],
            Capability::DELAYED_CAPTURE->name =>          [self::NOT_IMPLEMENTED, self::NOT_IMPLEMENTED],
            Capability::PARTIAL_REFUND_CARD->name =>      [self::SUPPORTED,      self::SUPPORTED],
            Capability::PARTIAL_REFUND_PIX->name =>       [self::LIMITATION,     self::SUPPORTED],
            Capability::REFUND_BANK_SLIP->name =>         [self::LIMITATION,     self::LIMITATION],
            Capability::INVOICE_DUPLICATION->name =>      [self::SUPPORTED,      self::SUPPORTED],
            Capability::IDEMPOTENCY->name =>              [self::SUPPORTED,      self::SUPPORTED],
            Capability::IDEMPOTENCY_ALL_ENDPOINTS->name => [self::LIMITATION,    self::SUPPORTED],
            Capability::SUBSCRIPTIONS->name =>            [self::SUPPORTED,      self::NOT_IMPLEMENTED],
            Capability::PLANS->name =>                    [self::SUPPORTED,      self::NOT_IMPLEMENTED],
            Capability::PLAN_DEACTIVATION->name =>        [self::LIMITATION,     self::NOT_IMPLEMENTED],
            Capability::CANCEL_AT_PERIOD_END->name =>     [self::LIMITATION,     self::NOT_IMPLEMENTED],
            Capability::NATIVE_COUPONS->name =>           [self::LIMITATION,     self::NOT_IMPLEMENTED],
            Capability::PLAN_CHANGE_PRORATION->name =>    [self::LIMITATION,     self::NOT_IMPLEMENTED],
            Capability::SUBSCRIPTION_CREDITS->name =>     [self::NOT_IMPLEMENTED, self::LIMITATION],
            Capability::MANAGES_RECURRENCE->name =>       [self::LIMITATION,     self::NOT_IMPLEMENTED],
        ];

        $cases = [];
        foreach ($matrix as $name => [$iugu, $stripe]) {
            $capability = constant(Capability::class . '::' . $name);
            $cases["iugu {$name}"] = ['iugu', $capability, $iugu];
            $cases["stripe {$name}"] = ['stripe', $capability, $stripe];
        }

        return $cases;
    }

    public function testMatrixCoversEveryCapabilityForEveryDriver(): void
    {
        $this->assertCount(count(Capability::cases()) * 2, self::matrixProvider());
    }

    #[DataProvider('driverProvider')]
    public function testCapabilitiesAndNotYetImplementedDoNotOverlap(string $gateway): void
    {
        $driver = self::driver($gateway);

        $overlap = array_filter(
            $driver->capabilities(),
            static fn (Capability $capability) => in_array($capability, $driver->notYetImplemented(), true)
        );

        $this->assertSame([], $overlap);
        $this->assertSame($driver->capabilities(), array_values(array_unique($driver->capabilities(), SORT_REGULAR)));
    }

    /**
     * A capability de assinaturas (ou planos) só é declarada por driver que implementa o
     * contract correspondente, e todo driver que implementa o contract a declara.
     */
    #[DataProvider('driverProvider')]
    public function testSubscriptionAndPlanCapabilitiesMatchTheContracts(string $gateway): void
    {
        $driver = self::driver($gateway);

        $this->assertSame($driver instanceof SubscriptionContract, $driver->supports(Capability::SUBSCRIPTIONS));
        $this->assertSame($driver instanceof PlanContract, $driver->supports(Capability::PLANS));
    }

    public static function driverProvider(): array
    {
        return ['iugu' => ['iugu'], 'stripe' => ['stripe']];
    }

    /**
     * O README publica a saída de `composer capabilities:table`; declaração nova sem regerar
     * a tabela falha aqui.
     */
    public function testReadmeContainsTheGeneratedTable(): void
    {
        $table = CapabilitiesTable::markdown(['iugu' => self::driver('iugu'), 'stripe' => self::driver('stripe')]);
        $readme = file_get_contents(__DIR__ . '/../../../README.md');

        $this->assertStringContainsString($table, $readme, 'README desatualizado: rode `composer capabilities:table` e cole a saída na seção Capabilities');
    }

    public function testTableHasOneRowPerCapabilityAndOneColumnPerGateway(): void
    {
        $table = CapabilitiesTable::markdown(['iugu' => self::driver('iugu'), 'stripe' => self::driver('stripe')]);
        $lines = explode("\n", trim($table));

        $this->assertSame('| Capability | Significado | Iugu | Stripe |', $lines[0]);
        $this->assertSame('|---|---|---|---|', $lines[1]);
        $this->assertCount(count(Capability::cases()) + 2, $lines);
        $this->assertStringStartsWith('| `CREDIT_CARD` | Fatura paga com cartão de crédito. | sim | sim |', $lines[2]);
    }

    private static function driver(string $gateway): GatewayContract&DeclaresCapabilities
    {
        return match ($gateway) {
            'iugu' => new IuguGateway(new QueuedIuguApiRequest([])),
            'stripe' => new StripeGateway(),
        };
    }
}
