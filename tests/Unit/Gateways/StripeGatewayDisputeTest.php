<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Carbon\Carbon;
use Stripe\ApiRequestor;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Dispute;
use Potelo\MultiPayment\Gateways\StripeGateway;
use Potelo\MultiPayment\Enums\DisputeStatus;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;
use Potelo\MultiPayment\Tests\Unit\RecordingLogger;

/**
 * Operações de contestação do driver Stripe: busca, listagem, contestar com evidências e
 * acatar. A resposta gravada da sandbox fica em `tests/fixtures/stripe/disputes/`.
 */
class StripeGatewayDisputeTest extends TestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment' => [
                'gateways' => [
                    'stripe' => [
                        'api_key' => 'sk_test_fake',
                        'class' => StripeGateway::class,
                    ],
                ],
            ],
        ]));
        $app->instance('log', $this->logger = new RecordingLogger());
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

    public function testGetDisputeParsesTheRecordedDispute(): void
    {
        $stripeDispute = self::recordedDispute('needs_response');
        $httpClient = RecordingStripeHttpClient::withResponses([$stripeDispute]);

        $dispute = new Dispute();
        $dispute->id = $stripeDispute['id'];
        $result = (new StripeGateway())->getDispute($dispute);

        [$method, $url] = $httpClient->calls[0];
        $this->assertSame('get', $method);
        $this->assertSame("/v1/disputes/{$stripeDispute['id']}", parse_url($url, PHP_URL_PATH));

        $this->assertSame($dispute, $result);
        $this->assertSame($stripeDispute['id'], $result->id);
        $this->assertSame($stripeDispute['payment_intent'], $result->invoiceId);
        $this->assertSame(12345, $result->amount);
        $this->assertSame(DisputeStatus::OPEN, $result->status);
        $this->assertSame('fraudulent', $result->reason);
        $this->assertInstanceOf(Carbon::class, $result->dueBy);
        $this->assertSame($stripeDispute['evidence_details']['due_by'], $result->dueBy->getTimestamp());
        $this->assertSame($stripeDispute['created'], $result->openedAt->getTimestamp());
        $this->assertNull($result->closedAt);
        $this->assertSame('stripe', $result->gateway);
        $this->assertSame([], $this->logger->records);
    }

    public function testGetDisputeWithoutIdIsRefusedBeforeAnyRequest(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);

        try {
            (new StripeGateway())->getDispute(new Dispute());
            $this->fail('Era esperada ModelAttributeValidationException');
        } catch (ModelAttributeValidationException) {
        }

        $this->assertSame([], $httpClient->calls);
    }

    /**
     * Mapa dos oito status do objeto Dispute da Stripe para o genérico.
     */
    public static function statusProvider(): array
    {
        return [
            'warning_needs_response' => ['warning_needs_response', DisputeStatus::OPEN],
            'needs_response' => ['needs_response', DisputeStatus::OPEN],
            'warning_under_review' => ['warning_under_review', DisputeStatus::UNDER_REVIEW],
            'under_review' => ['under_review', DisputeStatus::UNDER_REVIEW],
            'won' => ['won', DisputeStatus::WON],
            'warning_closed' => ['warning_closed', DisputeStatus::WON],
            'prevented' => ['prevented', DisputeStatus::WON],
            'lost' => ['lost', DisputeStatus::LOST],
        ];
    }

    #[DataProvider('statusProvider')]
    public function testMapsEveryStripeDisputeStatusToTheGenericOne(string $stripeStatus, DisputeStatus $expected): void
    {
        RecordingStripeHttpClient::withResponses([
            self::recordedDispute('needs_response', ['status' => $stripeStatus]),
        ]);

        $dispute = new Dispute();
        $dispute->id = 'du_fake';

        $this->assertSame($expected, (new StripeGateway())->getDispute($dispute)->status);
        $this->assertSame([], $this->logger->records);
    }

    public function testAnUnknownDisputeStatusBecomesUnknownWithAWarning(): void
    {
        RecordingStripeHttpClient::withResponses([
            self::recordedDispute('needs_response', ['status' => 'status_novo']),
        ]);

        $dispute = new Dispute();
        $dispute->id = 'du_fake';

        $this->assertSame(DisputeStatus::UNKNOWN, (new StripeGateway())->getDispute($dispute)->status);
        $this->assertCount(1, $this->logger->records);
        $this->assertSame('warning', $this->logger->records[0]['level']);
    }

    public function testListDisputesPassesTheLimitAndParsesEachDispute(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::fixture('disputes/needs_response'),
        ]);

        $disputes = (new StripeGateway())->listDisputes(1, 50);

        [$method, $url, $params] = $httpClient->calls[0];
        $this->assertSame('get', $method);
        $this->assertSame('/v1/disputes', parse_url($url, PHP_URL_PATH));
        $this->assertSame(['limit' => 50], $params);

        $this->assertCount(1, $disputes);
        $this->assertInstanceOf(Dispute::class, $disputes[0]);
        $this->assertSame(DisputeStatus::OPEN, $disputes[0]->status);
    }

    public function testListDisputesValidatesPageAndLimitBeforeAnyRequest(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);

        try {
            (new StripeGateway())->listDisputes(0);
            $this->fail('Era esperada ModelAttributeValidationException');
        } catch (ModelAttributeValidationException) {
        }

        try {
            (new StripeGateway())->listDisputes(1, 101);
            $this->fail('Era esperada ModelAttributeValidationException');
        } catch (ModelAttributeValidationException) {
        }

        $this->assertSame([], $httpClient->calls);
    }

    public function testContestDisputeSubmitsTheEvidenceWithTheIdempotencyKey(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::recordedDispute('needs_response', ['status' => 'under_review']),
        ]);

        $result = (new StripeGateway())->contestDispute(
            'du_fake123',
            ['uncategorized_text' => 'O cliente usou o produto no dia seguinte à compra.'],
            'contest-1'
        );

        [$method, $url, $params] = $httpClient->calls[0];
        $this->assertSame('post', $method);
        $this->assertSame('/v1/disputes/du_fake123', parse_url($url, PHP_URL_PATH));
        $this->assertSame([
            'evidence' => ['uncategorized_text' => 'O cliente usou o produto no dia seguinte à compra.'],
            // o encoder do stripe-php serializa booleanos como string antes da camada HTTP
            'submit' => 'true',
        ], $params);
        $this->assertSame('contest-1', $httpClient->header(0, 'Idempotency-Key'));

        $this->assertSame(DisputeStatus::UNDER_REVIEW, $result->status);
    }

    public function testAcceptDisputeClosesTheDisputeAsLost(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::recordedDispute('lost'),
        ]);

        $result = (new StripeGateway())->acceptDispute('du_fake123', 'accept-1');

        [$method, $url] = $httpClient->calls[0];
        $this->assertSame('post', $method);
        $this->assertSame('/v1/disputes/du_fake123/close', parse_url($url, PHP_URL_PATH));
        $this->assertSame('accept-1', $httpClient->header(0, 'Idempotency-Key'));

        $this->assertSame(DisputeStatus::LOST, $result->status);
        $this->assertTrue($result->status->isLost());
    }

    /**
     * Caminho do model: `contest()` e `accept()` resolvem o gateway gravado no model e
     * delegam ao driver.
     */
    public function testDisputeModelOperationsDelegateToTheGateway(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::recordedDispute('needs_response', ['status' => 'under_review']),
            self::recordedDispute('lost'),
        ]);

        $dispute = new Dispute();
        $dispute->id = 'du_fake123';
        $dispute->gateway = 'stripe';

        $contested = $dispute->contest(['uncategorized_text' => 'evidencia']);
        $accepted = $dispute->accept();

        $this->assertSame(DisputeStatus::UNDER_REVIEW, $contested->status);
        $this->assertSame(DisputeStatus::LOST, $accepted->status);
        $this->assertSame('/v1/disputes/du_fake123', parse_url($httpClient->calls[0][1], PHP_URL_PATH));
        $this->assertSame('/v1/disputes/du_fake123/close', parse_url($httpClient->calls[1][1], PHP_URL_PATH));
    }

    public function testInvoiceHydratesOnceAndCachesTheResult(): void
    {
        $stripePaymentIntent = self::fixture('payment_intents/paid');
        $httpClient = RecordingStripeHttpClient::withResponses([$stripePaymentIntent]);

        $dispute = new Dispute();
        $dispute->invoiceId = $stripePaymentIntent['id'];
        $dispute->gateway = 'stripe';

        $invoice = $dispute->invoice();

        $this->assertSame($stripePaymentIntent['id'], $invoice->id);
        $this->assertCount(1, $httpClient->calls);
        $this->assertSame('get', $httpClient->calls[0][0]);

        $this->assertSame($invoice, $dispute->invoice());
        $this->assertCount(1, $httpClient->calls);
    }

    public function testInvoiceWithoutTheInvoiceIdIsRefusedBeforeAnyRequest(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);

        $dispute = new Dispute();
        $dispute->gateway = 'stripe';

        try {
            $dispute->invoice();
            $this->fail('Era esperada ModelAttributeValidationException');
        } catch (ModelAttributeValidationException) {
        }

        $this->assertSame([], $httpClient->calls);
    }

    /**
     * Objeto Dispute gravado da sandbox (`tests/fixtures/stripe/disputes/`), com os campos
     * sobrescritos do cenário.
     */
    private static function recordedDispute(string $fixture, array $overrides = []): array
    {
        return array_merge(self::fixture("disputes/{$fixture}")['data'][0], $overrides);
    }

    private static function fixture(string $path): array
    {
        return json_decode(file_get_contents(__DIR__ . "/../../fixtures/stripe/{$path}.json"), true);
    }
}
