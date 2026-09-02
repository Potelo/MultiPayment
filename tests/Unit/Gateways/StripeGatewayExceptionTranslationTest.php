<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Stripe\ApiRequestor;
use Stripe\Util\CaseInsensitiveArray;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Stripe\Exception\CardException;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\InvoiceItem;
use Stripe\Exception\RateLimitException as StripeRateLimitException;
use Stripe\Exception\PermissionException;
use Stripe\Exception\IdempotencyException;
use PHPUnit\Framework\Attributes\DataProvider;
use Potelo\MultiPayment\Gateways\StripeGateway;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\UnknownApiErrorException;
use Stripe\Exception\UnexpectedValueException as StripeUnexpectedValueException;
use Potelo\MultiPayment\Tests\Unit\RecordingLogger;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\ChargingException;
use Potelo\MultiPayment\Exceptions\NotFoundException;
use Potelo\MultiPayment\Exceptions\RateLimitException;
use Potelo\MultiPayment\Exceptions\ValidationException;
use Potelo\MultiPayment\Exceptions\CardDeclinedException;
use Potelo\MultiPayment\Exceptions\AuthenticationException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;
use Potelo\MultiPayment\Exceptions\IdempotencyConflictException;
use Stripe\Exception\AuthenticationException as StripeAuthenticationException;
use Potelo\MultiPayment\Enums\DeclineCode;
use Potelo\MultiPayment\Enums\PaymentMethod;

/**
 * Cobre a tradução de falhas do stripe-php para as exceções do pacote: classe escolhida pela
 * classe do SDK e pelo status HTTP, exceção original em `getPrevious()`, status em
 * `httpStatus` e recusa de cartão com `declineCode` normalizado.
 */
