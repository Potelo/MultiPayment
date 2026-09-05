<?php

namespace Potelo\MultiPayment\Tests\Unit\Console;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Potelo\MultiPayment\Gateways\IuguGateway;
use Potelo\MultiPayment\Gateways\StripeGateway;
use Potelo\MultiPayment\Tests\Unit\RecordingLogger;
use Potelo\MultiPayment\Console\SyncSubscriptionsCommand;
use Potelo\MultiPayment\Tests\Unit\Gateways\QueuedIuguApiRequest;

/**
 * Comando `multipayment:sync-subscriptions`: aplica as emulações no gateway que as declara,
 * informa que o gateway que gerencia sozinho não tem o que sincronizar e, com `--dry-run`,
 * relata sem escrever.
 */
class SyncSubscriptionsCommandTest extends TestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-04 12:00:00');

        // Command::run() configura os prompts pelo Application; o container mínimo dos testes
        // só precisa responder que está em teste
        $app = new class extends Container {
            public function runningUnitTests(): bool
            {
                return true;
            }
        };
        $app->instance('config', new Repository([
            'multi-payment' => [
                'default' => 'iugu',
                'gateways' => [
                    'iugu' => ['api_key' => 'test-api-key', 'class' => IuguGateway::class],
                    'stripe' => ['api_key' => 'sk_test_fake', 'class' => StripeGateway::class],
                ],
            ],
        ]));
        $app->instance('log', $this->logger = new RecordingLogger());
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        QueuedIuguApiRequest::restoreSdkRequester();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    /**
     * Executa o comando com o container mínimo dos testes unitários e devolve a saída.
     *
     * @param  array  $input
     * @return string
     */
    private function runCommand(array $input): string
    {
        $command = new SyncSubscriptionsCommand();
        $command->setLaravel(Facade::getFacadeApplication());
        $output = new BufferedOutput();

        $this->assertSame(0, $command->run(new ArrayInput($input), $output));

        return $output->fetch();
    }

    private static function listResponse(array $items): object
    {
        return (object) ['totalItems' => count($items), 'items' => $items];
    }

    private static function expiredDiscountSubscription(): object
    {
        return (object) [
            'id' => 'sub_expirado',
            'plan_identifier' => 'plano_mensal',
            'expires_at' => '2026-10-01',
            'active' => true,
            'suspended' => false,
            'subitems' => [(object) ['id' => 'si_d1', 'description' => 'Promo', 'price_cents' => -500, 'quantity' => 1, 'recurrent' => true]],
            'custom_variables' => [(object) ['name' => 'mp_discount_si_d1_until', 'value' => '2026-09-03']],
        ];
    }

    public function testTheCommandAppliesTheIuguEmulationsAndLogsEachAction(): void
    {
        $api = (new QueuedIuguApiRequest([
            self::listResponse([self::expiredDiscountSubscription()]),
            (object) ['id' => 'sub_expirado'],
        ]))->installAsSdkRequester();

        $output = $this->runCommand(['--gateway' => 'iugu']);

        $this->assertStringContainsString('assinatura sub_expirado', $output);
        $this->assertStringContainsString('desconto si_d1 vencido em 2026-09-03 removido', $output);
        $this->assertCount(2, $api->calls);
        $this->assertSame('PUT', $api->calls[1]['method']);

        $logged = array_filter(
            $this->logger->records,
            static fn (array $record) => str_contains($record['message'], 'multipayment:sync-subscriptions')
        );
        $this->assertCount(1, $logged);
        $record = array_values($logged)[0];
        $this->assertSame('info', $record['level']);
        $this->assertSame('remove_discount', $record['context']['action']);
        $this->assertSame('sub_expirado', $record['context']['subscription']);
    }

    public function testDryRunReportsWithoutWriting(): void
    {
        $api = (new QueuedIuguApiRequest([
            self::listResponse([self::expiredDiscountSubscription()]),
        ]))->installAsSdkRequester();

        $output = $this->runCommand(['--gateway' => 'iugu', '--dry-run' => true]);

        $this->assertStringContainsString('[dry-run]', $output);
        $this->assertStringContainsString('desconto si_d1 vencido', $output);
        $this->assertCount(1, $api->calls);
        $this->assertSame('GET', $api->calls[0]['method']);
    }

    public function testAGatewayThatManagesTheFeaturesItselfIsSkippedWithAMessage(): void
    {
        $output = $this->runCommand(['--gateway' => 'stripe']);

        $this->assertStringContainsString('[stripe] o gateway gerencia cupom e cancelamento ao fim do ciclo', $output);
    }

    /**
     * Sem `--gateway`, o comando percorre todos os gateways configurados: sincroniza a Iugu e
     * pula o Stripe com a mensagem.
     */
    public function testWithoutTheOptionEveryConfiguredGatewayIsVisited(): void
    {
        $api = (new QueuedIuguApiRequest([
            self::listResponse([]),
        ]))->installAsSdkRequester();

        $output = $this->runCommand([]);

        $this->assertStringContainsString('[iugu] nada a aplicar.', $output);
        $this->assertStringContainsString('[stripe] o gateway gerencia', $output);
        $this->assertCount(1, $api->calls);
    }

    /**
     * A configuração padrão do pacote registra os dois gateways mesmo quando a aplicação só
     * usa um; o gateway sem `api_key` é pulado na varredura sem `--gateway`.
     */
    public function testAGatewayWithoutAnApiKeyIsSkippedInTheSweep(): void
    {
        Facade::getFacadeApplication()['config']->set('multi-payment.gateways.iugu.api_key', null);
        $api = (new QueuedIuguApiRequest([]))->installAsSdkRequester();

        $output = $this->runCommand([]);

        $this->assertStringContainsString('[iugu] sem api_key configurada; gateway pulado.', $output);
        $this->assertStringContainsString('[stripe] o gateway gerencia', $output);
        $this->assertCount(0, $api->calls);
    }
}
