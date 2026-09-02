<?php

namespace Potelo\MultiPayment\Contracts;

use Potelo\MultiPayment\Enums\Capability;

/**
 * Declaração do que um gateway suporta, em dois níveis: o que o gateway oferece e a lib
 * implementa, e o que o gateway oferece mas a lib ainda não construiu. Uma capability fora
 * das duas listas é limitação do gateway.
 */
interface DeclaresCapabilities
{
    /**
     * Capabilities que o gateway oferece e este driver implementa.
     *
     * @return Capability[]
     */
    public function capabilities(): array;

    /**
     * Capabilities que o gateway oferece mas este driver ainda não implementa.
     *
     * @return Capability[]
     */
    public function notYetImplemented(): array;

    /**
     * Diz se a capability está em `capabilities()`.
     *
     * @param  Capability  $capability
     * @return bool
     */
    public function supports(Capability $capability): bool;
}
