<?php

namespace Potelo\MultiPayment\Tests\Unit;

use Stripe\ApiRequestor;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\MultiPayment;
use Potelo\MultiPayment\Gateways\StripeGateway;
use Potelo\MultiPayment\Tests\Unit\Gateways\RecordingStripeHttpClient;

class MultiPaymentGatewayRoutingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment' => [
                // default proposital em iugu: o teste falharia se o gateway selecionado fosse ignorado
                'default' => 'iugu',
                'gateways' => [
                    'iugu' => ['api_key' => 'iugu-key', 'class' => \Potelo\MultiPayment\Gateways\IuguGateway::class],
                    'stripe' => ['api_key' => 'sk_test_fake', 'class' => StripeGateway::class],
                ],
            ],
        ]));
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testDuplicateInvoiceUsesTheSelectedGatewayInsteadOfTheDefault(): void
    {
        $pendingPix = [
            'id' => 'pi_fake123',
            'object' => 'payment_intent',
            'status' => 'requires_action',
            'amount' => 5000,
            'currency' => 'brl',
            'customer' => 'cus_fake123',
            'created' => 1786700000,
            'payment_method_types' => ['pix'],
            'next_action' => null,
            'metadata' => [],
            'latest_charge' => null,
        ];
        $customer = [
            'id' => 'cus_fake123',
            'object' => 'customer',
            'name' => 'Fake Customer',
            'email' => 'email@exemplo.com',
            'phone' => null,
            'address' => null,
            'metadata' => [],
            'created' => 1786700000,
            'invoice_settings' => ['default_payment_method' => null],
            'tax_ids' => ['object' => 'list', 'data' => [
                ['id' => 'txi_fake1', 'object' => 'tax_id', 'type' => 'br_cpf', 'value' => '20176996915'],
            ]],
        ];
        $newIntent = array_merge($pendingPix, ['id' => 'pi_fake456']);
        $canceled = array_merge($pendingPix, ['status' => 'canceled']);
        $httpClient = RecordingStripeHttpClient::withResponses([$pendingPix, $customer, $newIntent, $canceled]);

        $invoice = (new MultiPayment('stripe'))
            ->duplicateInvoice('pi_fake123', \Carbon\Carbon::now()->addDay());

        // antes do fix, o model resolveria o gateway default (iugu)
        $this->assertStringContainsString('api.stripe.com/v1/payment_intents/pi_fake123', $httpClient->calls[0][1]);
        $this->assertSame('pi_fake456', $invoice->id);
        $this->assertSame('stripe', $invoice->gateway);
    }

    public function testSetDefaultCardUsesTheSelectedGatewayInsteadOfTheDefault(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            [
                'id' => 'cus_fake123',
                'object' => 'customer',
                // campos lidos pelo parseCustomer: ausentes, o StripeObject emite aviso "Undefined property"
                'name' => null,
                'email' => null,
                'phone' => null,
                'address' => null,
                'metadata' => [],
                'created' => 1786700000,
                'invoice_settings' => ['default_payment_method' => 'pm_fake123'],
            ],
        ]);

        $customer = (new MultiPayment('stripe'))->setDefaultCard('cus_fake123', 'pm_fake123');

        // a chamada foi à API da Stripe — antes do fix, o model resolvia o gateway default (iugu)
        $this->assertCount(1, $httpClient->calls);
        $this->assertStringContainsString('api.stripe.com/v1/customers/cus_fake123', $httpClient->calls[0][1]);
        $this->assertSame('pm_fake123', $customer->defaultCard->id);
    }
}
