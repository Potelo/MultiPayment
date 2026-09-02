<?php

namespace Potelo\MultiPayment\Contracts;

use Potelo\MultiPayment\Exceptions\IdempotencyConflictException;

/**
 * Deduplicação de operações de escrita por chave de idempotência, feita pela lib para os
 * endpoints em que o gateway não aceita o cabeçalho `Idempotency-Key`.
 *
 * A store executa a operação uma única vez por chave e guarda o resultado; chamadas seguintes
 * com a mesma chave, dentro do prazo, devolvem o resultado guardado sem executar de novo.
 * Duas execuções concorrentes com a mesma chave são serializadas por lock: a segunda recebe
 * `IdempotencyConflictException`, como a Iugu responde 409. Operação que lança não é guardada,
 * e a chamada seguinte executa de novo.
 */
interface IdempotencyStore
{
    /**
     * Executa a operação uma única vez por chave e devolve o resultado, guardado ou recém
     * produzido.
     *
     * @param  string  $key
     * @param  callable  $operation  a operação; o retorno é guardado por `$ttlSeconds`
     * @param  int  $ttlSeconds  prazo em que a chave devolve o resultado guardado
     * @return mixed
     * @throws IdempotencyConflictException  outra execução com a mesma chave está em andamento
     */
    public function remember(string $key, callable $operation, int $ttlSeconds): mixed;

    /**
     * Diz se a chave tem resultado guardado ainda dentro do prazo.
     *
     * @param  string  $key
     * @return bool
     */
    public function has(string $key): bool;
}