class StripeGatewayExceptionTranslationTest extends TestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new RecordingLogger();
        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment.gateways.stripe.api_key' => 'sk_test_fake',
        ]));
        $app->instance('log', $this->logger);
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
        // página HTML de proxy: sem corpo JSON não há `code` para ler, então só o 5xx é classificado
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

    public function testRateLimitBecomesRateLimitExceptionWithRetryAfterFromTheHeader(): void
    {
        RecordingStripeHttpClient::withResponses([
            [['error' => ['type' => 'rate_limit_error', 'message' => 'Too many requests']], 429, ['Retry-After' => '3']],
        ]);

        try {
            (new StripeGateway())->getCustomer($this->customerWithId());
            $this->fail('Esperava RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertInstanceOf(GatewayException::class, $e);
            $this->assertNotInstanceOf(GatewayNotAvailableException::class, $e);
            $this->assertInstanceOf(StripeRateLimitException::class, $e->getPrevious());
            $this->assertSame(429, $e->httpStatus);
            $this->assertSame(3, $e->retryAfter);
            $this->assertSame('rate_limit_error', $e->getErrors()['type']);
        }
    }

    public function testRetryAfterIsReadFromTheCaseInsensitiveHeadersOfTheSdk(): void
    {
        // o CurlClient do stripe-php entrega os cabeçalhos neste objeto, com as chaves em minúsculas
        RecordingStripeHttpClient::withResponses([
            [['error' => ['type' => 'rate_limit_error', 'message' => 'Too many requests']], 429,
                new CaseInsensitiveArray(['Retry-After' => '3'])],
        ]);

        try {
            (new StripeGateway())->getCustomer($this->customerWithId());
            $this->fail('Esperava RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(3, $e->retryAfter);
        }
    }

    #[DataProvider('retryAfterHeaderProvider')]
    public function testRetryAfterHeaderVariants(array $headers, ?int $expected): void
    {
        RecordingStripeHttpClient::withResponses([
            [['error' => ['type' => 'rate_limit_error', 'message' => 'Too many requests']], 429, $headers],
        ]);

        try {
            (new StripeGateway())->getCustomer($this->customerWithId());
            $this->fail('Esperava RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame($expected, $e->retryAfter);
        }
    }

    public static function retryAfterHeaderProvider(): array
    {
        return [
            'minúsculas (HTTP/2)' => [['retry-after' => '10'], 10],
            'valor em lista' => [['Retry-After' => ['5']], 5],
            'data HTTP' => [['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT'], null],
            'vazio' => [['Retry-After' => ''], null],
        ];
    }

    public function testRateLimitWithoutRetryAfterHeaderLeavesItNull(): void
    {
        RecordingStripeHttpClient::withResponses([
            [['error' => ['type' => 'rate_limit_error', 'message' => 'Too many requests']], 429],
        ]);

        try {
            (new StripeGateway())->getCustomer($this->customerWithId());
            $this->fail('Esperava RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertNull($e->retryAfter);
        }
    }

    public function testIdempotencyErrorBecomesIdempotencyConflictException(): void
    {
        RecordingStripeHttpClient::withResponses([
            [['error' => [
                'type' => 'idempotency_error',
                'message' => 'Keys for idempotent requests can only be used with the same parameters they were first used with.',
            ]], 400],
        ]);

        try {
            (new StripeGateway())->createCustomer($this->customerModel());
            $this->fail('Esperava IdempotencyConflictException');
        } catch (IdempotencyConflictException $e) {
            $this->assertInstanceOf(GatewayException::class, $e);
            $this->assertNotInstanceOf(ValidationException::class, $e);
            $this->assertInstanceOf(IdempotencyException::class, $e->getPrevious());
            $this->assertSame(400, $e->httpStatus);
            $this->assertSame('idempotency_error', $e->getErrors()['type']);
        }
    }

    public function testResourceMissingBecomesNotFoundExceptionWithNormalizedErrorsAndPrevious(): void
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
            $this->fail('Esperava NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertInstanceOf(GatewayException::class, $e);
            $this->assertNotInstanceOf(ValidationException::class, $e);
            $this->assertInstanceOf(InvalidRequestException::class, $e->getPrevious());
            $this->assertSame(404, $e->httpStatus);
            $this->assertSame([
                'type' => 'invalid_request_error',
                'code' => 'resource_missing',
                'param' => 'customer',
            ], $e->getErrors());
        }
    }

    public function testInvalidRequestWith404StatusIsNotFoundEvenWithoutResourceMissingCode(): void
    {
        RecordingStripeHttpClient::withResponses([
            [['error' => ['type' => 'invalid_request_error', 'message' => 'No such customer']], 404],
        ]);

        try {
            (new StripeGateway())->getCustomer($this->customerWithId());
            $this->fail('Esperava NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame(404, $e->httpStatus);
        }
    }

    public function testInvalidRequestBecomesValidationExceptionWithTheParamAsField(): void
    {
        RecordingStripeHttpClient::withResponses([
            [['error' => [
                'type' => 'invalid_request_error',
                'code' => 'parameter_invalid_integer',
                'param' => 'amount',
                'message' => 'Invalid integer: abc',
            ]], 400],
        ]);

        try {
            (new StripeGateway())->createInvoice($this->creditCardInvoiceModel());
            $this->fail('Esperava ValidationException');
        } catch (ValidationException $e) {
            $this->assertInstanceOf(GatewayException::class, $e);
            $this->assertInstanceOf(InvalidRequestException::class, $e->getPrevious());
            $this->assertSame(400, $e->httpStatus);
            $this->assertSame(['amount' => ['Invalid integer: abc']], $e->fieldErrors);
            $this->assertSame('parameter_invalid_integer', $e->getErrors()['code']);
        }
    }

    public function testInvalidRequestWithoutParamGoesToTheBaseField(): void
    {
        RecordingStripeHttpClient::withResponses([
            [['error' => [
                'type' => 'invalid_request_error',
                'code' => 'payment_intent_unexpected_state',
                'message' => 'This PaymentIntent could not be canceled.',
            ]], 400],
        ]);

        $invoice = new Invoice();
        $invoice->id = 'pi_fake123';

        try {
            (new StripeGateway())->cancelInvoice($invoice);
            $this->fail('Esperava ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['base' => ['This PaymentIntent could not be canceled.']], $e->fieldErrors);
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
            $this->assertInstanceOf(CardDeclinedException::class, $e);
            $this->assertNotInstanceOf(GatewayException::class, $e);
            $this->assertInstanceOf(CardException::class, $e->getPrevious());
            $this->assertSame(402, $e->httpStatus);
            $this->assertSame('card_declined', $e->reason);
            $this->assertSame(DeclineCode::GENERIC, $e->declineCode);
            $this->assertSame('generic_decline', $e->gatewayCode);
            $this->assertFalse($e->retryable);
            $this->assertSame('card_error', $e->chargeResponse['type']);
            $this->assertStringContainsString('stripe', $e->getMessage());
            $this->assertStringContainsString('Your card was declined.', $e->getMessage());
        }
    }

    public function testCardDeclineIsCaughtByTheNewName(): void
    {
        RecordingStripeHttpClient::withResponses([
            [['error' => ['type' => 'card_error', 'code' => 'expired_card', 'message' => 'Your card has expired.']], 402],
        ]);

        try {
            (new StripeGateway())->createInvoice($this->creditCardInvoiceModel());
            $this->fail('Esperava CardDeclinedException');
        } catch (CardDeclinedException $e) {
            // sem decline_code o driver lê o code
            $this->assertSame(DeclineCode::EXPIRED_CARD, $e->declineCode);
            $this->assertSame('expired_card', $e->gatewayCode);
            $this->assertSame('expired_card', $e->reason);
        }
    }

    #[DataProvider('declineCodeProvider')]
    public function testDeclineCodeIsNormalizedAndRetryableFollowsTheCode(string $stripeDeclineCode, DeclineCode $expected, bool $retryable): void
    {
        RecordingStripeHttpClient::withResponses([
            [['error' => [
                'type' => 'card_error',
                'code' => 'card_declined',
                'decline_code' => $stripeDeclineCode,
                'message' => 'Your card was declined.',
            ]], 402],
        ]);

        try {
            (new StripeGateway())->createInvoice($this->creditCardInvoiceModel());
            $this->fail('Esperava ChargingException');
        } catch (ChargingException $e) {
            $this->assertSame($expected, $e->declineCode);
            $this->assertSame($stripeDeclineCode, $e->gatewayCode);
            $this->assertSame($retryable, $e->retryable);
        }

        $this->assertSame([], $this->logger->records);
    }

    public static function declineCodeProvider(): array
    {
        return [
            'insufficient_funds' => ['insufficient_funds', DeclineCode::INSUFFICIENT_FUNDS, true],
            'card_velocity_exceeded' => ['card_velocity_exceeded', DeclineCode::INSUFFICIENT_FUNDS, true],
            'expired_card' => ['expired_card', DeclineCode::EXPIRED_CARD, false],
            'incorrect_cvc' => ['incorrect_cvc', DeclineCode::INCORRECT_CVC, false],
            'incorrect_number' => ['incorrect_number', DeclineCode::INCORRECT_NUMBER, false],
            'invalid_expiry_year' => ['invalid_expiry_year', DeclineCode::INVALID_CARD, false],
            'lost_card' => ['lost_card', DeclineCode::LOST_OR_STOLEN, false],
            'stolen_card' => ['stolen_card', DeclineCode::LOST_OR_STOLEN, false],
            'fraudulent' => ['fraudulent', DeclineCode::FRAUD_SUSPECTED, false],
            'authentication_required' => ['authentication_required', DeclineCode::AUTHENTICATION_REQUIRED, false],
            'card_not_supported' => ['card_not_supported', DeclineCode::BRAND_NOT_SUPPORTED, false],
            'do_not_honor' => ['do_not_honor', DeclineCode::DO_NOT_HONOR, false],
            'call_issuer' => ['call_issuer', DeclineCode::DO_NOT_HONOR, false],
            'processing_error' => ['processing_error', DeclineCode::TRY_AGAIN, true],
            'try_again_later' => ['try_again_later', DeclineCode::TRY_AGAIN, true],
            'generic_decline' => ['generic_decline', DeclineCode::GENERIC, false],
        ];
    }

    public function testAdviceCodeOverridesTheRetryableDefault(): void
    {
        RecordingStripeHttpClient::withResponses([
            [['error' => [
                'type' => 'card_error',
                'code' => 'card_declined',
                'decline_code' => 'generic_decline',
                'advice_code' => 'try_again_later',
                'message' => 'Your card was declined.',
            ]], 402],
            [['error' => [
                'type' => 'card_error',
                'code' => 'card_declined',
                'decline_code' => 'insufficient_funds',
                'advice_code' => 'do_not_try_again',
                'message' => 'Your card has insufficient funds.',
            ]], 402],
        ]);

        try {
            (new StripeGateway())->createInvoice($this->creditCardInvoiceModel());
            $this->fail('Esperava ChargingException');
        } catch (ChargingException $e) {
            $this->assertSame(DeclineCode::GENERIC, $e->declineCode);
            $this->assertTrue($e->retryable);
        }

        try {
            (new StripeGateway())->createInvoice($this->creditCardInvoiceModel());
            $this->fail('Esperava ChargingException');
        } catch (ChargingException $e) {
            $this->assertSame(DeclineCode::INSUFFICIENT_FUNDS, $e->declineCode);
            $this->assertFalse($e->retryable);
        }

        $this->assertSame([], $this->logger->records);
    }

    public function testCardErrorWithoutAnyCodeBecomesUnknownWithoutCodeAndWithoutLog(): void
    {
        RecordingStripeHttpClient::withResponses([
            [['error' => ['type' => 'card_error', 'message' => 'Your card was declined.']], 402],
        ]);

        try {
            (new StripeGateway())->createInvoice($this->creditCardInvoiceModel());
            $this->fail('Esperava ChargingException');
        } catch (ChargingException $e) {
            $this->assertSame(DeclineCode::UNKNOWN, $e->declineCode);
            $this->assertNull($e->gatewayCode);
            $this->assertFalse($e->retryable);
            $this->assertNull($e->reason);
        }

        $this->assertSame([], $this->logger->records);
    }

    public function testUnmappedDeclineCodeBecomesUnknownAndIsLogged(): void
    {
        RecordingStripeHttpClient::withResponses([
            [['error' => [
                'type' => 'card_error',
                'code' => 'card_declined',
                'decline_code' => 'offline_pin_required',
                'message' => 'Your card was declined.',
            ]], 402],
        ]);

        try {
            (new StripeGateway())->createInvoice($this->creditCardInvoiceModel());
            $this->fail('Esperava ChargingException');
        } catch (ChargingException $e) {
            $this->assertSame(DeclineCode::UNKNOWN, $e->declineCode);
            $this->assertSame('offline_pin_required', $e->gatewayCode);
            $this->assertFalse($e->retryable);
            $this->assertSame('card_declined', $e->reason);
        }

        $this->assertCount(1, $this->logger->records);
        $this->assertSame('info', $this->logger->records[0]['level']);
        $this->assertSame(['gateway' => 'stripe', 'code' => 'offline_pin_required'], $this->logger->records[0]['context']);
    }

    public function testCardDeclineDuringAttachCarriesTheCardExceptionDirectly(): void
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
            $this->assertInstanceOf(CardException::class, $e->getPrevious());
            $this->assertSame(DeclineCode::GENERIC, $e->declineCode);
        }
    }

    public function testCardDeclineWhenOnlySavingTheCardIsAlsoACardDecline(): void
    {
        RecordingStripeHttpClient::withResponses([
            [['error' => [
                'type' => 'card_error',
                'code' => 'card_declined',
                'decline_code' => 'stolen_card',
                'message' => 'Your card was declined.',
            ]], 402],
        ]);

        $creditCard = new CreditCard();
        $creditCard->token = 'pm_fake123';
        $creditCard->customer = $this->customerWithId();

        try {
            (new StripeGateway())->createCreditCard($creditCard);
            $this->fail('Esperava CardDeclinedException');
        } catch (CardDeclinedException $e) {
            $this->assertSame(DeclineCode::LOST_OR_STOLEN, $e->declineCode);
            $this->assertSame('stolen_card', $e->gatewayCode);
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

    private function customerModel(): Customer
    {
        $customer = new Customer();
        $customer->name = 'Cliente';
        $customer->email = 'cliente@example.com';

        return $customer;
    }

    private function creditCardInvoiceModel(): Invoice
    {
        $invoice = new Invoice();
        $invoice->customer = new Customer();
        $invoice->customer->id = 'cus_fake123';
        $invoice->availablePaymentMethods = [PaymentMethod::CREDIT_CARD];
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
