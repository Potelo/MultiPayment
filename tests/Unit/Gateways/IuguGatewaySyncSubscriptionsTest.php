<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Gateways\IuguGateway;

/**
 * Sincronização das emulações de assinatura na Iugu (`syncSubscriptions()`): remoção do
 * desconto vencido, limpeza de variável órfã e aplicação do cancelamento agendado, com prova
 * de que uma segunda passada sobre o estado resultante não escreve nada.
 */
class IuguGatewaySyncSubscriptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-04 12:00:00');

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment' => [
                'default' => 'iugu',
                'gateways' => ['iugu' => ['api_key' => 'test-api-key', 'class' => IuguGateway::class]],
            ],
        ]));
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    private static function subscription(string $id, array $overrides = []): object
    {
        return (object) array_merge([
            'id' => $id,
            'customer_id' => 'cus_1',
            'plan_identifier' => 'plano_mensal',
            'expires_at' => '2026-10-01',
            'active' => true,
            'suspended' => false,
            'in_trial' => false,
        ], $overrides);
    }

    private static function listResponse(array $items): object
    {
        return (object) ['totalItems' => count($items), 'items' => $items];
    }

    private static function expiredDiscountSubscription(): object
    {
        return self::subscription('sub_expirado', [
            'subitems' => [(object) ['id' => 'si_d1', 'description' => 'Promo', 'price_cents' => -500, 'quantity' => 1, 'recurrent' => true]],
            'custom_variables' => [(object) ['name' => 'mp_discount_si_d1_until', 'value' => '2026-09-03']],
        ]);
    }

    private static function dueCancellationSubscription(): object
    {
        return self::subscription('sub_agendado', [
            'custom_variables' => [
                (object) ['name' => 'mp_cancel_at_period_end', 'value' => '1'],
                (object) ['name' => 'mp_cancel_scheduled_for', 'value' => '2026-09-04'],
            ],
        ]);
    }

    public function testSyncAppliesEachPendingEmulationAndSkipsWhatIsNotDue(): void
    {
        $api = new QueuedIuguApiRequest([
            self::listResponse([
                self::expiredDiscountSubscription(),
                self::subscription('sub_vigente', [
                    'subitems' => [(object) ['id' => 'si_d2', 'description' => 'Promo', 'price_cents' => -500, 'quantity' => 1, 'recurrent' => true]],
                    'custom_variables' => [(object) ['name' => 'mp_discount_si_d2_until', 'value' => '2026-12-31']],
                ]),
                // validade inclusiva: o desconto que vale até hoje ainda não venceu
                self::subscription('sub_no_limite', [
                    'subitems' => [(object) ['id' => 'si_d3', 'description' => 'Promo', 'price_cents' => -500, 'quantity' => 1, 'recurrent' => true]],
                    'custom_variables' => [(object) ['name' => 'mp_discount_si_d3_until', 'value' => '2026-09-04']],
                ]),
                self::subscription('sub_suspensa', [
                    'suspended' => true,
                    'custom_variables' => [
                        (object) ['name' => 'mp_cancel_at_period_end', 'value' => '1'],
                        (object) ['name' => 'mp_cancel_scheduled_for', 'value' => '2026-09-01'],
                    ],
                ]),
                self::dueCancellationSubscription(),
                self::subscription('sub_orfa', [
                    'custom_variables' => [(object) ['name' => 'mp_discount_si_x_until', 'value' => '2026-09-01']],
                ]),
                self::subscription('sub_futura', [
                    'custom_variables' => [
                        (object) ['name' => 'mp_cancel_at_period_end', 'value' => '1'],
                        (object) ['name' => 'mp_cancel_scheduled_for', 'value' => '2026-09-05'],
                    ],
                ]),
            ]),
            self::subscription('sub_expirado'),
            self::subscription('sub_agendado', ['suspended' => true]),
            self::subscription('sub_agendado', ['suspended' => true]),
            self::subscription('sub_orfa'),
        ]);

        $actions = (new IuguGateway($api))->syncSubscriptions();

        $this->assertSame(
            [
                ['sub_expirado', 'remove_discount'],
                ['sub_agendado', 'cancel'],
                ['sub_orfa', 'remove_orphan_discount_variable'],
            ],
            array_map(static fn (array $action) => [$action['subscription'], $action['action']], $actions)
        );

        $this->assertCount(5, $api->calls);
        $this->assertStringContainsString('limit=100', $api->calls[0]['url']);
        $this->assertStringContainsString('start=0', $api->calls[0]['url']);

        $this->assertSame('PUT', $api->calls[1]['method']);
        $this->assertStringEndsWith('/subscriptions/sub_expirado', $api->calls[1]['url']);
        $this->assertSame([
            'custom_variables' => [['name' => 'mp_discount_si_d1_until', '_destroy' => true]],
            'subitems' => [['id' => 'si_d1', '_destroy' => true]],
        ], $api->calls[1]['data']);

        // a marca vai antes da suspensão, para uma falha no meio ser reprocessada na rodada
        // seguinte (assinatura suspensa sem a marca seria pulada)
        $this->assertSame('PUT', $api->calls[2]['method']);
        $this->assertStringEndsWith('/subscriptions/sub_agendado', $api->calls[2]['url']);
        $this->assertSame('mp_canceled_at', $api->calls[2]['data']['custom_variables'][0]['name']);
        $this->assertSame('POST', $api->calls[3]['method']);
        $this->assertStringEndsWith('/subscriptions/sub_agendado/suspend', $api->calls[3]['url']);

        $this->assertSame('PUT', $api->calls[4]['method']);
        $this->assertStringEndsWith('/subscriptions/sub_orfa', $api->calls[4]['url']);
        $this->assertSame(
            ['custom_variables' => [['name' => 'mp_discount_si_x_until', '_destroy' => true]]],
            $api->calls[4]['data']
        );
    }

    /**
     * Sobre o estado que a primeira passada deixou (subitem e variável removidos, assinatura
     * agendada suspensa com `mp_canceled_at`), a segunda passada não escreve nada.
     */
    public function testSyncIsIdempotentOverTheResultingState(): void
    {
        $api = new QueuedIuguApiRequest([
            self::listResponse([
                self::subscription('sub_expirado'),
                self::subscription('sub_agendado', [
                    'suspended' => true,
                    'custom_variables' => [
                        (object) ['name' => 'mp_cancel_at_period_end', 'value' => '1'],
                        (object) ['name' => 'mp_cancel_scheduled_for', 'value' => '2026-09-04'],
                        (object) ['name' => 'mp_canceled_at', 'value' => '2026-09-04T12:00:00-03:00'],
                    ],
                ]),
            ]),
        ]);

        $actions = (new IuguGateway($api))->syncSubscriptions();

        $this->assertSame([], $actions);
        $this->assertCount(1, $api->calls);
    }

    public function testDryRunReportsTheActionsWithoutWriting(): void
    {
        $api = new QueuedIuguApiRequest([
            self::listResponse([self::expiredDiscountSubscription(), self::dueCancellationSubscription()]),
        ]);

        $actions = (new IuguGateway($api))->syncSubscriptions(true);

        $this->assertCount(2, $actions);
        $this->assertCount(1, $api->calls);
        $this->assertSame('GET', $api->calls[0]['method']);
    }

    /**
     * Uma página cheia dispara a leitura da página seguinte, e as assinaturas das duas são
     * processadas.
     */
    public function testSyncPagesThroughTheSubscriptions(): void
    {
        $fullPage = array_map(
            static fn (int $i) => self::subscription("sub_{$i}"),
            range(1, 100)
        );

        $api = new QueuedIuguApiRequest([
            self::listResponse($fullPage),
            self::listResponse([self::expiredDiscountSubscription()]),
            self::subscription('sub_expirado'),
        ]);

        $actions = (new IuguGateway($api))->syncSubscriptions();

        $this->assertCount(1, $actions);
        $this->assertCount(3, $api->calls, 'a ação da segunda página precisa ser aplicada');
        $this->assertStringContainsString('start=0', $api->calls[0]['url']);
        $this->assertStringContainsString('start=100', $api->calls[1]['url']);
        $this->assertSame('PUT', $api->calls[2]['method']);
    }

    /**
     * Uma assinatura cuja escrita falha não derruba a varredura: o erro vai para o log e as
     * assinaturas seguintes são sincronizadas.
     */
    public function testAFailingSubscriptionDoesNotAbortTheSweep(): void
    {
        $app = Facade::getFacadeApplication();
        $app->instance('log', $logger = new \Potelo\MultiPayment\Tests\Unit\RecordingLogger());

        $api = new QueuedIuguApiRequest([
            self::listResponse([
                self::expiredDiscountSubscription(),
                self::dueCancellationSubscription(),
            ]),
            new \IuguObjectNotFound('not found'),
            self::subscription('sub_agendado'),
            self::subscription('sub_agendado', ['suspended' => true]),
        ]);

        $actions = (new IuguGateway($api))->syncSubscriptions();

        $this->assertSame(['sub_agendado'], array_column($actions, 'subscription'));
        $this->assertCount(4, $api->calls, 'a segunda assinatura precisa ser sincronizada depois da falha');
        $failures = array_filter(
            $logger->records,
            static fn (array $record) => str_contains($record['message'], 'sub_expirado')
        );
        $this->assertCount(1, $failures);
        $this->assertSame('warning', array_values($failures)[0]['level']);
    }

    /**
     * Data de agendamento que não é uma data lê como sem agendamento, com aviso no log, e
     * nada é aplicado.
     */
    public function testAnUnreadableScheduledDateIsIgnoredWithAWarning(): void
    {
        $app = Facade::getFacadeApplication();
        $app->instance('log', $logger = new \Potelo\MultiPayment\Tests\Unit\RecordingLogger());

        $api = new QueuedIuguApiRequest([
            self::listResponse([self::subscription('sub_ilegivel', [
                'custom_variables' => [
                    (object) ['name' => 'mp_cancel_at_period_end', 'value' => '1'],
                    (object) ['name' => 'mp_cancel_scheduled_for', 'value' => 'amanha'],
                ],
            ])]),
        ]);

        $actions = (new IuguGateway($api))->syncSubscriptions();

        $this->assertSame([], $actions);
        $this->assertCount(1, $api->calls);
        $this->assertCount(1, $logger->records);
        $this->assertSame('warning', $logger->records[0]['level']);
        $this->assertStringContainsString('mp_cancel_scheduled_for', $logger->records[0]['message']);
    }
}
