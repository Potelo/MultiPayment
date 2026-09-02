<?php

namespace Potelo\MultiPayment\Gateways\Concerns;

use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;

/**
 * Implementa `supports()` de `DeclaresCapabilities` sobre as listas do driver e oferece as
 * guardas que os drivers chamam antes de qualquer requisição.
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
