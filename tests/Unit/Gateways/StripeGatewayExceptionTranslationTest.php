<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Stripe\ApiRequestor;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Stripe\Exception\CardException;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\InvoiceItem;
use Stripe\Exception\RateLimitException;
use Stripe\Exception\PermissionException;
use PHPUnit\Framework\Attributes\DataProvider;
use Potelo\MultiPayment\Gateways\StripeGateway;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\UnknownApiErrorException;
use Stripe\Exception\UnexpectedValueException as StripeUnexpectedValueException;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\ChargingException;
use Potelo\MultiPayment\Exceptions\AuthenticationException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;
use Stripe\Exception\AuthenticationException as StripeAuthenticationException;

/**
 * Cobre a tradução de falhas do stripe-php para as exceções do pacote: classe escolhida pelo
 * status HTTP, exceção original em `getPrevious()` e status em `httpStatus`.
 */
class StripeGatewayExceptionTranslationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment.gateways.stripe.api_key' => 'sk_test_fake',
        ]));
        Facade::setFacadeApplication($app);

        RecordingStripeHttpClient::withResponses([]);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testInvalidApiKeyBecomesAuthenticationExceptionWithPrevious(): void
    {
        RecordingStripeHttpClient::withResponses([
            [['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided']], 401],
        ]);

        try {
            (new StripeGateway())->getCustomer($this->customerWithId());
            $this->fail('Esperava AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertNotInstanceOf(GatewayNotAvailableException::class, $e);
            $this->assertInstanceOf(StripeAuthenticationException::class, $e->getPrevious());
            $this->assertSame(401, $e->httpStatus);
            $this->assertStringContainsString('stripe', $e->getMessage());
            $this->assertStringContainsString('Invalid API Key provided', $e->getMessage());
        }
    }

    public function testKeyWithoutPermissionBecomesAuthenticationException(): void
    {
        RecordingStripeHttpClient::withResponses([
            [['error' => ['type' => 'invalid_request_error', 'message' => 'This API key does not have permission']], 403],
        ]);

        try {
            (new StripeGateway())->getCustomer($this->customerWithId());
            $this->fail('Esperava AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertInstanceOf(PermissionException::class, $e->getPrevious());
            $this->assertSame(403, $e->httpStatus);
        }
    }

    #[DataProvider('serverErrorStatusProvider')]
    public function testServerErrorsBecomeGatewayNotAvailableExceptionWithStatus(int $status): void
    {
        RecordingStripeHttpClient::withResponses([
            [['error' => ['type' => 'api_error', 'message' => 'Something went wrong']], $status],
        ]);

        try {
            (new StripeGateway())->getCustomer($this->customerWithId());
            $this->fail('Esperava GatewayNotAvailableException');
        } catch (GatewayNotAvailableException $e) {
            $this->assertInstanceOf(UnknownApiErrorException::class, $e->getPrevious());
            $this->assertSame($status, $e->httpStatus);
        }
    }

    public static function serverErrorStatusProvider(): array
    {
        return ['500' => [500], '502' => [502], '503' => [503]];
    }

    public function testServerErrorWithHtmlBodyBecomesGatewayNotAvailableExceptionWithStatus(): void
    {
        // o stripe-php não consegue decodificar a página do proxy e lança
        // UnexpectedValueException com o status em getCode()
        RecordingStripeHttpClient::withResponses([['<html>502 Bad Gateway</html>', 502]]);

        try {
            (new StripeGateway())->getCustomer($this->customerWithId());
            $this->fail('Esperava GatewayNotAvailableException');
        } catch (GatewayNotAvailableException $e) {
            $this->assertInstanceOf(StripeUnexpectedValueException::class, $e->getPrevious());
            $this->assertSame(502, $e->httpStatus);
        }
    }

    public function testClientErrorWithHtmlBodyStaysGatewayExceptionWithStatus(): void
    {
        RecordingStripeHttpClient::withResponses([['<html>404 Not Found</html>', 404]]);

        try {
            (new StripeGateway())->getCustomer($this->customerWithId());
            $this->fail('Esperava GatewayException');
        } catch (GatewayException $e) {
            $this->assertNotInstanceOf(GatewayNotAvailableException::class, $e);
            $this->assertSame(404, $e->httpStatus);
        }
    }

    public function testConnectionFailureBecomesGatewayNotAvailableExceptionWithoutStatus(): void
    {
        $original = new ApiConnectionException('Could not connect to Stripe (https://api.stripe.com)');
        RecordingStripeHttpClient::withResponses([$original]);

        try {
            (new StripeGateway())->getCustomer($this->customerWithId());
            $this->fail('Esperava GatewayNotAvailableException');
        } catch (GatewayNotAvailableException $e) {
            $this->assertSame($original, $e->getPrevious());
            $this->assertNull($e->httpStatus);
        }
    }

    public function testRateLimitStaysGatewayExceptionWithStatusExposed(): void
    {
        RecordingStripeHttpClient::withResponses([
            [['error' => ['type' => 'rate_limit_error', 'message' => 'Too many requests']], 429],
        ]);

        try {
            (new StripeGateway())->getCustomer($this->customerWithId());
            $this->fail('Esperava GatewayException');
        } catch (GatewayException $e) {
            $this->assertNotInstanceOf(GatewayNotAvailableException::class, $e);
            $this->assertNotInstanceOf(AuthenticationException::class, $e);
            $this->assertInstanceOf(RateLimitException::class, $e->getPrevious());
            $this->assertSame(429, $e->httpStatus);
            $this->assertSame('rate_limit_error', $e->getErrors()['type']);
        }
    }

    public function testInvalidRequestStaysGatewayExceptionWithNormalizedErrorsAndPrevious(): void
    {
        RecordingStripeHttpClient::withResponses([
            [['error' => [
                'type' => 'invalid_request_error',
                'code' => 'resource_missing',
                'param' => 'customer',
                'message' => 'No such customer',
            ]], 404],
        ]);

        try {
            (new StripeGateway())->getCustomer($this->customerWithId());
            $this->fail('Esperava GatewayException');
        } catch (GatewayException $e) {
            $this->assertInstanceOf(InvalidRequestException::class, $e->getPrevious());
            $this->assertSame(404, $e->httpStatus);
            $this->assertSame([
                'type' => 'invalid_request_error',
                'code' => 'resource_missing',
                'param' => 'customer',
            ], $e->getErrors());
        }
    }

    public function testCardDeclineAttachesTheCardExceptionAndItsStatus(): void
    {
        RecordingStripeHttpClient::withResponses([
            [['error' => [
                'type' => 'card_error',
                'code' => 'card_declined',
                'decline_code' => 'generic_decline',
                'message' => 'Your card was declined.',
            ]], 402],
        ]);

        try {
            (new StripeGateway())->createInvoice($this->creditCardInvoiceModel());
            $this->fail('Esperava ChargingException');
        } catch (ChargingException $e) {
            $this->assertInstanceOf(CardException::class, $e->getPrevious());
            $this->assertSame(402, $e->httpStatus);
            $this->assertSame('card_declined', $e->reason);
        }
    }

    public function testCardDeclineDuringAttachKeepsTheWholeExceptionChain(): void
    {
        RecordingStripeHttpClient::withResponses([
            [['error' => [
                'type' => 'card_error',
                'code' => 'card_declined',
                'decline_code' => 'generic_decline',
                'message' => 'Your card was declined.',
            ]], 402],
        ]);

        $invoice = $this->creditCardInvoiceModel();
        $invoice->creditCard = new CreditCard();
        $invoice->creditCard->token = 'pm_fake123';

        try {
            (new StripeGateway())->createInvoice($invoice);
            $this->fail('Esperava ChargingException');
        } catch (ChargingException $e) {
            $this->assertSame(402, $e->httpStatus);
            $this->assertInstanceOf(GatewayException::class, $e->getPrevious());
            $this->assertInstanceOf(CardException::class, $e->getPrevious()->getPrevious());
        }
    }

    public function testUnexpectedExceptionInsideTheSdkBecomesGatewayExceptionWithPrevious(): void
    {
        $original = new \RuntimeException('falha inesperada');
        RecordingStripeHttpClient::withResponses([$original]);

        try {
            (new StripeGateway())->getCustomer($this->customerWithId());
            $this->fail('Esperava GatewayException');
        } catch (GatewayException $e) {
            $this->assertSame($original, $e->getPrevious());
            $this->assertNull($e->httpStatus);
        }
    }

    private function customerWithId(): Customer
    {
        $customer = new Customer();
        $customer->id = 'cus_fake123';

        return $customer;
    }

    private function creditCardInvoiceModel(): Invoice
    {
        $invoice = new Invoice();
        $invoice->customer = new Customer();
        $invoice->customer->id = 'cus_fake123';
        $invoice->availablePaymentMethods = [Invoice::PAYMENT_METHOD_CREDIT_CARD];
        $invoice->creditCard = new CreditCard();
        $invoice->creditCard->id = 'pm_fake123';
        $item = new InvoiceItem();
        $item->description = 'Assinatura mensal';
        $item->price = 12345;
        $item->quantity = 1;
        $invoice->items = [$item];

        return $invoice;
    }
}
