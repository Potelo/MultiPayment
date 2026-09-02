<?php

namespace Potelo\MultiPayment\Tests\Integration;

use Potelo\MultiPayment\Tests\TestCase;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Facades\MultiPayment;
use PHPUnit\Framework\Attributes\DataProvider;
use Potelo\MultiPayment\Contracts\IdempotencyStore;
use Potelo\MultiPayment\Exceptions\IdempotencyConflictException;

/**
 * Chave de idempotência contra as sandboxes: a mesma chave na criação de fatura devolve a mesma
 * fatura nos dois gateways (cabeçalho `Idempotency-Key` honrado pelo gateway), a mesma chave com
 * outro payload é conflito na Stripe, e na Iugu a `IdempotencyStore` da lib cobre o cancelamento.
 */
class IdempotencyTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        // a CacheIdempotencyStore registrada pelo provider precisa de um cache com lock
        $app['config']->set('cache.default', 'array');
    }

    #[DataProvider('gatewayProvider')]
    public function testCreatingAnInvoiceTwiceWithTheSameKeyReturnsTheSameInvoice(string $gateway): void
    {
        $customer = $this->createCustomer($gateway, self::customerWithoutAddress());
        $key = 'multipayment-idem-' . bin2hex(random_bytes(8));

        $first = $this->pixInvoiceBuilder($gateway, $customer->id)->withIdempotencyKey($key)->create();
        $second = $this->pixInvoiceBuilder($gateway, $customer->id)->withIdempotencyKey($key)->create();

        $this->assertNotEmpty($first->id);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(InvoiceStatus::PENDING, $second->status);
    }

    #[DataProvider('gatewayProvider')]
    public function testCreatingAnInvoiceTwiceWithoutAKeyCreatesTwoInvoices(string $gateway): void
    {
        $customer = $this->createCustomer($gateway, self::customerWithoutAddress());

        $first = $this->pixInvoiceBuilder($gateway, $customer->id)->create();
        $second = $this->pixInvoiceBuilder($gateway, $customer->id)->create();

        $this->assertNotSame($first->id, $second->id);
    }

    /**
     * Na Iugu o gateway responde 409 sem o id do cliente original (`resource_id: processing`),
     * então a segunda chamada lança em vez de devolver o cliente.
     */
    public function testIuguRejectsTheSameKeyOnCustomerCreation(): void
    {
        $key = 'multipayment-idem-' . bin2hex(random_bytes(8));
        $data = self::customerWithoutAddress();
        $data['email'] = "idem-{$key}@example.com";

        $first = MultiPayment::setGateway('iugu')->newCustomer()
            ->setName($data['name'])->setEmail($data['email'])->setTaxDocument($data['taxDocument'])
            ->withIdempotencyKey($key)
            ->create();
        $this->assertNotEmpty($first->id);

        try {
            MultiPayment::setGateway('iugu')->newCustomer()
                ->setName($data['name'])->setEmail($data['email'])->setTaxDocument($data['taxDocument'])
                ->withIdempotencyKey($key)
                ->create();
            $this->fail('Esperava IdempotencyConflictException');
        } catch (IdempotencyConflictException $e) {
            $this->assertSame(409, $e->httpStatus);
            $this->assertNull($e->resourceId);
        }
    }

    /**
     * O provider só existe para o `TestCase` reconhecer o gateway e pular a pausa da Iugu.
     */
    #[DataProvider('stripeProvider')]
    public function testStripeRejectsTheSameKeyWithAnotherPayload(string $gateway): void
    {
        $customer = $this->createCustomer('stripe', self::customerWithoutAddress());
        $key = 'multipayment-idem-' . bin2hex(random_bytes(8));

        $this->pixInvoiceBuilder('stripe', $customer->id)->withIdempotencyKey($key)->create();

        $this->expectException(IdempotencyConflictException::class);

        $this->pixInvoiceBuilder('stripe', $customer->id, 2000)->withIdempotencyKey($key)->create();
    }

    public function testIuguCancellationIsDeduplicatedByTheStore(): void
    {
        $customer = $this->createCustomer('iugu', self::customerWithoutAddress());
        $invoice = $this->pixInvoiceBuilder('iugu', $customer->id)->create();
        $key = 'multipayment-idem-' . bin2hex(random_bytes(8));

        $first = MultiPayment::setGateway('iugu')->cancelInvoice($invoice->id, $key);
        $second = MultiPayment::setGateway('iugu')->cancelInvoice($invoice->id, $key);

        $this->assertSame(InvoiceStatus::CANCELED, $first->status);
        $this->assertSame(InvoiceStatus::CANCELED, $second->status);
        $this->assertTrue($this->app->make(IdempotencyStore::class)->has('iugu:' . $key));
    }

    public static function gatewayProvider(): array
    {
        return ['iugu' => ['iugu'], 'stripe' => ['stripe']];
    }

    public static function stripeProvider(): array
    {
        return ['stripe' => ['stripe']];
    }

    private function pixInvoiceBuilder(string $gateway, string $customerId, int $amount = 1000): \Potelo\MultiPayment\Builders\InvoiceBuilder
    {
        return MultiPayment::setGateway($gateway)->newInvoice()
            ->addAvailablePaymentMethod(PaymentMethod::PIX)
            ->setCustomer($this->customerWithId($gateway, $customerId))
            ->addItem('Idempotency sandbox test', $amount, 1)
            // data fixa: uma expiração derivada do instante da chamada mudaria o payload entre
            // as tentativas, e a Stripe recusa a mesma chave com payload diferente
            ->setDueDate(now()->addDays(2)->startOfDay());
    }

    private function customerWithId(string $gateway, string $customerId): \Potelo\MultiPayment\Models\Customer
    {
        $customer = new \Potelo\MultiPayment\Models\Customer();
        $customer->id = $customerId;
        $customer->gateway = $gateway;
        $customer->name = 'Fake Customer';
        $customer->email = 'email@exemplo.com';
        $customer->taxDocument = '20176996915';

        return $customer;
    }
}
