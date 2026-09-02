<?php

namespace Potelo\MultiPayment\Contracts;

use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Capabilities\CapabilityRestriction;

/**
 * Declaração do que um gateway suporta, em dois níveis: o que o gateway oferece e a lib
 * implementa, e o que o gateway oferece mas a lib ainda não construiu. Uma capability fora
 * das duas listas é limitação do gateway. Uma capability suportada pode ainda ter uma
 * restrição (`restriction()`), que descreve em que parte dos casos ela vale.
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

    /**
     * Diz se todas as capabilities informadas estão em `capabilities()`. Sem argumento,
     * responde verdadeiro.
     *
     * @param  Capability  ...$capabilities
     * @return bool
     */
    public function supportsAll(Capability ...$capabilities): bool;

    /**
     * Restrições das capabilities suportadas, com o valor da capability como chave. Uma
     * capability sem entrada aqui vale em todos os casos.
     *
     * @return array<string, CapabilityRestriction>
     */
    public function restrictions(): array;

    /**
     * Restrição da capability, ou nulo quando ela vale em todos os casos (ou não é suportada).
     *
     * @param  Capability  $capability
     * @return CapabilityRestriction|null
     */
    public function restriction(Capability $capability): ?CapabilityRestriction;
}
