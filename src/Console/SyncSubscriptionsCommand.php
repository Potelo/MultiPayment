<?php

namespace Potelo\MultiPayment\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Helpers\LogHelper;
use Potelo\MultiPayment\Helpers\ConfigurationHelper;
use Potelo\MultiPayment\Exceptions\ConfigurationException;
use Potelo\MultiPayment\Contracts\SubscriptionSyncContract;

/**
 * Aplica as emulações de assinatura nos gateways que dependem da lib: remove o desconto cuja
 * validade passou e suspende, marcando como cancelada, a assinatura cujo cancelamento agendado
 * chegou à data. A aplicação agenda o comando (por exemplo, de hora em hora); num gateway que
 * gerencia os dois recursos sozinho, nada é feito. Rodar duas vezes não muda nada na segunda.
 */
class SyncSubscriptionsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'multipayment:sync-subscriptions
        {--gateway= : Sincroniza só o gateway informado; sem a opção, todos os configurados}
        {--dry-run : Mostra o que seria feito sem escrever nada no gateway}';

    /**
     * @var string
     */
    protected $description = 'Aplica as emulações de assinatura (desconto vencido, cancelamento agendado) nos gateways que dependem da lib';

    /**
     * Executa a sincronização e escreve cada ação na saída e no log. Sem `--gateway`, percorre
     * os gateways configurados, pulando os sem `api_key` (a configuração padrão do pacote
     * registra os dois gateways mesmo quando a aplicação só usa um).
     *
     * @return int
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayNotAvailableException
     */
    public function handle(): int
    {
        $names = $this->option('gateway')
            ? [$this->option('gateway')]
            : array_keys(Config::get('multi-payment.gateways', []));
        $dryRun = (bool) $this->option('dry-run');
        $prefix = $dryRun ? '[dry-run] ' : '';

        foreach ($names as $name) {
            if (!$this->option('gateway') && empty(Config::get("multi-payment.gateways.{$name}.api_key"))) {
                $this->info("[{$name}] sem api_key configurada; gateway pulado.");
                continue;
            }

            $gateway = ConfigurationHelper::resolveGateway($name);

            $emulatesSubscriptions = $gateway->isEmulated(Capability::COUPONS)
                || $gateway->isEmulated(Capability::CANCEL_AT_PERIOD_END);
            if (!$emulatesSubscriptions) {
                $this->info("[{$name}] o gateway gerencia cupom e cancelamento ao fim do ciclo; nada a sincronizar.");
                continue;
            }

            if (!$gateway instanceof SubscriptionSyncContract) {
                throw ConfigurationException::GatewayMissingContract(
                    $gateway,
                    $gateway->isEmulated(Capability::COUPONS) ? Capability::COUPONS : Capability::CANCEL_AT_PERIOD_END,
                    SubscriptionSyncContract::class
                );
            }

            $actions = $gateway->syncSubscriptions($dryRun);
            foreach ($actions as $action) {
                $line = "[{$name}] assinatura {$action['subscription']}: {$action['detail']}";
                $this->line($prefix . $line);
                LogHelper::info("multipayment:sync-subscriptions {$prefix}{$line}", [
                    'gateway' => $name,
                    'subscription' => $action['subscription'],
                    'action' => $action['action'],
                    'dry_run' => $dryRun,
                ]);
            }

            if ($actions === []) {
                $this->info("[{$name}] nada a aplicar.");
            }
        }

        return self::SUCCESS;
    }
}
