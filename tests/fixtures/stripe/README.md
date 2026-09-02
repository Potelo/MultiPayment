# Fixtures da Stripe

Respostas da sandbox da Stripe, API `2026-07-29.dahlia`, gravadas em 2026-09-02 com o
`expand` que o driver usa (`payments.data.payment.payment_intent` no Invoice;
`latest_charge.balance_transaction` e `latest_charge.refunds` no PaymentIntent). Só o
`client_secret` dos PaymentIntents foi substituído por um placeholder.

## `invoices/`

Gravadas na sandbox:

| Arquivo | Como foi produzida |
|---|---|
| `draft.json` | Invoice criado com um invoice item, antes de finalizar |
| `open_requires_payment_method.json` | finalizado, sem tentativa de pagamento |
| `open_after_declined_attempt.json` | `pay` com `pm_card_chargeCustomerFail` recusado (o PaymentIntent tem `latest_charge`) |
| `open_requires_action.json` | `pay` com `pm_card_authenticationRequired` |
| `paid.json` | `pay` com `pm_card_visa`; o mesmo Invoice serve para os estornos, que só mudam o PaymentIntent |
| `paid_disputed.json` | `pay` com `pm_card_createDispute` |
| `paid_out_of_band.json` | `pay` com `paid_out_of_band` (InvoicePayment do tipo `payment_record`; o PaymentIntent padrão é cancelado) |
| `paid_zero_amount_due.json` | invoice item de valor zero, finalizado (sem PaymentIntent) |
| `void.json` | `void` de um Invoice `open` |
| `uncollectible.json` | `mark_uncollectible` de um Invoice `open` |

Montadas sobre `open_requires_payment_method.json`, porque a sandbox não produz o estado:

| Arquivo | Diferença |
|---|---|
| `open_requires_confirmation.json` | status do PaymentIntent |
| `open_requires_capture.json` | status do PaymentIntent |
| `open_processing.json` | status do PaymentIntent |
| `open_partially_paid.json` | `amount_paid` 5000 e `amount_remaining` 7345 |
| `open_without_payment_intent.json` | `payments.data` vazio |

## `payment_intents/`

`after_declined_attempt.json`, `paid.json`, `partially_refunded.json`, `refunded.json` e
`disputed.json` são o GET do PaymentIntent dos Invoices acima. `requires_action.json` é
`after_declined_attempt.json` com o status trocado e um `next_action` de 3DS.

## `disputes/`

`needs_response.json` é o GET de `/v1/disputes?charge=` do charge disputado;
`lost.json` é o mesmo com o status trocado.

## `subscriptions/`

Montadas a partir do objeto Subscription documentado para a API `2026-07-29.dahlia` (a
sessão de sandbox não criou assinaturas): `active.json` é a base, com um item de preço
recorrente mensal, e as demais trocam `status` e os campos que acompanham cada estado
(`trial_start`/`trial_end` em `trialing` e `paused`, `canceled_at`/`ended_at` em `canceled`,
`ended_at` em `incomplete_expired`). `active_pause_collection.json` é a base com
`pause_collection` preenchido. Servem ao mapa de status
(`Gateways\Stripe\SubscriptionStatuses`); quando o driver ler assinatura, regravar a partir da
sandbox.
