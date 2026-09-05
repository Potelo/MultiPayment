<?php

namespace Potelo\MultiPayment\Contracts;

use Potelo\MultiPayment\Models\Dispute;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Operações sobre contestações (chargebacks), guardadas pela capability `DISPUTES`. O contract
 * fica fora da composição de `GatewayContract` enquanto nem todo gateway o implementa, como
 * `SubscriptionContract`.
 */
interface DisputeContract
{
    /**
     * Busca a contestação no gateway pelo `id` do model.
     *
     * @param  Dispute  $dispute
     * @return Dispute
     * @throws GatewayException|GatewayNotAvailableException|ModelAttributeValidationException
     */
    public function getDispute(Dispute $dispute): Dispute;

    /**
     * Lista as contestações da conta.
     *
     * @param  int  $page
     * @param  int  $limit
     * @return Dispute[]
     * @throws GatewayException|GatewayNotAvailableException|ModelAttributeValidationException
     */
    public function listDisputes(int $page = 1, int $limit = 100): array;

    /**
     * Responde à contestação com as evidências informadas e devolve a contestação atualizada.
     * O formato de `$evidence` é o do gateway: no Stripe são os campos do hash `evidence` do
     * objeto Dispute (`uncategorized_text`, `customer_communication`...), enviados e submetidos
     * na mesma requisição; na Iugu são os arquivos comprobatórios em base64 (`file_1` a
     * `file_5`, até 10 páginas e 8 MB somados).
     *
     * @param  string  $id
     * @param  array  $evidence  evidências no formato do gateway
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     * @return Dispute
     * @throws GatewayException|GatewayNotAvailableException|ModelAttributeValidationException
     */
    public function contestDispute(string $id, array $evidence, ?string $idempotencyKey = null): Dispute;

    /**
     * Acata a contestação, devolvendo o valor ao pagador sem disputa, e devolve a contestação
     * atualizada.
     *
     * @param  string  $id
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     * @return Dispute
     * @throws GatewayException|GatewayNotAvailableException|ModelAttributeValidationException
     */
    public function acceptDispute(string $id, ?string $idempotencyKey = null): Dispute;
}
