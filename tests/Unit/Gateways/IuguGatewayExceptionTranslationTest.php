<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Plan;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\InvoiceItem;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Gateways\IuguGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\ChargingException;
use Potelo\MultiPayment\Exceptions\MultiPaymentException;
use Potelo\MultiPayment\Exceptions\AuthenticationException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Enums\PaymentMethod;

/**
 * Cobre a tradução de falhas do SDK da Iugu para as exceções do pacote: classe escolhida pelo
 * status HTTP, exceção original em `getPrevious()` e status em `httpStatus`. Os fluxos que
 * passam pelo requester injetado usam `QueuedIuguApiRequest` direto; os que usam recursos
 * estáticos do SDK (`Iugu_Customer::create()`, `Iugu_PaymentToken::create()`, `Iugu_Charge`)
 * instalam o mesmo fake como requester do SDK.
 */
class IuguGatewayExceptionTranslationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment.gateways.iugu.api_key' => 'test-api-key',
            'multi-payment.gateways.iugu.id' => 'account-id',
            'multi-payment.environment' => 'testing',
        ]));
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        QueuedIuguApiRequest::restoreSdkRequester();
        unset($GLOBALS['iugu_last_api_response_code']);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testUnauthorizedJsonBodyBecomesAuthenticationException(): void
    {
        // a Iugu responde 401 com corpo JSON, então o SDK devolve a resposta em vez de lançar
        $api = new QueuedIuguApiRequest([
            new QueuedIuguResponse((object) ['errors' => 'Unauthorized'], 401),
        ]);

        try {
            (new IuguGateway($api))->getInvoice($this->invoiceWithId());
            $this->fail('Esperava AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertNotInstanceOf(GatewayNotAvailableException::class, $e);
            $this->assertSame(401, $e->httpStatus);
            $this->assertStringContainsString('Unauthorized', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    public function testForbiddenJsonBodyBecomesAuthenticationException(): void
    {
        $api = new QueuedIuguApiRequest([
            new QueuedIuguResponse((object) ['errors' => 'Forbidden'], 403),
        ]);

        try {
            (new IuguGateway($api))->cancelInvoice($this->invoiceWithId());
            $this->fail('Esperava AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame(403, $e->httpStatus);
            $this->assertNull($e->getPrevious());
            $this->assertStringContainsString('Forbidden', $e->getMessage());
        }
    }

    public function testUnauthorizedNonJsonResponseBecomesAuthenticationExceptionWithPrevious(): void
    {
        $original = new \IuguRequestException('<html>401 Unauthorized</html>', 401);
        $api = new QueuedIuguApiRequest([$original]);

        try {
            (new IuguGateway($api))->getInvoice($this->invoiceWithId());
            $this->fail('Esperava AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame($original, $e->getPrevious());
            $this->assertSame(401, $e->httpStatus);
        }
    }

    public function testMissingApiKeyBecomesAuthenticationExceptionNotGatewayNotAvailable(): void
    {
        $original = new \IuguAuthenticationException('Chave de API não configurada.');
        $api = new QueuedIuguApiRequest([$original]);

        try {
            (new IuguGateway($api))->getInvoice($this->invoiceWithId());
            $this->fail('Esperava AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertNotInstanceOf(GatewayNotAvailableException::class, $e);
            $this->assertSame($original, $e->getPrevious());
            $this->assertNull($e->httpStatus);
        }
    }

    #[DataProvider('serverErrorProvider')]
    public function testServerErrorsAndTimeoutBecomeGatewayNotAvailableException(\Throwable $original, ?int $expectedStatus): void
    {
        $api = new QueuedIuguApiRequest([$original]);

        try {
            (new IuguGateway($api))->getInvoice($this->invoiceWithId());
            $this->fail('Esperava GatewayNotAvailableException');
        } catch (GatewayNotAvailableException $e) {
            $this->assertSame($original, $e->getPrevious());
            $this->assertSame($expectedStatus, $e->httpStatus);
        }
    }

    public static function serverErrorProvider(): array
    {
        return [
            '502 com página html do proxy' => [new \IuguRequestException('<html>502 Bad Gateway</html>', 502), 502],
            '503 sem corpo json' => [new \IuguRequestException('Service Unavailable', 503), 503],
            '500 sem corpo json' => [new \IuguRequestException('Internal Server Error', 500), 500],
            // cURL sem resposta: corpo vazio e código 0
            'timeout de rede' => [new \IuguRequestException('', 0), null],
        ];
    }

    public function testRequestExceptionWithoutCodeButWithMessageIsNotReadAsNetworkFailure(): void
    {
        // fetchAPI() do SDK lança IuguRequestException('Iugu: ...') sem código para resposta com `error`
        $original = new \IuguRequestException('Iugu: invoice unavailable');
        $api = new QueuedIuguApiRequest([$original]);

        try {
            (new IuguGateway($api))->getInvoice($this->invoiceWithId());
            $this->fail('Esperava GatewayException');
        } catch (GatewayException $e) {
            $this->assertNotInstanceOf(GatewayNotAvailableException::class, $e);
            $this->assertSame($original, $e->getPrevious());
            $this->assertNull($e->httpStatus);
            $this->assertStringContainsString('invoice unavailable', $e->getMessage());
        }
    }

    public function testServerErrorWithJsonBodyBecomesGatewayNotAvailableException(): void
    {
        $api = new QueuedIuguApiRequest([
            new QueuedIuguResponse((object) ['errors' => 'Service Unavailable'], 503),
        ]);

        try {
            (new IuguGateway($api))->getInvoice($this->invoiceWithId());
            $this->fail('Esperava GatewayNotAvailableException');
        } catch (GatewayNotAvailableException $e) {
            $this->assertSame(503, $e->httpStatus);
            $this->assertNull($e->getPrevious());
        }
    }

    public function testNotFoundKeepsBeingGatewayExceptionWithStatusAndPrevious(): void
    {
        // fetchAPI() do SDK relança IuguObjectNotFound sem o código HTTP
        $original = new \IuguObjectNotFound('invoice: not found');
        $api = new QueuedIuguApiRequest([$original]);

        try {
            (new IuguGateway($api))->getInvoice($this->invoiceWithId());
            $this->fail('Esperava GatewayException');
        } catch (GatewayException $e) {
            $this->assertNotInstanceOf(GatewayNotAvailableException::class, $e);
            $this->assertSame($original, $e->getPrevious());
            $this->assertSame(404, $e->httpStatus);
        }
    }

    #[DataProvider('clientErrorProvider')]
    public function testOtherHttpErrorsBecomeGatewayExceptionWithStatusExposed(int $status): void
    {
        $api = new QueuedIuguApiRequest([
            new QueuedIuguResponse((object) ['errors' => ['base' => ['erro']]], $status),
        ]);

        try {
            (new IuguGateway($api))->getInvoice($this->invoiceWithId());
            $this->fail('Esperava GatewayException');
        } catch (GatewayException $e) {
            $this->assertNotInstanceOf(GatewayNotAvailableException::class, $e);
            $this->assertNotInstanceOf(AuthenticationException::class, $e);
            $this->assertSame($status, $e->httpStatus);
            $this->assertSame(['base' => ['erro']], $e->getErrors());
        }
    }

    public static function clientErrorProvider(): array
    {
        return [
            'validação' => [422],
            'requisição inválida' => [400],
            'conflito de idempotência' => [409],
            'rate limit' => [429],
        ];
    }

    public function testJsonErrorBodyWithoutKnownStatusIsStillGatewayException(): void
    {
        // ramo defensivo: o SDK real grava o status em toda resposta decodificada, mas se ele
        // faltar o gateway ainda respondeu, então a falha não pode ser lida como indisponibilidade
        $api = new QueuedIuguApiRequest([
            new QueuedIuguResponse((object) ['errors' => 'Not Found'], 0),
        ]);

        try {
            (new IuguGateway($api))->getInvoice($this->invoiceWithId());
            $this->fail('Esperava GatewayException');
        } catch (GatewayException $e) {
            $this->assertNotInstanceOf(GatewayNotAvailableException::class, $e);
            $this->assertNull($e->httpStatus);
        }
    }

    public function testUnexpectedExceptionBecomesGatewayExceptionWithPrevious(): void
    {
        $original = new \RuntimeException('json inesperado');
        $api = new QueuedIuguApiRequest([$original]);

        try {
            (new IuguGateway($api))->getInvoice($this->invoiceWithId());
            $this->fail('Esperava GatewayException');
        } catch (GatewayException $e) {
            $this->assertSame($original, $e->getPrevious());
            $this->assertNull($e->httpStatus);
            $this->assertStringContainsString('json inesperado', $e->getMessage());
        }
    }

    #[DataProvider('injectedRequesterFlowProvider')]
    public function testEveryInjectedRequesterFlowAttachesThePreviousException(\Closure $operation): void
    {
        $original = new \IuguRequestException('<html>502 Bad Gateway</html>', 502);
        $api = new QueuedIuguApiRequest([$original]);

        try {
            $operation(new IuguGateway($api));
            $this->fail('Esperava GatewayNotAvailableException');
        } catch (GatewayNotAvailableException $e) {
            $this->assertSame($original, $e->getPrevious());
            $this->assertSame(502, $e->httpStatus);
        }

        $this->assertCount(1, $api->calls);
    }

    public static function injectedRequesterFlowProvider(): array
    {
        $invoice = static function (): Invoice {
            $invoice = new Invoice();
            $invoice->id = 'inv_1';

            return $invoice;
        };
        $subscription = static function (): Subscription {
            $subscription = new Subscription();
            $subscription->id = 'sub_1';

            return $subscription;
        };

        return [
            'getInvoice' => [fn (IuguGateway $g) => $g->getInvoice($invoice())],
            'cancelInvoice' => [fn (IuguGateway $g) => $g->cancelInvoice($invoice())],
            'refundInvoice' => [function (IuguGateway $g) use ($invoice) {
                $paid = $invoice();
                $paid->paymentMethod = PaymentMethod::CREDIT_CARD;
                $paid->status = InvoiceStatus::PAID;
                $paid->paidAt = Carbon::now();

                return $g->refundInvoice($paid);
            }],
            'rescheduleAutomaticPixPayment' => [fn (IuguGateway $g) => $g->rescheduleAutomaticPixPayment($invoice())],
            'getSubscription' => [fn (IuguGateway $g) => $g->getSubscription($subscription())],
            'suspendSubscription' => [fn (IuguGateway $g) => $g->suspendSubscription($subscription())],
            'getPlan' => [function (IuguGateway $g) {
                $plan = new Plan();
                $plan->identifier = 'plano_mensal';

                return $g->getPlan($plan);
            }],
            'duplicateInvoice' => [fn (IuguGateway $g) => $g->duplicateInvoice($invoice(), Carbon::parse('2026-10-01'))],
            'updateCustomer' => [function (IuguGateway $g) {
                $customer = self::customerWithId();

                return $g->updateCustomer($customer);
            }],
            'deleteCreditCard' => [fn (IuguGateway $g) => $g->deleteCreditCard(self::savedCreditCard())],
        ];
    }

    #[DataProvider('staticSdkFlowProvider')]
    public function testEveryStaticSdkFlowAttachesThePreviousException(\Closure $operation, array $responsesBefore = []): void
    {
        $original = new \IuguRequestException('<html>502 Bad Gateway</html>', 502);
        $api = (new QueuedIuguApiRequest([...$responsesBefore, $original]))->installAsSdkRequester();

        try {
            $operation(new IuguGateway($api));
            $this->fail('Esperava GatewayNotAvailableException');
        } catch (GatewayNotAvailableException $e) {
            $this->assertSame($original, $e->getPrevious());
            $this->assertSame(502, $e->httpStatus);
        }

        $this->assertCount(count($responsesBefore) + 1, $api->calls);
    }

    public static function staticSdkFlowProvider(): array
    {
        return [
            'createInvoice (Iugu_Invoice::create)' => [function (IuguGateway $g) {
                $invoice = new Invoice();
                $invoice->customer = self::customerWithId();
                $invoice->availablePaymentMethods = [PaymentMethod::PIX];
                $item = new InvoiceItem();
                $item->description = 'Item';
                $item->price = 1000;
                $item->quantity = 1;
                $invoice->items = [$item];

                return $g->createInvoice($invoice);
            }],
            'chargeInvoiceWithCreditCard (Iugu_Charge::create)' => [function (IuguGateway $g) {
                $invoice = new Invoice();
                $invoice->id = 'inv_1';
                $invoice->creditCard = self::savedCreditCard();

                return $g->chargeInvoiceWithCreditCard($invoice);
            }],
            'createCustomer (Iugu_Customer::create)' => [function (IuguGateway $g) {
                $customer = self::customerWithId();
                $customer->id = null;

                return $g->createCustomer($customer);
            }],
            'getCustomer (Iugu_Customer::fetch)' => [fn (IuguGateway $g) => $g->getCustomer(self::customerWithId())],
            'createCreditCard (token ok, Iugu_PaymentMethod::create falha)' => [
                function (IuguGateway $g) {
                    $creditCard = self::savedCreditCard();
                    $creditCard->id = null;
                    $creditCard->token = 'tok_1';

                    return $g->createCreditCard($creditCard);
                },
            ],
            'createCreditCard (Iugu_PaymentToken::create falha)' => [
                function (IuguGateway $g) {
                    $creditCard = self::savedCreditCard();
                    $creditCard->id = null;
                    $creditCard->number = '4111111111111111';
                    $creditCard->cvv = '123';
                    $creditCard->firstName = 'Cliente';
                    $creditCard->lastName = 'Teste';
                    $creditCard->month = '12';
                    $creditCard->year = '2030';

                    return $g->createCreditCard($creditCard);
                },
            ],
            'getCreditCard (payment_methods()->fetch)' => [fn (IuguGateway $g) => $g->getCreditCard(self::savedCreditCard())],
        ];
    }

    private static function customerWithId(): Customer
    {
        $customer = new Customer();
        $customer->id = 'cus_1';
        $customer->name = 'Cliente';
        $customer->email = 'cliente@example.com';
        $customer->taxDocument = '20176996915';

        return $customer;
    }

    private static function savedCreditCard(): CreditCard
    {
        $creditCard = new CreditCard();
        $creditCard->id = 'pm_1';
        $creditCard->customer = self::customerWithId();

        return $creditCard;
    }

    public function testStaticSdkResourceFailureIsTranslatedWithPrevious(): void
    {
        $original = new \IuguRequestException('<html>502 Bad Gateway</html>', 502);
        $api = (new QueuedIuguApiRequest([$original]))->installAsSdkRequester();

        try {
            (new IuguGateway($api))->createCustomer($this->customerModel());
            $this->fail('Esperava GatewayNotAvailableException');
        } catch (GatewayNotAvailableException $e) {
            $this->assertSame($original, $e->getPrevious());
        }

        $this->assertCount(1, $api->calls);
        $this->assertSame('POST', $api->calls[0]['method']);
    }

    public function testStaticSdkResourceUnauthorizedBodyBecomesAuthenticationException(): void
    {
        $api = (new QueuedIuguApiRequest([
            new QueuedIuguResponse((object) ['errors' => 'Unauthorized'], 401),
        ]))->installAsSdkRequester();

        try {
            (new IuguGateway($api))->createCustomer($this->customerModel());
            $this->fail('Esperava AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame(401, $e->httpStatus);
        }

        $this->assertCount(1, $api->calls);
    }

    public function testInvalidRawCardOnTokenizationBecomesGatewayExceptionWithoutSecondRequest(): void
    {
        $api = (new QueuedIuguApiRequest([
            new QueuedIuguResponse((object) ['errors' => ['number' => ['não é válido']]], 422),
        ]))->installAsSdkRequester();

        try {
            (new IuguGateway($api))->createCreditCard($this->rawCreditCardModel());
            $this->fail('Esperava GatewayException');
        } catch (GatewayException $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertSame(['number' => ['não é válido']], $e->getErrors());
            $this->assertStringContainsString('payment token', $e->getMessage());
        }

        // só a tokenização foi tentada; o cartão não chegou a ser salvo no cliente
        $this->assertCount(1, $api->calls);
        $this->assertStringEndsWith('/payment_token', $api->calls[0]['url']);
    }

    public function testSdkExceptionDuringTokenizationDoesNotEscapeThePackage(): void
    {
        $original = new \IuguRequestException('<html>502 Bad Gateway</html>', 502);
        $api = (new QueuedIuguApiRequest([$original]))->installAsSdkRequester();

        try {
            (new IuguGateway($api))->createCreditCard($this->rawCreditCardModel());
            $this->fail('Esperava exceção do pacote');
        } catch (MultiPaymentException $e) {
            $this->assertInstanceOf(GatewayNotAvailableException::class, $e);
            $this->assertSame($original, $e->getPrevious());
        }
    }

    public function testTokenizationResponseWithoutIdBecomesGatewayException(): void
    {
        $api = (new QueuedIuguApiRequest([(object) ['method' => 'credit_card']]))->installAsSdkRequester();

        try {
            (new IuguGateway($api))->createCreditCard($this->rawCreditCardModel());
            $this->fail('Esperava GatewayException');
        } catch (GatewayException $e) {
            // resposta 200 sem token: o status vem da global gravada pelo requester
            $this->assertSame(200, $e->httpStatus);
        }

        // sem token não há tentativa de salvar o cartão no cliente
        $this->assertCount(1, $api->calls);
    }

    public function testSuccessfulTokenizationUsesTheReturnedIdAsToken(): void
    {
        $api = (new QueuedIuguApiRequest([
            (object) ['id' => 'tok_1', 'method' => 'credit_card'],
            (object) [
                'id' => 'pm_1',
                'description' => 'CREDIT CARD',
                'data' => (object) ['brand' => 'VISA', 'display_number' => 'XXXX-XXXX-XXXX-4242', 'month' => 12, 'year' => 2030],
            ],
        ]))->installAsSdkRequester();

        $creditCard = (new IuguGateway($api))->createCreditCard($this->rawCreditCardModel());

        $this->assertSame('tok_1', $creditCard->token);
        $this->assertSame('pm_1', $creditCard->id);
        $this->assertCount(2, $api->calls);
        $this->assertSame('tok_1', $api->calls[1]['data']['token']);
    }

    public function testFailureReadingTheInvoiceAfterAChargeDoesNotEscapeThePackage(): void
    {
        // Iugu_Charge::invoice() faz um GET separado; fetchAPI() relança IuguObjectNotFound
        $api = (new QueuedIuguApiRequest([
            (object) ['success' => true, 'invoice_id' => 'inv_1'],
            new \IuguObjectNotFound('{"errors":"Not Found"}', 404),
        ]))->installAsSdkRequester();

        $invoice = $this->invoiceWithId();
        $invoice->creditCard = new CreditCard();
        $invoice->creditCard->id = 'pm_1';

        try {
            (new IuguGateway($api))->chargeInvoiceWithCreditCard($invoice);
            $this->fail('Esperava GatewayException');
        } catch (GatewayException $e) {
            $this->assertInstanceOf(\IuguObjectNotFound::class, $e->getPrevious());
            $this->assertSame(404, $e->httpStatus);
        }

        $this->assertCount(2, $api->calls);
    }

    public function testDeclinedChargeExposesTheHttpStatusOnChargingException(): void
    {
        $api = (new QueuedIuguApiRequest([
            (object) ['success' => false, 'LR' => '51', 'info_message' => 'Saldo insuficiente'],
        ]))->installAsSdkRequester();

        $invoice = $this->invoiceWithId();
        $invoice->creditCard = new CreditCard();
        $invoice->creditCard->id = 'pm_1';

        try {
            (new IuguGateway($api))->chargeInvoiceWithCreditCard($invoice);
            $this->fail('Esperava ChargingException');
        } catch (ChargingException $e) {
            $this->assertSame(200, $e->httpStatus);
            $this->assertNull($e->getPrevious());
        }
    }

    public function testDuplicateInvoiceGoesThroughTheInjectedRequesterAndTranslatesUnauthorized(): void
    {
        // Iugu_Invoice::duplicate() do SDK engole a exceção e devolve false; o driver faz o POST direto
        $api = new QueuedIuguApiRequest([
            new QueuedIuguResponse((object) ['errors' => 'Unauthorized'], 401),
        ]);

        try {
            (new IuguGateway($api))->duplicateInvoice($this->invoiceWithId(), Carbon::parse('2026-10-01'));
            $this->fail('Esperava AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame(401, $e->httpStatus);
        }

        $this->assertCount(1, $api->calls);
        $this->assertSame('POST', $api->calls[0]['method']);
        $this->assertStringEndsWith('/invoices/inv_1/duplicate', $api->calls[0]['url']);
        $this->assertSame(['due_date' => '2026-10-01'], $api->calls[0]['data']);
    }

    public function testDuplicateInvoiceParsesTheDuplicatedInvoice(): void
    {
        $api = new QueuedIuguApiRequest([$this->pendingInvoiceResponse('inv_2')]);

        $duplicated = (new IuguGateway($api))->duplicateInvoice(
            $this->invoiceWithId(),
            Carbon::parse('2026-10-01'),
            ['ignore_due_email' => true]
        );

        $this->assertSame('inv_2', $duplicated->id);
        $this->assertSame(InvoiceStatus::PENDING, $duplicated->status);
        $this->assertSame(['ignore_due_email' => true, 'due_date' => '2026-10-01'], $api->calls[0]['data']);
    }

    public function testUpdateCustomerGoesThroughTheInjectedRequesterAndTranslatesServerError(): void
    {
        // Iugu_Customer::save() do SDK engole a exceção e devolve false; o driver faz o PUT direto
        $original = new \IuguRequestException('<html>502 Bad Gateway</html>', 502);
        $api = new QueuedIuguApiRequest([$original]);

        $customer = $this->customerModel();
        $customer->id = 'cus_1';

        try {
            (new IuguGateway($api))->updateCustomer($customer);
            $this->fail('Esperava GatewayNotAvailableException');
        } catch (GatewayNotAvailableException $e) {
            $this->assertSame($original, $e->getPrevious());
        }

        $this->assertCount(1, $api->calls);
        $this->assertSame('PUT', $api->calls[0]['method']);
        $this->assertStringEndsWith('/customers/cus_1', $api->calls[0]['url']);
        $this->assertSame('Cliente', $api->calls[0]['data']['name']);
        $this->assertSame('20176996915', $api->calls[0]['data']['cpf_cnpj']);
    }

    public function testUpdateCustomerParsesTheUpdatedCustomer(): void
    {
        $api = new QueuedIuguApiRequest([$this->iuguCustomerResponse(['name' => 'Cliente Novo'])]);

        $customer = $this->customerModel();
        $customer->id = 'cus_1';
        $customer->name = 'Cliente Novo';

        $updated = (new IuguGateway($api))->updateCustomer($customer);

        $this->assertSame('cus_1', $updated->id);
        $this->assertSame('Cliente Novo', $updated->name);
        $this->assertSame('iugu', $updated->gateway);
        $this->assertSame('2026-09-02T09:00:00-03:00', $updated->createdAt->toIso8601String());
    }

    public function testDeleteCreditCardGoesThroughTheInjectedRequesterAndTranslatesUnauthorized(): void
    {
        // Iugu_PaymentMethod::delete() do SDK engole a exceção e devolve false; o driver faz o DELETE direto
        $api = new QueuedIuguApiRequest([
            new QueuedIuguResponse((object) ['errors' => 'Unauthorized'], 401),
        ]);

        $creditCard = new CreditCard();
        $creditCard->id = 'pm_1';
        $creditCard->customer = new Customer();
        $creditCard->customer->id = 'cus_1';

        $this->expectException(AuthenticationException::class);

        try {
            (new IuguGateway($api))->deleteCreditCard($creditCard);
        } finally {
            $this->assertCount(1, $api->calls);
            $this->assertSame('DELETE', $api->calls[0]['method']);
            $this->assertStringEndsWith('/customers/cus_1/payment_methods/pm_1', $api->calls[0]['url']);
        }
    }

    public function testDeleteCreditCardSucceedsSilentlyOnAValidResponse(): void
    {
        $api = new QueuedIuguApiRequest([(object) ['id' => 'pm_1', 'description' => 'CREDIT CARD']]);

        $creditCard = new CreditCard();
        $creditCard->id = 'pm_1';
        $creditCard->customer = new Customer();
        $creditCard->customer->id = 'cus_1';

        (new IuguGateway($api))->deleteCreditCard($creditCard);

        $this->assertCount(1, $api->calls);
    }

    private function pendingInvoiceResponse(string $id): object
    {
        return (object) [
            'id' => $id,
            'status' => 'pending',
            'total_cents' => 10000,
            'paid_at' => null,
            'secure_url' => "https://faturas.iugu.com/{$id}",
            'taxes_paid_cents' => null,
            'created_at_iso' => '2026-09-02T09:00:00-03:00',
            'paid_cents' => 0,
            'refunded_cents' => 0,
            'due_date' => '2026-10-01',
            'payment_method' => null,
            'payable_with' => 'pix',
            'customer_id' => 'cus_1',
            'customer_name' => 'Cliente',
            'email' => 'cliente@example.com',
            'payer_phone' => null,
            'payer_phone_prefix' => null,
            'items' => [
                (object) ['description' => 'Item', 'price_cents' => 10000, 'quantity' => 1],
            ],
            'payer_address_zip_code' => null,
            'bank_slip' => null,
            'pix' => null,
            'automatic_pix' => null,
            'credit_card_transaction' => null,
            'variables' => [],
        ];
    }

    private function iuguCustomerResponse(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 'cus_1',
            'name' => 'Cliente',
            'email' => 'cliente@example.com',
            'cpf_cnpj' => '20176996915',
            'phone' => null,
            'phone_prefix' => null,
            'created_at' => '2026-09-02T09:00:00-03:00',
            'custom_variables' => [],
            'default_payment_method_id' => null,
        ], $overrides);
    }

    private function invoiceWithId(): Invoice
    {
        $invoice = new Invoice();
        $invoice->id = 'inv_1';

        return $invoice;
    }

    private function customerModel(): Customer
    {
        $customer = new Customer();
        $customer->name = 'Cliente';
        $customer->email = 'cliente@example.com';
        $customer->taxDocument = '20176996915';

        return $customer;
    }

    private function rawCreditCardModel(): CreditCard
    {
        $creditCard = new CreditCard();
        $creditCard->customer = new Customer();
        $creditCard->customer->id = 'cus_1';
        $creditCard->number = '4111111111111111';
        $creditCard->cvv = '123';
        $creditCard->firstName = 'Cliente';
        $creditCard->lastName = 'Teste';
        $creditCard->month = '12';
        $creditCard->year = '2030';

        return $creditCard;
    }
}
