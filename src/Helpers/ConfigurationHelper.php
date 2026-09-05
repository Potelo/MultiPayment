<?php

namespace Potelo\MultiPayment\Helpers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Facade;
use Illuminate\Contracts\Container\Container;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Contracts\IdempotencyStore;
use Potelo\MultiPayment\Exceptions\ConfigurationException;

class ConfigurationHelper
{
    /**
     * Resolve o driver do gateway: uma instância passa direto; um nome (ou nulo, que usa o
     * default) é procurado em `multi-payment.gateways.{nome}` e a config da chave registrada é
     * entregue ao driver, então duas chaves com a mesma `class` e credenciais próprias
     * funcionam lado a lado. Para uma chave registrada com `class` válida, um bind no
     * container (da chave `multi-payment.gateway.{nome}` ou da classe do driver) prevalece
     * sobre a instanciação, o que permite substituir o driver por um fake em testes; nome fora
     * da config lança `ConfigurationException` antes de qualquer consulta ao container.
     *
     * @param  string|GatewayContract|null  $gateway
     *
     * @return GatewayContract
     * @throws ConfigurationException
     */
    public static function resolveGateway($gateway): GatewayContract
    {
        if (is_null($gateway)) {
            $gateway = Config::get('multi-payment.default');
        }
        if (is_string($gateway)) {
            $config = Config::get('multi-payment.gateways.'.$gateway);
            if (empty($config)) {
                throw ConfigurationException::GatewayNotConfigured($gateway);
            }
            $className = $config['class'] ?? '';
            if (!is_string($className) || !class_exists($className)) {
                throw ConfigurationException::GatewayNotFound((string) $className);
            }
            // o driver identifica a conta pelo nome da chave (gateway_name), que preenche o
            // gateway dos models devolvidos e fecha o ciclo de releitura na conta certa
            $config = ['gateway_name' => $gateway] + (array) $config;
            $gateway = self::makeGateway($gateway, $className, $config);
        }
        if (!$gateway instanceof GatewayContract) {
            throw ConfigurationException::GatewayInvalidInterface(get_class($gateway));
        }
        return $gateway;
    }

    /**
     * Constrói o driver da chave registrada. Com bind no container (primeiro da chave
     * `multi-payment.gateway.{nome}`, depois da classe), resolve por `make()` com a config da
     * chave como parâmetro `config`. Sem bind, instancia a classe direto, passando a config ao
     * parâmetro `config` do construtor quando ele existe; o container não monta as demais
     * dependências porque construiria os clientes dos SDKs sem credencial.
     *
     * @param  string  $name  nome da chave em `multi-payment.gateways`
     * @param  string  $className
     * @param  array  $config  config da chave registrada
     * @return object
     */
    private static function makeGateway(string $name, string $className, array $config): object
    {
        $app = Facade::getFacadeApplication();
        if ($app instanceof Container) {
            if ($app->bound("multi-payment.gateway.{$name}")) {
                return self::makeBound($app, "multi-payment.gateway.{$name}", $config);
            }
            if ($app->bound($className)) {
                return self::makeBound($app, $className, $config);
            }
        }

        $constructor = (new \ReflectionClass($className))->getConstructor();
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            if ($parameter->getName() === 'config') {
                return new $className(...['config' => $config]);
            }
        }

        return new $className();
    }

    /**
     * Resolve um abstract já registrado no container. Um bind de closure não compartilhado
     * recebe a config da chave como parâmetro `config`; instância registrada e singleton são
     * resolvidos sem parâmetros, porque `make()` com parâmetros reconstrói o abstract a cada
     * chamada em vez de devolver (e guardar) a instância compartilhada.
     *
     * @param  Container  $app
     * @param  string  $abstract
     * @param  array  $config  config da chave registrada
     * @return object
     */
    private static function makeBound(Container $app, string $abstract, array $config): object
    {
        $hasClosureBinding = method_exists($app, 'getBindings')
            && array_key_exists($abstract, $app->getBindings());
        $isShared = method_exists($app, 'isShared') && $app->isShared($abstract);

        return $hasClosureBinding && !$isShared
            ? $app->make($abstract, ['config' => $config])
            : $app->make($abstract);
    }

    /**
     * Resolve a `IdempotencyStore` registrada no container do Laravel (o service provider
     * registra a `CacheIdempotencyStore`; a aplicação pode substituí-la por um bind próprio).
     *
     * @return IdempotencyStore
     * @throws ConfigurationException  sem container ou sem a store registrada nele
     */
    public static function resolveIdempotencyStore(): IdempotencyStore
    {
        $app = Facade::getFacadeApplication();
        if ($app instanceof Container && $app->bound(IdempotencyStore::class)) {
            $store = $app->make(IdempotencyStore::class);
            if ($store instanceof IdempotencyStore) {
                return $store;
            }
        }

        throw ConfigurationException::IdempotencyStoreNotConfigured();
    }

    /**
     * Prazo, em segundos, em que a `IdempotencyStore` devolve o resultado guardado
     * (`multi-payment.idempotency.ttl`, padrão de 24 horas).
     *
     * @return int
     */
    public static function idempotencyTtl(): int
    {
        return (int) (Config::get('multi-payment.idempotency.ttl') ?? 86400);
    }

    /**
     * Prazo, em segundos, em que uma entrega de webhook com o mesmo id conta como replay na
     * deduplicação (`multi-payment.webhooks.dedup_ttl`, padrão de 72 horas).
     *
     * @return int
     */
    public static function webhookDedupTtl(): int
    {
        return (int) (Config::get('multi-payment.webhooks.dedup_ttl') ?? 259200);
    }

    /**
     * Diz se `Model::fill()` recusa chave desconhecida (`multi-payment.strict_fill`, padrão
     * verdadeiro). Sem container do Laravel, ou sem a chave na configuração, vale o padrão.
     *
     * @return bool
     */
    public static function strictFill(): bool
    {
        $app = Facade::getFacadeApplication();
        if (!$app instanceof Container || !$app->bound('config')) {
            return true;
        }

        return (bool) ($app->make('config')->get('multi-payment.strict_fill') ?? true);
    }
}