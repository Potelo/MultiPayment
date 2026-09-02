<?php

namespace Potelo\MultiPayment\Exceptions;

use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Contracts\GatewayContract;

class ConfigurationException extends MultiPaymentException
{
    /**
     * O driver declara a capability em `capabilities()` mas não implementa o contract que a
     * operação exige (erro de driver, sem requisição).
     *
     * @param  GatewayContract  $gateway
     * @param  Capability  $capability
     * @param  class-string  $contract
     * @return self
     */
    public static function GatewayMissingContract(GatewayContract $gateway, Capability $capability, string $contract): self
    {
        $contractName = substr(strrchr($contract, '\\'), 1) ?: $contract;

        return new static(
            'Gateway [' . get_class($gateway) . "] declares the {$capability->value} capability"
            . " but does not implement {$contractName}"
        );
    }

    /**
     * O driver passou na verificação de capability mas não tem o método que o despacho por
     * convenção de nome esperava (erro de driver, sem requisição).
     *
     * @param  string  $gatewayClass
     * @param  string  $method
     * @return self
     */
    public static function GatewayMethodNotFound(string $gatewayClass, string $method): self
    {
        return new static("Gateway [{$gatewayClass}] does not have method [{$method}]");
    }

    /**
     * The gateway does not implement the interface.
     *
     * @param $gateway
     *
     * @return self
     */
    public static function GatewayInvalidInterface($gateway): self
    {
        return new static("Gateway [" . get_class($gateway) . "] must implement " . GatewayContract::class . " interface");
    }

    /**
     * Specified gateway is not present in the list of gateways
     *
     * @param  string  $gateway
     *
     * @return self
     */
    public static function GatewayNotConfigured(string $gateway): self
    {
        return new static("Gateway [{$gateway}] not found in configuration file.");
    }

    /**
     * Gateway not found exception.
     *
     * @param  string  $gateway
     *
     * @return self
     */
    public static function GatewayNotFound(string $gateway): self
    {
        return new static("Gateway class [{$gateway}] not found.");
    }

    /**
     * Nenhuma `IdempotencyStore` registrada no container, e a operação recebeu uma chave de
     * idempotência num endpoint que o gateway não deduplica sozinho.
     *
     * @return self
     */
    public static function IdempotencyStoreNotConfigured(): self
    {
        return new static(
            'Nenhuma IdempotencyStore registrada no container: registre o MultiPaymentServiceProvider '
            . '(que usa o cache do Laravel) ou faça bind de Potelo\\MultiPayment\\Contracts\\IdempotencyStore.'
        );
    }

    /**
     * O cache store configurado para a `CacheIdempotencyStore` não suporta lock.
     *
     * @param  string  $storeClass
     * @return self
     */
    public static function IdempotencyStoreWithoutLock(string $storeClass): self
    {
        return new static(
            "O cache store [{$storeClass}] não suporta lock; a CacheIdempotencyStore exige um store "
            . 'com LockProvider (redis, memcached, database, file, array ou dynamodb). Configure '
            . 'multi-payment.idempotency.cache_store com um deles.'
        );
    }
}
