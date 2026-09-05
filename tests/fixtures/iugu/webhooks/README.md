# Fixtures de webhook da Iugu

Entregas reais capturadas em 2026-09-05, de um webhook registrado na sandbox por
`POST /v1/web_hooks` com `event: all` e `authorization` configurado (registro removido da
sandbox ao final da captura). Cada arquivo guarda uma entrega completa: método HTTP, instante
da captura, headers e o corpo cru `application/x-www-form-urlencoded` (com `data[...]`
aninhado), exatamente como a Iugu enviou. Dos headers, só os de infraestrutura do endpoint de
captura (`host`) foram descartados; os que ficaram estão intactos, e nenhum corpo foi editado
(o `content-length` confere com o tamanho do corpo em todos). O token `mp-lote4-token-abc123`
é o valor de teste configurado no registro, sem uso fora desta captura.

O que os headers mostram:

- o token configurado em `authorization` no registro chega cru no header HTTP `authorization`,
  sem prefixo;
- toda entrega leva um header `idempotency-key` com um UUID, único por entrega nesta captura
  (as catorze chaves são distintas; se a chave se repete nas retentativas de uma mesma
  entrega segue sem observação, porque o endpoint respondeu 200 em todas);
- `user-agent` é `Iugu-Webhooks` e o corpo não traz timestamp do evento nem id de entrega.

| Arquivo | Como foi produzida |
|---|---|
| `invoice.created.json` | fatura avulsa Pix e cartão criada por `POST /v1/invoices` (`data[source]` `api`) |
| `invoice.status_changed.paid.json` | a mesma fatura paga com cartão de teste aprovado por `POST /v1/charge` (a sandbox não tem simulador de pagamento Pix pela API) |
| `invoice.status_changed.refunded.json` | `POST /v1/invoices/{id}/refund` integral na fatura paga |
| `invoice.refund.json` | o mesmo `POST /v1/invoices/{id}/refund` da linha acima; entregue junto com o `status_changed`, mesma fatura |
| `subscription.created.json` | `POST /v1/subscriptions` com `payable_with` Pix |
| `invoice.created.subscription.json` | fatura do primeiro ciclo da assinatura (`data[source]` `subscription`, com `data[subscription_id]`) |
| `invoice.status_changed.canceled.json` | a fatura do ciclo cancelada pela suspensão da assinatura |
| `subscription.suspended.json` | `POST /v1/subscriptions/{id}/suspend` |
| `subscription.changed.json` | `PUT /v1/subscriptions/{id}` gravando uma `custom_variable` |
| `invoice.created.reactivation.json` | fatura nova gerada por `POST /v1/subscriptions/{id}/activate` (a sandbox não entregou `subscription.activated`) |
| `customer_payment_method.new.json` | `POST /v1/customers/{id}/payment_methods` com token de cartão de teste |
| `invoice.payment_failed.json` | `POST /v1/charge` com o cartão de teste recusado `4012888888881881`; o corpo traz `data[lr]` `05` (na resposta síncrona do charge o campo é `LR`) |
| `invoice.partially_refunded.json` | `POST /v1/invoices/{id}/refund` com `partial_value_refund_cents` 3000 numa fatura paga; o corpo traz `data[amount]` com o valor estornado |
| `invoice.status_changed.partially_refunded.json` | entregue junto com o `partially_refunded` acima, mesma fatura |
| `supported_events.json` | resposta de `GET /v1/web_hooks/supported_events` |
