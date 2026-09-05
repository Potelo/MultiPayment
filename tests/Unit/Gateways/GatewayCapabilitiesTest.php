<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\MultiPayment;
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
    private const EMULATED = CapabilitiesTable::EMULATED;
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
        $this->assertSame(
            in_array($expected, [self::SUPPORTED, self::EMULATED], true),
            $driver->supports($capability)
        );
        $this->assertSame($expected === self::EMULATED, $driver->isEmulated($capability));
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
            Capability::BANK_SLIP->name =>                [self::SUPPORTED,      self::SUPPORTED],
            Capability::AUTOMATIC_PIX->name =>            [self::SUPPORTED,      self::SUPPORTED],
            Capability::MULTIPLE_PAYMENT_METHODS->name => [self::SUPPORTED,      self::NOT_IMPLEMENTED],
            Capability::RAW_CARD_DATA->name =>            [self::SUPPORTED,      self::LIMITATION],
            Capability::CARD_SETUP_AUTHENTICATION->name => [self::LIMITATION,    self::SUPPORTED],
            Capability::INSTALLMENTS->name =>             [self::SUPPORTED,      self::LIMITATION],
            Capability::DELAYED_CAPTURE->name =>          [self::SUPPORTED,      self::SUPPORTED],
            Capability::PARTIAL_REFUND_CARD->name =>      [self::SUPPORTED,      self::SUPPORTED],
            Capability::PARTIAL_REFUND_PIX->name =>       [self::LIMITATION,     self::SUPPORTED],
            Capability::REFUND_BANK_SLIP->name =>         [self::LIMITATION,     self::LIMITATION],
            Capability::INVOICE_DUPLICATION->name =>      [self::SUPPORTED,      self::SUPPORTED],
            Capability::INVOICE_CANCELLATION->name =>     [self::SUPPORTED,      self::SUPPORTED],
            Capability::IDEMPOTENCY->name =>              [self::SUPPORTED,      self::SUPPORTED],
            Capability::IDEMPOTENCY_ALL_ENDPOINTS->name => [self::LIMITATION,    self::SUPPORTED],
            Capability::SUBSCRIPTIONS->name =>            [self::SUPPORTED,      self::SUPPORTED],
            Capability::PLANS->name =>                    [self::SUPPORTED,      self::SUPPORTED],
            Capability::PLAN_DEACTIVATION->name =>        [self::LIMITATION,     self::SUPPORTED],
            Capability::CANCEL_AT_PERIOD_END->name =>     [self::EMULATED,       self::SUPPORTED],
            Capability::COUPONS->name =>                  [self::EMULATED,       self::SUPPORTED],
            Capability::PERCENT_DISCOUNT->name =>         [self::LIMITATION,     self::SUPPORTED],
            Capability::PLAN_CHANGE_PRORATION->name =>    [self::LIMITATION,     self::SUPPORTED],
            Capability::SUBSCRIPTION_CREDITS->name =>     [self::NOT_IMPLEMENTED, self::LIMITATION],
            Capability::MANAGES_RECURRENCE->name =>       [self::LIMITATION,     self::SUPPORTED],
            Capability::GATEWAY_DUNNING->name =>          [self::LIMITATION,     self::SUPPORTED],
            Capability::WEBHOOKS->name =>                 [self::SUPPORTED,      self::SUPPORTED],
            Capability::DISPUTES->name =>                 [self::SUPPORTED,      self::SUPPORTED],
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
    public function testCapabilitiesNotYetImplementedAndEmulatedDoNotOverlap(string $gateway): void
    {
        $driver = self::driver($gateway);

        $overlap = array_filter(
            $driver->capabilities(),
            static fn (Capability $capability) => in_array($capability, $driver->notYetImplemented(), true)
                || in_array($capability, $driver->emulated(), true)
        );
        $emulatedOverlap = array_filter(
            $driver->emulated(),
            static fn (Capability $capability) => in_array($capability, $driver->notYetImplemented(), true)
        );

        $this->assertSame([], $overlap);
        $this->assertSame([], $emulatedOverlap);
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

    public function testTableHasOneRowPerCapabilityOneColumnPerGatewayAndARestrictionsColumn(): void
    {
        $table = CapabilitiesTable::markdown(['iugu' => self::driver('iugu'), 'stripe' => self::driver('stripe')]);
        $lines = explode("\n", trim($table));

        $this->assertSame('| Capability | Significado | Iugu | Stripe | Restrições |', $lines[0]);
        $this->assertSame('|---|---|---|---|---|', $lines[1]);
        $this->assertCount(count(Capability::cases()) + 2, $lines);
        $this->assertStringStartsWith('| `CREDIT_CARD` | Fatura paga com cartão de crédito. | sim | sim | Stripe: ', $lines[2]);
        $this->assertStringEndsWith('| sim | sim |  |', $lines[3], 'PIX não tem restrição em nenhum gateway');
    }

    /**
     * Cada restrição declarada aparece na coluna com o nome do gateway; capability sem restrição
     * deixa a célula vazia.
     */
    public function testRestrictionsCellListsEveryGatewayThatRestrictsTheCapability(): void
    {
        $gateways = ['iugu' => self::driver('iugu'), 'stripe' => self::driver('stripe')];

        $this->assertSame('', CapabilitiesTable::restrictionsCell($gateways, Capability::PIX));
        $this->assertStringStartsWith('Iugu: ', CapabilitiesTable::restrictionsCell($gateways, Capability::INSTALLMENTS));
        $this->assertStringStartsWith('Stripe: ', CapabilitiesTable::restrictionsCell($gateways, Capability::INVOICE_DUPLICATION));
    }

    #[DataProvider('restrictionProvider')]
    public function testDriverDeclaresTheExpectedRestriction(string $gateway, Capability $capability, ?array $expected): void
    {
        $restriction = self::driver($gateway)->restriction($capability);

        if (is_null($expected)) {
            $this->assertNull($restriction);

            return;
        }

        $this->assertNotNull($restriction);
        $this->assertNotSame('', $restriction->description);
        $this->assertSame($expected['payment_methods'] ?? null, $restriction->allowedPaymentMethods);
        $this->assertSame($expected['brands'] ?? null, $restriction->allowedBrands);
        $this->assertSame($expected['max_installments'] ?? null, $restriction->maxInstallments);
    }

    public static function restrictionProvider(): array
    {
        return [
            'iugu parcelamento' => ['iugu', Capability::INSTALLMENTS, ['max_installments' => 12]],
            'iugu cartão sem restrição' => ['iugu', Capability::CREDIT_CARD, null],
            'iugu duplicação sem restrição' => ['iugu', Capability::INVOICE_DUPLICATION, null],
            'stripe bandeiras' => ['stripe', Capability::CREDIT_CARD, ['brands' => ['visa', 'mastercard']]],
            'stripe duplicação só pix' => ['stripe', Capability::INVOICE_DUPLICATION, ['payment_methods' => [PaymentMethod::PIX]]],
            'stripe cancelamento de rascunho' => ['stripe', Capability::INVOICE_CANCELLATION, []],
            'stripe nextBillingAt só na criação' => ['stripe', Capability::SUBSCRIPTIONS, []],
            'stripe cupom dura meses inteiros' => ['stripe', Capability::COUPONS, []],
            'iugu cupom sem restrição' => ['iugu', Capability::COUPONS, null],
            'stripe pix sem restrição' => ['stripe', Capability::PIX, null],
        ];
    }

    /**
     * Uma restrição só faz sentido sobre uma capability suportada: célula "não implementado" ou
     * "limitação do gateway" não pode ter restrição.
     */
    #[DataProvider('driverProvider')]
    public function testRestrictionsOnlyCoverSupportedCapabilities(string $gateway): void
    {
        $driver = self::driver($gateway);

        foreach ($driver->restrictions() as $value => $restriction) {
            $capability = Capability::from($value);
            $this->assertTrue($driver->supports($capability), "{$gateway} restringe {$capability->name} sem suportá-la");
            $this->assertEquals($restriction, $driver->restriction($capability));
        }
    }

    /**
     * O máximo de parcelas da Iugu vem da configuração da conta, com 12 como padrão.
     */
    public function testIuguMaxInstallmentsComesFromTheConfiguration(): void
    {
        Facade::getFacadeApplication()['config']->set('multi-payment.gateways.iugu.max_installments', 6);

        $restriction = self::driver('iugu')->restriction(Capability::INSTALLMENTS);

        $this->assertSame(6, $restriction->maxInstallments);
        $this->assertStringContainsString('até 6', $restriction->description);
    }

    #[DataProvider('driverProvider')]
    public function testSupportsAllRequiresEveryCapability(string $gateway): void
    {
        $driver = self::driver($gateway);

        $this->assertTrue($driver->supportsAll());
        $this->assertTrue($driver->supportsAll(Capability::CREDIT_CARD, Capability::PIX));
        $this->assertFalse($driver->supportsAll(Capability::CREDIT_CARD, Capability::REFUND_BANK_SLIP));
    }

    /**
     * A fachada expõe `supportsAll()`, `restriction()` e `restrictions()` do gateway.
     */
    public function testTheFacadeExposesSupportsAllAndTheRestrictions(): void
    {
        Facade::getFacadeApplication()['config']->set('multi-payment.gateways.iugu.class', IuguGateway::class);
        Facade::getFacadeApplication()['config']->set('multi-payment.gateways.stripe.class', StripeGateway::class);

        $payment = new MultiPayment('stripe');

        $this->assertTrue($payment->supportsAll(Capability::CREDIT_CARD, Capability::PIX));
        $this->assertFalse($payment->supportsAll(Capability::CREDIT_CARD, Capability::MULTIPLE_PAYMENT_METHODS));
        $this->assertSame(['visa', 'mastercard'], $payment->restriction(Capability::CREDIT_CARD)->allowedBrands);
        $this->assertNull($payment->restriction(Capability::PIX));
        $this->assertSame(12, $payment->restriction(Capability::INSTALLMENTS, 'iugu')->maxInstallments);
        $this->assertArrayHasKey(Capability::INVOICE_DUPLICATION->value, $payment->restrictions());
        $this->assertArrayHasKey(Capability::INSTALLMENTS->value, $payment->restrictions('iugu'));
    }

    /**
     * A fachada expõe `emulated()` e `isEmulated()` do gateway, e o nome antigo
     * `Capability::NATIVE_COUPONS` resolve para o mesmo caso `COUPONS`.
     */
    public function testTheFacadeExposesTheEmulatedListAndTheLegacyCouponsName(): void
    {
        Facade::getFacadeApplication()['config']->set('multi-payment.gateways.iugu.class', IuguGateway::class);
        Facade::getFacadeApplication()['config']->set('multi-payment.gateways.stripe.class', StripeGateway::class);

        $payment = new MultiPayment('stripe');

        $this->assertSame([], $payment->emulated());
        $this->assertFalse($payment->isEmulated(Capability::COUPONS));
        $this->assertSame(
            [Capability::COUPONS, Capability::CANCEL_AT_PERIOD_END],
            $payment->emulated('iugu')
        );
        $this->assertTrue($payment->isEmulated(Capability::CANCEL_AT_PERIOD_END, 'iugu'));
        $this->assertTrue($payment->supports(Capability::COUPONS, 'iugu'));
        $this->assertSame(Capability::COUPONS, Capability::NATIVE_COUPONS);
    }

    private static function driver(string $gateway): GatewayContract&DeclaresCapabilities
    {
        return match ($gateway) {
            'iugu' => new IuguGateway(new QueuedIuguApiRequest([])),
            'stripe' => new StripeGateway(),
        };
    }
}
