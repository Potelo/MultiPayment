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
            if (empty(Config::get('multi-payment.gateways.'.$gateway))) {
                throw ConfigurationException::GatewayNotConfigured($gateway);
            }
            $className = Config::get("multi-payment.gateways.$gateway.class");
            if (!class_exists($className)) {
                throw ConfigurationException::GatewayNotFound($className);
            }
            $gateway = new $className;
        }
        if (!$gateway instanceof GatewayContract) {
            throw ConfigurationException::GatewayInvalidInterface(get_class($gateway));
        }
        return $gateway;
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
}