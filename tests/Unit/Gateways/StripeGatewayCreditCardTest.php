<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Carbon\Carbon;
use Stripe\ApiRequestor;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\InvoiceItem;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Enums\DeclineCode;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Gateways\StripeGateway;
use Potelo\MultiPayment\Exceptions\ChargingException;
use Potelo\MultiPayment\Exceptions\CardDeclinedException;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Cartão no driver da Stripe: salvamento por SetupIntent confirmado na hora (cartão cobrável,
 * cartão que exige autenticação do pagador, cartão recusado), conclusão do setup depois da
 * autenticação, leitura e exclusão, tudo com o fake da camada HTTP do stripe-php.
 */
class StripeGatewayCreditCardTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/stripe/setup_intents/';

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

    /**
     * O SetupIntent confirmado com cliente anexa o PaymentMethod; nenhum attach é enviado.
     */
    public function testCreateCreditCardSavesTheCardThroughAConfirmedSetupIntent(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->setupIntentResponse()]);

        $result = (new StripeGateway())->createCreditCard($this->creditCardModel());

        $this->assertCount(1, $httpClient->calls);
        [$method, $url, $params] = $httpClient->calls[0];
        $this->assertSame('post', $method);
        $this->assertSame('/v1/setup_intents', parse_url($url, PHP_URL_PATH));
        $this->assertSame([
            'customer' => 'cus_fake123',
            'payment_method' => 'pm_fake123',
            'payment_method_types' => ['card'],
            'usage' => 'off_session',
            'confirm' => 'true',
            'expand' => ['payment_method'],
        ], $params);

        $this->assertSame('pm_fake123', $result->id);
        $this->assertSame('seti_fake123', $result->setupId);
        $this->assertFalse($result->requiresAction);
        $this->assertNull($result->clientSecret);
        $this->assertNull($result->actionUrl);
        $this->assertSame('visa', $result->brand);
        $this->assertSame('4242', $result->lastDigits);
        $this->assertSame('08', $result->month);
        $this->assertSame('2027', $result->year);
        $this->assertSame('Faker', $result->firstName);
        $this->assertSame('Teste', $result->lastName);
        $this->assertSame('stripe', $result->gateway);
        $this->assertInstanceOf(\Stripe\PaymentMethod::class, $result->original);
        $this->assertInstanceOf(Carbon::class, $result->createdAt);
    }

    /**
     * Resposta em que a Stripe devolve o PaymentMethod sem cliente: o driver anexa por conta
     * própria antes de devolver o cartão.
     */
    public function testCreateCreditCardAttachesWhenStripeReturnsTheCardWithoutCustomer(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            $this->setupIntentResponse(paymentMethodCustomer: null),
            $this->paymentMethodResponse(customer: 'cus_fake123'),
        ]);

        $result = (new StripeGateway())->createCreditCard($this->creditCardModel());

        $this->assertSame([
            'post /v1/setup_intents',
            'post /v1/payment_methods/pm_fake123/attach',
        ], $this->paths($httpClient));
        $this->assertSame(['customer' => 'cus_fake123'], $httpClient->calls[1][2]);
        $this->assertSame('pm_fake123', $result->id);
        $this->assertFalse($result->requiresAction);
    }

    public function testCreateDefaultCreditCardWithDescriptionIssuesExtraUpdates(): void
    {
        $metadata = ['description' => 'cartão principal', 'set_as_default' => '1'];
        $httpClient = RecordingStripeHttpClient::withResponses([
            $this->setupIntentResponse(metadata: $metadata),
            $this->paymentMethodResponse(customer: 'cus_fake123', metadata: ['description' => 'cartão principal']),
            $this->stripeCustomerResponse(),
        ]);

        $creditCard = $this->creditCardModel();
        $creditCard->description = 'cartão principal';
        $creditCard->default = true;
        $result = (new StripeGateway())->createCreditCard($creditCard);

        $this->assertSame([
            'post /v1/setup_intents',
            'post /v1/payment_methods/pm_fake123',
            'post /v1/customers/cus_fake123',
        ], $this->paths($httpClient));
        $this->assertSame($metadata, $httpClient->calls[0][2]['metadata']);
        $this->assertSame(['metadata' => ['description' => 'cartão principal']], $httpClient->calls[1][2]);
        $this->assertSame(
            ['invoice_settings' => ['default_payment_method' => 'pm_fake123']],
            $httpClient->calls[2][2]
        );
        $this->assertSame('cartão principal', $result->description);
    }

    /**
     * Resposta gravada na sandbox com `pm_card_visa`: o PaymentMethod expandido já vem com o
     * cliente, e a descrição e a marcação de padrão em `metadata` do setup viram as duas
     * escritas seguintes.
     */
    public function testCreateCreditCardParsesTheRecordedSucceededSetupIntent(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::fixture('succeeded'),
            $this->paymentMethodResponse(customer: 'cus_VBjzroZKS8d5LY', metadata: ['description' => 'cartão de teste']),
            $this->stripeCustomerResponse(),
        ]);

        $creditCard = $this->creditCardModel();
        $creditCard->customer->id = 'cus_VBjzroZKS8d5LY';
        $creditCard->description = 'cartão de teste';
        $creditCard->default = true;
        $result = (new StripeGateway())->createCreditCard($creditCard);

        $this->assertSame([
            'post /v1/setup_intents',
            'post /v1/payment_methods/pm_1UBMVgPjx0CusuMrtGi8Aruq',
            'post /v1/customers/cus_VBjzroZKS8d5LY',
        ], $this->paths($httpClient));
        $this->assertSame(
            ['invoice_settings' => ['default_payment_method' => 'pm_1UBMVgPjx0CusuMrtGi8Aruq']],
            $httpClient->calls[2][2]
        );
        $this->assertSame('seti_1UBMVgPjx0CusuMrtgTZPbBG', $result->setupId);
        $this->assertFalse($result->requiresAction);
        $this->assertSame('cartão de teste', $result->description);
    }

    /**
     * `metadata` de `gatewayOptions` convive com as chaves que o setup usa para a descrição
     * e o padrão.
     */
    public function testCreateCreditCardMergesTheConsumerMetadataWithTheSetupMetadata(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            $this->setupIntentResponse(metadata: ['user_id' => '7', 'description' => 'cartão principal']),
            $this->paymentMethodResponse(customer: 'cus_fake123', metadata: ['description' => 'cartão principal']),
        ]);

        $creditCard = $this->creditCardModel();
        $creditCard->description = 'cartão principal';
        $creditCard->gatewayOptions = ['metadata' => ['user_id' => '7']];
        $result = (new StripeGateway())->createCreditCard($creditCard);

        $this->assertSame(['user_id' => '7', 'description' => 'cartão principal'], $httpClient->calls[0][2]['metadata']);
        $this->assertSame('post /v1/payment_methods/pm_fake123', $this->paths($httpClient)[1]);
        $this->assertSame('cartão principal', $result->description);
    }

    public function testConfirmCreditCardSetupRefusesASetupWithoutCustomerBeforeAnyWrite(): void
    {
        $setupIntent = $this->setupIntentResponse(paymentMethodCustomer: null);
        $setupIntent['customer'] = null;
        $httpClient = RecordingStripeHttpClient::withResponses([$setupIntent]);

        try {
            (new StripeGateway())->confirmCreditCardSetup('seti_fake123');
            $this->fail('Esperava ModelAttributeValidationException');
        } catch (ModelAttributeValidationException $e) {
            $this->assertStringContainsString('no customer', $e->getMessage());
        }
        $this->assertSame(['get /v1/setup_intents/seti_fake123'], $this->paths($httpClient));
    }

    public function testCreateCreditCardConvertsLegacyTokenIntoPaymentMethod(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            $this->paymentMethodResponse(),
            $this->setupIntentResponse(),
        ]);

        $creditCard = $this->creditCardModel();
        $creditCard->token = 'tok_fake123';
        (new StripeGateway())->createCreditCard($creditCard);

        $this->assertSame([
            'post /v1/payment_methods',
            'post /v1/setup_intents',
        ], $this->paths($httpClient));
        $this->assertSame(['type' => 'card', 'card' => ['token' => 'tok_fake123']], $httpClient->calls[0][2]);
        $this->assertSame('pm_fake123', $httpClient->calls[1][2]['payment_method']);
    }

    /**
     * Resposta gravada na sandbox com `pm_card_authenticationRequired`: o cartão volta sem id,
     * com o que o navegador precisa para autenticar, e nada é anexado ao cliente.
     */
    public function testCreateCreditCardReturnsRequiresActionWhenTheIssuerAsksForAuthentication(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([self::fixture('requires_action')]);

        $creditCard = $this->creditCardModel();
        $creditCard->description = 'cartão 3ds';
        $result = (new StripeGateway())->createCreditCard($creditCard);

        $this->assertSame(['post /v1/setup_intents'], $this->paths($httpClient));
        $this->assertTrue($result->requiresAction);
        $this->assertNull($result->id);
        $this->assertSame('seti_1UBMViPjx0CusuMrBNDQEzqv', $result->setupId);
        $this->assertSame('seti_1UBMViPjx0CusuMrBNDQEzqv_secret_PLACEHOLDER', $result->clientSecret);
        $this->assertNull($result->actionUrl, 'sem return_url a autenticação é pelo Stripe.js (use_stripe_sdk)');
        $this->assertSame('visa', $result->brand);
        $this->assertSame('3184', $result->lastDigits);
        $this->assertSame('09', $result->month);
        $this->assertSame('2027', $result->year);
        $this->assertSame('cartão 3ds', $result->description);
        $this->assertSame('cus_fake123', $result->customer->id);
        $this->assertSame('stripe', $result->gateway);
        $this->assertInstanceOf(\Stripe\SetupIntent::class, $result->original);
        $this->assertInstanceOf(Carbon::class, $result->createdAt);
    }

    /**
     * Com `return_url` em `gatewayOptions`, a Stripe devolve a página hospedada de 3DS em
     * `next_action.redirect_to_url`, que vira `actionUrl`.
     */
    public function testCreateCreditCardExposesTheHostedAuthenticationPageWhenReturnUrlIsGiven(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([self::fixture('requires_action_redirect')]);

        $creditCard = $this->creditCardModel();
        $creditCard->gatewayOptions = ['return_url' => 'https://exemplo.com/retorno'];
        $result = (new StripeGateway())->createCreditCard($creditCard);

        $this->assertSame('https://exemplo.com/retorno', $httpClient->calls[0][2]['return_url']);
        $this->assertTrue($result->requiresAction);
        $this->assertStringStartsWith('https://hooks.stripe.com/3d_secure_2/hosted?', $result->actionUrl);
        $this->assertSame('seti_1UBMVjPjx0CusuMr53vgIbuw', $result->setupId);
    }

    /**
     * Recusa no setup (resposta 402 gravada com `pm_card_chargeDeclined`) é `ChargingException`
     * com o código traduzido e o `advice_code` decidindo `retryable`.
     */
    public function testCreateCreditCardDeclinedAtSetupBecomesCardDeclinedException(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([[self::fixture('card_declined'), 402]]);

        try {
            (new StripeGateway())->createCreditCard($this->creditCardModel());
            $this->fail('Esperava CardDeclinedException');
        } catch (CardDeclinedException $e) {
            $this->assertInstanceOf(ChargingException::class, $e);
            $this->assertSame(DeclineCode::GENERIC, $e->declineCode);
            $this->assertSame('generic_decline', $e->gatewayCode);
            $this->assertTrue($e->retryable, 'advice_code try_again_later');
            $this->assertSame(402, $e->httpStatus);
            $this->assertSame('card_declined', $e->reason);
            $this->assertInstanceOf(\Stripe\Exception\CardException::class, $e->getPrevious());
            $this->assertIsArray($e->chargeResponse);
            $this->assertSame('generic_decline', $e->chargeResponse['decline_code']);
            $this->assertSame('requires_payment_method', $e->chargeResponse['setup_intent']['status']);
        }
        $this->assertSame(['post /v1/setup_intents'], $this->paths($httpClient));
    }

    /**
     * Depois da autenticação: o setup é lido, o cartão já está anexado, e a descrição e a
     * marcação de padrão guardadas em `metadata` do setup são aplicadas.
     */
    public function testConfirmCreditCardSetupAppliesTheSetupMetadataAndReturnsTheCard(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            $this->setupIntentResponse(metadata: ['description' => 'cartão principal', 'set_as_default' => '1']),
            $this->paymentMethodResponse(customer: 'cus_fake123', metadata: ['description' => 'cartão principal']),
            $this->stripeCustomerResponse(),
        ]);

        $result = (new StripeGateway())->confirmCreditCardSetup('seti_fake123');

        $this->assertSame([
            'get /v1/setup_intents/seti_fake123',
            'post /v1/payment_methods/pm_fake123',
            'post /v1/customers/cus_fake123',
        ], $this->paths($httpClient));
        $this->assertSame(['expand' => ['payment_method']], $httpClient->calls[0][2]);
        $this->assertSame('pm_fake123', $result->id);
        $this->assertSame('seti_fake123', $result->setupId);
        $this->assertFalse($result->requiresAction);
        $this->assertSame('cartão principal', $result->description);
        $this->assertTrue($result->default);
        $this->assertSame('cus_fake123', $result->customer->id);
        $this->assertSame('4242', $result->lastDigits);
    }

    public function testConfirmCreditCardSetupKeepsRequiresActionWhileThePayerHasNotAuthenticated(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([self::fixture('requires_action')]);

        $result = (new StripeGateway())->confirmCreditCardSetup('seti_1UBMViPjx0CusuMrBNDQEzqv');

        $this->assertSame(['get /v1/setup_intents/seti_1UBMViPjx0CusuMrBNDQEzqv'], $this->paths($httpClient));
        $this->assertTrue($result->requiresAction);
        $this->assertNull($result->id);
        $this->assertSame('seti_1UBMViPjx0CusuMrBNDQEzqv', $result->setupId);
        $this->assertSame('cus_VBjzroZKS8d5LY', $result->customer->id);
        $this->assertNotEmpty($result->clientSecret);
        $this->assertSame('cartão 3ds', $result->description, 'a descrição volta de metadata do setup');
        $this->assertSame('3184', $result->lastDigits);
    }

    /**
     * Autenticação que falhou: o setup volta a `requires_payment_method` com
     * `last_setup_error`, e a recusa pede ação do pagador.
     */
    public function testConfirmCreditCardSetupOnAFailedAuthenticationThrowsCardDeclined(): void
    {
        RecordingStripeHttpClient::withResponses([
            $this->setupIntentResponse(status: 'requires_payment_method', paymentMethodCustomer: null, lastSetupError: [
                'type' => 'invalid_request_error',
                'code' => 'setup_intent_authentication_failure',
                'message' => 'The latest attempt to set up the payment method has failed because authentication failed.',
            ]),
        ]);

        try {
            (new StripeGateway())->confirmCreditCardSetup('seti_fake123');
            $this->fail('Esperava CardDeclinedException');
        } catch (CardDeclinedException $e) {
            $this->assertSame(DeclineCode::AUTHENTICATION_REQUIRED, $e->declineCode);
            $this->assertSame('setup_intent_authentication_failure', $e->gatewayCode);
            $this->assertTrue($e->declineCode->requiresPayerAction());
            $this->assertFalse($e->retryable);
            $this->assertNull($e->httpStatus);
            $this->assertSame('seti_fake123', $e->chargeResponse['id']);
        }
    }

    public function testConfirmCreditCardSetupOnACanceledSetupThrowsCardDeclined(): void
    {
        RecordingStripeHttpClient::withResponses([
            $this->setupIntentResponse(status: 'canceled', paymentMethodCustomer: null, cancellationReason: 'abandoned'),
        ]);

        try {
            (new StripeGateway())->confirmCreditCardSetup('seti_fake123');
            $this->fail('Esperava CardDeclinedException');
        } catch (CardDeclinedException $e) {
            $this->assertSame(DeclineCode::UNKNOWN, $e->declineCode);
            $this->assertSame('abandoned', $e->gatewayCode);
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('canceled', $e->getMessage());
            $this->assertSame('seti_fake123', $e->chargeResponse['id']);
            $this->assertSame('canceled', $e->chargeResponse['status']);
        }
    }

    /**
     * Venda avulsa com token de cartão que exige autenticação: a cobrança fora de sessão não
     * tem como atendê-la, e a fatura não chega a ser criada.
     */
    public function testCreateInvoiceWithATokenThatRequiresAuthenticationFailsBeforeThePaymentIntent(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([self::fixture('requires_action')]);

        $invoice = new Invoice();
        $invoice->customer = new Customer();
        $invoice->customer->id = 'cus_fake123';
        $invoice->availablePaymentMethods = [PaymentMethod::CREDIT_CARD];
        $invoice->creditCard = new CreditCard();
        $invoice->creditCard->token = 'pm_fake123';
        $item = new InvoiceItem();
        $item->description = 'Curso';
        $item->price = 19900;
        $item->quantity = 1;
        $invoice->items = [$item];

        try {
            (new StripeGateway())->createInvoice($invoice);
            $this->fail('Esperava CardDeclinedException');
        } catch (CardDeclinedException $e) {
            $this->assertSame(DeclineCode::AUTHENTICATION_REQUIRED, $e->declineCode);
            $this->assertSame('authentication_required', $e->gatewayCode);
            $this->assertTrue($e->declineCode->requiresPayerAction());
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('confirmCreditCardSetup', $e->getMessage());
            $this->assertSame('seti_1UBMViPjx0CusuMrBNDQEzqv', $e->chargeResponse['id']);
        }
        $this->assertSame(['post /v1/setup_intents'], $this->paths($httpClient), 'nenhum PaymentIntent é criado');
    }

    public function testCreditCardConfirmSetupDelegatesToTheGatewayWithItsSetupId(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->setupIntentResponse()]);

        $creditCard = new CreditCard();
        $creditCard->setupId = 'seti_fake123';
        $creditCard->requiresAction = true;
        $creditCard->clientSecret = 'seti_fake123_secret_fake';
        $creditCard->customer = new Customer();
        $creditCard->customer->id = 'cus_fake123';
        $creditCard->customer->name = 'Faker Teste';
        $result = $creditCard->confirmSetup(new StripeGateway());

        $this->assertSame(['get /v1/setup_intents/seti_fake123'], $this->paths($httpClient));
        $this->assertSame($creditCard, $result, 'o próprio model é atualizado e devolvido');
        $this->assertSame('pm_fake123', $creditCard->id);
        $this->assertFalse($creditCard->requiresAction);
        $this->assertNull($creditCard->clientSecret);
        $this->assertSame('Faker Teste', $creditCard->customer->name, 'o customer já preenchido é mantido');
    }

    public function testCreditCardConfirmSetupRequiresTheSetupId(): void
    {
        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessage('setupId');

        (new CreditCard())->confirmSetup(new StripeGateway());
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
        $this->assertFalse($result->requiresAction);
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

        $this->assertSame([
            'get /v1/payment_methods/pm_fake123',
            'post /v1/payment_methods/pm_fake123/detach',
        ], $this->paths($httpClient));
    }

    private function paths(RecordingStripeHttpClient $httpClient): array
    {
        return array_map(static fn ($call) => $call[0] . ' ' . parse_url($call[1], PHP_URL_PATH), $httpClient->calls);
    }

    private static function fixture(string $name): array
    {
        return json_decode(file_get_contents(self::FIXTURES . $name . '.json'), true);
    }

    private function creditCardModel(): CreditCard
    {
        $creditCard = new CreditCard();
        $creditCard->token = 'pm_fake123';
        $creditCard->customer = new Customer();
        $creditCard->customer->id = 'cus_fake123';

        return $creditCard;
    }

    /**
     * SetupIntent com o PaymentMethod expandido, no formato da resposta da Stripe.
     */
    private function setupIntentResponse(
        string $status = 'succeeded',
        ?string $paymentMethodCustomer = 'cus_fake123',
        array $metadata = [],
        ?array $lastSetupError = null,
        ?string $cancellationReason = null
    ): array {
        return [
            'id' => 'seti_fake123',
            'object' => 'setup_intent',
            'status' => $status,
            'customer' => 'cus_fake123',
            'usage' => 'off_session',
            'client_secret' => 'seti_fake123_secret_fake',
            'created' => 1786700000,
            'payment_method_types' => ['card'],
            'metadata' => $metadata,
            'next_action' => null,
            'last_setup_error' => $lastSetupError,
            'cancellation_reason' => $cancellationReason,
            'payment_method' => $this->paymentMethodResponse(customer: $paymentMethodCustomer),
        ];
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
