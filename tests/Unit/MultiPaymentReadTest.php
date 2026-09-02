<?php

namespace Potelo\MultiPayment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\MultiPayment;
use Potelo\MultiPayment\Models\Plan;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Enums\PlanInterval;
use Potelo\MultiPayment\Gateways\IuguGateway;
use Potelo\MultiPayment\Enums\SubscriptionStatus;
use Potelo\MultiPayment\Exceptions\NotFoundException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;
use Potelo\MultiPayment\Tests\Unit\Gateways\QueuedIuguApiRequest;

/**
 * Leitura de assinatura e de plano pela fachada, com o gateway construído pela configuração e
 * o fake instalado como requester do SDK da Iugu.
 */
class MultiPaymentReadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment' => [
                'default' => 'iugu',
                'gateways' => ['iugu' => ['api_key' => 'iugu-key', 'class' => IuguGateway::class]],
            ],
        ]));
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        QueuedIuguApiRequest::restoreSdkRequester();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testGetSubscriptionReadsTheSubscriptionById(): void
    {
        $api = (new QueuedIuguApiRequest([
            (object) [
                'id' => 'sub_1',
                'customer_id' => 'cus_1',
                'plan_identifier' => 'plano_mensal',
                'price_cents' => 10000,
                'expires_at' => '2026-10-01',
                'active' => true,
                'suspended' => false,
                'in_trial' => false,
            ],
        ]))->installAsSdkRequester();

        $subscription = (new MultiPayment('iugu'))->getSubscription('sub_1');

        $this->assertInstanceOf(Subscription::class, $subscription);
        $this->assertCount(1, $api->calls);
        $this->assertSame('GET', $api->calls[0]['method']);
        $this->assertStringEndsWith('/subscriptions/sub_1', $api->calls[0]['url']);
        $this->assertSame('sub_1', $subscription->id);
        $this->assertSame('plano_mensal', $subscription->planId);
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertSame('iugu', $subscription->gateway);
    }

    /**
     * O valor informado é buscado primeiro como identificador do plano.
     */
    public function testGetPlanReadsThePlanByItsIdentifier(): void
    {
        $api = (new QueuedIuguApiRequest([self::planResponse()]))->installAsSdkRequester();

        $plan = (new MultiPayment('iugu'))->getPlan('plano_mensal');

        $this->assertInstanceOf(Plan::class, $plan);
        $this->assertCount(1, $api->calls);
        $this->assertStringEndsWith('/plans/identifier/plano_mensal', $api->calls[0]['url']);
        $this->assertSame('plan_1', $plan->id);
        $this->assertSame('plano_mensal', $plan->identifier);
        $this->assertSame(10000, $plan->amount);
        $this->assertSame(PlanInterval::MONTH, $plan->interval);
    }

    /**
     * Quando o identificador não existe, o mesmo valor é buscado como id do gateway.
     */
    public function testGetPlanFallsBackToTheIdWhenTheIdentifierDoesNotExist(): void
    {
        $api = (new QueuedIuguApiRequest([
            new \IuguObjectNotFound('plan: not found'),
            self::planResponse(),
        ]))->installAsSdkRequester();

        $plan = (new MultiPayment('iugu'))->getPlan('plan_1');

        $this->assertCount(2, $api->calls);
        $this->assertStringEndsWith('/plans/identifier/plan_1', $api->calls[0]['url']);
        $this->assertStringEndsWith('/plans/plan_1', $api->calls[1]['url']);
        $this->assertSame('plan_1', $plan->id);
    }

    /**
     * Plano inexistente pelos dois caminhos lança a `NotFoundException` da busca pelo id.
     */
    public function testGetPlanThrowsNotFoundWhenNeitherLookupFinds(): void
    {
        $api = (new QueuedIuguApiRequest([
            new \IuguObjectNotFound('identifier: not found'),
            new \IuguObjectNotFound('id: not found'),
        ]))->installAsSdkRequester();

        try {
            (new MultiPayment('iugu'))->getPlan('inexistente');
            $this->fail('Esperava NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertStringContainsString('id: not found', $e->getMessage());
        }
        $this->assertCount(2, $api->calls);
        $this->assertStringEndsWith('/plans/inexistente', $api->calls[1]['url']);
    }

    /**
     * Só o 404 da busca pelo identificador leva à busca pelo id; outro erro sobe sem a segunda
     * requisição.
     */
    public function testGetPlanDoesNotFallBackToTheIdWhenTheIdentifierLookupFailsForAnotherReason(): void
    {
        $api = (new QueuedIuguApiRequest([
            new \IuguRequestException('Bad Gateway', 502),
        ]))->installAsSdkRequester();

        try {
            (new MultiPayment('iugu'))->getPlan('plano_mensal');
            $this->fail('Esperava GatewayNotAvailableException');
        } catch (GatewayNotAvailableException) {
            $this->assertCount(1, $api->calls);
        }
    }

    private static function planResponse(): object
    {
        return (object) [
            'id' => 'plan_1',
            'identifier' => 'plano_mensal',
            'name' => 'Mensal',
            'interval' => 1,
            'interval_type' => 'months',
            'prices' => [(object) ['value_cents' => 10000, 'currency' => 'BRL']],
        ];
    }
}
