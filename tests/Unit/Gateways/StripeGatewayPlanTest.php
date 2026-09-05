<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Stripe\ApiRequestor;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Plan;
use Potelo\MultiPayment\Gateways\StripeGateway;
use Potelo\MultiPayment\Enums\PlanInterval;
use Potelo\MultiPayment\Exceptions\NotFoundException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Plano no driver Stripe: criação como par Product e Price, busca por id ou `lookup_key`,
 * listagem paginada por cursor e arquivamento do Price em `deactivatePlan()`.
 */
class StripeGatewayPlanTest extends TestCase
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

    public function testCreatePlanCreatesAProductAndARecurringPriceAndParsesThePrice(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::productResponse(),
            self::priceResponse(),
        ]);

        $plan = new Plan();
        $plan->name = 'Mensal';
        $plan->identifier = 'plano_mensal';
        $plan->amount = 10000;
        $plan->interval = PlanInterval::MONTH;

        $result = (new StripeGateway())->createPlan($plan);

        $this->assertSame(['post /v1/products', 'post /v1/prices'], self::calledPaths($httpClient));
        $this->assertSame(['name' => 'Mensal', 'metadata' => ['identifier' => 'plano_mensal']], $httpClient->calls[0][2]);
        $priceParams = $httpClient->calls[1][2];
        $this->assertSame('prod_fake1', $priceParams['product']);
        $this->assertSame(10000, $priceParams['unit_amount']);
        $this->assertSame('brl', $priceParams['currency']);
        $this->assertSame(['interval' => 'month', 'interval_count' => 1], $priceParams['recurring']);
        $this->assertSame('plano_mensal', $priceParams['lookup_key']);
        $this->assertSame('Mensal', $priceParams['nickname']);
        $this->assertContains('product', $priceParams['expand']);

        $this->assertSame('price_fake1', $result->id);
        $this->assertSame('plano_mensal', $result->identifier);
        $this->assertSame('Mensal', $result->name);
        $this->assertSame(10000, $result->amount);
        $this->assertSame(PlanInterval::MONTH, $result->interval);
        $this->assertSame(1, $result->intervalCount);
        $this->assertSame('BRL', $result->currency);
        $this->assertTrue($result->active);
        $this->assertSame('stripe', $result->gateway);
    }

    public function testCreatePlanUsesTheNameAsIdentifierWhenNoneIsGiven(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::productResponse(),
            self::priceResponse(),
        ]);

        $plan = new Plan();
        $plan->name = 'Mensal';
        $plan->amount = 10000;
        $plan->interval = PlanInterval::MONTH;

        (new StripeGateway())->createPlan($plan);

        $this->assertSame(['identifier' => 'Mensal'], $httpClient->calls[0][2]['metadata']);
        $this->assertSame('Mensal', $httpClient->calls[1][2]['lookup_key']);
    }

    public function testCreatePlanTranslatesYearAndDayIntervals(): void
    {
        foreach ([[PlanInterval::YEAR, 2, 'year'], [PlanInterval::DAY, 15, 'day']] as [$interval, $count, $expected]) {
            $httpClient = RecordingStripeHttpClient::withResponses([
                self::productResponse(),
                self::priceResponse(),
            ]);

            $plan = new Plan();
            $plan->name = 'Plano';
            $plan->amount = 10000;
            $plan->interval = $interval;
            $plan->intervalCount = $count;

            (new StripeGateway())->createPlan($plan);

            $this->assertSame(['interval' => $expected, 'interval_count' => $count], $httpClient->calls[1][2]['recurring']);
        }
    }

    public function testCreatePlanWithoutAnIntervalFailsBeforeTheNetwork(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);
        $plan = new Plan();
        $plan->name = 'Mensal';
        $plan->amount = 10000;

        $this->expectException(ModelAttributeValidationException::class);

        try {
            (new StripeGateway())->createPlan($plan);
        } finally {
            $this->assertSame([], $httpClient->calls);
        }
    }

    public function testGetPlanByIdRetrievesThePriceWithTheProductExpanded(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([self::priceResponse()]);

        $plan = new Plan();
        $plan->id = 'price_fake1';

        $result = (new StripeGateway())->getPlan($plan);

        $this->assertSame(['get /v1/prices/price_fake1'], self::calledPaths($httpClient));
        $this->assertSame(['expand' => ['product']], $httpClient->calls[0][2]);
        $this->assertSame('plano_mensal', $result->identifier);
    }

    public function testGetPlanByIdentifierSearchesTheLookupKey(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([self::priceListResponse([self::priceResponse()])]);

        $plan = new Plan();
        $plan->identifier = 'plano_mensal';

        $result = (new StripeGateway())->getPlan($plan);

        $this->assertSame(['get /v1/prices'], self::calledPaths($httpClient));
        $this->assertSame(['plano_mensal'], $httpClient->calls[0][2]['lookup_keys']);
        $this->assertContains('data.product', $httpClient->calls[0][2]['expand']);
        $this->assertSame('price_fake1', $result->id);
    }

    public function testGetPlanByAnIdentifierWithThePricePrefixReadsItAsAnId(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([self::priceResponse()]);

        $plan = new Plan();
        $plan->identifier = 'price_fake1';

        (new StripeGateway())->getPlan($plan);

        $this->assertSame(['get /v1/prices/price_fake1'], self::calledPaths($httpClient));
    }

    public function testGetPlanByAnUnknownIdentifierRaisesNotFound(): void
    {
        RecordingStripeHttpClient::withResponses([self::priceListResponse([])]);

        $plan = new Plan();
        $plan->identifier = 'inexistente';

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessageMatches('/inexistente/');

        (new StripeGateway())->getPlan($plan);
    }

    public function testGetPlanWithoutIdOrIdentifierIsRefusedBeforeTheNetwork(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);

        $this->expectException(ModelAttributeValidationException::class);

        try {
            (new StripeGateway())->getPlan(new Plan());
        } finally {
            $this->assertSame([], $httpClient->calls);
        }
    }

    public function testListPlansListsRecurringPrices(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::priceListResponse([self::priceResponse(), self::priceResponse(['id' => 'price_fake2', 'lookup_key' => 'plano_anual'])]),
        ]);

        $plans = (new StripeGateway())->listPlans(1, 50);

        $this->assertSame(['get /v1/prices'], self::calledPaths($httpClient));
        $this->assertSame('recurring', $httpClient->calls[0][2]['type']);
        $this->assertSame(50, $httpClient->calls[0][2]['limit']);
        $this->assertCount(2, $plans);
        $this->assertSame('plano_anual', $plans[1]->identifier);
    }

    public function testListPlansWalksTheCursorToReachALaterPage(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::priceListResponse([self::priceResponse()], true),
            self::priceListResponse([self::priceResponse(['id' => 'price_fake2'])]),
        ]);

        $plans = (new StripeGateway())->listPlans(2, 1);

        $this->assertCount(2, $httpClient->calls);
        $this->assertSame('price_fake1', $httpClient->calls[1][2]['starting_after']);
        $this->assertCount(1, $plans);
        $this->assertSame('price_fake2', $plans[0]->id);
    }

    public function testListPlansBeyondTheEndReturnsAnEmptyList(): void
    {
        RecordingStripeHttpClient::withResponses([self::priceListResponse([self::priceResponse()])]);

        $this->assertSame([], (new StripeGateway())->listPlans(2, 1));
    }

    public function testListPlansValidatesPageAndLimitBeforeTheNetwork(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);
        $gateway = new StripeGateway();

        foreach ([[0, 10], [1, 0], [1, 101]] as [$page, $limit]) {
            try {
                $gateway->listPlans($page, $limit);
                $this->fail('Esperava ModelAttributeValidationException');
            } catch (ModelAttributeValidationException) {
            }
        }
        $this->assertSame([], $httpClient->calls);
    }

    public function testDeactivatePlanArchivesThePrice(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([self::priceResponse(['active' => false])]);

        $plan = new Plan();
        $plan->id = 'price_fake1';

        $result = (new StripeGateway())->deactivatePlan($plan);

        $this->assertSame(['post /v1/prices/price_fake1'], self::calledPaths($httpClient));
        // o encoder do stripe-php envia booleano como a string 'false'
        $this->assertSame('false', $httpClient->calls[0][2]['active']);
        $this->assertFalse($result->active);
    }

    public function testDeactivatePlanWithoutIdOrIdentifierIsRefusedBeforeTheNetwork(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);

        $this->expectException(ModelAttributeValidationException::class);

        try {
            (new StripeGateway())->deactivatePlan(new Plan());
        } finally {
            $this->assertSame([], $httpClient->calls);
        }
    }

    public function testDeactivatePlanByIdentifierResolvesThePriceFirst(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::priceListResponse([self::priceResponse()]),
            self::priceResponse(['active' => false]),
        ]);

        $plan = new Plan();
        $plan->identifier = 'plano_mensal';

        $result = (new StripeGateway())->deactivatePlan($plan);

        $this->assertSame(['get /v1/prices', 'post /v1/prices/price_fake1'], self::calledPaths($httpClient));
        $this->assertFalse($result->active);
    }

    /**
     * @return string[]  `método caminho` de cada chamada gravada
     */
    private static function calledPaths(RecordingStripeHttpClient $httpClient): array
    {
        return array_map(
            static fn (array $call) => $call[0] . ' ' . parse_url($call[1], PHP_URL_PATH),
            $httpClient->calls
        );
    }

    private static function productResponse(): array
    {
        return [
            'id' => 'prod_fake1',
            'object' => 'product',
            'name' => 'Mensal',
            'active' => true,
            'created' => 1786700000,
            'metadata' => ['identifier' => 'plano_mensal'],
        ];
    }

    private static function priceResponse(array $overrides = []): array
    {
        return array_merge([
            'id' => 'price_fake1',
            'object' => 'price',
            'active' => true,
            'currency' => 'brl',
            'lookup_key' => 'plano_mensal',
            'nickname' => 'Mensal',
            'created' => 1786700000,
            'product' => self::productResponse(),
            'recurring' => ['interval' => 'month', 'interval_count' => 1, 'usage_type' => 'licensed'],
            'type' => 'recurring',
            'unit_amount' => 10000,
            'unit_amount_decimal' => '10000',
        ], $overrides);
    }

    private static function priceListResponse(array $prices, bool $hasMore = false): array
    {
        return [
            'object' => 'list',
            'url' => '/v1/prices',
            'has_more' => $hasMore,
            'data' => $prices,
        ];
    }
}
