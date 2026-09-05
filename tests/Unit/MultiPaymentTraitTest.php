<?php

namespace Potelo\MultiPayment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\MultiPayment;
use Potelo\MultiPayment\Facades\MultiPayment as MultiPaymentFacade;
use Potelo\MultiPayment\Gateways\IuguGateway;
use Potelo\MultiPayment\Traits\MultiPaymentTrait;
use Potelo\MultiPayment\Enums\InvoiceStatus;

/**
 * `MultiPaymentTrait`: `setCustomerId()` só escreve a coluna, `persistCustomerId()` escreve e
 * salva, e `charge()` persiste o id do cliente novo criado junto com a fatura.
 */
class MultiPaymentTraitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment' => [
                'default' => 'iugu',
                'gateways' => [
                    'iugu' => ['api_key' => 'chave', 'customer_column' => 'iugu_id', 'class' => IuguGateway::class],
                    'iugu_b' => ['api_key' => 'chave-b', 'customer_column' => 'iugu_b_id', 'class' => IuguGateway::class],
                ],
            ],
        ]));
        $app->bind('multiPayment', fn () => new MultiPayment());
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    private function billable(): object
    {
        return new class {
            use MultiPaymentTrait;

            public ?string $iugu_id = null;
            public ?string $iugu_b_id = null;
            public int $saves = 0;

            public function save(): bool
            {
                $this->saves++;

                return true;
            }
        };
    }

    public function testSetCustomerIdWritesTheColumnWithoutSaving(): void
    {
        $billable = $this->billable();

        $billable->setCustomerId('iugu', 'cus_novo');

        $this->assertSame('cus_novo', $billable->iugu_id);
        $this->assertSame(0, $billable->saves);
    }

    public function testPersistCustomerIdWritesTheColumnAndSaves(): void
    {
        $billable = $this->billable();

        $billable->persistCustomerId('iugu', 'cus_novo');

        $this->assertSame('cus_novo', $billable->iugu_id);
        $this->assertSame(1, $billable->saves);
    }

    public function testChargePersistsTheCustomerIdCreatedWithTheInvoice(): void
    {
        MultiPaymentFacade::fake();
        $billable = $this->billable();

        $invoice = $billable->charge([
            'items' => [['description' => 'Mensalidade', 'price' => 10000, 'quantity' => 1]],
            'payment_method' => 'pix',
            'customer' => ['name' => 'Fulano', 'email' => 'fulano@exemplo.com', 'tax_document' => '20176996915'],
        ], 'iugu');

        $this->assertSame(InvoiceStatus::PENDING, $invoice->status);
        $this->assertSame($invoice->customer->id, $billable->iugu_id);
        $this->assertSame(1, $billable->saves);
    }

    /**
     * O nome de gateway do `charge()` escolhe a chave registrada: a cobrança por uma chave que
     * não é a default cria a fatura naquela conta e persiste o cliente na coluna dela.
     */
    public function testChargeHonorsTheGivenGatewayKey(): void
    {
        $fakes = MultiPaymentFacade::fake();
        $billable = $this->billable();

        $invoice = $billable->charge([
            'items' => [['description' => 'Mensalidade', 'price' => 10000, 'quantity' => 1]],
            'payment_method' => 'pix',
            'customer' => ['name' => 'Fulano', 'email' => 'fulano@exemplo.com', 'tax_document' => '20176996915'],
        ], 'iugu_b');

        $this->assertSame('iugu_b', $invoice->gateway);
        $this->assertCount(1, $fakes['iugu_b']->createdInvoices());
        $this->assertCount(0, $fakes['iugu']->createdInvoices());
        $this->assertSame($invoice->customer->id, $billable->iugu_b_id);
        $this->assertNull($billable->iugu_id);
    }

    public function testChargeWithAKnownCustomerIdDoesNotSaveAgain(): void
    {
        MultiPaymentFacade::fake();
        $billable = $this->billable();
        $billable->iugu_id = 'cus_existente';

        $invoice = $billable->charge([
            'items' => [['description' => 'Mensalidade', 'price' => 10000, 'quantity' => 1]],
            'payment_method' => 'pix',
            'customer' => ['name' => 'Fulano', 'email' => 'fulano@exemplo.com', 'tax_document' => '20176996915'],
        ], 'iugu');

        $this->assertSame('cus_existente', $invoice->customer->id);
        $this->assertSame(0, $billable->saves);
    }
}
