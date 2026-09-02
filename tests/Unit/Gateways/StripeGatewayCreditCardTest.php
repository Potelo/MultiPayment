<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Carbon\Carbon;
use Stripe\ApiRequestor;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Gateways\StripeGateway;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

class StripeGatewayCreditCardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment.gateways.stripe.api_key' => 'sk_test_fake',
        ]));
        Facade::setFacadeApplication($app);

        // fake vazio por padrão: teste que esquecer withResponses() estoura em vez de ir à rede
        RecordingStripeHttpClient::withResponses([]);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testCreateCreditCardRejectsRawCardData(): void
    {
        $creditCard = $this->creditCardModel();
        $creditCard->token = null;
        $creditCard->number = '4111111111111111';
        $creditCard->month = '12';
        $creditCard->year = '2030';
        $creditCard->cvv = '123';

        $httpClient = RecordingStripeHttpClient::withResponses([]);

        try {
            (new StripeGateway())->createCreditCard($creditCard);
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::RAW_CARD_DATA, $e->capability);
            $this->assertSame(UnsupportedOperationException::REASON_GATEWAY_LIMITATION, $e->reason);
            $this->assertStringContainsString('Stripe.js', $e->getMessage());
        }
        $this->assertSame([], $httpClient->calls);
    }

    public function testCreateCreditCardRequiresCustomer(): void
    {
        $creditCard = new CreditCard();
        $creditCard->token = 'pm_fake123';

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessage('customer');

        (new StripeGateway())->createCreditCard($creditCard);
    }

    public function testCreateCreditCardAttachesTokenizedPaymentMethod(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->paymentMethodResponse()]);

        $result = (new StripeGateway())->createCreditCard($this->creditCardModel());

        $this->assertCount(1, $httpClient->calls);
        [$method, $url, $params] = $httpClient->calls[0];
        $this->assertSame('post', $method);
        $this->assertSame('/v1/payment_methods/pm_fake123/attach', parse_url($url, PHP_URL_PATH));
        $this->assertSame(['customer' => 'cus_fake123'], $params);

        $this->assertSame('pm_fake123', $result->id);
        $this->assertSame('visa', $result->brand);
        $this->assertSame('4242', $result->lastDigits);
        $this->assertSame('08', $result->month);
        $this->assertSame('2027', $result->year);
        $this->assertSame('Faker', $result->firstName);
        $this->assertSame('Teste', $result->lastName);
        $this->assertSame('stripe', $result->gateway);
        $this->assertInstanceOf(Carbon::class, $result->createdAt);
    }

    public function testCreateDefaultCreditCardWithDescriptionIssuesExtraUpdates(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            $this->paymentMethodResponse(),
            $this->paymentMethodResponse(metadata: ['description' => 'cartão principal']),
            $this->stripeCustomerResponse(),
        ]);

        $creditCard = $this->creditCardModel();
        $creditCard->description = 'cartão principal';
        $creditCard->default = true;
        $result = (new StripeGateway())->createCreditCard($creditCard);

        $paths = array_map(static fn ($call) => $call[0] . ' ' . parse_url($call[1], PHP_URL_PATH), $httpClient->calls);
        $this->assertSame([
            'post /v1/payment_methods/pm_fake123/attach',
            'post /v1/payment_methods/pm_fake123',
            'post /v1/customers/cus_fake123',
        ], $paths);
        $this->assertSame(['metadata' => ['description' => 'cartão principal']], $httpClient->calls[1][2]);
        $this->assertSame(
            ['invoice_settings' => ['default_payment_method' => 'pm_fake123']],
            $httpClient->calls[2][2]
        );
        $this->assertSame('cartão principal', $result->description);
    }

    public function testCreateCreditCardConvertsLegacyTokenIntoPaymentMethod(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            $this->paymentMethodResponse(),
            $this->paymentMethodResponse(),
        ]);

        $creditCard = $this->creditCardModel();
        $creditCard->token = 'tok_fake123';
        (new StripeGateway())->createCreditCard($creditCard);

        $paths = array_map(static fn ($call) => $call[0] . ' ' . parse_url($call[1], PHP_URL_PATH), $httpClient->calls);
        $this->assertSame([
            'post /v1/payment_methods',
            'post /v1/payment_methods/pm_fake123/attach',
        ], $paths);
        $this->assertSame(['type' => 'card', 'card' => ['token' => 'tok_fake123']], $httpClient->calls[0][2]);
    }

    public function testGetCreditCardValidatesOwnershipWhenCustomerIsInformed(): void
    {
        RecordingStripeHttpClient::withResponses([
            $this->paymentMethodResponse(customer: 'cus_other'),
        ]);

        $creditCard = $this->creditCardModel();
        $creditCard->id = 'pm_fake123';

        try {
            (new StripeGateway())->getCreditCard($creditCard);
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertStringContainsString('does not belong to customer', $e->getMessage());
            $this->assertSame(Capability::CREDIT_CARD, $e->capability);
            $this->assertSame(UnsupportedOperationException::REASON_GATEWAY_LIMITATION, $e->reason);
        }
    }

    public function testGetCreditCardSkipsOwnershipCheckWhenCustomerOmitted(): void
    {
        RecordingStripeHttpClient::withResponses([
            $this->paymentMethodResponse(customer: 'cus_other'),
        ]);

        $creditCard = new CreditCard();
        $creditCard->id = 'pm_fake123';
        $result = (new StripeGateway())->getCreditCard($creditCard);

        $this->assertSame('pm_fake123', $result->id);
        $this->assertSame('4242', $result->lastDigits);
    }

    public function testDeleteCreditCardDetachesThePaymentMethod(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            $this->paymentMethodResponse(customer: 'cus_fake123'),
            $this->paymentMethodResponse(),
        ]);

        $creditCard = $this->creditCardModel();
        $creditCard->id = 'pm_fake123';
        (new StripeGateway())->deleteCreditCard($creditCard);

        $paths = array_map(static fn ($call) => $call[0] . ' ' . parse_url($call[1], PHP_URL_PATH), $httpClient->calls);
        $this->assertSame([
            'get /v1/payment_methods/pm_fake123',
            'post /v1/payment_methods/pm_fake123/detach',
        ], $paths);
    }

    private function creditCardModel(): CreditCard
    {
        $creditCard = new CreditCard();
        $creditCard->token = 'pm_fake123';
        $creditCard->customer = new Customer();
        $creditCard->customer->id = 'cus_fake123';

        return $creditCard;
    }

    private function paymentMethodResponse(?string $customer = null, array $metadata = []): array
    {
        return [
            'id' => 'pm_fake123',
            'object' => 'payment_method',
            'type' => 'card',
            'customer' => $customer,
            'created' => 1786700000,
            'billing_details' => ['name' => 'Faker Teste'],
            'metadata' => $metadata,
            'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 8, 'exp_year' => 2027],
        ];
    }

    private function stripeCustomerResponse(): array
    {
        return [
            'id' => 'cus_fake123',
            'object' => 'customer',
            'invoice_settings' => ['default_payment_method' => 'pm_fake123'],
        ];
    }
}
