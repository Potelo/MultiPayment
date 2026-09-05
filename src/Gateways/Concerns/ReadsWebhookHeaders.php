<?php

namespace Potelo\MultiPayment\Gateways\Concerns;

/**
 * Leitura de cabeçalhos de uma entrega de webhook, no formato cru que `parseWebhook()` recebe.
 */
trait ReadsWebhookHeaders
{
    /**
     * Valor de um cabeçalho da entrega, sem diferenciar maiúsculas no nome; um valor em lista
     * (como o Laravel entrega) devolve o primeiro item.
     *
     * @param  array  $headers
     * @param  string  $name
     * @return string|null
     */
    private static function webhookHeaderValue(array $headers, string $name): ?string
    {
        foreach ($headers as $headerName => $value) {
            if (strcasecmp((string) $headerName, $name) !== 0) {
                continue;
            }
            $value = is_array($value) ? reset($value) : $value;

            return is_string($value) ? $value : null;
        }

        return null;
    }
}
