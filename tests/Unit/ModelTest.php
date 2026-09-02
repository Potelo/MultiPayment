<?php

namespace Potelo\MultiPayment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Builders\InvoiceBuilder;
use Potelo\MultiPayment\Gateways\StripeGateway;

/**
 * Cobre o alias gatewayOptions / gatewayAdicionalOptions do Model e do Builder, sem rede.
 */
class ModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
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

    public function testGatewayOptionsStartsEmptyAndIsAPublicArray(): void
    {
        $invoice = new Invoice();

        $this->assertSame([], $invoice->gatewayOptions);
        $this->assertTrue(property_exists($invoice, 'gatewayOptions'));
        $this->assertFalse(property_exists($invoice, 'gatewayAdicionalOptions'));
    }

    #[IgnoreDeprecations]
    public function testWritingTheDeprecatedNameFillsGatewayOptionsWithADeprecationNotice(): void
    {
        $invoice = new Invoice();

        $this->expectUserDeprecationMessage('Model::$gatewayAdicionalOptions está obsoleto desde 2026-09-02; use $gatewayOptions');

        $invoice->gatewayAdicionalOptions = ['expires_in' => 3];

        $this->assertSame(['expires_in' => 3], $invoice->gatewayOptions);
    }

    #[IgnoreDeprecations]
    public function testReadingTheDeprecatedNameReturnsGatewayOptionsWithADeprecationNotice(): void
    {
        $invoice = new Invoice();
        $invoice->gatewayOptions = ['idempotency_key' => 'abc'];

        $this->expectUserDeprecationMessage('Model::$gatewayAdicionalOptions está obsoleto desde 2026-09-02; use $gatewayOptions');

        $this->assertSame(['idempotency_key' => 'abc'], $invoice->gatewayAdicionalOptions);
    }

    #[IgnoreDeprecations]
    public function testIndirectModificationThroughTheDeprecatedNameStillAltersTheArray(): void
    {
        $invoice = new Invoice();
        $invoice->gatewayOptions = ['a' => 1];

        $this->expectUserDeprecationMessage('Model::$gatewayAdicionalOptions está obsoleto desde 2026-09-02; use $gatewayOptions');

        $invoice->gatewayAdicionalOptions['b'] = 2;

        $this->assertSame(['a' => 1, 'b' => 2], $invoice->gatewayOptions);
    }

    #[IgnoreDeprecations]
    public function testIssetAndEmptyWorkOnTheDeprecatedName(): void
    {
        $invoice = new Invoice();

        // isset() só passa pelo __isset, sem aviso; empty() também lê o valor pelo __get e avisa
        $this->assertTrue(isset($invoice->gatewayAdicionalOptions));
        $this->assertFalse(isset($invoice->propriedadeInexistente));

        $this->expectUserDeprecationMessage('Model::$gatewayAdicionalOptions está obsoleto desde 2026-09-02; use $gatewayOptions');

        $this->assertTrue(empty($invoice->gatewayAdicionalOptions));

        $invoice->gatewayOptions = ['a' => 1];

        $this->assertFalse(empty($invoice->gatewayAdicionalOptions));
    }

    public function testFillAcceptsBothSnakeCaseKeys(): void
    {
        $novo = new Customer();
        $novo->fill(['name' => 'Ana', 'gateway_options' => ['x' => 1]]);
        $this->assertSame(['x' => 1], $novo->gatewayOptions);

        $antigo = new Customer();
        @$antigo->fill(['name' => 'Ana', 'gateway_adicional_options' => ['y' => 2]]);
        $this->assertSame(['y' => 2], $antigo->gatewayOptions);
    }

    #[IgnoreDeprecations]
    public function testFillWithTheDeprecatedKeyTriggersADeprecationNotice(): void
    {
        $this->expectUserDeprecationMessage('Model::$gatewayAdicionalOptions está obsoleto desde 2026-09-02; use $gatewayOptions');

        (new Customer())->fill(['gateway_adicional_options' => ['y' => 2]]);
    }

    public function testToArrayExposesGatewayOptionsUnderTheNewKeyOnly(): void
    {
        $customer = new Customer();
        $customer->name = 'Ana';
        $customer->gatewayOptions = ['x' => 1];

        $array = $customer->toArray();

        $this->assertSame(['x' => 1], $array['gateway_options']);
        $this->assertArrayNotHasKey('gateway_adicional_options', $array);
    }

    public function testBuilderSetGatewayOptionsFillsTheModel(): void
    {
        $builder = new InvoiceBuilder(new StripeGateway());

        $this->assertSame($builder, $builder->setGatewayOptions(['expand' => ['customer']]));
        $this->assertSame(['expand' => ['customer']], $builder->get()->gatewayOptions);
    }

    #[IgnoreDeprecations]
    public function testBuilderDeprecatedSetterStillWorksWithADeprecationNotice(): void
    {
        $builder = new InvoiceBuilder(new StripeGateway());

        $this->expectUserDeprecationMessage('Builder::setGatewayAdicionalOptions() está obsoleto desde 2026-09-02; use setGatewayOptions()');

        $builder->setGatewayAdicionalOptions(['expand' => ['customer']]);

        $this->assertSame(['expand' => ['customer']], $builder->get()->gatewayOptions);
    }
}
