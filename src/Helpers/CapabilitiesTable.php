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

    /** Célula de capability que o gateway não oferece e a lib entrega por emulação. */
    public const EMULATED = 'emulado';

    /** Célula de capability que o gateway oferece mas a lib ainda não implementa. */
    public const NOT_IMPLEMENTED = 'não implementado';

    /** Célula de capability que o gateway não oferece. */
    public const GATEWAY_LIMITATION = 'limitação do gateway';

    /**
     * Gera a tabela com uma linha por caso de `Capability`, uma coluna por gateway, na ordem
     * informada, e uma última coluna com as restrições declaradas por cada gateway para a
     * capability (`restriction()`), vazia quando nenhum gateway restringe.
     *
     * @param  array<string, DeclaresCapabilities>  $gateways  nome do gateway como chave
     * @return string
     */
    public static function markdown(array $gateways): string
    {
        $header = '| Capability | Significado | '
            . implode(' | ', array_map('ucfirst', array_keys($gateways)))
            . ' | Restrições |';
        $separator = '|---|---|' . str_repeat('---|', count($gateways)) . '---|';

        $rows = [];
        foreach (Capability::cases() as $capability) {
            $cells = array_map(
                static fn (DeclaresCapabilities $gateway) => self::cell($gateway, $capability),
                array_values($gateways)
            );
            $rows[] = "| `{$capability->name}` | {$capability->description()} | "
                . implode(' | ', $cells)
                . ' | ' . self::restrictionsCell($gateways, $capability) . ' |';
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
        if (in_array($capability, $gateway->capabilities(), true)) {
            return self::SUPPORTED;
        }

        if ($gateway->isEmulated($capability)) {
            return self::EMULATED;
        }

        if (in_array($capability, $gateway->notYetImplemented(), true)) {
            return self::NOT_IMPLEMENTED;
        }

        return self::GATEWAY_LIMITATION;
    }

    /**
     * Célula de restrições de uma capability: a descrição declarada por cada gateway,
     * prefixada pelo nome dele, separadas por quebra de linha HTML. Vazia quando nenhum
     * gateway restringe a capability.
     *
     * @param  array<string, DeclaresCapabilities>  $gateways  nome do gateway como chave
     * @param  Capability  $capability
     * @return string
     */
    public static function restrictionsCell(array $gateways, Capability $capability): string
    {
        $parts = [];
        foreach ($gateways as $name => $gateway) {
            $restriction = $gateway->restriction($capability);
            if (!is_null($restriction)) {
                $parts[] = ucfirst($name) . ': ' . str_replace('|', '\\|', $restriction->description);
            }
        }

        return implode('<br>', $parts);
    }
}
