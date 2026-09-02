<?php

namespace Potelo\MultiPayment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Plan;
use Potelo\MultiPayment\Models\Refund;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\InvoiceItem;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * `Model::fill()` estrito: chave desconhecida lança `ModelAttributeValidationException` com as
 * chaves aceitas, chave com prefixo `gateway_` continua livre e `multi-payment.strict_fill`
 * em falso volta ao comportamento permissivo.
 */
class ModelFillTest extends TestCase
{
    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testUnknownKeyIsRejectedWithTheListOfAcceptedKeys(): void
    {
        $this->configure(['multi-payment.strict_fill' => true]);
        $customer = new Customer();

        try {
            $customer->fill(['name' => 'Ana', 'nome_fantasia' => 'Loja']);
            $this->fail('Chave desconhecida deveria lançar ModelAttributeValidationException');
        } catch (ModelAttributeValidationException $e) {
            $this->assertStringContainsString('`nome_fantasia` key is unknown for the `Customer` model', $e->getMessage());
            $this->assertStringContainsString('name, email, tax_document', $e->getMessage());
            $this->assertStringContainsString('gateway_options', $e->getMessage());
        }
        // as chaves anteriores à desconhecida já foram aplicadas
        $this->assertSame('Ana', $customer->name);
    }

    public function testStrictIsTheDefaultWithoutTheConfigurationKey(): void
    {
        $this->configure([]);

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessage('`trial_days` key is unknown for the `Invoice` model');

        (new Invoice())->fill(['amount' => 10000, 'trial_days' => 7]);
    }

    public function testStrictIsTheDefaultWithoutALaravelContainer(): void
    {
        Facade::setFacadeApplication(null);

        $this->expectException(ModelAttributeValidationException::class);

        (new Customer())->fill(['nome' => 'Ana']);
    }

    public function testPermissiveModeIgnoresUnknownKeysSilently(): void
    {
        $this->configure(['multi-payment.strict_fill' => false]);
        $customer = new Customer();

        $customer->fill(['name' => 'Ana', 'nome_fantasia' => 'Loja']);

        $this->assertSame('Ana', $customer->name);
        $this->assertFalse(property_exists($customer, 'nomeFantasia'));
    }

    public function testKeysWithTheGatewayPrefixStayFree(): void
    {
        $this->configure(['multi-payment.strict_fill' => true]);
        $customer = new Customer();

        $customer->fill(['name' => 'Ana', 'gateway_options' => ['x' => 1], 'gateway_customer_ref' => 'abc', 'gatewayOtherRef' => 'def']);

        $this->assertSame(['x' => 1], $customer->gatewayOptions);
        $this->assertFalse(property_exists($customer, 'gatewayCustomerRef'));
        $this->assertFalse(property_exists($customer, 'gatewayOtherRef'));
    }

    public function testStrictIsTheDefaultWithAContainerWithoutConfig(): void
    {
        Facade::setFacadeApplication(new Container());

        $this->expectException(ModelAttributeValidationException::class);

        (new Customer())->fill(['nome' => 'Ana']);
    }

    public function testGatewayOptionsContentIsFree(): void
    {
        $this->configure(['multi-payment.strict_fill' => true]);
        $invoice = new Invoice();

        $invoice->fill(['amount' => 10000, 'gateway_options' => ['qualquer_chave' => 'vale', 'expires_in' => 3]]);

        $this->assertSame(['qualquer_chave' => 'vale', 'expires_in' => 3], $invoice->gatewayOptions);
    }

    public function testCamelCaseKeysAreAccepted(): void
    {
        $this->configure(['multi-payment.strict_fill' => true]);
        $customer = new Customer();

        $customer->fill(['taxDocument' => '20176996915', 'phoneArea' => '71']);

        $this->assertSame('20176996915', $customer->taxDocument);
        $this->assertSame('71', $customer->phoneArea);
    }

    public function testNestedModelsAreStrictToo(): void
    {
        $this->configure(['multi-payment.strict_fill' => true]);

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessage('`nome` key is unknown for the `Customer` model');

        (new Invoice())->fill(['amount' => 10000, 'customer' => ['nome' => 'Ana']]);
    }

    /**
     * As chaves que os `fill()` especializados consomem antes do `Model` (`items`, `customer`,
     * `expires_at`, `credit_card`, datas da assinatura) continuam aceitas.
     */
    public function testKeysConsumedBySpecializedFillsAreStillAccepted(): void
    {
        $this->configure(['multi-payment.strict_fill' => true]);

        $invoice = new Invoice();
        $invoice->fill([
            'items' => [['description' => 'Item', 'price' => 1000, 'quantity' => 1]],
            'customer' => ['name' => 'Ana', 'email' => 'ana@example.com', 'address' => ['zip_code' => '41820330']],
            'expires_at' => '2026-10-01',
            'credit_card' => ['token' => 'pm_x', 'first_name' => 'Ana'],
            'available_payment_methods' => ['credit_card'],
            'origin_type' => 'invoice',
        ]);
        $this->assertSame('41820330', $invoice->customer->address->zipCode);
        $this->assertSame('pm_x', $invoice->creditCard->token);

        $subscription = new Subscription();
        $subscription->fill([
            'plan_id' => 'plano',
            'trial_ends_at' => '2026-10-01',
            'next_billing_at' => '2026-11-01',
            'customer' => ['id' => 'cus_1'],
            'latest_invoice' => ['id' => 'inv_1', 'status' => 'paid'],
            'items' => [['description' => 'Extra', 'amount' => 500]],
        ]);
        $this->assertSame('inv_1', $subscription->latestInvoice->id);

        $card = new CreditCard();
        $card->fill(['token' => 'tok_x', 'customer' => ['id' => 'cus_1'], 'default' => true]);
        $this->assertTrue($card->default);
    }

    public function testFillableKeysAreTheSnakeCasePropertiesIncludingEnums(): void
    {
        $this->assertSame(['description', 'price', 'quantity', 'gateway_options'], InvoiceItem::fillableKeys());

        $keys = Invoice::fillableKeys();
        foreach (['id', 'status', 'amount', 'payment_method', 'available_payment_methods', 'origin_type', 'credit_card', 'expires_at', 'gateway_options'] as $key) {
            $this->assertContains($key, $keys);
        }
        $this->assertContains('interval', Plan::fillableKeys());
        $this->assertContains('status', Refund::fillableKeys());
    }

    private function configure(array $config): void
    {
        $app = new Container();
        $app->instance('config', new Repository($config));
        Facade::setFacadeApplication($app);
    }
}
