<?php

namespace Potelo\MultiPayment\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Controller da rota pronta do pacote (`multi-payment.webhooks.route`): entrega a requisição
 * ao pipeline de `WebhookHandler`, que verifica, deduplica, despacha os eventos do Laravel e
 * responde. O driver vem do parâmetro de rota `gateway`.
 */
class WebhookController
{
    /**
     * Consome a entrega de webhook recebida pela rota do pacote.
     *
     * @param  Request  $request
     * @return Response
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     */
    public function __invoke(Request $request): Response
    {
        return (new WebhookHandler())->handle($request);
    }
}
