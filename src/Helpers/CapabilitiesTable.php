<?php

namespace Potelo\MultiPayment\Helpers;

use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Contracts\DeclaresCapabilities;

/**
 * Monta, em Markdown, a matriz de capabilities por gateway a partir das declarações dos
 * drivers. O README publica a saída de `composer capabilities:table`.
 */
class CapabilitiesTable
{
    /** Célula de capability que o gateway oferece e a lib implementa. */
    public const SUPPORTED = 'sim';

    /** Célula de capability que o gateway oferece mas a lib ainda não implementa. */
    public const NOT_IMPLEMENTED = 'não implementado';

    /** Célula de capability que o gateway não oferece. */
    public const GATEWAY_LIMITATION = 'limitação do gateway';

    /**
     * Gera a tabela com uma linha por caso de `Capability` e uma coluna por gateway, na
     * ordem informada.
     *
     * @param  array<string, DeclaresCapabilities>  $gateways  nome do gateway como chave
     * @return string
     */
    public static function markdown(array $gateways): string
    {
        $header = '| Capability | Significado | ' . implode(' | ', array_map('ucfirst', array_keys($gateways))) . ' |';
        $separator = '|---|---|' . str_repeat('---|', count($gateways));

        $rows = [];
        foreach (Capability::cases() as $capability) {
            $cells = array_map(
                static fn (DeclaresCapabilities $gateway) => self::cell($gateway, $capability),
                array_values($gateways)
            );
            $rows[] = "| `{$capability->name}` | {$capability->description()} | " . implode(' | ', $cells) . ' |';
        }

        return implode("\n", array_merge([$header, $separator], $rows)) . "\n";
    }

    /**
     * Célula da matriz para um gateway e uma capability.
     *
     * @param  DeclaresCapabilities  $gateway
     * @param  Capability  $capability
     * @return string
     */
    public static function cell(DeclaresCapabilities $gateway, Capability $capability): string
    {
        if ($gateway->supports($capability)) {
            return self::SUPPORTED;
        }

        if (in_array($capability, $gateway->notYetImplemented(), true)) {
            return self::NOT_IMPLEMENTED;
        }

        return self::GATEWAY_LIMITATION;
    }
}
