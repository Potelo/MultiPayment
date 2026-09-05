<?php

namespace Potelo\MultiPayment\Contracts;

use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Capabilities\CapabilityRestriction;

/**
 * Declaração do que um gateway suporta, em três níveis: o que o gateway oferece e a lib
 * implementa, o que o gateway oferece mas a lib ainda não construiu, e o que o gateway não
 * oferece mas a lib entrega por emulação. Uma capability fora das três listas é limitação do
 * gateway. Uma capability suportada pode ainda ter uma restrição (`restriction()`), que
 * descreve em que parte dos casos ela vale.
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
     * Capabilities que o gateway não oferece e este driver entrega por conta própria. A
     * emulação de assinatura depende do comando `multipayment:sync-subscriptions` agendado
     * pela aplicação.
     *
     * @return Capability[]
     */
    public function emulated(): array;

    /**
     * Diz se a capability está em `emulated()`: a lib a entrega por conta própria.
     *
     * @param  Capability  $capability
     * @return bool
     */
    public function isEmulated(Capability $capability): bool;

    /**
     * Diz se a capability está em `capabilities()` ou em `emulated()`.
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
