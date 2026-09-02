<?php

namespace Potelo\MultiPayment\Contracts;

/**
 * Enum que aceita, na conversão a partir de string, um valor fora dos seus casos sem lançar
 * exceção: o valor desconhecido vira um caso próprio (por exemplo `UNKNOWN`) e é registrado
 * no log. Os models usam este contrato ao converter o que é escrito numa propriedade de enum.
 */
interface AcceptsUnknownValue
{
    /**
     * Converte o valor de string no caso correspondente, ou no caso reservado a valor
     * desconhecido, registrando este último no log.
     *
     * @param  string  $value
     * @param  string|null  $gateway  nome do gateway de origem, para o log
     * @return static
     */
    public static function fromValue(string $value, ?string $gateway = null): static;
}
