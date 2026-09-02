<?php

namespace Potelo\MultiPayment\Gateways\Concerns;

use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Capabilities\CapabilityRestriction;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;

/**
 * Implementa `supports()`, `supportsAll()` e `restriction()` de `DeclaresCapabilities` sobre
 * as listas do driver e oferece as guardas que os drivers chamam antes de qualquer requisição.
 * `restrictions()` devolve lista vazia; o driver que tem restrição a sobrescreve.
 */
trait ChecksCapabilities
{
    /**
     * @inheritDoc
     */
    abstract public function capabilities(): array;

    /**
     * @inheritDoc
     */
    abstract public function notYetImplemented(): array;

    /**
     * @inheritDoc
     */
    public function supports(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /**
     * @inheritDoc
     */
    public function supportsAll(Capability ...$capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if (!$this->supports($capability)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function restrictions(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function restriction(Capability $capability): ?CapabilityRestriction
    {
        return $this->restrictions()[$capability->value] ?? null;
    }

    /**
     * Lança `UnsupportedOperationException` quando a capability não está em `capabilities()`.
     *
     * @param  Capability  $capability
     * @param  string  $detail  orientação acrescentada ao fim da mensagem
     * @return void
     * @throws UnsupportedOperationException
     */
    protected function assertSupports(Capability $capability, string $detail = ''): void
    {
        if (!$this->supports($capability)) {
            throw UnsupportedOperationException::forGateway($this, $capability, $detail);
        }
    }

    /**
     * Lança `UnsupportedOperationException` na primeira capability da lista que não está em
     * `capabilities()`.
     *
     * @param  Capability[]  $capabilities
     * @return void
     * @throws UnsupportedOperationException
     */
    protected function assertSupportsAll(array $capabilities): void
    {
        foreach ($capabilities as $capability) {
            $this->assertSupports($capability);
        }
    }
}
