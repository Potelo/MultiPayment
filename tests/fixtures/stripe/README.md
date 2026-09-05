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

## `setup_intents/`

Gravadas na sandbox em 2026-09-02, com `expand[]=payment_method` e o SetupIntent criado e
confirmado na mesma requisição (`usage: off_session`, `payment_method_types: ['card']`), como
o driver faz em `createCreditCard()`:

| Arquivo | Como foi produzida |
|---|---|
| `succeeded.json` | `pm_card_visa`, com `metadata` de descrição e de padrão; o PaymentMethod expandido já vem com `customer` (a Stripe anexa ao confirmar) |
| `requires_action.json` | `pm_card_authenticationRequired`, sem `return_url`: `next_action` do tipo `use_stripe_sdk` (o objeto interno foi reduzido a alguns campos; o certificado do servidor de diretório saiu) e PaymentMethod sem `customer` |
| `requires_action_redirect.json` | idem, com `return_url`: `next_action` do tipo `redirect_to_url`, com a chave publicável trocada por placeholder na URL |
| `card_declined.json` | corpo da resposta 402 de `pm_card_chargeDeclined` (`card_declined`, `generic_decline`, `advice_code` `try_again_later`), com o SetupIntent em `requires_payment_method` dentro do erro |

O `client_secret` de todas foi substituído por um placeholder.

## `subscriptions/`

Gravadas na sandbox em 2026-09-04, pelo próprio driver e com o `expand` que ele usa
(`default_payment_method` e `items.data.price.product`); ids, `lookup_key`, nomes de plano e
e-mail foram renomeados para os valores estáveis das fixtures (`sub_1UBJmk...`,
`plano_mensal`...), sem tocar no restante do payload:

| Arquivo | Como foi produzida |
|---|---|
| `active.json` | assinatura criada com cartão salvo (`pm_card_visa`) e plano mensal |
| `active_pause_collection.json` | a mesma assinatura depois de `suspendSubscription()` |
| `active_cancel_at_period_end.json` | a mesma depois de `cancelSubscription(atPeriodEnd: true)` |
| `canceled.json` | a mesma depois do cancelamento imediato (já no plano anual, pela troca) |
| `trialing.json` | assinatura criada com `trialDays` 7 no cartão salvo |

Montadas sobre `active.json` (ou `trialing.json`), porque a sandbox não produz o estado:

| Arquivo | Diferença |
|---|---|
| `incomplete.json` | status |
| `incomplete_expired.json` | status e `ended_at` |
| `past_due.json` | status |
| `unpaid.json` | status |
| `paused.json` | status, sobre `trialing.json` (trial que terminou sem método de pagamento) |

## `webhooks/`

Eventos entregues por `stripe listen` (CLI 1.50.10) numa sessão de sandbox em 2026-09-04, um
arquivo por tipo, com o corpo cru byte a byte como recebido (o `Stripe-Signature` de cada
entrega e o signing secret do listener ficam em arquivo fora do git, para o teste de
verificação de assinatura de uma versão futura validar o corpo exato). `mandate.updated` não
foi gravado: exige Pix Automático, que a conta ainda não tem liberado.
