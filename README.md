## Introdução

MultiPayment permite gerenciar pagamentos de diversos gateways de pagamento. Atualmente suporta Iugu e Stripe.

- [Introdução](#introdução)
- [Requisitos](#requisitos)
- [Instalação](#instalação)
- [Configuração](#configuração)
- [Gateways](#gateways)
  - [Capabilities](#capabilities)
  - [Status da fatura](#status-da-fatura)
  - [Migração das constantes para enum](#migração-das-constantes-para-enum)
  - [Particularidades do Stripe](#particularidades-do-stripe)
  - [Opções extras do gateway](#opções-extras-do-gateway)
  - [Idempotência](#idempotência)
- [Webhooks](#webhooks)
- [Utilizando](#utilizando)
  - [MultiPayment](#multipayment)
    - [Criar e cobrar uma fatura (InvoiceBuilder)](#criar-e-cobrar-uma-fatura-invoicebuilder)
    - [Datas da fatura](#datas-da-fatura)
    - [Pix Automático](#pix-automático)
    - [Pix Automático: quem agenda a cobrança](#pix-automático-quem-agenda-a-cobrança)
    - [Assinaturas e planos](#assinaturas-e-planos)
    - [Emulações na Iugu](#emulações-na-iugu)
    - [CustomerBuilder](#customerbuilder)
    - [Salvar cartão (CreditCardBuilder)](#salvar-cartão-creditcardbuilder)
    - [getInvoice](#getinvoice)
    - [Outras operações de fatura](#outras-operações-de-fatura)
    - [Captura em duas etapas](#captura-em-duas-etapas)
    - [Estorno](#estorno)
    - [charge (alternativa por array)](#charge-alternativa-por-array)
  - [Models](#models)
    - [Customer](#customer)
    - [Invoice](#invoice)
    - [Refund](#refund)
    - [Subscription](#subscription)
    - [Plan](#plan)
- [Apêndice: chaves do array de `charge()`](#apêndice-chaves-do-array-de-charge)

## Requisitos
  - PHP 8.3+
  - Laravel 10.0+

## Instalação

Instale esse pacote pelo composer:

```  
composer require potelo/multi-payment "dev-main"  
```  

O SDK da Iugu vem do fork `Potelo/iugu-php`, que não está no Packagist, e o Composer não herda
a lista de repositórios de uma dependência. Declare o fork no `composer.json` da aplicação
antes de instalar:

```json
"repositories": [
    {"type": "git", "url": "https://github.com/Potelo/iugu-php.git"}
]
```

## Configuração
Após instalar o pacote rode o comando abaixo para publicar as configurações no projeto Laravel
```  
php artisan vendor:publish --provider="Potelo\MultiPayment\Providers\MultiPaymentServiceProvider"  
```  
Verifique se o arquivo `multi-payment.php` foi criado no diretório `config/`.

Agora configure as variáveis de ambiente no arquivo .env:

```dotenv
APP_ENV=local

MULTIPAYMENT_DEFAULT=iugu

#iugu  
IUGU_ID=
IUGU_APIKEY=
IUGU_MAX_INSTALLMENTS=12   # opcional; máximo de parcelas habilitado na conta (ver Capabilities)
IUGU_WEBHOOK_TOKEN=        # token configurado no registro do webhook (ver a seção Webhooks)

#stripe
STRIPE_APIKEY=
STRIPE_PIX_MANDATE_REFERENCE=   # opcional; nome exibido no aplicativo do banco no mandato de Pix Automático (ver Pix Automático)
STRIPE_WEBHOOK_SECRET=          # secret do endpoint de webhook, whsec_... (ver a seção Webhooks)
STRIPE_WEBHOOK_TOLERANCE=300    # opcional; tolerância do timestamp assinado, em segundos

#idempotência (opcional; ver a seção Idempotência)
MULTIPAYMENT_IDEMPOTENCY_TTL=86400
MULTIPAYMENT_IDEMPOTENCY_CACHE_STORE=

#webhooks (opcional; ver a seção Webhooks)
MULTIPAYMENT_WEBHOOK_DEDUP_TTL=259200
MULTIPAYMENT_WEBHOOK_ROUTE_ENABLED=false   # liga a rota pronta do pacote (ver "Rota pronta")

#fill() estrito (opcional, padrão true; ver a seção "fill() estrito")
MULTIPAYMENT_STRICT_FILL=true
```  

Opcionalmente você pode configurar o Trait, para facilitar o uso do método `charge` junto a um usuário.

```php
use Potelo\MultiPayment\MultiPaymentTrait;  
  
class User extends Authenticatable
{
    use MultiPaymentTrait;
}
```
Usando o Trait:
```php
$usuario = User::find(1);
$usuario->charge($options, 'iugu', 10000);  
```
Também é possível utilizar o Facade:
```php
\Potelo\MultiPayment\Facades\MultiPayment::charge($options);  
```

## Gateways

### Capabilities

Cada driver declara o que suporta em três níveis, pelo contract `DeclaresCapabilities`:
`capabilities()` lista o que o gateway oferece e a lib implementa; `notYetImplemented()` lista o
que o gateway oferece mas a lib ainda não construiu (planejado para uma versão futura); e
`emulated()` lista o que o gateway não oferece mas a lib entrega por conta própria (ver
[Emulações na Iugu](#emulações-na-iugu)). O que não aparece em nenhuma das três listas é
limitação do gateway. `supports(Capability $c)` responde verdadeiro para capability
implementada ou emulada, `isEmulated(Capability $c)` diz se ela roda na lib, e
`supportsAll(Capability ...$c)` exige todas de uma vez. Os valores são o enum
`Potelo\MultiPayment\Enums\Capability`.

Uma capability suportada pode valer só numa parte dos casos. `restriction(Capability $c)` devolve
um `CapabilityRestriction` (`Potelo\MultiPayment\Capabilities\CapabilityRestriction`) com a
`description` da restrição e, quando ela é enumerável, `allowedPaymentMethods`, `allowedBrands`
ou `maxInstallments`; nulo quando a capability vale em todos os casos. `restrictions()` lista
todas, com o valor da capability como chave.

Consulte a capability **antes** de montar a interface de checkout ou de escolher o gateway, em
vez de capturar a exceção depois:

```php
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Facades\MultiPayment;

if (!MultiPayment::gateway('stripe')->supports(Capability::AUTOMATIC_PIX)) {
    $gateway = 'iugu';                                   // roteia antes de exibir a opção
}

MultiPayment::supports(Capability::INSTALLMENTS, 'iugu');  // true
MultiPayment::supportsAll(Capability::PIX, Capability::INVOICE_DUPLICATION);   // no gateway da instância
MultiPayment::capabilities('stripe');                       // Capability[] que a lib implementa
MultiPayment::notYetImplemented('stripe');                  // Capability[] planejadas
MultiPayment::emulated('iugu');                             // [Capability::COUPONS, Capability::CANCEL_AT_PERIOD_END]
MultiPayment::isEmulated(Capability::COUPONS, 'iugu');      // true: exige o comando de sincronização agendado

// restrições dentro de um "sim", consultáveis antes de tokenizar ou de exibir a opção
$brands = MultiPayment::restriction(Capability::CREDIT_CARD, 'stripe');
if ($brands && !$brands->allowsBrand($binLookup->brand)) {
    $gateway = 'iugu';                                   // Elo, Hipercard e Amex vão para a Iugu
}
MultiPayment::restriction(Capability::INSTALLMENTS, 'iugu')->maxInstallments;   // 12 por padrão
MultiPayment::restriction(Capability::PIX, 'iugu');                             // null: vale em todos os casos
```

Toda operação fora das capabilities do gateway lança `UnsupportedOperationException` **antes de
qualquer requisição**, inclusive antes de criar o cliente que acompanha a fatura ou a
assinatura. A exceção traz `capability`, `gateway` e `reason` (`not_implemented` quando o
gateway oferece e a lib ainda não implementou; `gateway_limitation` quando o gateway não
oferece; `managed_by_gateway` quando o próprio gateway conduz a operação e a chamada pela lib
não se aplica, como o agendamento de Pix Automático no Stripe). Ver
[Tratamento de erros](#tratamento-de-erros).

A matriz abaixo é gerada a partir das declarações dos drivers com `composer capabilities:table`;
o teste `GatewayCapabilitiesTest` falha quando o README fica defasado em relação ao código. A
coluna "Restrições" é o que `restriction()` devolve para cada gateway.

| Capability | Significado | Iugu | Stripe | Restrições |
|---|---|---|---|---|
| `CREDIT_CARD` | Fatura paga com cartão de crédito. | sim | sim | Stripe: Na conta brasileira só cartão de crédito Visa e Mastercard; outra bandeira é recusada na cobrança com DeclineCode::BRAND_NOT_SUPPORTED. |
| `PIX` | Fatura paga com Pix avulso, com QR Code de pagamento único. | sim | sim |  |
| `BANK_SLIP` | Fatura paga com boleto bancário. | sim | sim | Stripe: A Stripe aceita boleto de R$ 5,00 a R$ 49.999,99, com vencimento de hoje a 60 dias; fora dessas janelas a criação é recusada antes da requisição. |
| `AUTOMATIC_PIX` | Recorrência de Pix Automático autorizada pelo pagador; quem agenda cada cobrança depende de `MANAGES_RECURRENCE`. | sim | sim | Iugu: A recorrência nasce na fatura (Invoice com automaticPix e método pix) e a aplicação é o motor de recorrência; a assinatura não aceita paymentMethod automatic_pix.<br>Stripe: A recorrência é o mandato de uma assinatura (paymentMethod automatic_pix na criação) e o gateway agenda as cobranças; fatura avulsa com automaticPix não é aceita, e as operações de agendamento e de cancelamento de cobrança da lib respondem managed_by_gateway. |
| `MULTIPLE_PAYMENT_METHODS` | Fatura aberta a mais de um método de pagamento, escolhido pelo pagador na hora de pagar. | sim | não implementado |  |
| `RAW_CARD_DATA` | Cartão informado com número e CVV pela API; sem ela, o cartão é tokenizado no navegador e só o token chega à lib. | sim | limitação do gateway |  |
| `CARD_SETUP_AUTHENTICATION` | Autenticação do portador com o emissor (3DS) ao salvar o cartão: cartão que exige ação do pagador volta com `CreditCard::$requiresAction` verdadeiro e `id` nulo, e `confirmCreditCardSetup()` conclui o salvamento depois da autenticação. | limitação do gateway | sim |  |
| `INSTALLMENTS` | Parcelamento da cobrança no cartão de crédito. | sim | limitação do gateway | Iugu: O número de parcelas vai em gatewayOptions['months'], até 12 (máximo da conta, configurável em multi-payment.gateways.iugu.max_installments); a lib não lê as parcelas da fatura paga. |
| `DELAYED_CAPTURE` | Cobrança em duas etapas no cartão: reserva do valor agora e captura depois. | sim | sim | Iugu: Só cartão de crédito, com o fluxo de pagamento em duas etapas habilitado na conta da Iugu; a captura é sempre do valor integral e a Iugu cancela sozinha a autorização não capturada em 7 dias.<br>Stripe: Só cartão de crédito na venda avulsa (PaymentIntent); a fatura de assinatura é cobrada pela Stripe com captura imediata. |
| `PARTIAL_REFUND_CARD` | Estorno de parte do valor numa fatura paga com cartão. | sim | sim |  |
| `PARTIAL_REFUND_PIX` | Estorno de parte do valor numa fatura paga com Pix. | limitação do gateway | sim |  |
| `REFUND_BANK_SLIP` | Estorno pela API de uma fatura paga com boleto. | limitação do gateway | limitação do gateway |  |
| `INVOICE_DUPLICATION` | Segunda via de uma fatura pendente com nova data de vencimento (`duplicateInvoice`). | sim | sim | Stripe: Só fatura Pix pendente de venda avulsa (PaymentIntent); cartão, boleto, outro estado ou fatura de assinatura são recusados. |
| `INVOICE_CANCELLATION` | Cancelamento de uma fatura ainda não paga (`cancelInvoice`). | sim | sim | Stripe: A fatura de assinatura (objeto Invoice) só é anulada depois de finalizada pela Stripe (rascunho é recusado), e o boleto pendente só depois de o voucher vencer. |
| `IDEMPOTENCY` | Chave de idempotência (`idempotencyKey`) honrada em toda operação de escrita, pelo gateway ou pela deduplicação da lib (`IdempotencyStore`). | sim | sim |  |
| `IDEMPOTENCY_ALL_ENDPOINTS` | Chave de idempotência honrada pelo próprio gateway em toda operação de escrita, sem depender da deduplicação da lib. | limitação do gateway | sim |  |
| `SUBSCRIPTIONS` | Assinatura recorrente: criar, buscar, atualizar, suspender, retomar, cancelar, trocar de plano e listar. | sim | sim | Stripe: nextBillingAt vale só na criação da assinatura; na troca de plano e na atualização a Stripe não aceita uma data arbitrária de próxima cobrança. |
| `PLANS` | Plano de assinatura: criar, buscar e listar. | sim | sim |  |
| `PLAN_DEACTIVATION` | Desativar um plano sem apagá-lo (`deactivatePlan`). | limitação do gateway | sim |  |
| `CANCEL_AT_PERIOD_END` | Cancelar a assinatura só no fim do período já pago (`cancel(atPeriodEnd: true)`). | emulado | sim |  |
| `COUPONS` | Cupom de assinatura com prazo: desconto limitado a um número de ciclos ou válido até uma data (`validUntil`). | emulado | sim | Stripe: O cupom da Stripe dura meses inteiros (duration_in_months): cycles maior que 1 exige plano com intervalo mensal ou anual, e validUntil vira meses inteiros contados da aplicação, arredondados para cima. |
| `PERCENT_DISCOUNT` | Desconto percentual (`percentOff`) sobre o valor da assinatura. | limitação do gateway | sim |  |
| `PLAN_CHANGE_PRORATION` | Crédito proporcional do período não usado, calculado pelo gateway, ao trocar de plano (`changePlan()` com `ProrationBehavior::CREDIT`). | limitação do gateway | sim |  |
| `SUBSCRIPTION_CREDITS` | Assinatura com saldo de créditos consumíveis, abatidos a cada uso. | não implementado | limitação do gateway |  |
| `MANAGES_RECURRENCE` | O gateway agenda as cobranças do Pix Automático por conta própria; sem ela, a aplicação é o motor de recorrência e chama as operações de `AutomaticPixContract` na periodicidade certa. | limitação do gateway | sim |  |
| `GATEWAY_DUNNING` | O gateway conduz a régua de retentativas da cobrança recusada de uma assinatura de forma adaptativa, dispensando régua da aplicação; sem ela, a retentativa do gateway é fixa ou ausente, e retentar além dela é decisão da aplicação. | limitação do gateway | sim |  |
| `WEBHOOKS` | Leitura de webhooks do gateway: `parseWebhook()` verifica a autenticidade da entrega e a traduz num `WebhookEvent` normalizado. | sim | sim |  |

Sobre as restrições e algumas células:

- **Operação fora da restrição** lança `UnsupportedOperationException::restricted()`, com a
  capability, `reason` `gateway_limitation` e a mensagem que descreve a restrição: duplicação
  fora de Pix pendente, cancelamento de rascunho de fatura de assinatura ou de boleto com
  voucher em aberto no Stripe, cartão que pertence a outro cliente no Stripe (ver
  [Particularidades do Stripe](#particularidades-do-stripe)).
  A bandeira fora da restrição de `CREDIT_CARD` chega depois, na cobrança, como
  `CardDeclinedException` com `DeclineCode::BRAND_NOT_SUPPORTED`; por isso vale consultar
  `restriction()->allowsBrand()` antes de tokenizar.
- **`CARD_SETUP_AUTHENTICATION`** faz `newCreditCard()->create()` devolver um cartão com
  `requiresAction` quando o emissor exige autenticação; na Iugu, que só valida o cartão
  (Zero Auth, sem 3DS), o cartão volta sempre cobrável (ver
  [Salvar cartão](#salvar-cartão-creditcardbuilder)).
- **`INSTALLMENTS` na Iugu** é informado em `gateway_options['months']`; a lib não modela parcelas
  nem lê os campos da fatura parcelada. O máximo publicado em `maxInstallments` vem de
  `multi-payment.gateways.iugu.max_installments` (`IUGU_MAX_INSTALLMENTS`, 12 por padrão) e
  deve refletir o parcelamento habilitado na conta.
- **`IDEMPOTENCY` na Iugu** é honrada pelo gateway só na criação de fatura, cliente e assinatura e
  na cobrança com cartão; nas demais operações de escrita a deduplicação é da lib, pela
  `IdempotencyStore`, que exige o cache do Laravel configurado (ver [Idempotência](#idempotência)).
  Por isso a Iugu não tem `IDEMPOTENCY_ALL_ENDPOINTS`.
- **`PARTIAL_REFUND_PIX` e `REFUND_BANK_SLIP`** chegam como `RefundNotSupportedException`, com
  `isCapabilityLimitation()` verdadeiro; a classe fica fora da árvore de
  `UnsupportedOperationException` (ver [Estorno](#estorno)).
- **`MANAGES_RECURRENCE`** é informativa: diz quem agenda a cobrança do Pix Automático (ver
  [Pix Automático: quem agenda a cobrança](#pix-automático-quem-agenda-a-cobrança)).
- **`GATEWAY_DUNNING`** também é informativa: no Stripe, os Smart Retries retentam a renovação
  recusada por conta própria, sem régua da aplicação; na Iugu a régua do gateway é fixa e
  curta (cinco tentativas), e a aplicação que quiser retentar além dela monta a própria régua
  (o motivo da recusa fica em `Invoice::$lastPaymentError`, ver
  [Motivo da recusa na leitura](#motivo-da-recusa-na-leitura)).
- **`PLAN_CHANGE_PRORATION`** é o que `changePlan()` com `ProrationBehavior::CREDIT` exige; as
  outras duas políticas fazem parte de `SUBSCRIPTIONS` (ver [Troca de plano](#troca-de-plano)).
- **`WEBHOOKS`** guarda `parseWebhook()`: a entrega é verificada e traduzida num
  `WebhookEvent` nos dois gateways (ver [Webhooks](#webhooks)).

### Status da fatura

`Invoice::$status` é o enum `Potelo\MultiPayment\Enums\InvoiceStatus`, sempre no vocabulário do
pacote; o status específico de cada gateway fica em `original`. Os treze estados:

| `InvoiceStatus` | Significado | Iugu | Stripe | Helper que responde |
|---|---|---|---|---|
| `PENDING` | Aguardando pagamento | `pending`, `draft` | PaymentIntent em `requires_payment_method`, `requires_action`, `requires_confirmation`; Invoice `draft` ou `open` sem pagamento em curso | `isOpen()` e `isPayable()` |
| `AUTHORIZED` | Valor reservado no cartão, aguardando captura ou análise | `in_analysis`, `authorized` | PaymentIntent `requires_capture` | `isOpen()` e `isPayable()` |
| `PROCESSING` | Pagamento em processamento no gateway | (não emite) | PaymentIntent `processing` | `isOpen()` |
| `PAID` | Valor recebido | `paid` | PaymentIntent `succeeded` sem estorno nem contestação; Invoice `paid` quitado sem cobrança | `isSettled()` |
| `PARTIALLY_PAID` | Parte do valor recebida, restante em aberto | `partially_paid` | Invoice `open` com parte do `total` em `amount_paid` (não emite em venda avulsa) | `isSettled()`, `isOpen()` e `isPayable()` |
| `EXTERNALLY_PAID` | Quitada fora do gateway, por baixa manual | `externally_paid` | Invoice `paid` com pagamento registrado fora da Stripe (não emite em venda avulsa) | `isSettled()` |
| `PARTIALLY_REFUNDED` | Estorno voluntário, parcial | `partially_refunded` | charge com `amount_refunded` menor que o total | `isSettled()` |
| `REFUNDED` | Estorno voluntário, integral | `refunded` | charge com `refunded = true` | `isTerminal()` |
| `DISPUTED` | Contestação aberta sobre fatura paga, resolução pendente | `in_protest` | charge `disputed` com dispute em `warning_needs_response`, `warning_under_review`, `needs_response` ou `under_review` | `isContested()` |
| `CHARGEBACK` | Contestação perdida: valor devolvido ao cliente pelo gateway | `chargeback` | dispute em `lost` | `isContested()` e `isTerminal()` |
| `CANCELED` | Cancelada antes do pagamento | `canceled` | PaymentIntent `canceled`; Invoice `void` | `isTerminal()` |
| `EXPIRED` | Venceu sem pagamento; continua pagável | `expired` | Invoice `uncollectible` (não emite em venda avulsa) | `isPayable()` |
| `UNKNOWN` | Status que a lib não reconhece | qualquer outro | qualquer outro | nenhum responde verdadeiro |

Dispute ganha (`won`), encerrada sem virar chargeback (`warning_closed`) ou prevenida
(`prevented`) não altera o status: a fatura volta a ler como `PAID` (ou como estornada, se
houve estorno). Um status fora do mapa vira `UNKNOWN`, com um aviso no log da aplicação (nível
`warning`) contendo o valor original e o gateway, e o valor cru continua em `original`. No
Stripe a coluna mistura as duas origens da fatura: a venda avulsa (PaymentIntent) e a fatura
de assinatura (Invoice), cuja regra de precedência está em
[Fatura no Stripe: duas origens](#fatura-no-stripe-duas-origens).

Os helpers do enum respondem às perguntas de negócio sem comparar status um a um:

```php
use Potelo\MultiPayment\Enums\InvoiceStatus;

$invoice->status->isSettled();    // recebi dinheiro? PAID, PARTIALLY_PAID, EXTERNALLY_PAID, PARTIALLY_REFUNDED
$invoice->status->isOpen();       // pagamento ainda por resolver? PENDING, AUTHORIZED, PROCESSING, PARTIALLY_PAID
$invoice->status->isPayable();    // aceita um pagamento agora? PENDING, AUTHORIZED, PARTIALLY_PAID, EXPIRED
$invoice->status->isContested();  // tem briga? DISPUTED, CHARGEBACK
$invoice->status->isTerminal();   // acabou? REFUNDED, CHARGEBACK, CANCELED

match ($invoice->status) {
    InvoiceStatus::DISPUTED => $this->openDisputeTicket($invoice),
    InvoiceStatus::CHARGEBACK => $this->writeOff($invoice),
    InvoiceStatus::EXPIRED => $this->offerNewPix($invoice),
    default => null,
};
```

`PARTIALLY_PAID` responde verdadeiro a `isSettled()` e a `isOpen()` ao mesmo tempo: parte do
dinheiro entrou e o restante segue cobrável. `EXPIRED` responde verdadeiro só a `isPayable()`:
a fatura vencida continua pagável nos dois gateways (na Iugu ela segue devida até ser paga ou
cancelada, e na Stripe `uncollectible` pode voltar a `paid`), então ela fica fora de
`isTerminal()` e fora de `isOpen()`, que descreve a fatura com pagamento em curso. `PROCESSING`
responde só a `isOpen()`: há um pagamento em curso, e a fatura fica fora de `isPayable()`. Quem
decide se para de cobrar deve olhar `isTerminal()`; quem decide se oferece um novo Pix ou boleto
deve olhar `isPayable()`.
Os helpers estáticos `Invoice::isSettled()` e `Invoice::isContested()` continuam existindo,
delegam ao enum e estão obsoletos (emitem `E_USER_DEPRECATED`).

> **Mudança de comportamento (versão 5.0.0).** `InvoiceStatus::EXPIRED->isTerminal()` passou a
> responder falso. Quem usava `isTerminal()` para parar de cobrar parava cedo demais na Iugu,
> onde a fatura vencida segue pagável. Se a aplicação tratava a fatura vencida como encerrada,
> trate `EXPIRED` explicitamente, ou use `isPayable()` para decidir se ainda cabe pagamento.

> **Mudança de comportamento (versão 5.0.0).** Até a 4.1.0, fatura Iugu em `in_protest` lia como
> `paid` e fatura em `chargeback` lia como `refunded`; no Stripe, charge contestado lia como
> `paid`. A partir desta versão elas leem como `DISPUTED` e `CHARGEBACK`. Quem compara com
> `PAID` para decidir se recebeu **deixa de ver faturas em disputa como pagas**, e quem compara
> com `REFUNDED` deixa de confundir chargeback com estorno voluntário. Na mesma versão, a Iugu
> deixou de ser achatada: `in_analysis` lia como `pending` e agora é `AUTHORIZED`;
> `partially_paid` lia como `pending` e agora é `PARTIALLY_PAID`; `externally_paid` lia como
> `paid` e agora é `EXTERNALLY_PAID`; `expired` lia como `canceled` e agora é `EXPIRED`. No
> Stripe, `requires_capture` lia como `pending` e agora é `AUTHORIZED`; `processing` lia como
> `pending` e agora é `PROCESSING`. Status desconhecido lançava `GatewayException` e agora vira
> `UNKNOWN` com log. Se a aplicação precisava do comportamento antigo, use `isSettled()` para
> "pago", `isOpen()` para "ainda cobrável" e trate `DISPUTED` e `CHARGEBACK` explicitamente.

### Status da assinatura

`Subscription::$status` é o enum `Potelo\MultiPayment\Enums\SubscriptionStatus`, no mesmo
desenho do status da fatura; as flags ou o status específico do gateway ficam em `original`.
Os nove estados:

| `SubscriptionStatus` | Significado | Iugu | Stripe | Helper que responde |
|---|---|---|---|---|
| `PENDING` | Criada e ainda sem cobrança confirmada | `active` falso com `expires_at` futuro ou ausente | `incomplete` | `isRecoverable()` |
| `TRIALING` | Em período de teste | `in_trial` | `trialing` | `isActive()` |
| `ACTIVE` | Em dia | `active` | `active` | `isActive()` |
| `PAST_DUE` | Cobrança vencida sem pagamento | derivado: `expires_at` no passado com alguma fatura de `recent_invoices` em aberto | `past_due`, `unpaid` | `isRecoverable()` |
| `PAUSED` | Cobrança pausada pelo gateway | (não emite) | `paused` (trial terminou sem método de pagamento) | `isRecoverable()` |
| `SUSPENDED` | Cobrança interrompida pela aplicação | `suspended` | qualquer status não encerrado com `pause_collection` preenchido (é o que `suspend()` grava) | `isRecoverable()` |
| `CANCELED` | Encerrada | `suspended` com a marca `mp_canceled_at` em `custom_variables`, gravada por `cancel()` | `canceled` | `isEnded()` |
| `EXPIRED` | Ciclo terminou sem renovação | `active` falso com `expires_at` no passado e nenhuma fatura em aberto | `incomplete_expired` | `isEnded()` |
| `UNKNOWN` | Status que a lib não reconhece | (não emite: a Iugu não tem campo de status) | qualquer outro | nenhum responde verdadeiro |

A precedência na Iugu é a ordem em que o driver testa as flags: `suspended` (com ou sem a
marca) vence `in_trial`, que vence a derivação de `past_due`, que vence `active`. A regra de
`EXPIRED` na Iugu segue o painel do gateway (assinatura "Expirada") e o webhook
`subscription.expired`. Um status
fora do mapa vira `UNKNOWN`, com um aviso no log da aplicação (nível `warning`) contendo o valor
original e o gateway.

```php
use Potelo\MultiPayment\Enums\SubscriptionStatus;

if ($subscription->status->isActive()) {            // TRIALING, ACTIVE
    $account->grantAccess();
} elseif ($subscription->status->isRecoverable()) { // PENDING, PAST_DUE, PAUSED, SUSPENDED
    $dunning->start($subscription);
} elseif ($subscription->status->isEnded()) {       // CANCELED, EXPIRED
    $account->revokeAccess();
}

$subscription->status === SubscriptionStatus::PAST_DUE;
$subscription->status->value;                       // 'past_due', para gravar no banco
```

Cada estado responde verdadeiro a exatamente um dos três helpers (`UNKNOWN` a nenhum), então
os três `if` acima cobrem tudo que a lib produz.

> **Mudança de comportamento (versão 5.0.0).** No Stripe, a assinatura com `pause_collection`
> preenchido lê como `SUSPENDED` (nas prévias da 5.0.0 lia como `PAUSED`). Só a aplicação grava
> `pause_collection` (inclusive via `suspend()`), então `suspend()` produz o mesmo estado nos
> dois gateways e `PAUSED` fica reservado à pausa por iniciativa do gateway (o status `paused`
> da Stripe, de trial que terminou sem método de pagamento). Quem comparava com `PAUSED` depois
> de `suspend()` deve comparar com `SUSPENDED`, ou usar `isRecoverable()`, que cobre os dois.

> **Mudança de comportamento (versão 5.0.0).** Até a 4.1.0, `Subscription::$status` era uma
> string e `cancel()` na Iugu devolvia `suspended`, o mesmo estado de `suspend()`. Agora a
> propriedade devolve `SubscriptionStatus` (compará-la com `Subscription::STATUS_*` é sempre
> falso; use o caso do enum ou `->value`), e `cancel()` na Iugu devolve `CANCELED`: além de
> suspender, o driver grava a data em `custom_variables` (`mp_canceled_at`), numa segunda
> requisição, e é essa marca que distingue os dois estados na leitura. `resume()` remove a
> marca. Assinaturas canceladas antes desta versão continuam lendo como `SUSPENDED`, porque não
> têm a marca.

### Migração das constantes para enum

Status da fatura, status da assinatura, método de pagamento e intervalo do plano são enums do
namespace `Potelo\MultiPayment\Enums`: `InvoiceStatus`, `SubscriptionStatus`, `PaymentMethod`
(`CREDIT_CARD`, `BANK_SLIP`, `PIX`, `AUTOMATIC_PIX`) e `PlanInterval` (`DAY`, `WEEK`, `MONTH`,
`YEAR`). As propriedades `Invoice::$status`, `Invoice::$paymentMethod`,
`Invoice::$availablePaymentMethods`, `Subscription::$status`, `Subscription::$paymentMethod`,
`Subscription::$availablePaymentMethods` e `Plan::$interval` devolvem o enum na leitura e
aceitam, na escrita, tanto o caso do enum quanto a string do valor (as constantes antigas). O
mesmo vale para `fill()` e para os builders.

As constantes antigas (`Invoice::STATUS_*`, `Subscription::STATUS_*`,
`Invoice::PAYMENT_METHOD_*`, `Plan::INTERVAL_*`) continuam existindo, com os mesmos valores de
string dos enums, e estão marcadas como `@deprecated`. O que muda é a **comparação**: a
propriedade agora devolve um enum, então compará-la diretamente com a string antiga é sempre
falso.

```php
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Enums\PlanInterval;

// antes
if ($invoice->status === Invoice::STATUS_PAID) { ... }
if (in_array($invoice->paymentMethod, [Invoice::PAYMENT_METHOD_PIX, Invoice::PAYMENT_METHOD_BANK_SLIP])) { ... }
$invoice->paymentMethod = Invoice::PAYMENT_METHOD_CREDIT_CARD;
$plan->interval = Plan::INTERVAL_MONTH;
$model->status_pagamento = $invoice->status;              // gravando no banco

// depois (recomendado)
if ($invoice->status === InvoiceStatus::PAID) { ... }
if (in_array($invoice->paymentMethod, [PaymentMethod::PIX, PaymentMethod::BANK_SLIP], true)) { ... }
$invoice->paymentMethod = PaymentMethod::CREDIT_CARD;
$plan->interval = PlanInterval::MONTH;
$model->status_pagamento = $invoice->status->value;       // 'paid'

// transição: a constante antiga ainda vale como valor de string
if ($invoice->status->value === Invoice::STATUS_PAID) { ... }
$invoice->paymentMethod = Invoice::PAYMENT_METHOD_CREDIT_CARD;  // convertido para PaymentMethod::CREDIT_CARD
```

Onde a string vai para fora do PHP (banco, JSON, log, comparação com valor vindo do gateway),
use `->value`. `toArray()` já emite o valor de string, e `json_encode($invoice)` também.

Valor de string fora do enum tem dois tratamentos: em `status`, vira `InvoiceStatus::UNKNOWN`
ou `SubscriptionStatus::UNKNOWN` com aviso no log; em `paymentMethod`,
`availablePaymentMethods` e `interval`, lança
`ModelAttributeValidationException` na escrita, com a lista de valores aceitos. Em
`availablePaymentMethods` só entram `CREDIT_CARD`, `BANK_SLIP` e `PIX`; `AUTOMATIC_PIX` é
recusado na lista e em `Invoice::$paymentMethod`, porque a fatura com Pix Automático é criada
com `PIX` e o objeto `automaticPix` preenchido. Em `Subscription::$paymentMethod` ele é
aceito: no Stripe é o método da assinatura com mandato (ver
[Pix Automático](#pix-automático)).

> **Mudança de comportamento (versão 5.0.0).** `$invoice->status === Invoice::STATUS_PAID` e
> comparações equivalentes com `paymentMethod` e `interval` passam a ser **falsas**, porque a
> propriedade devolve um enum. Revise cada comparação com constante ou string literal e troque
> pelo caso do enum ou compare `->value`. Método de pagamento e intervalo inválidos lançam já
> na escrita da propriedade (até a 4.1.0, só `validate()` acusava).

### Particularidades do Stripe

- **Cartão é token-only.** O Stripe não aceita dados crus de cartão pela API (exigiria
  liberação de "raw card data" e escopo PCI SAQ D). Tokenize o cartão no navegador com
  Stripe.js e envie o id resultante (`pm_...`) em `credit_card.token` / `CreditCard::$token`
  (tokens legados `tok_...` também são aceitos). O caminho com `number`/`cvv` lança
  `UnsupportedOperationException` (`RAW_CARD_DATA`, `gateway_limitation`) orientando o uso de
  token.
- **Bandeiras aceitas no Brasil: somente Visa e Mastercard crédito.** Elo, Hipercard, Amex e
  débito nacional não são suportados pelo Stripe BR. Para essas bandeiras, roteie a cobrança
  para outro gateway (ex.: Iugu) — de preferência detectando a bandeira pelo BIN antes de
  tokenizar. Para decidir o fallback programaticamente, use `CardDeclinedException::$declineCode`:
  `DeclineCode::BRAND_NOT_SUPPORTED` é a recusa por bandeira (`card_not_supported` na Stripe), e
  `retryable` diz se vale repetir com o mesmo cartão (ver [Códigos de recusa](#códigos-de-recusa)).
  `GatewayNotAvailableException` também sinaliza "tente outro gateway"; `AuthenticationException`
  sinaliza credencial errada e não deve gerar fallback (ver [Tratamento de erros](#tratamento-de-erros)).
- **Salvar cartão autentica o portador quando o emissor exige.** `newCreditCard()->create()`
  cria e confirma um SetupIntent (`usage: off_session`): o cartão que o emissor aprova volta
  salvo e cobrável; o cartão que exige autenticação (3DS) volta com `requiresAction`
  verdadeiro, sem `id`, e só fica cobrável depois de `confirmCreditCardSetup()` (ver
  [Salvar cartão](#salvar-cartão-creditcardbuilder)). Na venda avulsa com token
  (`addCreditCardToken()`), um cartão que exige autenticação lança `CardDeclinedException` com
  `DeclineCode::AUTHENTICATION_REQUIRED` antes de criar a fatura: a cobrança fora de sessão não
  tem como atendê-la. Esse código pede ação do pagador (autenticar o cartão ou informar outro);
  o gateway respondeu normalmente e não cabe fallback.
- **Pix exige `tax_document` do cliente** (CPF/CNPJ vai nos billing details do pagamento).
- **A expiração do QR Code do Pix é `pixExpiresAt`** (opcional; default do Stripe: 4 horas) e,
  quando informada, deve ficar entre 10 segundos e 14 dias no futuro. Sem ela, `dueDate` faz o
  QR Code expirar no fim do dia do vencimento, dentro da mesma janela (ver
  [Datas da fatura](#datas-da-fatura)). Só um método por fatura: `paymentMethod`,
  `availablePaymentMethods` com um único método ou só o cartão (`addCreditCardId()`,
  `addCreditCardToken()`); sem nenhum dos três, `ModelAttributeValidationException` antes da rede.
- **Pix expirado continua pendente e re-cobrável.** Na Iugu, fatura expirada vira `canceled`;
  no Stripe ela volta a aguardar pagamento (`pending`) e pode ser paga com cartão via
  `chargeInvoiceWithCreditCard` ou duplicada com `duplicateInvoice` (nova expiração;
  a original é cancelada). Só fatura Pix pendente de venda avulsa é duplicável: cartão, boleto,
  fatura em outro estado ou fatura de assinatura lança `UnsupportedOperationException`
  (`INVOICE_DUPLICATION`, `gateway_limitation`); `restriction(Capability::INVOICE_DUPLICATION)`
  publica a regra.
- **Boleto exige `tax_document`, nome, e-mail e endereço do cliente.** O CPF/CNPJ vai no
  voucher (`boleto.tax_id`) e os demais nos billing details (a sandbox aceita boleto sem
  esses dados, a produção os exige); a falta de qualquer um, inclusive de rua, cidade, estado
  ou CEP no endereço, lança `ModelAttributeValidationException` antes da rede. O valor deve
  ficar entre R$ 5,00 e
  R$ 49.999,99 e o vencimento (`dueDate`) de hoje a 60 dias, também validados antes da
  requisição; sem `dueDate`, vale o prazo padrão da conta na Stripe (3 dias). A fatura volta
  pendente com a página hospedada do voucher em `url`, a linha digitável em `bankSlip->number`
  e o PDF em `bankSlip->url` (a Stripe só publica o número, então `barcode_data` e
  `barcode_image` ficam vazios). A compensação leva até um dia útil depois do pagamento;
  acompanhe por `getInvoice()`.
- **Boleto pendente não pode ser cancelado nem re-cobrado.** A Stripe não invalida o voucher
  antes do vencimento: `cancelInvoice()` numa fatura com o voucher em aberto é recusado
  (`UnsupportedOperationException` restrita de `INVOICE_CANCELLATION` quando o model traz o
  voucher; `ValidationException` quando a recusa vem do gateway), e
  `chargeInvoiceWithCreditCard()` também é recusado pelo gateway enquanto o voucher vale.
  Vencido o voucher, a fatura volta a aguardar pagamento (`pending`) e pode ser cancelada ou
  cobrada com cartão. Estorno de boleto fica fora da API nos dois gateways
  (`REFUND_BANK_SLIP`).
- **Cartão pertence a um único cliente.** Cobrar, buscar ou excluir um `pm_` informando outro
  cliente lança `UnsupportedOperationException` (`CREDIT_CARD`, `gateway_limitation`) antes da
  operação.
- **A fatura tem duas origens.** A venda avulsa é um PaymentIntent (`pi_`) e a fatura de
  assinatura é um objeto Invoice da Stripe (`in_`); `getInvoice()` aceita os dois ids e
  `Invoice::$originType` diz qual voltou. Ver
  [Fatura no Stripe: duas origens](#fatura-no-stripe-duas-origens).
- **`url` da fatura**: no pix é a página hospedada com instruções de pagamento
  (`hosted_instructions_url`); em fatura de cartão é `null` — não assuma `url` preenchida
  como na Iugu (`secure_url`).
- **`fee` é assíncrono para cartão**: pode vir `null` logo após a cobrança e preenchido em um
  `getInvoice` posterior.
- **Contestação custa uma requisição a mais.** O charge da Stripe só traz a flag `disputed`;
  quando ela é verdadeira, o pacote consulta `/v1/disputes` do charge para decidir entre
  `disputed` e `chargeback` (ver [Status da fatura](#status-da-fatura)). Fatura sem contestação
  não paga esse GET.
- **Idempotência em toda escrita.** A chave informada em `idempotencyKey` vai no cabeçalho
  `Idempotency-Key` de toda requisição de escrita da operação, inclusive cliente, cartão,
  cancelamento e as requisições secundárias, com chaves derivadas (ver
  [Idempotência](#idempotência)).

### Fatura no Stripe: duas origens

Na Stripe a `Invoice` do pacote pode vir de dois objetos, e `Invoice::$originType` (enum
`Potelo\MultiPayment\Enums\InvoiceOriginType`) diz de qual:

| `originType` | Objeto da Stripe | Quando | `original` |
|---|---|---|---|
| `PAYMENT_INTENT` | PaymentIntent (`pi_`) | venda avulsa criada pela lib (cartão ou Pix) | `\Stripe\PaymentIntent` |
| `INVOICE` | Invoice (`in_`) | fatura de assinatura gerada pelo Stripe Billing | `\Stripe\Invoice` |

Na Iugu `originType` é sempre `INVOICE`: toda fatura de lá é o objeto de fatura do gateway.

`getInvoice()` aceita os dois ids e decide pelo prefixo qual objeto ler:

```php
use Potelo\MultiPayment\Enums\InvoiceOriginType;

$payment = new \Potelo\MultiPayment\MultiPayment('stripe');

$avulsa = $payment->getInvoice('pi_3UBH...');      // originType PAYMENT_INTENT
$assinatura = $payment->getInvoice('in_1UBH...');  // originType INVOICE

if ($assinatura->originType === InvoiceOriginType::INVOICE) {
    $assinatura->original->hosted_invoice_url;   // objeto cru da Stripe, quando precisar do detalhe
}
```

O que muda na fatura de origem `INVOICE`:

- **Line items** vêm dos itens reais do Invoice (`lines.data`, a primeira página, de até dez
  itens); na venda avulsa continuam sendo reconstruídos do `metadata` do PaymentIntent.
- **`url`** é a página hospedada da fatura (`hosted_invoice_url`), com o QR Code do Pix quando
  for o caso; `pix` continua trazendo o QR Code quando o PaymentIntent tem um.
- **`dueDate`** é o `due_date` da fatura, quando ela tem um, e **`pixExpiresAt`** a expiração
  do QR Code do Pix, quando o PaymentIntent tem um.
- **`paidAt`** é o instante em que a Stripe marcou a fatura como paga.
- **Uma requisição a mais** quando a fatura já teve tentativa de pagamento: o charge do
  PaymentIntent fica além do limite de `expand` da Stripe e é lido num GET à parte.
- **`cancelInvoice()`** anula a fatura (`void`); a Stripe cancela sozinha o PaymentIntent
  dela. Rascunho (`draft`) não é anulável e lança `UnsupportedOperationException`
  (`INVOICE_CANCELLATION`, `gateway_limitation`) orientando a esperar a finalização; fatura
  `paid` ou já anulada lança `ValidationException`, como o PaymentIntent já pago ou cancelado.
- **`duplicateInvoice()`** é recusado com `UnsupportedOperationException` (`INVOICE_DUPLICATION`,
  `gateway_limitation`): a próxima fatura da assinatura é gerada pela Stripe, e um Pix expirado
  se resolve com nova tentativa de pagamento da mesma fatura.
- **`refundInvoice()` e `refundableAmount()`** agem sobre o PaymentIntent da fatura: o driver
  a relê para apontá-lo e conferir o estado, e o restante estornável é o do charge dela. A
  fatura quitada sem cobrança pela Stripe (paga fora dela, ou sem valor a cobrar) é recusada
  com `RefundNotSupportedException` (`no_gateway_charge`) e devolve zero em
  `refundableAmount()`. Depois do estorno, o status da fatura relida vem do charge
  (`REFUNDED` ou `PARTIALLY_REFUNDED`); o objeto Invoice da Stripe segue `paid`.
- **`chargeInvoiceWithCreditCard()`** paga a fatura aberta com o cartão (`invoices.pay`). A
  Stripe exige um PaymentMethod anexado ao cliente da fatura, então um cartão informado por
  token é salvo antes (SetupIntent, como em `createCreditCard()`); se o emissor exigir
  autenticação do pagador, a operação lança `ChargingException` com
  `DeclineCode::AUTHENTICATION_REQUIRED` e a orientação do fluxo em duas etapas.
- **`captureInvoice()`** é recusado (`UnsupportedOperationException`, restrição de
  `DELAYED_CAPTURE`): a fatura de assinatura é cobrada pela Stripe com captura imediata.

**Precedência de status.** O status do Invoice da Stripe manda no ciclo de vida da fatura; o
PaymentIntent e o charge só refinam o detalhe de pagamento. Um PaymentIntent `succeeded` não
torna paga uma fatura que a Stripe ainda considera `open`, e um PaymentIntent `canceled` não
cancela uma fatura `open`. A tabela completa:

| Invoice Stripe | PaymentIntent e charge | `InvoiceStatus` |
|---|---|---|
| `draft` | qualquer | `PENDING` |
| `open` | ausente, `requires_payment_method`, `requires_action` ou `requires_confirmation` | `PENDING` |
| `open` | `requires_capture` | `AUTHORIZED` |
| `open` | `processing` | `PROCESSING` |
| `open` | `amount_paid` do Invoice maior que zero e menor que `total` | `PARTIALLY_PAID` |
| `paid` | `succeeded`, charge sem dispute e sem refund | `PAID` |
| `paid` | `succeeded`, charge com refund parcial | `PARTIALLY_REFUNDED` |
| `paid` | `succeeded`, charge com refund total | `REFUNDED` |
| `paid` | charge com dispute aberta | `DISPUTED` |
| `paid` | charge com dispute perdida (`lost`) | `CHARGEBACK` |
| `paid` | pagamento registrado fora da Stripe (`paid_out_of_band`) | `EXTERNALLY_PAID` |
| `paid` | sem PaymentIntent `succeeded`; pagamento do tipo `charge` anexado à fatura | `PAID` |
| `paid` | sem PaymentIntent e `amount_due` zero (avaliação gratuita, saldo de crédito, valor abaixo do mínimo) | `PAID` |
| `void` | qualquer | `CANCELED` |
| `uncollectible` | qualquer | `EXPIRED` |
| qualquer combinação não listada | | `UNKNOWN`, com aviso no log contendo os três status e o id da fatura |

Duas ressalvas para quem trata status como definitivo:

- **`uncollectible` lê como `EXPIRED` e continua reversível na Stripe**: a fatura pode voltar a
  `paid` ou ir a `void` depois. Uma fatura `EXPIRED` de origem `INVOICE` pode, portanto, ler
  como `PAID` numa releitura; `isTerminal()` responde falso e `isPayable()` verdadeiro para
  `EXPIRED` por isso.
- **`open` com PaymentIntent `succeeded` fica em `UNKNOWN` de propósito**: a Stripe atualiza o
  Invoice no mesmo instante em que confirma o pagamento, então essa combinação é uma leitura
  no meio da transição ou um pagamento fora do padrão que não quitou a fatura. Se aparecer no
  log, releia a fatura.

### Opções extras do gateway

Todo model tem o array público `gatewayOptions`: é a válvula de escape para enviar ao gateway
uma opção que a lib não modela. O driver mescla esse array ao payload que monta a partir do
model, e as chaves daqui sobrepõem as geradas. Nos arrays de entrada (`charge()`, `fill()`) a
chave é `gateway_options`; nos builders, `setGatewayOptions()`.

```php
$invoice = $payment->newInvoice()
    ->addAvailablePaymentMethod(PaymentMethod::PIX)
    ->addCustomer('Nome', 'email@example.com', '01234567891')
    ->addItem('Produto', 10000, 1)
    ->setGatewayOptions(['expires_in' => 3])   // opção da Iugu, sem equivalente genérico
    ->create();

$customer->gatewayOptions = ['metadata' => ['crm_id' => '42']];   // opção da Stripe
```

Use com moderação: o conteúdo é específico de um gateway e não passa por validação da lib. Se
uma opção vira uso recorrente, ela deve ser modelada genericamente.

> **Nome antigo.** Até a 4.1.0 o array se chamava `gatewayAdicionalOptions` (com
> `setGatewayAdicionalOptions()` no builder e `gateway_adicional_options` nos arrays). Os três
> continuam funcionando como alias do nome novo, lendo e escrevendo o mesmo array, e emitem um
> aviso `E_USER_DEPRECATED` a cada uso; estão marcados `@deprecated` desde 2026-09-02 e saem na
> próxima versão maior. A única diferença observável é `toArray()`, que passa a devolver a
> chave `gateway_options`.

### `fill()` estrito

`Model::fill()` (e, por consequência, `charge($attributes)`, `create($data)` e os arrays
aninhados de `customer`, `items`, `credit_card`...) lança `ModelAttributeValidationException`
para chave que não corresponde a nenhuma propriedade do model. A mensagem traz o model, a chave
e a lista de chaves aceitas:

```php
$payment->charge(['amount' => 10000, 'trial_days' => 7, 'customer' => [...]]);
// ModelAttributeValidationException: The `trial_days` key is unknown for the `Invoice` model.
// Accepted keys: id, status, paid_at, amount, ..., gateway_options.
```

Duas exceções à regra: chave com prefixo `gateway_` (ou `gateway` em `camelCase`) continua
sendo ignorada em silêncio, e o conteúdo de `gateway_options` é livre (vai inteiro ao gateway).
As chaves aceitas de cada model estão em `Model::fillableKeys()`, em `snake_case`. Use sempre
`snake_case` nos arrays: uma chave escalar em `camelCase` (`taxDocument`) também é aceita, mas
as chaves que viram objeto ou data (`customer`, `items`, `credit_card`, `due_date`...) só são
convertidas na forma em `snake_case`.

Para desligar temporariamente durante uma migração, use a configuração
`multi-payment.strict_fill` (variável `MULTIPAYMENT_STRICT_FILL`); com `false`, a chave
desconhecida volta a ser descartada sem erro, como nas versões anteriores.

> **Mudança de comportamento (versão 5.0.0).** Até a 4.1.0, `fill()` descartava chave
> desconhecida em silêncio: um `trial_days` ou um `idempotency_key` fora de `gateway_options`
> simplesmente não faziam nada. Agora lançam. Se a aplicação monta os arrays a partir de dados
> externos, valide as chaves antes ou desligue `strict_fill` enquanto ajusta.

### Idempotência

Toda operação de escrita aceita uma chave de idempotência como último argumento
(`?string $idempotencyKey = null`), na fachada, nos models e nos drivers; nos builders ela entra
por `withIdempotencyKey()`. Duas chamadas com a mesma chave produzem um único efeito no gateway:
a segunda devolve o resultado da primeira em vez de criar outra fatura, outro estorno ou outra
troca de plano.

```php
use Potelo\MultiPayment\Facades\MultiPayment;

$invoice = MultiPayment::newInvoice()
    ->addAvailablePaymentMethod(PaymentMethod::PIX)
    ->setCustomer($customer)
    ->addItem('Mensalidade', 10000, 1)
    ->withIdempotencyKey($order->uuid)          // uma chave por intenção de escrita
    ->create();

MultiPayment::refundInvoice($invoice->id, 5000, idempotencyKey: "refund-{$order->uuid}");
MultiPayment::cancelInvoice($invoice->id, idempotencyKey: "cancel-{$order->uuid}");
$subscription->changePlan('plano_anual', idempotencyKey: "upgrade-{$order->uuid}");
$customer->save('iugu', idempotencyKey: "customer-{$user->id}");
```

**A lib nunca gera uma chave por conta própria.** Sem `idempotencyKey`, a requisição vai sem
deduplicação e um retry cria um segundo registro; é a aplicação que sabe qual pedido, assinatura
ou estorno a chamada representa, então é ela que escolhe a chave (um UUID gravado junto do
pedido, por exemplo). Gerar a chave por baixo esconderia esse risco. Regras da chave: a mesma
chave sempre com o mesmo payload; chave nova para cada nova intenção; retry com a mesma chave
só depois de uma falha em que a resposta não chegou ou o processo caiu.

Duas regras de payload que valem para a chave: a Stripe compara o payload e recusa a mesma
chave com conteúdo diferente (`IdempotencyConflictException`), então um campo derivado do
instante da chamada, como um `pixExpiresAt` calculado de `now()`, precisa ser gravado junto da
chave e reenviado igual; a Iugu não compara e responde com o recurso original mesmo que o
payload tenha mudado (por isso o `expires_at` que `setTrialDays()` calcula a cada tentativa não
conflita com a chave na Iugu).

Retry seguro:

```php
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;
use Potelo\MultiPayment\Exceptions\IdempotencyConflictException;

$key = $order->idempotency_key ??= (string) Str::uuid();   // gravada antes da primeira tentativa

try {
    $invoice = $payment->newInvoice()->/* ... */->withIdempotencyKey($key)->create();
} catch (GatewayNotAvailableException $e) {
    // timeout ou 5xx: repetir mais tarde com a MESMA chave não cobra duas vezes
    ChargeOrder::dispatch($order)->delay(now()->addMinutes(5));
} catch (IdempotencyConflictException $e) {
    // a primeira tentativa ainda está em andamento, ou a chave foi reusada com outro payload:
    // consultar o resultado dela em vez de repetir
}
```

Quem honra a chave depende da operação e do gateway. A Stripe aceita `Idempotency-Key` em
todo POST e, na repetição, devolve a mesma resposta; a Iugu só aceita o cabeçalho em quatro
endpoints e, na repetição, responde 409 apontando o recurso original (`resource_id`): para
fatura e cobrança com cartão a lib lê essa fatura e a devolve, então a segunda chamada tem o
mesmo resultado da primeira; para cliente e assinatura a Iugu não informa o id
(`resource_id: processing`) e a segunda chamada lança `IdempotencyConflictException`, cabendo à
aplicação consultar o registro que gravou na primeira. Nos demais endpoints da Iugu a lib
deduplica por conta própria com a `IdempotencyStore` (abaixo):

| Operação | Iugu | Stripe |
|---|---|---|
| Criar fatura (`create()`, `charge()`), com Pix, boleto ou cartão | gateway (`POST /invoices` ou `POST /charge`) | gateway |
| Cobrar fatura com cartão (`chargeInvoiceWithCreditCard`) | gateway (`POST /charge`) | gateway |
| Criar cliente | gateway | gateway |
| Criar assinatura | gateway | gateway |
| Atualizar cliente, definir cartão padrão | store da lib | gateway |
| Salvar cartão, excluir cartão | store da lib | gateway |
| Concluir o setup do cartão (`confirmCreditCardSetup`) | (limitação do gateway) | gateway, nas escritas secundárias (`{chave}:attach`, `{chave}:metadata`, `{chave}:default`); a leitura do setup não leva chave |
| Estornar, cancelar, duplicar fatura | store da lib | gateway |
| Suspender, retomar, cancelar, atualizar assinatura, trocar de plano | store da lib | gateway |
| Criar plano | store da lib | gateway |
| Desativar plano (`deactivatePlan`) | (limitação do gateway) | gateway |
| Reagendar e cancelar Pix Automático | store da lib | (não se aplica: o gateway agenda, `managed_by_gateway`) |

Quando uma operação faz mais de uma requisição de escrita (salvar o cartão antes de cobrar,
criar o tax id ao atualizar o cliente, remover subitens antes de atualizar a assinatura), a
requisição principal leva a chave informada e as secundárias levam chaves derivadas dela
(`{chave}:card`, `{chave}:tax_id`, `{chave}:remove`...): a Stripe recusa a mesma chave em dois
endpoints, e a derivação é determinística, então um retry reproduz as mesmas chaves. O cliente
criado junto com a fatura ou a assinatura (`Invoice::save()` sem `customer.id`) recebe
`{chave}:customer`, então o retry de `charge()` com cliente novo não cria um segundo cliente
(na Iugu, onde a repetição da chave em cliente responde 409, o retry de `charge()` com cliente
novo lança `IdempotencyConflictException`; consulte a fatura pelo registro da aplicação ou
crie o cliente antes com a própria chave).

Com chave, as guardas locais que dependem do estado do recurso deixam de recusar um retry: um
estorno sobre fatura já estornada (ou acima do restante) é enviado mesmo assim e a Stripe
repete o refund original quando a chave é a dele (senão a recusa da guarda é a que sobe); a
duplicação de uma fatura já cancelada e a exclusão de um cartão já desvinculado seguem o mesmo
caminho. Na Iugu, o estorno com chave passa inteiro (leitura, guardas e `POST`) pela store, e
o retry devolve o `Refund` da primeira execução.

**A `IdempotencyStore`.** Nas operações da Iugu marcadas "store da lib", a requisição de escrita
passa por `Potelo\MultiPayment\Contracts\IdempotencyStore`: a primeira execução com a chave é
guardada por 24 horas (`multi-payment.idempotency.ttl`), e as seguintes devolvem a resposta
guardada sem chamar a Iugu. A mesma chave reaparecendo em outra operação (um cancelamento e
depois um estorno com a chave do cancelamento) lança `IdempotencyConflictException` em vez de
devolver a resposta errada. Duas execuções concorrentes com a mesma chave são serializadas por
lock, e a segunda recebe `IdempotencyConflictException`, como a Iugu responde 409. Uma execução
que lança não é guardada (o retry executa de novo). O service provider registra a
`CacheIdempotencyStore`, sobre o cache do Laravel, que exige um store com suporte a lock
(`redis`, `memcached`, `database`, `file`, `array` ou `dynamodb`); sem cache configurado, a
primeira operação com chave num endpoint da store lança `ConfigurationException`. Operações
sem chave nunca tocam a store, e os quatro endpoints nativos da Iugu tampouco. A interface tem
`remember()`, `has()` e `forget()`; o `forget()` desfaz um resultado guardado e é usado pela
deduplicação de webhooks quando o processamento de uma entrega falha (ver a seção Webhooks),
então uma store própria precisa implementá-lo.

```php
// config/multi-payment.php
'idempotency' => [
    'ttl' => env('MULTIPAYMENT_IDEMPOTENCY_TTL', 86400),           // segundos
    'cache_store' => env('MULTIPAYMENT_IDEMPOTENCY_CACHE_STORE'),  // nulo: o cache padrão da aplicação
    'prefix' => 'multi-payment:idempotency:',
],

// outra store: bind próprio no service provider da aplicação
$this->app->bind(IdempotencyStore::class, fn () => new MinhaStore());

// testes da aplicação: store em memória, sem cache
$this->app->instance(IdempotencyStore::class, new InMemoryIdempotencyStore());
```

Limite da store: ela só protege de um retry feito **depois** de uma resposta recebida. Se a
Iugu executou a escrita e a resposta se perdeu (timeout), nada foi guardado e o retry com a
mesma chave executa de novo; só o gateway conseguiria deduplicar esse caso, e nesses endpoints
a Iugu não deduplica. Para a troca de plano com cobrança e o estorno, confira o estado da
assinatura ou da fatura antes de repetir depois de um timeout.

> **Nome antigo.** Até a 4.1.0 a chave ia em `gateway_options['idempotency_key']` e só o
> Stripe a repassava, na criação de fatura e no estorno. A chave nesse lugar continua sendo
> usada, com aviso `E_USER_DEPRECATED`, quando o argumento não é informado (o argumento tem
> precedência), e deixa de ir no corpo da requisição da Iugu, que a ecoava como campo da
> fatura. Sai na próxima versão maior.

## Tratamento de erros

Toda exceção do pacote herda de `MultiPaymentException`. Nenhuma exceção dos SDKs da Iugu ou da
Stripe sai do pacote: os drivers traduzem cada falha para uma das classes abaixo, anexam a
exceção original em `getPrevious()` (quando o SDK lançou uma; a Iugu devolve alguns erros como
corpo JSON sem exceção) e expõem o status HTTP da resposta em `httpStatus` (nulo quando não
houve resposta HTTP, como numa falha de rede ou numa validação local).

A árvore, com a indentação marcando a herança:

```
MultiPaymentException
    ConfigurationException              gateway não configurado, classe inválida, driver que declara capability sem o contract ou o método, IdempotencyStore sem cache
    ModelAttributeValidationException   atributo obrigatório ausente ou inválido, antes da requisição
    UnsupportedOperationException       operação fora das capabilities do gateway, ou fora da restrição de uma capability, antes da requisição
    RefundNotSupportedException         estorno recusado pela lib antes da requisição (limitação do gateway ou estado da fatura)
    AuthenticationException             credencial recusada (401, 403) ou não configurada
    GatewayNotAvailableException        5xx, falha de conexão ou timeout
    CardDeclinedException               cartão recusado, na cobrança ou ao salvar: declineCode, gatewayCode, retryable
        ChargingException               nome antigo, deprecado; é a classe que os drivers lançam
    GatewayException                    resposta de erro do gateway: httpStatus e getErrors()
        ValidationException             400 ou 422: fieldErrors por campo
        NotFoundException               404: recurso inexistente no gateway
        RateLimitException              429: retryAfter em segundos quando o gateway informa
        IdempotencyConflictException    409 na Iugu, idempotency_error na Stripe, lock da IdempotencyStore ocupado
```

| Exceção | Quando | O que fazer |
|---|---|---|
| `CardDeclinedException` | O gateway respondeu e a cobrança foi recusada pelo emissor, pelo adquirente ou pelo antifraude. `declineCode` (`DeclineCode`) é o motivo normalizado, `gatewayCode` o código original (`decline_code` da Stripe, LR da Iugu), `retryable` diz se vale repetir com o mesmo cartão. Também é a recusa ao salvar o cartão (`newCreditCard()->create()`, `confirmCreditCardSetup()`); quando vem do estado do setup lido (`last_setup_error`), `httpStatus` é nulo e o SetupIntent vai em `chargeResponse` | Ramificar por `declineCode`: outro gateway em `BRAND_NOT_SUPPORTED`, nova tentativa só se `retryable`, ação do pagador nos demais (ver [Códigos de recusa](#códigos-de-recusa)) |
| `ChargingException` | Nome antigo de `CardDeclinedException`, deprecado. É a classe que os drivers lançam, então `catch` por qualquer um dos dois nomes captura a mesma exceção | Migrar o `catch` para `CardDeclinedException` |
| `ValidationException` | O gateway recusou o payload (400 ou 422 na Iugu, `invalid_request_error` na Stripe); `fieldErrors` traz as mensagens por campo (`base` para erro sem campo) | Corrigir a chamada; repetir igual falha de novo |
| `NotFoundException` | Recurso inexistente no gateway (404 na Iugu, `resource_missing` na Stripe): id errado, de outra conta ou removido | Conferir o id; não repetir |
| `RateLimitException` | O gateway limitou a taxa de requisições (429); nada foi executado. `retryAfter` traz os segundos do cabeçalho `Retry-After` quando o gateway o envia; nulo quando não envia | Esperar e repetir |
| `IdempotencyConflictException` | Chave de idempotência reutilizada (409 na Iugu em cliente e assinatura; `idempotency_error` na Stripe quando o payload mudou), a primeira requisição com a chave ainda em andamento, ou lock ocupado na `IdempotencyStore` da lib. `resourceId` traz o id do recurso original quando o gateway o informa | Consultar o resultado da primeira requisição (`resourceId` ou o registro da aplicação) ou usar chave nova; nunca repetir com a mesma chave e outro conteúdo |
| `AuthenticationException` | Chave de API inválida, revogada, sem permissão (401 ou 403) ou não configurada | Registrar e alertar. Repetir a chamada ou trocar de gateway não resolve |
| `GatewayNotAvailableException` | Erro 5xx, falha de conexão ou timeout | Repetir mais tarde ou tentar outro gateway |
| `UnsupportedOperationException` | Operação fora das capabilities do gateway, ou fora da restrição de uma capability suportada (`restriction()`), antes de qualquer requisição; `capability`, `gateway` e `reason` (`not_implemented`, `gateway_limitation` ou `managed_by_gateway`) dizem qual e por quê | Rotear para um gateway que declare a capability; melhor ainda, consultar `supports()` e `restriction()` antes (ver [Capabilities](#capabilities)). Em `managed_by_gateway`, seguir a orientação da mensagem: a operação é conduzida pelo próprio gateway |
| `RefundNotSupportedException` | Estorno recusado pela lib antes de chamar o gateway: limitação do gateway (boleto, Pix parcial; `isCapabilityLimitation()` verdadeiro e `capability` preenchida) ou estado da fatura (já estornada, valor acima do restante, prazo vencido, quitada sem cobrança pelo gateway). Herda direto de `MultiPaymentException`: `catch (UnsupportedOperationException)` não a captura | Ver [Estorno](#estorno) |
| `ModelAttributeValidationException` | Atributo obrigatório ausente ou inválido, antes de qualquer requisição, inclusive regra de valor que só um gateway impõe (`PlanInterval::DAY` e teto de 599 meses na Iugu, `nextBillingAt` diferente de `trialEndsAt`, `page` e `limit` fora da faixa, plano com `id` em `save()`, valor de estorno zero ou negativo) | Corrigir a chamada |
| `ConfigurationException` | Gateway não configurado ou classe inválida; driver que declara uma capability sem implementar o contract ou sem o método do despacho por convenção; `IdempotencyStore` sem registro no container ou sobre um cache sem lock | Corrigir a configuração ou o driver |
| `GatewayException` | Qualquer outra resposta de erro do gateway, e a classe pai das quatro de resposta acima; `httpStatus` e `getErrors()` sempre preenchidos com a resposta. Nenhuma regra local da lib a lança | Depende do caso; `httpStatus` e `getErrors()` dizem o que aconteceu |

Um `catch` por camada. As subclasses vêm antes de `GatewayException`, senão ela captura tudo:

```php
use Potelo\MultiPayment\Enums\DeclineCode;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\RateLimitException;
use Potelo\MultiPayment\Exceptions\ValidationException;
use Potelo\MultiPayment\Exceptions\CardDeclinedException;
use Potelo\MultiPayment\Exceptions\AuthenticationException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;

try {
    $invoice = $payment->newInvoice()->/* ... */->create();
} catch (CardDeclinedException $e) {
    // o gateway respondeu; a recusa é do pagamento
    if ($e->declineCode === DeclineCode::BRAND_NOT_SUPPORTED) {
        return $this->chargeOn('iugu');                     // outro gateway aceita a bandeira
    }
    if ($e->retryable) {
        return $this->scheduleRetry($order, hours: 24);     // falha temporária ou saldo: mesmo cartão, mais tarde
    }
    return back()->withErrors('Pagamento recusado. Confira os dados do cartão ou use outro.');
} catch (ValidationException $e) {
    return back()->withErrors($e->fieldErrors);             // ['email' => ['não é válido']]
} catch (UnsupportedOperationException $e) {
    report($e);            // nada foi enviado; $e->capability e $e->gateway dizem o que faltou
    return $this->chargeOn('iugu');
} catch (AuthenticationException $e) {
    report($e);            // credencial errada: alerta, sem retry e sem fallback
    abort(500);
} catch (RateLimitException $e) {
    return $this->retryIn($e->retryAfter ?? 5);             // segundos; nulo quando o gateway não informa
} catch (GatewayNotAvailableException $e) {
    return $this->retryWithBackoff();                       // 5xx ou timeout: repetir mais tarde
} catch (GatewayException $e) {
    report($e);            // 404, 409 e o restante; $e->getPrevious() é a exceção do SDK, com stack trace e corpo
    throw $e;
}
```

### Códigos de recusa

`CardDeclinedException::$declineCode` é um `DeclineCode`, o mesmo vocabulário nos dois gateways.
O código original fica em `$gatewayCode`; código que a tabela do pacote ainda não conhece vira
`UNKNOWN`, com o original preservado e uma linha em nível `info` no log. `$retryable` segue o
código (`DeclineCode::isRetryable()`) e, na Stripe, é sobrescrito pelo `advice_code` quando ele
diz `try_again_later` ou `do_not_try_again`. `requiresPayerAction()` diz se a recusa pede ação
do pagador antes de qualquer nova tentativa.

| `DeclineCode` | Significado | `retryable` | Stripe (`decline_code` ou `code`) | Iugu (LR) |
|---|---|---|---|---|
| `INSUFFICIENT_FUNDS` | Saldo ou limite insuficiente | sim | `insufficient_funds`, `card_velocity_exceeded`, `withdrawal_count_limit_exceeded` | 51, 61, 65, 70, BL, DM, N4 |
| `EXPIRED_CARD` | Cartão vencido | não | `expired_card` | 54 |
| `INCORRECT_CVC` | CVC incorreto | não | `incorrect_cvc`, `invalid_cvc` | (a tabela da Iugu não tem código próprio) |
| `INCORRECT_NUMBER` | Número do cartão incorreto ou ausente | não | `incorrect_number`, `invalid_number` | 14, 25 |
| `INVALID_CARD` | Outros dados inválidos: validade, conta inexistente, cartão não desbloqueado | não | `invalid_expiry_month`, `invalid_expiry_year`, `invalid_account`, `new_account_information_available`, `incorrect_address`, `incorrect_zip` | 1, 12, 15, 30, 46, 56, 78, 101, 111, 115, 122, 6P, AV, BM, BP, BR, CF, CG, DF, DQ, G4, KA, KE, U3 |
| `LOST_OR_STOLEN` | Perdido, roubado, retido ou bloqueado pelo emissor; não exibir o motivo ao pagador | não | `lost_card`, `stolen_card`, `pickup_card`, `restricted_card` | 4, 41, 43, 62, 146, BN |
| `FRAUD_SUSPECTED` | Suspeita de fraude ou antifraude; tratar como recusa genérica diante do pagador | não | `fraudulent`, `merchant_blacklist` | 7, 59, AF01, AF02, BP171 |
| `AUTHENTICATION_REQUIRED` | O emissor exige autenticação (3DS); a cobrança fora de sessão não atende | não | `authentication_required`, `authentication_not_handled`, `mobile_device_authentication_required`, `setup_intent_authentication_failure`, `payment_intent_authentication_failure` | AI |
| `BRAND_NOT_SUPPORTED` | Bandeira, função (crédito ou débito) ou moeda não aceita nesta cobrança; candidato a outro gateway | não | `card_not_supported`, `currency_not_supported` | 39, 52, 53, 57, 79, 5C, AB, AC, AH, C1, DS, EK, G5 |
| `DO_NOT_HONOR` | O emissor recusou sem detalhar e orienta o pagador a procurá-lo | não | `do_not_honor`, `call_issuer`, `no_action_taken`, `not_permitted`, `security_violation`, `service_not_allowed`, `stop_payment_order`, `transaction_not_allowed`, `revocation_of_authorization`, `revocation_of_all_authorizations`, `do_not_try_again` | 5, 6, 60, 63, 67, 93, 99, 100, 109, 110, 116, 121, 181, 200, B1, B2, BP176, C2, C3, FC, FG, GA, GD, GF, GK, GT, N7, NR, R0, R1, R2, R3, RE, RP, SC |
| `TRY_AGAIN` | Falha temporária no emissor, no adquirente ou na comunicação | sim | `processing_error`, `issuer_not_available`, `reenter_transaction`, `try_again_later`, `approve_with_id` | 19, 28, 85, 89, 90, 91, 92, 96, 98, 911, 912, 999, 99A, 99B, 99C, 99TA, 99Z, AA, AF, AG, BD, BO, BP900, BP901, BP902 |
| `GENERIC` | Recusa sem motivo específico | não | `generic_decline`, `card_declined`, `duplicate_transaction`, `invalid_amount`, `testmode_decline` | 13, 64, 80, 94, 97, FE |
| `UNKNOWN` | Código fora da tabela; o original está em `gatewayCode` | não | qualquer outro | qualquer outro (senha, chip, saque, credenciamento do lojista) |

As tabelas completas vivem em `src/Gateways/Stripe/DeclineCodes.php` e
`src/Gateways/Iugu/DeclineCodes.php`, com a fonte oficial no cabeçalho de cada uma. Na Iugu, LR
numérico é comparado sem zeros à esquerda (`05` e `5` são o mesmo código); `gatewayCode` guarda
o valor como veio.

`CardDeclinedException::$reason` (string) continua preenchido e está deprecado: na Stripe traz a
normalização antiga (`card_declined`, `brand_not_supported`, `authentication_required`,
`expired_card`, `insufficient_funds`, `incorrect_cvc`, ou o `code` original); na Iugu, que antes
o deixava nulo, traz o valor de `declineCode`. Compare com `declineCode`.

### Motivo da recusa na leitura

A recusa síncrona chega como `CardDeclinedException`; a recusa que acontece longe da chamada
(a renovação de assinatura que falhou, avisada por webhook) aparece na **leitura da fatura**:
`Invoice::$lastPaymentError` é um `PaymentError` com o mesmo `DeclineCode` da exceção, o código
original em `gatewayCode`, a mensagem do gateway em `message`, o momento da tentativa em
`occurredAt` (quando o gateway o informa) e `retryable()`, que responde pela orientação do
gateway (`advice_code` da Stripe) ou, na falta dela, por `DeclineCode::isRetryable()`. Nulo
quando a fatura não tem tentativa recusada registrada.

```php
$invoice = MultiPayment::setGateway('stripe')->getInvoice($invoiceId);

if ($invoice->status->isPayable() && $invoice->lastPaymentError?->retryable()) {
    RetryCharge::dispatch($invoice)->delay(now()->addDay());
}

$invoice->lastPaymentError?->declineCode;    // DeclineCode::GENERIC
$invoice->lastPaymentError?->gatewayCode;    // 'generic_decline' (Stripe) ou o LR (Iugu)
```

No Stripe o campo vem de `last_payment_error` do PaymentIntent (na fatura de assinatura,
também de `last_finalization_error` do Invoice). Na Iugu o driver lê o código LR que a fatura
expuser (os campos `LR` e `lr` e o trecho `LR: xx` das mensagens, os formatos que a Iugu usa na
resposta de cobrança e no webhook); a Iugu não documenta o campo do LR na fatura, então na
Iugu o campo pode vir nulo mesmo depois de uma recusa, e só `declineCode` e `gatewayCode` são
preenchidos.

> **Mudança de comportamento (versão 5.0.0).** Até a 4.1.0, credencial inválida chegava como
> `GatewayNotAvailableException` (Stripe e chave Iugu não configurada) ou como `GatewayException`
> genérica (chave Iugu recusada com 401), e um cartão inválido no caminho de dados crus da Iugu
> podia deixar escapar uma `IuguRequestException` do SDK. Agora os três casos lançam
> `AuthenticationException` ou `GatewayException` do pacote. Quem repetia toda
> `GatewayNotAvailableException` deixa de repetir credencial errada; quem capturava
> `GatewayException` para chave recusada na Iugu precisa capturar `AuthenticationException`.
>
> Na mesma versão, operação não suportada ou ainda não implementada (fatura avulsa com Pix
> Automático no Stripe; desconto percentual e desativação
> de plano na Iugu; cartão com dados crus e duplicação fora de Pix pendente no Stripe) deixou de
> chegar como `GatewayException` (ou `GatewayException::methodNotFound`) e passou a lançar
> `UnsupportedOperationException`, que herda de `MultiPaymentException`. Um
> `catch (GatewayException $e)` sozinho deixa de capturar esses casos.
>
> Ainda na 5.0.0, `GatewayException` deixou de ser lançada por regra local, sem requisição: regra
> de valor (`page` e `limit` fora da faixa, plano com `id` em `save()`, `nextBillingAt` diferente
> de `trialEndsAt`, `PlanInterval::DAY` e teto de 599 meses na Iugu) virou
> `ModelAttributeValidationException`; restrição do gateway (cartão de outro cliente, rascunho
> não anulável, fatura sem cliente na duplicação no Stripe) virou
> `UnsupportedOperationException::restricted()`; driver que declara capability sem o contract ou
> sem o método de despacho virou `ConfigurationException`. Toda `GatewayException` que sobrou
> traz `httpStatus` da resposta. `RefundNotSupportedException` deixou de herdar de
> `UnsupportedOperationException` (ver [Estorno](#estorno)).
>
> Ainda na 5.0.0, validação (400, 422), 404, 409 e 429 passaram a chegar como
> `ValidationException`, `NotFoundException`, `IdempotencyConflictException` e
> `RateLimitException`. Todas herdam de `GatewayException`, então `catch (GatewayException $e)`
> continua capturando; um `catch` da subclasse precisa vir antes. A recusa de cartão passou a
> ser `CardDeclinedException` (lançada pelo nome antigo `ChargingException`, que herda dela),
> com a mensagem em português; salvar um cartão recusado pela Stripe (`newCreditCard()->create()`)
> também lança `CardDeclinedException`, onde antes vinha `GatewayException`.
>
> Também na 5.0.0, toda operação de escrita ganhou o último argumento `idempotencyKey` (e os
> builders, `withIdempotencyKey()`), inclusive nos contracts dos gateways; quem implementa
> `GatewayContract` fora do pacote precisa acrescentar o parâmetro. Na Iugu, `retryAfter` de
> `RateLimitException` passou a vir preenchido quando o cabeçalho `Retry-After` chega, e todas
> as chamadas ao gateway passaram a usar o requester injetável do driver (o fork
> `Potelo/iugu-php` 1.1.0), o que não muda a API pública.

## Webhooks

`parseWebhook()` verifica a autenticidade de uma entrega de webhook e a traduz num
`WebhookEvent` normalizado (`Potelo\MultiPayment\Models\WebhookEvent`), no vocabulário do
pacote, nos dois gateways. A operação é guardada pela capability `WEBHOOKS`. Sobre o parser há
duas camadas opcionais, descritas mais abaixo: a rota pronta do pacote (seção "Rota pronta"),
que verifica, deduplica e despacha os eventos do Laravel (seção "Eventos do Laravel") sem
nenhuma linha na aplicação. Quem prefere tratar tudo por conta própria registra a própria rota
e chama o parser:

```php
use Illuminate\Http\Request;
use Potelo\MultiPayment\Enums\WebhookEventType;
use Potelo\MultiPayment\Facades\MultiPayment;
use Potelo\MultiPayment\Webhooks\WebhookDeduplicator;

Route::post('/webhooks/{gateway}', function (Request $request, string $gateway) {
    $event = MultiPayment::setGateway($gateway)->parseWebhookRequest($request);

    if ((new WebhookDeduplicator())->flagReplay($event)->isReplay) {
        return response()->noContent();          // entrega repetida dentro do prazo de deduplicação
    }

    match ($event->type) {
        WebhookEventType::INVOICE_PAYMENT_FAILED => Dunning::start($event->invoice(), $event->declineCode),
        WebhookEventType::SUBSCRIPTION_CANCELED => Access::revoke($event->subscription()),
        default => null,
    };

    return response()->noContent();
});
```

Fora do Laravel (ou de um `Request`), `parseWebhook(string $rawBody, array $headers)` recebe o
corpo cru e os cabeçalhos direto. O corpo precisa chegar byte a byte como entregue: a
verificação de assinatura é sobre os bytes, então use `$request->getContent()` (ou
`file_get_contents('php://input')`), sem reserializar o JSON.

**Verificação de autenticidade.** Sempre ligada; entrega recusada lança
`WebhookSignatureException` com `reason` (`missing_header`, `invalid_signature`,
`timestamp_out_of_tolerance`, `missing_secret`) antes de qualquer parse. No Stripe a
verificação é o HMAC-SHA256 do cabeçalho `Stripe-Signature`, comparado em tempo constante, com
o secret do endpoint em `multi-payment.gateways.stripe.webhook_secret`
(`STRIPE_WEBHOOK_SECRET`, o `whsec_...` do dashboard ou do `stripe listen`) e tolerância de
timestamp em `multi-payment.gateways.stripe.webhook_tolerance` (300 segundos por padrão). Na
Iugu a autenticidade é um token compartilhado: o valor definido no campo `authorization` do
registro do webhook chega cru no cabeçalho HTTP `authorization` da entrega e é comparado em
tempo constante com `multi-payment.gateways.iugu.webhook_token` (`IUGU_WEBHOOK_TOKEN`). Essa
verificação é mais fraca que a do Stripe (um token compartilhado, sem timestamp nem vínculo
com o corpo), e a regra que a compensa é a hidratação obrigatória: o status vem sempre de
`invoice()`/`subscription()`, que releem o recurso na API autenticada.

**O `WebhookEvent`.** Campos: `id` (estável por entrega, para deduplicação), `type`
(`WebhookEventType`), `occurredAt`, `gateway`, `resourceType` e `resourceId` (o objeto do
gateway, no vocabulário dele), `invoiceId` e `subscriptionId` (ids aceitos por `getInvoice()` e
`getSubscription()`), `disputeId` (id da contestação no gateway), `declineCode` (num evento de
falha de pagamento) e `raw` (o payload original decodificado). Evento que o driver não mapeia vira `WebhookEventType::UNKNOWN`, sem
exceção, com tudo preservado em `raw`. Os helpers `concernsInvoice()` e
`concernsSubscription()` do enum dizem que hidratação faz sentido para cada tipo. No Stripe o
`id` é o id do evento (`evt_`) e `occurredAt` é o `created` dele; na Iugu o corpo não traz id
de entrega nem instante do evento, então o `id` é o UUID do cabeçalho `idempotency-key` da
entrega (sem ele, um id derivado de evento, id do recurso e status, que pode colidir entre duas
entregas legítimas iguais) e `occurredAt` é o momento do parse.

**Hidratação sob demanda.** O payload nunca é fonte de status: decisão de negócio usa
`$event->invoice()` e `$event->subscription()`, que releem o recurso no gateway na primeira
chamada (pelos mesmos `getInvoice()` e `getSubscription()`) e guardam o resultado no objeto.
`refund()` devolve o estorno mais recente da fatura relida, compartilhando a mesma leitura, e
`dispute()` devolve o id da contestação do payload (um model de contestação está planejado para
uma versão futura). Num evento de falha, `declineCode` vem do payload quando ele o traz e é
completado por `invoice()` a partir de `Invoice::$lastPaymentError`.

**Deduplicação.** Os gateways reenviam entregas, então o mesmo evento pode chegar mais de uma
vez. `WebhookDeduplicator::flagReplay($event)` registra o `id` na `IdempotencyStore` do pacote
(chave `webhook:{gateway}:{id}`, prazo de `multi-payment.webhooks.dedup_ttl`, 72 horas por
padrão) e marca `isReplay` quando ele já foi visto; descartar ou processar o replay é decisão
da aplicação. O parser sozinho não toca na store, para inspecionar um payload em teste ou num
replay manual sem queimar o id.

Mapeamento dos eventos do Stripe para o tipo comum:

| Evento do Stripe | `WebhookEventType` |
|---|---|
| `customer.subscription.created` | `SUBSCRIPTION_CREATED` |
| `customer.subscription.updated` | `SUBSCRIPTION_UPDATED`; com a assinatura `canceled` ou com `cancel_at_period_end`, `SUBSCRIPTION_CANCELED`; com `pause_collection` preenchido, `SUBSCRIPTION_SUSPENDED` |
| `customer.subscription.deleted` | `SUBSCRIPTION_CANCELED` |
| `invoice.created` | `INVOICE_CREATED` |
| `invoice.paid` | `INVOICE_PAID`; com `billing_reason` `subscription_cycle`, `SUBSCRIPTION_RENEWED` |
| `invoice.payment_failed` | `INVOICE_PAYMENT_FAILED` |
| `invoice.voided` | `INVOICE_CANCELED` |
| `charge.refunded` | `REFUND_CREATED` |
| `charge.dispute.created` | `DISPUTE_OPENED` |
| `charge.dispute.closed` | `DISPUTE_CLOSED` |
| `payment_method.updated`, `setup_intent.succeeded` | `PAYMENT_METHOD_UPDATED` |
| `mandate.updated` | `PIX_MANDATE_CHANGED` |
| qualquer outro | `UNKNOWN`, com o payload em `raw` |

Duas notas sobre o mapa:

- **Renovação chega como `SUBSCRIPTION_RENEWED`, sem um `INVOICE_PAID` separado.** O
  `invoice.paid` de um ciclo de renovação vira o tipo mais específico; quem contabiliza
  pagamentos de fatura deve tratar os dois tipos (os helpers ajudam: `SUBSCRIPTION_RENEWED`
  responde verdadeiro a `concernsInvoice()` e a fatura paga fica em `invoice()`).
- **A venda avulsa no Stripe emite eventos de PaymentIntent** (`payment_intent.succeeded`,
  `payment_intent.payment_failed`), que ficam fora da tabela comum e chegam como `UNKNOWN`; o
  evento ainda aponta a fatura (`invoiceId` com o id `pi_`), então `invoice()` hidrata e o
  status normalizado vem da releitura. Num `payment_intent.payment_failed`, `declineCode` vem
  preenchido do payload.

Mapeamento dos eventos da Iugu para o tipo comum:

| Evento da Iugu | `WebhookEventType` |
|---|---|
| `invoice.created` | `INVOICE_CREATED` |
| `invoice.status_changed` | resolvido pela fatura relida (ver a nota abaixo): `INVOICE_PAID` (paga, inclusive fora do gateway), `INVOICE_CANCELED` (cancelada ou expirada), `REFUND_CREATED` (estornada, total ou parcial), `DISPUTE_OPENED` (contestada), `DISPUTE_CLOSED` (chargeback) ou `INVOICE_UPDATED` (qualquer outro status) |
| `invoice.payment_failed`, `invoice.dunning_action` | `INVOICE_PAYMENT_FAILED`, com `declineCode` traduzido do `data[lr]` quando ele vem |
| `invoice.refund`, `invoice.partially_refunded` | `REFUND_CREATED` |
| `subscription.created` | `SUBSCRIPTION_CREATED` |
| `subscription.renewed` | `SUBSCRIPTION_RENEWED` |
| `subscription.changed`, `subscription.activated` | `SUBSCRIPTION_UPDATED` |
| `subscription.suspended` | `SUBSCRIPTION_SUSPENDED` |
| `subscription.expired` | `SUBSCRIPTION_CANCELED` (a assinatura expirada está encerrada) |
| `customer_payment_method.new` | `PAYMENT_METHOD_UPDATED` |
| `automatic_pix.authorization_changed` | `PIX_MANDATE_CHANGED` |
| qualquer outro (inclusive `invoice.due`) | `UNKNOWN`, com o payload em `raw`; evento `invoice.*` ainda aponta a fatura em `invoiceId` |

Três notas sobre o mapa da Iugu:

- **`invoice.status_changed` sempre custa um GET.** O evento é multiuso (pagamento,
  cancelamento, estorno e contestação chegam pelo mesmo nome) e o corpo traz só o status novo,
  sem garantia de ordem de entrega, então o driver relê a fatura durante o parse e resolve o
  tipo pelo status normalizado dela. A fatura relida fica disponível em `invoice()` sem nova
  requisição. Se a fatura não existir mais, o evento vira `UNKNOWN` com aviso no log.
- **O mesmo estorno chega por duas entregas.** A Iugu envia `invoice.refund` (ou
  `invoice.partially_refunded`) junto com o `invoice.status_changed` da mesma fatura, e os dois
  resolvem para `REFUND_CREATED`. São entregas distintas, com ids próprios, então a deduplicação
  não as junta; quem contabiliza estornos deve deduplicar pelo recurso (por exemplo, pelo
  acumulado de `refundedAmount` da fatura relida).
- **`invoice.due` fica como `UNKNOWN`** por decisão: é um lembrete de vencimento próximo, sem
  tipo comum correspondente nos dois gateways; o payload segue em `raw` e a fatura em
  `invoiceId` para quem quiser tratá-lo.

### Rota pronta

A rota do pacote recebe a entrega, verifica a autenticidade, descarta replay e despacha os
eventos do Laravel. Nasce desligada; para ligar, publique a configuração e habilite o bloco
`webhooks.route` (ou defina `MULTIPAYMENT_WEBHOOK_ROUTE_ENABLED=true`):

```php
// config/multi-payment.php
'webhooks' => [
    'route' => [
        'enabled' => env('MULTIPAYMENT_WEBHOOK_ROUTE_ENABLED', false),
        'path' => '/multipayment/webhooks/{gateway}',
        'middleware' => [],
    ],
],
```

O service provider registra a rota (`POST`, nome `multipayment.webhook`) quando `enabled` é
verdadeiro, e o parâmetro `{gateway}` escolhe o driver: no gateway, registre
`https://sua-aplicacao.com/multipayment/webhooks/iugu` e `.../stripe`. A rota fica fora de
qualquer grupo de middleware, porque webhook não tem sessão nem CSRF; o que a aplicação
precisar (limitação de taxa, por exemplo) entra em `middleware` na configuração.

As respostas da rota:

- **200 para entrega aceita**, inclusive as de tipo `UNKNOWN` (registradas no log com o
  recurso apontado; o `WebhookReceived` genérico ainda é despachado).
- **200 para replay**, sem despachar nada: para o gateway, qualquer resposta fora do 2xx é
  falha de entrega e provoca nova retentativa (a Iugu chega a desativar um endpoint que segue
  falhando; o Stripe retenta por dias), e a entrega original já foi processada.
- **400 para assinatura ou token recusado**, com o corpo vazio: o endpoint é público e a
  resposta não deve dizer a quem sonda o que faltou; o motivo (`reason` da
  `WebhookSignatureException`) fica no log da aplicação.
- **404 para gateway desconhecido ou sem a capability `WEBHOOKS`**, também sem detalhe.
- **500 para credencial de webhook não configurada** (`webhook_secret` ou `webhook_token`
  ausente na configuração do gateway): é erro da aplicação, e o 5xx mantém o gateway
  retentando até a configuração ser corrigida.

Quem quer a própria rota com o mesmo pipeline usa o helper `webhooks()` da fachada:

```php
use Illuminate\Http\Request;
use Potelo\MultiPayment\Facades\MultiPayment;

Route::post('/meus-webhooks/{gateway}', function (Request $request) {
    return MultiPayment::webhooks()->handle($request);
});
```

`handle()` lê o gateway do parâmetro de rota `gateway`; numa rota sem o parâmetro, vale o
gateway da instância da fachada (`MultiPayment::setGateway('stripe')->webhooks()->handle(...)`)
ou o default da configuração.

### Eventos do Laravel

O pipeline (da rota pronta ou de `webhooks()->handle()`) despacha dois eventos por entrega
aceita, os dois carregando o `WebhookEvent` na propriedade `$webhook`: primeiro o
`Potelo\MultiPayment\Events\WebhookReceived` genérico, depois a classe correspondente ao tipo
comum, uma por caso de `WebhookEventType` (`InvoicePaid`, `InvoicePaymentFailed`,
`SubscriptionRenewed`, `SubscriptionCanceled`, `RefundCreated`, `PixMandateChanged`, e assim
por diante, todas em `Potelo\MultiPayment\Events`). Entrega de tipo `UNKNOWN` despacha só o
genérico; replay descartado não despacha nada.

```php
use Illuminate\Support\Facades\Event;
use Potelo\MultiPayment\Events\InvoicePaymentFailed;
use Potelo\MultiPayment\Events\SubscriptionCanceled;

Event::listen(InvoicePaymentFailed::class, function (InvoicePaymentFailed $event) {
    Dunning::start($event->webhook->invoice(), $event->webhook->declineCode);
});

Event::listen(SubscriptionCanceled::class, function (SubscriptionCanceled $event) {
    Access::revoke($event->webhook->subscription());
});
```

O despacho é síncrono: o listener roda dentro da requisição do webhook, e a hidratação
(`invoice()`, `subscription()`) custa a leitura no gateway ali mesmo. Para responder rápido ao
gateway e trabalhar depois, implemente `ShouldQueue` no listener, como em qualquer evento do
Laravel; a hidratação passa a acontecer no worker da fila.

Falha num listener síncrono desfaz a marcação da deduplicação e a requisição responde 500,
então o gateway reenvia a entrega e ela é processada de novo por inteiro: o `WebhookReceived`
e o evento tipado são despachados outra vez, inclusive para os listeners que já tinham rodado
antes da falha. Escreva listeners que toleram reprocessamento, ou enfileire-os (a falha passa
a seguir a política de retry da fila, e a entrega responde 200 na hora).

### Reproduzindo entregas em desenvolvimento

O comando `multipayment:webhook-replay` reproduz uma entrega gravada em arquivo pelo mesmo
pipeline da rota, para exercitar os listeners sem depender do gateway:

```
php artisan multipayment:webhook-replay stripe tests/fixtures/stripe/webhooks/invoice.paid.json
php artisan multipayment:webhook-replay iugu tests/fixtures/iugu/webhooks/invoice.created.json
```

O arquivo pode ser o corpo cru da entrega (um evento do Stripe, por exemplo) ou um envelope
JSON `{headers, body}` com o corpo urlencoded, o formato das capturas da Iugu do pacote. O
comando reautentica a entrega com a credencial configurada (assinatura nova com
`webhook_secret`, ou o token no cabeçalho `authorization` com `webhook_token`), porque a
assinatura gravada não vale para o secret da aplicação nem para o relógio atual, e roda o
pipeline sem consultar nem gravar a deduplicação, para o mesmo arquivo poder ser reproduzido
quantas vezes for preciso. Atenção: entrega cujo parse hidrata (o `invoice.status_changed` da
Iugu) faz a leitura real no gateway configurado.

Para receber entregas reais em desenvolvimento: no Stripe, a CLI encaminha os eventos da conta
para a aplicação local (`stripe listen --forward-to localhost:8000/multipayment/webhooks/stripe`)
e imprime o secret `whsec_...` da sessão, que vai em `STRIPE_WEBHOOK_SECRET`. A Iugu não tem
CLI de encaminhamento: exponha a aplicação por um túnel público (ngrok, Cloudflare Tunnel) e
registre o webhook na sandbox (`POST /v1/web_hooks`) apontando para
`https://<túnel>/multipayment/webhooks/iugu`, com o campo `authorization` do registro igual ao
`IUGU_WEBHOOK_TOKEN` configurado.

## Utilizando

### MultiPayment:
Usando a classe `MultiPayment`:
```php
$payment = new \Potelo\MultiPayment\MultiPayment(); // gateway default será usado
// ou
$payment = new \Potelo\MultiPayment\MultiPayment('iugu');
// ou  
$payment = new \Potelo\MultiPayment\MultiPayment();
$payment->setGateway('iugu');
```
#### Criar e cobrar uma fatura (InvoiceBuilder)

O builder é o caminho principal: método de pagamento por enum, datas por `Carbon` e validação
antes de qualquer requisição. O mesmo código cobra o cartão nos dois gateways:

```php
use Potelo\MultiPayment\Enums\PaymentMethod;

$payment = new \Potelo\MultiPayment\MultiPayment('iugu');   // ou 'stripe'

$invoice = $payment->newInvoice()
    ->setPaymentMethod(PaymentMethod::CREDIT_CARD)
    ->addCustomer('Nome do cliente', 'email@example.com', '20176996915', null, '71', '999999999')
    ->addItem('Produto 1', 10000, 1)
    ->addItem('Produto 2', 5000, 2)
    ->addCreditCardId($card->id)                 // cartão salvo; ou addCreditCardToken('pm_...')
    ->withIdempotencyKey($order->uuid)
    ->create();

$invoice->status;          // InvoiceStatus::PAID (ou CardDeclinedException)
$invoice->paymentMethod;   // PaymentMethod::CREDIT_CARD
```

Pix e boleto abrem a fatura e devolvem o QR Code ou a linha digitável; as duas datas da fatura
têm um sentido só em todos os gateways (ver [Datas da fatura](#datas-da-fatura)):

```php
$pix = $payment->newInvoice()
    ->setPaymentMethod(PaymentMethod::PIX)
    ->addCustomer('Nome do cliente', 'email@example.com', '20176996915')
    ->addItem('Mensalidade', 10000, 1)
    ->setPixExpiresAt(now()->addHours(4))          // expiração do QR Code
    ->create();
$pix->pix->qrCodeText;

$boleto = $payment->newInvoice()
    ->setPaymentMethod(PaymentMethod::BANK_SLIP)
    ->addCustomer('Nome do cliente', 'email@example.com', '20176996915')
    ->addCustomerAddress('41820330', 'Rua', '123', null, 'Bairro', 'Salvador', 'BA')
    ->addItem('Mensalidade', 10000, 1)
    ->setDueDate(today()->addDays(3))              // vencimento
    ->create();
$boleto->bankSlip->number;                         // linha digitável nos dois gateways
```

No Stripe o boleto exige CPF/CNPJ e endereço do cliente, aceita valores de R$ 5,00 a
R$ 49.999,99 e vencimento de hoje a 60 dias, tudo validado antes da requisição (ver
[Particularidades do Stripe](#particularidades-do-stripe)).

`setPaymentMethod()` decide como a fatura é criada quando `availablePaymentMethods` fica vazia;
`setAvailablePaymentMethods([...])` (ou `addAvailablePaymentMethod()`) abre a fatura a mais de
um método na Iugu e tem precedência sobre `setPaymentMethod()`, que então precisa constar da
lista. Cartão informado sem método algum também cobra o cartão; cartão junto de um método ou de
uma lista sem `credit_card` é recusado com `ModelAttributeValidationException`, em vez de ser
ignorado. `amount` junto de `items` só é aceito quando é a soma deles.
Confira `src/Builders/InvoiceBuilder.php` para saber quais métodos estão disponíveis; a
alternativa por array está em [charge](#charge-alternativa-por-array).

> **Mudança de comportamento (versão 5.0.0).** Até a 4.1.0 a escrita ignorava
> `Invoice::$paymentMethod` (`payment_method` no array): na Iugu, uma fatura com
> `payment_method` `credit_card` e `credit_card` preenchido, sem `available_payment_methods`,
> nascia pendente aberta a todos os métodos da conta e o cartão não era cobrado, enquanto no
> Stripe o mesmo array cobrava o cartão. Agora `paymentMethod` (ou só o cartão) vale nos dois
> gateways, e o mesmo array produz o mesmo resultado financeiro. Quem dependia da fatura
> pendente deve deixar de informar o cartão: `credit_card` junto de uma lista (ou de um
> `payment_method`) sem cartão, que antes era ignorado, passou a lançar
> `ModelAttributeValidationException`.

#### Datas da fatura

| Propriedade | Array / builder | Iugu | Stripe |
|---|---|---|---|
| `dueDate` | `due_date` / `setDueDate()` | `due_date` (o dia; a fatura vencida continua pagável) | `due_date` da fatura de assinatura; no boleto avulso vira `expires_after_days` (de hoje a 60 dias) e a leitura devolve o instante em que o voucher vence; na venda avulsa por Pix sem `pixExpiresAt`, o fim desse dia vira a expiração do QR Code |
| `pixExpiresAt` | `pix_expires_at` / `setPixExpiresAt()` | `pix_qr_code_expires_at` (ISO 8601); sem `dueDate`, o vencimento é o dia em que o QR Code expira; a leitura só o preenche quando a fatura o devolve | `payment_method_options.pix.expires_at`, entre 10 segundos e 14 dias no futuro (sem ele, a Stripe usa 4 horas); volta na leitura |

As duas aceitam `Carbon` (ou `CarbonImmutable`) e, no array, string em `Y-m-d` ou ISO 8601 com
hora. `expiresAt` (`expires_at`, `setExpiresAt()`) continua funcionando como alias de `dueDate`,
com aviso `E_USER_DEPRECATED`, e sai na próxima versão maior: até a 4.1.0 era o vencimento na
Iugu e a expiração do QR Code no Stripe, então `'expires_at' => hoje` valia na Iugu e falhava no
Stripe. `toArray()` passa a emitir `due_date` e `pix_expires_at`.

#### Pix Automático

O Pix Automático está disponível nos dois gateways, com desenhos opostos (a responsabilidade
pela agenda está em
[Pix Automático: quem agenda a cobrança](#pix-automático-quem-agenda-a-cobrança)):

| | Iugu | Stripe |
|---|---|---|
| Onde a recorrência nasce | Na fatura: `Invoice` com método `pix` e `automaticPix` preenchido | Na assinatura: `Subscription` com `paymentMethod` `automatic_pix` (mandato) |
| Quem agenda cada cobrança | A aplicação, pelas operações de `AutomaticPixContract` | O gateway (`MANAGES_RECURRENCE`), com notificação de pré-débito três dias antes |
| Reagendar e cancelar cobrança | `rescheduleAutomaticPixPayment()`, `cancelAutomaticPixScheduledPayment()` | `UnsupportedOperationException` com `reason` `managed_by_gateway` |
| Encerrar a recorrência | `cancelAutomaticPixRecurrence()` | Cancelar a assinatura (`cancelSubscription()`); a Stripe encerra o mandato |
| Consultar cancelamentos | `getAutomaticPixCancellation()`, `listAutomaticPixCancellations()` | As mesmas operações, lendo o Mandate (`mandate_...`): mandato `inactive` devolve um cancelamento `completed` |
| Estado no model | `Invoice::$automaticPix` | `Subscription::$automaticPix`, com `nextDebitAt` e `preDebitNotificationAt` |

No Stripe, a assinatura com `paymentMethod` `automatic_pix` registra o mandato na criação
(`payment_method_options.pix.mandate_options`) e nasce com a primeira fatura em aberto: o
pagador autoriza o mandato ao pagar essa fatura (a página hospedada vem em
`latestInvoice->url`) e a Stripe cobra os ciclos seguintes sozinha. A lib deriva o mandato do
plano: o valor é a soma do plano com os itens recorrentes (com desconto na assinatura ele vira
um teto, `amount_type` `maximum`), a agenda vem do intervalo do plano (semanal, mensal,
trimestral, semestral ou anual; outro intervalo é recusado antes da requisição) e o primeiro
débito (`start_date`) do fim do trial ou de `nextBillingAt`, com o mínimo de três dias a
partir de hoje. O plano precisa de valor fixo (Price com `unit_amount`) e a assinatura com
mandato não troca de método depois de criada (cancele e crie outra).
`Subscription::$automaticPix` refina o mandato na escrita (`startsAt`, `endsAt`, `frequency`;
no builder, `setAutomaticPix()`) e volta preenchido na leitura, com as datas derivadas
`nextDebitAt` (o débito acontece três dias depois do início do ciclo) e
`preDebitNotificationAt`. O nome
exibido no aplicativo do banco vem da configuração
`multi-payment.gateways.stripe.pix_mandate_reference` (`STRIPE_PIX_MANDATE_REFERENCE`).

```php
$subscription = (new \Potelo\MultiPayment\MultiPayment('stripe'))
    ->newSubscription()
    ->setPlanId('plano_mensal')
    ->setCustomer($customer)
    ->setPaymentMethod(\Potelo\MultiPayment\Enums\PaymentMethod::AUTOMATIC_PIX)
    ->create();

$subscription->latestInvoice->url;                       // página onde o pagador autoriza o mandato
$subscription->automaticPix->startsAt;                   // primeiro débito (mínimo hoje mais 3 dias)
$subscription->automaticPix->nextDebitAt;                // próximo débito (ciclo mais 3 dias)
$subscription->automaticPix->preDebitNotificationAt;     // quando o pagador é notificado
```

O id e o status do mandato (`AutomaticPix::$mandateId`, `$mandateStatus`) não vêm na leitura
da assinatura: chegam pelo webhook `mandate.updated` da Stripe ou preenchidos pela consulta de
cancelamentos (`listAutomaticPixCancellations($mandateId)`). A fatura avulsa com
`automaticPix` no Stripe é recusada antes de qualquer requisição
(`UnsupportedOperationException`, `AUTOMATIC_PIX`, `not_implemented`): nesse gateway a
recorrência vive na assinatura. A conta Stripe da empresa ainda aguarda a liberação do
recurso; até lá, a criação real responde com a recusa do próprio gateway.

Um `start_date` derivado do relógio muda entre tentativas com a mesma chave de idempotência
(a Stripe compara o payload); num retry com chave, informe `startsAt` em `automaticPix`, como
já vale para as outras datas derivadas do instante da chamada.

Na Iugu, ele é configurado como parte da fatura:

```php
use Potelo\MultiPayment\Models\AutomaticPix;

$invoice = (new \Potelo\MultiPayment\MultiPayment('iugu'))
    ->newInvoice()
    ->addAvailablePaymentMethod('pix')
    ->addCustomer('Nome', 'email@example.com', '01234567891')
    ->addItem('Mensalidade', 10000, 1)
    ->addAutomaticPix(
        AutomaticPix::AUTHORIZATION_TYPE_QR_CODE_WITH_PAYMENT,
        AutomaticPix::FREQUENCY_MONTHLY,
        '2026-08-01',
        'contrato-123',
        '2027-08-01',
        AutomaticPix::RETRY_POLICY_ALLOWED,
    )
    ->addAutomaticPixCharge('Mensalidade do plano')
    ->create();
```

As demais operações também utilizam os modelos do MultiPayment, enquanto os nomes específicos da Iugu são tratados internamente pelo gateway:

```php
$multiPayment = new \Potelo\MultiPayment\MultiPayment('iugu');

$multiPayment->rescheduleAutomaticPixPayment($invoiceId);
$multiPayment->cancelAutomaticPixRecurrence($recurrenceId);
$multiPayment->cancelAutomaticPixScheduledPayment($invoice->automaticPixCharge);
$multiPayment->getAutomaticPixCancellation($recurrenceId, $cancellationId);
$multiPayment->listAutomaticPixCancellations($recurrenceId, page: 1, limit: 100);
```

#### Pix Automático: quem agenda a cobrança

Os dois gateways dividem a responsabilidade pela recorrência de forma oposta, e
`supports(Capability::MANAGES_RECURRENCE)` diz de que lado cada um fica: falso na Iugu (a
aplicação agenda), verdadeiro no Stripe (o gateway agenda). A regra é esta:

- **Na Iugu, a aplicação é o motor de recorrência.** A API cria a recorrência junto com a
  fatura e devolve o identificador, mas não controla a periodicidade das cobranças. É a
  aplicação que decide quando cobrar e usa os métodos de `AutomaticPixContract` para isso:
  `rescheduleAutomaticPixPayment()` para solicitar a retentativa de um agendamento,
  `cancelAutomaticPixScheduledPayment()` para cancelar uma cobrança agendada e
  `cancelAutomaticPixRecurrence()` para encerrar a recorrência. Sem esse motor na aplicação,
  nenhuma cobrança recorrente acontece.
- **No Stripe, o gateway agenda.** O mandato vive na Subscription e a Stripe controla o
  calendário: envia ao pagador a notificação de pré-débito obrigatória três dias antes de cada
  débito, cobra e faz as retentativas automáticas. A aplicação não agenda nada; a data de
  início do mandato precisa respeitar esse prazo de três dias, e as operações de agendamento
  da lib respondem `UnsupportedOperationException` com `reason` `managed_by_gateway`.

Por isso a assinatura com `paymentMethod` `automatic_pix` exige `MANAGES_RECURRENCE` além de
`AUTOMATIC_PIX`: na Iugu ela é recusada antes de qualquer requisição (a recorrência nasce na
fatura), e no Stripe a fatura avulsa com `automaticPix` é recusada (a recorrência nasce na
assinatura). As duas restrições são consultáveis em
`restriction(Capability::AUTOMATIC_PIX, $gateway)`.

**Migrar uma recorrência de um gateway para o outro exige desligar o motor da aplicação para
aquela recorrência** quando o destino é o Stripe. Se o motor continuar ativo, a aplicação e o
gateway cobram o mesmo ciclo e o cliente é debitado duas vezes. No sentido inverso, do Stripe
para a Iugu, o motor precisa ser ligado, senão a recorrência para de cobrar.

##### Testes com as sandboxes dos gateways

A suíte `Integration` reúne todos os testes que acessam as sandboxes reais (Iugu
e Stripe). Cada teste cria durante a execução os clientes, faturas e cartões de
que precisa; não há dependência de IDs ou outros dados previamente existentes no
gateway.

```bash
IUGU_ID=seu_account_id \
IUGU_APIKEY=seu_api_token \
STRIPE_APIKEY=sua_chave_sk_test \
./vendor/bin/phpunit -c phpunit.xml.dist --testsuite Integration
```

Atualmente, a sandbox da Iugu responde que Pix Automático não está disponível no modo de
teste. Os cenários que dependem desse recurso estão identificados com o grupo
`iugu-sandbox-limitation` e usam um `skip` explícito com a razão da limitação. Os
testes permanecem junto das classes responsáveis pelo builder e pela facade para
que possam ser reativados quando o ambiente passar a suportar o fluxo.

#### Assinaturas e planos

Assinatura recorrente e plano estão disponíveis nos dois gateways. Na Iugu o plano é o
recurso de plano nativo; no Stripe ele vira um par Product e Price recorrente (o id do plano
é o id do Price, com prefixo `price_`), e a assinatura é a Subscription do Stripe Billing.

O desconto (`SubscriptionDiscount`) funciona nos dois gateways. No Stripe cada desconto vira
um Coupon criado na hora e aplicado à assinatura: `cycles` 1 é `duration` `once`, `cycles`
acima de 1 e `validUntil` viram `repeating` com `duration_in_months` (o cupom da Stripe dura
meses inteiros: `cycles` acima de 1 exige plano mensal ou anual, e `validUntil` vira meses
contados da aplicação, arredondados para cima), e desconto sem prazo é `forever`; na leitura
o desconto volta com o id do Coupon e, no `repeating`, com o fim em `validUntil`. Na Iugu o
desconto é um subitem recorrente de valor negativo, e a validade (`validUntil`, ou a
calculada de `cycles` pelo intervalo do plano) é emulada pela lib (ver
[Emulações na Iugu](#emulações-na-iugu)). Desconto percentual (`percentOff`) só existe no
Stripe (`PERCENT_DISCOUNT`); a Iugu o recusa antes de qualquer requisição.

```php
use Potelo\MultiPayment\Models\Plan;
use Potelo\MultiPayment\Enums\PlanInterval;

$plan = new Plan();
$plan->name = 'Mensal';
$plan->identifier = 'plano_mensal';
$plan->amount = 10000; // centavos
$plan->interval = PlanInterval::MONTH; // DAY, WEEK, MONTH ou YEAR (a Iugu recusa DAY)
$plan->intervalCount = 1;
$plan->save('iugu');                   // ou save('stripe'): cria o Product e o Price

$subscription = (new \Potelo\MultiPayment\MultiPayment('iugu'))
    ->newSubscription()
    ->setPlanId('plano_mensal')
    ->setCustomerId($customer->id)
    ->setCreditCard($card->id)                     // cartão salvo (ou um CreditCard com token); implica o método cartão
    ->setTrialDays(7)                              // a primeira cobrança acontece no fim do teste
    ->addItem('Consultas extras', 2500, 2)         // item recorrente, valor em centavos
    ->addAmountDiscount('Promo', 500, cycles: 1)   // desconto só na próxima fatura
    ->withIdempotencyKey("sub-{$order->uuid}")
    ->create();

$subscription->status;          // SubscriptionStatus; na Iugu: TRIALING, ACTIVE, SUSPENDED, PENDING, PAST_DUE, CANCELED ou EXPIRED
$subscription->paymentMethod;   // PaymentMethod::CREDIT_CARD, lido do gateway
$subscription->trialEndsAt;     // calculado pela lib na criação

$porPix = (new \Potelo\MultiPayment\MultiPayment('iugu'))
    ->newSubscription()
    ->setPlanId('plano_mensal')
    ->setCustomerId($customer->id)
    ->setPaymentMethod(PaymentMethod::PIX)         // ou setAvailablePaymentMethods(['pix', 'bank_slip']), que tem precedência
    ->setNextBillingAt('2026-10-01')
    ->create();
```

Meio de pagamento e trial são conceitos da assinatura:

- **`setPaymentMethod()`** define com que método a assinatura é cobrada; com
  `availablePaymentMethods` vazia, o driver deriva a lista dele, e com ela preenchida o método
  precisa constar da lista. **`setCreditCard()`** aceita o id de um cartão salvo ou um
  `CreditCard` (token, ou dados crus na Iugu), implica o método cartão, e um cartão sem id é
  salvo no cliente ao criar a assinatura; cartão com uma lista sem `credit_card` é recusado com
  `ModelAttributeValidationException`. Na leitura, `paymentMethod` volta preenchido quando a
  assinatura aceita um único método, e a lista vem preenchida: para trocar o método de uma
  assinatura lida, troque `availablePaymentMethods` (ou a zere) antes de `save()`.
- **`setTrialDays()`** conta o período de teste a partir do momento em que a assinatura é criada:
  o driver calcula `trialEndsAt` na hora da requisição, o deixa no model e zera `trialDays`
  (o model devolvido pode ser salvo de novo). Prefira
  `setTrialDays()` a `setTrialEndsAt(now()->addDays(7))`: a segunda fixa a data no instante em
  que o model foi montado, e um model guardado para retry carrega uma data velha. Os dois não
  podem ser informados juntos.

Operações sobre a assinatura:

```php
use Potelo\MultiPayment\Enums\ProrationBehavior;

$subscription->suspend();
$subscription->resume();                        // desfaz a suspensão e o cancelamento agendado
$subscription->cancel();                        // CANCELED; na Iugu, suspende e grava a marca de cancelamento
$subscription->cancel(atPeriodEnd: true);       // segue ativa até o fim do período pago, com cancelAtPeriodEnd
$subscription->changePlan('plano_anual');       // ProrationBehavior::CHARGE_DIFFERENCE: cobra o plano novo agora
$subscription->changePlan('plano_anual', ProrationBehavior::NONE);   // nada é cobrado agora
$preview = $subscription->previewPlanChange('plano_anual');          // simula sem aplicar (ver "Troca de plano")

// itens e descontos são declarativos: a lista informada vira o estado da assinatura, e a lista
// que ficar em null é preservada como está no gateway
$mantido = new SubscriptionItem();
$mantido->id = $subscription->items[0]->id;
$subscription->items = [$mantido];
$subscription->save();

$payment = new \Potelo\MultiPayment\MultiPayment('iugu');
$subscription = $payment->getSubscription($subscriptionId);   // mesma forma de getInvoice()
$plan = $payment->getPlan('plano_mensal');                   // identificador ou id do gateway
$assinaturas = $payment->listSubscriptions($customer->id);
$planos = $payment->listPlans();
```

##### Troca de plano

`changePlan()` recebe a política de pró-rata como `Potelo\MultiPayment\Enums\ProrationBehavior`,
e cada gateway a traduz para o próprio parâmetro:

| `ProrationBehavior` | O que acontece | Iugu | Stripe |
|---|---|---|---|
| `CHARGE_DIFFERENCE` (padrão) | O plano novo é cobrado agora; o gateway decide o que abater do período já pago | `POST change_plan`: fatura emitida na hora, sem crédito do período anterior (a Iugu acrescenta ciclos no downgrade) | `proration_behavior: always_invoice`: as linhas de pró-rata (crédito do período não usado e cobrança do plano novo) são faturadas e cobradas na hora, e a fatura volta em `latestInvoice` |
| `NONE` | Nada é cobrado nem creditado agora; o plano novo vale a partir da próxima cobrança do ciclo | `PUT` com `skip_charge`, mantendo a data de cobrança (`nextBillingAt` preenchido vai junto) | `proration_behavior: none`; `nextBillingAt` diferente do lido é recusado (a Stripe não aceita mudar a data da próxima cobrança na troca) |
| `CREDIT` | O gateway calcula o crédito do período não usado e o aplica na próxima fatura | `UnsupportedOperationException` (`PLAN_CHANGE_PRORATION`, `gateway_limitation`), antes de qualquer requisição | `proration_behavior: create_prorations`: crédito e cobrança proporcionais entram na próxima fatura |

Como a política que o gateway não oferece é recusada antes da rede, consulte
`supports(Capability::PLAN_CHANGE_PRORATION)` antes de oferecer a opção de crédito no
checkout.

```php
$subscription->changePlan('plano_anual', ProrationBehavior::CHARGE_DIFFERENCE, idempotencyKey: "upgrade-{$order->uuid}");
$subscription->changePlan('basico', ProrationBehavior::NONE);
$subscription->changePlan('plano_anual', ProrationBehavior::CREDIT);   // Iugu: UnsupportedOperationException
```

`previewPlanChange()` simula a troca sem aplicá-la, com a mesma política de pró-rata de
`changePlan()` (`CHARGE_DIFFERENCE` por padrão), e devolve um `SubscriptionPlanChange`. No
Stripe a política vai na prévia (`proration_behavior`), então a simulação de `NONE` mostra a
troca sem linhas de pró-rata; na Iugu, que só tem um fluxo de simulação, `NONE` devolve a mesma
prévia de `CHARGE_DIFFERENCE`, e `CREDIT` é recusado antes da rede, como em `changePlan()`. A
fachada tem o mesmo caminho por id: `MultiPayment::previewSubscriptionPlanChange($id, $planId,
$proration)`.

- **`amount`**: o que a troca cobraria agora, em centavos; quando há linhas, é a soma de `items`.
- **`items`**: as linhas da fatura que a troca geraria, como `InvoiceItem` (crédito com `price`
  negativo). A lista nunca é nula. No Stripe as linhas vêm reais, da prévia de fatura
  (`invoices.create_preview`) com a política informada. A
  Iugu não devolve linhas em `change_plan_simulation`, então
  a lib monta uma linha de cobrança do plano novo (`Plano <novo>`, valendo `cost` mais
  `discount`) e, quando `discount` é maior que zero, uma linha negativa de crédito do plano
  antigo. O payload cru (o Invoice da prévia no Stripe; `cost`, `discount`, `cycles`,
  `expires_at`, `old_plan` e `new_plan` na Iugu) segue em `original`.
- **`effectiveAt`**: a data em que a próxima cobrança acontece após a troca. No Stripe é o fim
  de período da linha mais distante da prévia.
- **`appliesImmediately`**: se o plano novo passa a valer assim que a troca for aplicada. No
  Stripe é sempre verdadeiro (a Stripe aplica o plano novo na hora, independente do
  pagamento). Na
  Iugu é verdadeiro quando a assinatura é paga só com cartão (o cartão padrão é cobrado na
  hora) e falso quando ela aceita boleto ou Pix, porque a Iugu só efetiva a troca depois do
  pagamento da fatura gerada; uma assinatura aberta a mais de um método também lê como falso.
  Quando o model não traz os métodos de pagamento (só o id), o driver lê a assinatura antes
  da simulação.

```php
$preview = $subscription->previewPlanChange('plano_anual');
foreach ($preview->items as $line) {              // nunca null
    echo "{$line->description}: {$line->price}";
}
$preview->amount;                                 // 30000
$preview->effectiveAt;                            // Carbon: próxima cobrança depois da troca
$preview->appliesImmediately;                     // false numa assinatura paga por Pix na Iugu

$subscription->previewPlanChange('plano_anual', ProrationBehavior::CREDIT);   // Iugu: UnsupportedOperationException
```

> **Obsoleto (desde 2026-09-02).** O booleano `$charge` de `changePlan()` continua aceito, na
> mesma posição ou pelo nome (`charge: false`), e é traduzido (`true` para
> `CHARGE_DIFFERENCE`, `false` para `NONE`) com aviso `E_USER_DEPRECATED`; `charge` informado
> prevalece sobre a política. Nos drivers, `changeSubscriptionPlan()` aceita o booleano das
> mesmas duas formas. A remoção fica para uma versão futura.

Particularidades da Iugu:

- **Cancelar é suspender com uma marca.** A Iugu só suspende, então `cancel()` faz duas
  requisições: `POST /suspend` e um `PUT` que grava `mp_canceled_at` (data e hora, ISO 8601)
  em `custom_variables`. A assinatura lê como `CANCELED` enquanto estiver suspensa com a marca,
  e `canceledAt` é preenchido a partir dela. `resume()` de
  uma assinatura cancelada reativa e remove a marca (`PUT` com `_destroy`), voltando a
  `ACTIVE`. Chamar `cancel()` de novo numa assinatura já cancelada só repete a suspensão e
  mantém a data original. Se a segunda requisição de `cancel()` ou de `resume()` falhar, a
  exceção sobe com a assinatura no estado intermediário (suspensa sem marca, ou ativa com a
  marca); repita a chamada, de preferência com a mesma chave de idempotência, que a Iugu aceita
  `suspend` e `activate` repetidos. Uma marca `mp_canceled_at` que não seja uma data lê
  como ausente, com aviso no log. `cancel(atPeriodEnd: true)` é emulado pela lib e depende do
  comando de sincronização agendado (ver [Emulações na Iugu](#emulações-na-iugu)).
- **O prefixo `mp_` em `custom_variables` é reservado à lib** para o estado das emulações:
  `metadata` com uma chave assim é recusado com `ModelAttributeValidationException`, e na
  leitura as variáveis `mp_` não aparecem em `metadata` (viram os campos tipados:
  `canceledAt`, `cancelAtPeriodEnd`, `validUntil` do desconto).
- **Desconto é sempre valor fixo.** `percentOff` lança `UnsupportedOperationException`
  (`PERCENT_DISCOUNT`, `gateway_limitation`); `cycles` aceita `1` (uma fatura, subitem sem
  recorrência) ou `null` (até ser removido), e `cycles` maior que `1` ou `validUntil` são
  emulados pela lib (ver [Emulações na Iugu](#emulações-na-iugu)).
- **Plano anual é 12 meses, e plano diário não existe.** A Iugu só tem intervalos em semanas e
  meses, então `PlanInterval::YEAR` é enviado como `12 * intervalCount` meses e
  `PlanInterval::DAY` lança `ModelAttributeValidationException` antes de chamar a API. Na leitura vale a
  heurística inversa: todo plano em meses cujo intervalo é múltiplo de 12 volta como `YEAR` com
  `intervalCount` dividido por 12 (um plano criado direto na Iugu com 24 meses lê como 2 anos).
  Quem precisar do valor cru lê `original`. A Iugu aceita intervalo de 1 a 599, então um plano
  anual vai até `intervalCount` 49; acima disso o driver lança `ModelAttributeValidationException`
  antes de chamar a API.
- **Planos não são desativáveis.** `deactivatePlan` lança `UnsupportedOperationException`
  (`PLAN_DEACTIVATION`, `gateway_limitation`).
- **`nextBillingAt` e `trialEndsAt` são o mesmo campo** (`expires_at`), e o que os distingue é
  a cobrança do primeiro ciclo: por padrão a Iugu fatura o primeiro ciclo na criação e cobra o
  cartão padrão na hora, mesmo com `expires_at` no futuro (observado na sandbox), então um
  trial (`setTrialDays()` ou `setTrialEndsAt()`) vai com `only_charge_on_due_date`, e a
  assinatura nasce sem fatura (`latestInvoice` nulo) e sem cobrança até o fim do teste;
  `setNextBillingAt()` sozinho vai só como `expires_at`, com a fatura e a cobrança imediatas da
  Iugu (`gateway_options['only_charge_on_due_date']` sobrepõe os dois). Informar `trialEndsAt`
  (ou `trialDays`) e `nextBillingAt` com datas diferentes lança `ModelAttributeValidationException`. Ao prorrogar
  um trial lido do gateway, zere `nextBillingAt` antes, porque a leitura preenche os dois.
  `in_trial` (lido como `TRIALING`) só aparece em assinatura que a própria Iugu põe em teste; a
  assinatura criada com trial pela lib lê como `ACTIVE`, com `nextBillingAt` no fim do teste.
- **O cartão da assinatura é o cartão padrão do cliente.** A Iugu não guarda cartão por
  assinatura, então `setCreditCard()` torna o cartão informado o padrão do cliente (um `PUT` no
  cliente, ou o `set_as_default` ao salvar um cartão novo) antes de criar a assinatura, o que
  vale para as outras assinaturas do mesmo cliente. Só o método (`setPaymentMethod(CREDIT_CARD)`)
  cobra o cartão padrão que o cliente já tiver. A leitura não preenche `creditCard`.
- **`past_due` é derivado.** A Iugu não tem esse estado: o pacote o reporta quando a data da
  próxima cobrança já passou e **alguma** fatura de `recent_invoices` continua em aberto —
  pendente, vencida (`expired`) ou parcialmente paga. Olha todas, e não só a que virou
  `latestInvoice`, senão uma cancelada de vencimento posterior esconderia uma pendente anterior.
  Em compensação, fatura antiga que deixou de ser dívida por fora do pacote continua contando
  enquanto estiver em `recent_invoices`.
- **`latestInvoice` é a fatura mais recente, não a que gerou a inadimplência.** Vence a de maior
  vencimento, com o menor id desempatando, e entrada sem id é descartada — a ordem em que a Iugu
  devolve as faturas não influencia. Em `past_due` ela pode estar **quitada**, ou vir `null`:
  para chegar na fatura a pagar, liste as faturas do cliente. Vem resumida — id, status,
  vencimento e, quando a Iugu manda, a url; sem valor em centavos (`amount` fica `null`). Use
  `getInvoice()` pelo id para a fatura completa.
- **Atualizar itens ou descontos custa chamadas extras.** A Iugu recusa remover e adicionar
  subitens na mesma chamada, então o pacote lê a assinatura, envia a remoção sozinha e só depois
  a atualização — até três requisições. Entre a remoção e a atualização a assinatura fica sem os
  itens removidos, e se a segunda falhar eles não voltam sozinhos.
- **`cancelAtPeriodEnd` e `canceledAt` vêm das marcas da lib** (`mp_cancel_at_period_end` e
  `mp_canceled_at` em `custom_variables`), gravadas por `cancel()`. `paymentMethod` vai como
  `payable_with` e volta quando a assinatura aceita um único método (`all` e listas com mais
  de um leem como nulo).
- **Reativar exige data de cobrança.** Assinatura criada sem `nextBillingAt` fica sem data no
  gateway e, por isso, não volta com `resume()`: a Iugu responde sem erro e sem mudar nada, e o
  `status` devolvido segue `SUSPENDED`.
- **`active` e `suspended` são flags independentes.** Assinatura suspensa pode continuar com
  `active: true` na Iugu; o pacote dá precedência a `suspended` e reporta `SUSPENDED`.
- **A simulação de troca traz linhas montadas pela lib.** `change_plan_simulation` devolve só
  totais (`cost`, `discount`, `cycles`, `expires_at`, `old_plan`, `new_plan`, todos em
  `original`); `previewPlanChange()` monta `items` a partir deles (ver
  [Troca de plano](#troca-de-plano)). `cost` é lido como o valor líquido da troca, então
  `amount` é `cost` e, quando há `discount`, a linha do plano novo é `cost` mais `discount`.
- **Trocar de plano com cobrança gera fatura pendente, não pagamento.** `changePlan()` com
  `ProrationBehavior::CHARGE_DIFFERENCE` (o padrão) faz a Iugu emitir a fatura na hora, com
  vencimento imediato e sem crédito do período anterior. Ela volta resumida em
  `latestInvoice`, com status `pending`; use `getInvoice()` pelo id para o valor em centavos. Com `ProrationBehavior::NONE` nada é cobrado; `ProrationBehavior::CREDIT` é
  recusado antes da rede.
- **O plano de uma assinatura existente não muda por `save()`**; use `changePlan()`.
- **Plano não é atualizável.** `save()` num `Plan` que já tem `id` lança
  `ModelAttributeValidationException` antes da requisição; para mudar preço ou intervalo, crie
  outro plano e troque as assinaturas com `changePlan()`.
- **Fatura vencida lê como `EXPIRED` e continua sendo dívida.** A Iugu chama de `expired` a
  fatura que venceu sem pagamento; ela conta como fatura em aberto na derivação de `past_due`
  da assinatura, e `InvoiceStatus::EXPIRED->isPayable()` responde verdadeiro (`isOpen()`
  responde falso, porque não há pagamento em curso).
- **A assinatura lida traz o cliente resumido.** `Subscription::get()` preenche `customer` com
  id, nome e e-mail — documento, endereço e telefone não vêm da Iugu. Eles sobrevivem se o
  `customer` local já tiver o mesmo id; se o id for outro, ou o local não tiver id, o pacote
  troca o objeto para não misturar dados de dois clientes.

No update, data de cobrança e métodos de pagamento só são enviados quando mudaram em relação
ao que veio na leitura — um `save()` que mexeu só nos itens não altera a data de cobrança. Um
`creditCard` informado no update vira o padrão do cliente antes do `PUT` da assinatura.

> **Mudança de comportamento (versão 5.0.0).** Até a 4.1.0, `trialEndsAt` ia só como
> `expires_at`, e com cartão padrão a Iugu cobrava o primeiro ciclo na criação; agora o trial vai
> com `only_charge_on_due_date`. `Subscription::$paymentMethod` passou a ser escrito
> (`payable_with`) e lido; até a 4.1.0 era ignorado nas duas direções. O desconto de assinatura
> no Stripe deixou de ser recusado e vira Coupon; desconto percentual passou a ser recusado na
> Iugu com a capability `PERCENT_DISCOUNT` (antes `NATIVE_COUPONS`, hoje `COUPONS`); e as
> variáveis `mp_` da Iugu saíram de `metadata` (a marca de cancelamento aparecia lá) e passaram
> a ser recusadas na escrita.

#### Emulações na Iugu

A Iugu não oferece cupom com prazo nem cancelamento ao fim do ciclo; a lib emula os dois (a
célula da [tabela de capabilities](#capabilities) diz "emulado", e `isEmulated()` responde
verdadeiro). O estado da emulação vive em `custom_variables` da própria assinatura na Iugu,
com o prefixo reservado `mp_`, então qualquer instância da lib lê o mesmo estado sem banco
próprio:

| Variável | Conteúdo |
|---|---|
| `mp_discount_<subitem_id>_until` | Data (`Y-m-d`) até a qual o subitem de desconto vale |
| `mp_cancel_at_period_end` | `1` quando há cancelamento agendado |
| `mp_cancel_scheduled_for` | Data (`Y-m-d`) em que a assinatura deve ser suspensa |
| `mp_canceled_at` | Data em que a lib cancelou (gravada pelo `cancel()` imediato e pelo comando ao aplicar o agendado) |

Como funciona cada emulação:

- **Cupom com prazo.** O desconto continua sendo o subitem negativo; a validade (`validUntil`,
  ou a data calculada de `cycles` pelo intervalo do plano, contada da primeira cobrança) é
  gravada na variável do subitem numa segunda requisição depois da escrita. Na leitura o
  desconto volta com `validUntil` preenchido. Se essa segunda requisição falhar na criação, a
  exceção sobe com a assinatura já criada e o desconto sem prazo; reaplique a validade com um
  `save()` da lista de descontos, que o update regrava a variável.
- **Cancelamento ao fim do ciclo.** `cancel(atPeriodEnd: true)` não suspende: um único `PUT`
  grava a intenção e a data programada (a data da próxima cobrança), a assinatura segue
  `ACTIVE` com `cancelAtPeriodEnd` verdadeiro, e `resume()` limpa o agendamento.

**A emulação só funciona se o comando `multipayment:sync-subscriptions` roda.** Ele percorre
as assinaturas do gateway, remove o subitem de desconto cuja validade passou e suspende,
gravando `mp_canceled_at`, a assinatura cujo cancelamento agendado chegou à data. Agende-o na
aplicação:

```php
// app/Console/Kernel.php (ou routes/console.php)
$schedule->command('multipayment:sync-subscriptions')->hourly();
```

O comando é idempotente (rodar duas vezes não muda nada na segunda), escreve cada ação na
saída e no log, aceita `--gateway=iugu` para um gateway só e `--dry-run` para inspecionar sem
escrever; num gateway que gerencia os dois recursos sozinho (Stripe), ele não faz nada e diz
isso no log, e a varredura sem `--gateway` pula gateway configurado sem `api_key`. Entre o vencimento (do desconto ou do agendamento) e a execução seguinte do
comando existe uma janela em que a Iugu ainda tem o subitem, ou a assinatura ativa: uma
fatura gerada nessa janela sai com o desconto vencido, e a frequência do agendamento limita a
janela. Uma aplicação que já emulava esses recursos por conta própria precisa desligar a
lógica ao migrar, no mesmo deploy, para não remover o desconto nem suspender duas vezes.

Particularidades do Stripe:

- **O plano é um Product mais um Price recorrente.** `createPlan()` cria os dois: o Product
  guarda o nome e o identificador (`metadata.identifier`), o Price guarda o valor e o
  intervalo, e o id do plano é o id do Price (`price_...`). O identificador vai também em
  `lookup_key` do Price, que é como `getPlan()` o encontra (um identificador com o prefixo
  `price_` é lido direto como id); identificador repetido é recusado pela Stripe. Todos os
  intervalos de `PlanInterval` valem, inclusive `DAY`. `deactivatePlan()` arquiva o Price:
  as assinaturas existentes continuam cobrando e assinatura nova com o plano é recusada pela
  Stripe; o plano segue legível, com `active` falso, e aparece em `listPlans()`.
- **O cartão fica na assinatura.** `setCreditCard()` vira o `default_payment_method` da
  Subscription; o cartão padrão do cliente não muda (na Iugu muda, porque lá a assinatura não
  tem cartão próprio). Cartão sem id é salvo antes pelo fluxo de SetupIntent: se o emissor
  exigir autenticação do pagador, a assinatura não é criada e sobe `ChargingException` com
  `DeclineCode::AUTHENTICATION_REQUIRED` e o SetupIntent em `chargeResponse`; conclua com
  `confirmCreditCardSetup()` e crie a assinatura com o id do cartão salvo. Na leitura,
  `creditCard` volta preenchido com id, bandeira, últimos dígitos e validade do cartão padrão
  da assinatura (na Iugu a leitura não o preenche, porque a Iugu não informa o cartão). Por
  isso, trocar o método de uma assinatura lida exige limpar `creditCard` junto com a lista de
  métodos; com o cartão preenchido e um método sem cartão, a atualização é recusada antes da
  requisição com a orientação de removê-lo.
- **Com cartão, a primeira fatura é cobrada na criação** (`payment_behavior`
  `error_if_incomplete`): a recusa do cartão sobe como `ChargingException` e a assinatura não
  é criada. Com trial não há cobrança e a assinatura nasce `TRIALING` (a fatura de valor zero
  do trial volta em `latestInvoice`). Os dias de `setTrialDays()` vão como
  `trial_period_days`, que não muda entre tentativas com a mesma chave de idempotência.
- **Com Pix, a assinatura nasce com a primeira fatura em aberto** (`payment_behavior`
  `default_incomplete`, status `PENDING`): `latestInvoice` traz a fatura para o pagador
  quitar (`url` é a página hospedada). O método `pix` em assinatura depende de habilitação na
  conta Stripe; sem ela, a criação é recusada pelo gateway com `ValidationException`
  ("The payment method type `pix` is invalid").
- **Com Pix Automático (`setPaymentMethod(PaymentMethod::AUTOMATIC_PIX)`), a assinatura
  também nasce com a primeira fatura em aberto** e registra o mandato na criação; a Stripe
  agenda as cobranças seguintes. Ver [Pix Automático](#pix-automático).
- **Com boleto, a assinatura nasce ativa em modo de fatura enviada** (`collection_method`
  `send_invoice`, com `days_until_due` de 3 dias, sobrescritível por
  `gateway_options['days_until_due']`): a primeira fatura é finalizada na criação e volta em
  `latestInvoice` aberta, com vencimento e com a página hospedada em `url`, onde o pagador
  gera o voucher; as faturas dos ciclos seguintes são emitidas pela Stripe com o mesmo prazo.
  A troca de método de uma assinatura existente para boleto muda o modo de cobrança para
  `send_invoice`, e a troca de boleto para cartão ou Pix devolve a cobrança automática. Uma
  assinatura com mandato de Pix Automático não troca de método (nem uma existente passa a
  tê-lo): as duas direções são recusadas antes da requisição.
- **Itens extras criam Prices no Stripe.** Cada `SubscriptionItem` vira um subscription item
  com um Product e um Price próprios, criados na hora (o item precisa de `description` e
  `amount`); item com `recurring` falso vai como item avulso da primeira fatura. No update
  declarativo, item novo cria Price, item com `id` tem a quantidade atualizada e mantém o
  Price, item que saiu da lista é removido, e a troca não gera pró-rata.
- **Descontos criam Coupons no Stripe.** Cada `SubscriptionDiscount` novo vira um Coupon
  criado na hora, aplicado via `discounts` da Subscription; a duração vem de `cycles` e
  `validUntil` (ver [Assinaturas e planos](#assinaturas-e-planos)). No update a lista
  informada substitui a da assinatura: desconto com `id` (o id do Coupon, como veio na
  leitura) mantém o Coupon, desconto novo cria um, lista vazia remove todos, e a lista igual
  à lida não é reenviada. O Coupon criado não é apagado quando sai da assinatura: ele fica na
  conta, reutilizável pelo id.
- **`suspend()` pausa a cobrança** (`pause_collection` com `behavior` `void`) e a assinatura
  lê como `SUSPENDED`, o mesmo estado da Iugu; as faturas dos ciclos pausados são anuladas.
  `resume()` desfaz a pausa e também o cancelamento agendado por `cancel(atPeriodEnd: true)`.
  Assinatura cancelada de vez (`CANCELED`) não volta na Stripe: `resume()` é recusado pelo
  gateway com `ValidationException` (na Iugu, `resume()` reativa a cancelada).
- **`cancel(atPeriodEnd: true)` é nativo**: a assinatura segue ativa até o fim do período
  pago, com `cancelAtPeriodEnd` e `canceledAt` preenchidos no model. `cancel()` sem o
  argumento cancela na hora, sem estorno nem fatura final.
- **`nextBillingAt` vale só na criação** (`billing_cycle_anchor`). Na troca de plano e na
  atualização, um `nextBillingAt` diferente do que veio do gateway é recusado com
  `UnsupportedOperationException` (restrição consultável de `SUBSCRIPTIONS`): a Stripe não
  aceita uma data arbitrária de próxima cobrança fora do ciclo. Na leitura, `nextBillingAt` é
  o fim do período corrente do item do plano.
- **Leituras custam requisições a mais.** `getSubscription()` (e a criação e a troca com
  cobrança) relê a fatura mais recente para preencher `latestInvoice` por inteiro;
  `listSubscriptions()` e `listPlans()` paginam por cursor, então uma página além da primeira
  custa uma requisição por página anterior; `listSubscriptions()` traz assinaturas em
  qualquer status, sem `latestInvoice`.
- **A fatura de assinatura (`in_`) aceita leitura e escrita**: `getInvoice()`,
  `cancelInvoice()` (`void`), `refundInvoice()`/`refundableAmount()` (o estorno age sobre o
  PaymentIntent da fatura) e `chargeInvoiceWithCreditCard()` (`invoices.pay` com o cartão).
  Só a duplicação e a captura em duas etapas continuam de fora, como restrições consultáveis
  (a próxima fatura é gerada pela Stripe, e a cobrança dela captura na hora).

Confira `src/Builders/SubscriptionBuilder.php` para saber quais métodos estão disponíveis.

#### CustomerBuilder
```php
$multiPayment = new \Potelo\MultiPayment\MultiPayment('iugu');
$customerBuilder = $multiPayment->newCustomer();
$customer = $customerBuilder->setName('Nome')
    ->setEmail('email')
    ->setTaxDocument('01234567891')
    ->setPhone('999999999', '71')
    ->addAddress('45400000', 'Rua', 'S/N')
    ->create();
```
Confira `src/MultiPayment/Builders/CustomerBuilder.php` para saber quais métodos estão disponíveis.

#### Salvar cartão (CreditCardBuilder)

O cartão salvo fica vinculado ao cliente e pode ser cobrado depois por `addCreditCardId()`,
`setCreditCard()` na assinatura ou `chargeInvoiceWithCreditCard()`. No Stripe o token é o
PaymentMethod criado no navegador com Stripe.js (`pm_...`); na Iugu, o token do iugu.js ou os
dados do cartão (`setNumber()`, `setCvv()`...), que só a Iugu aceita (`RAW_CARD_DATA`).

```php
$payment = new \Potelo\MultiPayment\MultiPayment('stripe');   // ou 'iugu'

$card = $payment->newCreditCard()
    ->setCustomerId($customer->id)
    ->setToken($request->payment_method_id)
    ->setDescription('Cartão principal')
    ->setAsDefault()
    ->withIdempotencyKey("card-{$request->uuid}")
    ->create();

if ($card->requiresAction) {
    // o emissor exige autenticação do pagador (3DS) antes de o cartão ficar cobrável; o cartão
    // ainda não foi salvo (id nulo). O navegador conclui com o SDK do gateway:
    // stripe.confirmCardSetup($card->clientSecret) e, depois, o servidor confirma abaixo
    return response()->json(['setup_id' => $card->setupId, 'client_secret' => $card->clientSecret]);
}

$card->id;   // cartão salvo e cobrável
```

```php
// depois que o pagador autenticou no navegador
$card = $payment->confirmCreditCardSetup($setupId);   // ou $card->confirmSetup()

if ($card->requiresAction) {
    // o pagador ainda não concluiu a autenticação; peça de novo ou desista do cartão
}
$card->id;   // cartão salvo; a descrição e a marcação de padrão pedidas na criação já foram aplicadas
```

Regras do fluxo:

- **Quem autentica é o gateway com `CARD_SETUP_AUTHENTICATION`** (Stripe). Na Iugu a
  capability é limitação do gateway: o Zero Auth confere a validade do cartão com uma
  autorização de valor zero e não autentica o portador, então `requiresAction` é sempre falso,
  `setupId` é nulo e `confirmCreditCardSetup()` lança `UnsupportedOperationException`.
- **Página hospedada de autenticação.** No Stripe, `setGatewayOptions(['return_url' => ...])`
  faz a Stripe devolver a página de 3DS em `actionUrl`; sem `return_url`, `actionUrl` fica
  nulo e a autenticação é pelo Stripe.js com `clientSecret`.
- **Recusa é `CardDeclinedException`**, na criação ou na confirmação: cartão recusado pelo
  emissor no setup, autenticação que falhou (`DeclineCode::AUTHENTICATION_REQUIRED`, com
  `gatewayCode` `setup_intent_authentication_failure`) ou setup cancelado
  (`DeclineCode::UNKNOWN`). O SetupIntent vai em `chargeResponse`.
- **Idempotência.** A chave de `withIdempotencyKey()` vai no SetupIntent; a confirmação aceita
  a própria chave como segundo argumento (`confirmCreditCardSetup($setupId, $key)`).

> **Mudança de comportamento (versão 5.0.0).** Até a 4.1.0, `newCreditCard()->create()` no
> Stripe fazia só o `attach` do PaymentMethod: um cartão que exigia autenticação era salvo
> como cobrável e recusado na primeira cobrança `off_session`, com
> `DeclineCode::AUTHENTICATION_REQUIRED`. Agora esse cartão volta com `requiresAction` e sem
> `id`, e só é salvo depois da autenticação. Quem persiste `$card->id` logo após o `create()`
> precisa tratar `requiresAction` antes. A recusa do emissor passa a chegar no setup, e
> `retryable` segue o `advice_code` que a Stripe envia nesse ponto, que pode diferir do que
> vinha na cobrança.

#### getInvoice
```php
$invoiceId = '312ASDHGZXSGRTET312ASDHGZXSGRTET';
$payment = new \Potelo\MultiPayment\MultiPayment('iugu');
$foundInvoice = $payment->getInvoice($invoiceId);

// no Stripe o id pode ser de um PaymentIntent (pi_, venda avulsa) ou de um Invoice
// (in_, fatura de assinatura); originType diz qual voltou (ver "Fatura no Stripe: duas origens")
$foundInvoice = (new \Potelo\MultiPayment\MultiPayment('stripe'))->getInvoice('in_1UBH...');
$foundInvoice->originType;   // InvoiceOriginType::INVOICE
```

#### getSubscription e getPlan
```php
$payment = new \Potelo\MultiPayment\MultiPayment('iugu');
$subscription = $payment->getSubscription($subscriptionId);   // Subscription, com status, latestInvoice e cliente resumido

// getPlan() aceita o identificador definido na criação ou o id do gateway: busca primeiro pelo
// identificador e, se ele não existir, pelo id (duas requisições nesse caso)
$plan = $payment->getPlan('plano_mensal');
$plan = $payment->getPlan('7D96C7C932F2427CAF54F042345A13C6');
```
Nos dois, gateway sem `SUBSCRIPTIONS` ou `PLANS` lança `UnsupportedOperationException` antes de
qualquer requisição; plano inexistente pelos dois caminhos lança `NotFoundException`.

#### Outras operações de fatura
```php
$payment = new \Potelo\MultiPayment\MultiPayment('stripe');

// estorno do restante ou parcial (valor em centavos); devolve um Refund (seção "Estorno")
$refund = $payment->refundInvoice($invoiceId);
$refund = $payment->refundInvoice($invoiceId, 5000);
$payment->refundableAmount($invoiceId);   // quanto ainda pode ser estornado, em centavos

// cancelamento de fatura pendente (no Stripe, a fatura de assinatura in_ é anulada com void)
$payment->cancelInvoice($invoiceId);

// duplicar fatura pendente com nova expiração (no Stripe: somente pix de venda avulsa; a original é cancelada)
$payment->duplicateInvoice($invoiceId, \Carbon\Carbon::now()->addDays(3));

// cobrar uma fatura pendente com cartão (token OU id de cartão salvo)
$payment->chargeInvoiceWithCreditCard($invoiceId, 'pm_...');
$payment->chargeInvoiceWithCreditCard($invoiceId, null, $creditCardId);

// toda operação de escrita aceita a chave de idempotência como último argumento (seção "Idempotência")
$payment->refundInvoice($invoiceId, 5000, idempotencyKey: $uuid);
$payment->cancelInvoice($invoiceId, idempotencyKey: $uuid);
```

No Stripe todas as operações acima também valem para a fatura de assinatura (`in_`), com as
diferenças descritas em [Fatura no Stripe: duas origens](#fatura-no-stripe-duas-origens); a
única recusada é `duplicateInvoice()`.

#### Captura em duas etapas

Com `CaptureMethod::MANUAL` a fatura de cartão nasce autorizada: o valor fica reservado no
cartão (`InvoiceStatus::AUTHORIZED`) e a cobrança só se completa quando `captureInvoice()`
captura, ou é desfeita quando `cancelInvoice()` libera a reserva. A capability é
`DELAYED_CAPTURE`, com restrição consultável nos dois gateways (só cartão de crédito):

```php
use Potelo\MultiPayment\Enums\CaptureMethod;
use Potelo\MultiPayment\Enums\InvoiceStatus;

$payment = new \Potelo\MultiPayment\MultiPayment('stripe');

$invoice = $payment->newInvoice()
    ->setCustomer($customer)
    ->addItem('Reserva', 12345, 1)
    ->setAvailablePaymentMethods([\Potelo\MultiPayment\Enums\PaymentMethod::CREDIT_CARD])
    ->addCreditCardId($creditCardId)
    ->setCaptureMethod(CaptureMethod::MANUAL)
    ->create();

$invoice->status === InvoiceStatus::AUTHORIZED;   // valor reservado, nada capturado

// captura o valor integral, ou parcial no Stripe (a Stripe libera o restante da reserva)
$captured = $payment->captureInvoice($invoice->id, idempotencyKey: $uuid);
$captured = $payment->captureInvoice($invoice->id, 10000);

// ou libera a reserva sem cobrar
$payment->cancelInvoice($invoice->id);
```

O que muda por gateway:

- **Stripe**: o PaymentIntent vai com `capture_method: manual` e o confirm só reserva; a
  captura parcial (`amount_to_capture`) é aceita e libera o restante. `Invoice::$captureMethod`
  volta preenchido na leitura. A fatura de assinatura (`in_`) fica de fora: a Stripe a cobra
  com captura imediata.
- **Iugu**: a cobrança é o mesmo `POST /v1/charge` (por isso `MANUAL` exige fatura só de
  cartão, com o cartão informado na criação) e a autorização sem captura depende do **fluxo de
  pagamento em duas etapas habilitado na conta** (em Configurações, seção Cartão de Crédito);
  sem ele,
  a Iugu captura na hora. A fatura autorizada fica `in_analysis` (`AUTHORIZED`), a captura é
  sempre do valor integral (um valor em `captureInvoice()` é recusado antes da requisição) e a
  Iugu cancela sozinha a autorização não capturada em 7 dias.

A fatura fora de `AUTHORIZED` é recusada pelo gateway (`ValidationException`); na Iugu a
mensagem é "Apenas Faturas em análise podem ser capturadas".

#### Estorno

Sem valor, o estorno é do restante estornável; com valor em centavos, é parcial. A operação
devolve um `Refund` (`Potelo\MultiPayment\Models\Refund`) com o que o gateway registrou do
estorno, e a fatura relida depois dele fica em `$refund->invoice()`:

| Campo | Conteúdo |
|---|---|
| `id` | Id do estorno no gateway. Stripe: `re_...`; Iugu: `null`, porque a Iugu não identifica estornos |
| `invoiceId` | Id da fatura estornada |
| `amount` | Valor deste estorno, em centavos |
| `status` | `RefundStatus`: `PENDING`, `SUCCEEDED`, `FAILED`, `CANCELED` ou `UNKNOWN` (status que a lib não reconhece, com aviso no log). Na Iugu é sempre `SUCCEEDED`, porque a API só responde 200 com o estorno feito; na Stripe o estorno de cartão costuma nascer `PENDING` e virar `SUCCEEDED` depois |
| `reason` | Motivo em texto, quando o gateway devolve um (Stripe: `duplicate`, `fraudulent`, `requested_by_customer`); a lib não o envia ao gateway |
| `createdAt` | Data do estorno. Na Iugu é o momento da chamada |
| `original` | Objeto de estorno do gateway (Stripe); `null` na Iugu |
| `invoice()` | Fatura com o estado posterior ao estorno, já relida, sem requisição |

```php
use Potelo\MultiPayment\Enums\RefundStatus;

$payment = new \Potelo\MultiPayment\MultiPayment('stripe');

$refund = $payment->refundInvoice($invoiceId);         // o restante
$refund = $payment->refundInvoice($invoiceId, 5000);   // parcial

$refund->id;        // 're_...' (Stripe) ou null (Iugu)
$refund->amount;    // 5000
$refund->status;    // RefundStatus::PENDING ou RefundStatus::SUCCEEDED

$invoice = $refund->invoice();
$invoice->status;   // InvoiceStatus::REFUNDED ou InvoiceStatus::PARTIALLY_REFUNDED
```

`$invoice->refund(?int $amount = null, ?string $idempotencyKey = null)` num model faz o mesmo e
atualiza a própria instância: depois da chamada `$invoice->status` já é o novo status, e
`$refund->invoice()` é a mesma instância. `$invoice->refundedAmount` é só de leitura e traz o
total já estornado que o gateway informou; num model lido do gateway que já teve estorno parcial,
`refund()` sem valor estorna o que resta.

**Quanto ainda pode ser estornado.** `$invoice->refundableAmount()` (ou
`$payment->refundableAmount($id)`) devolve o restante em centavos, calculado pelo driver: na
Iugu é `paidAmount`, porque `paid_cents` já vem líquido do estornado; na Stripe é `paidAmount`
menos `refundedAmount`, porque o valor pago vem bruto. Zero para fatura não paga, já
integralmente estornada ou paga com boleto, cujo estorno `refund()` recusa nos dois gateways:
uma tela que exibe o valor de `refundableAmount()` nunca promete um estorno que a lib vai
recusar por limitação de capability. Um model que traz o valor pago e o método de pagamento não
custa requisição; um model só com o id (ou sem o método) lê a fatura. É o teto aritmético do
estorno; as guardas de Pix parcial e prazo continuam valendo.

```php
$invoice = $payment->getInvoice($id);           // já partially_refunded

$invoice->refundableAmount();                   // 5000 nos dois gateways
$refund = $invoice->refund(amount: 2000, idempotencyKey: $key);
$refund = $invoice->refund();                   // o restante
```

**Histórico de estornos.** Toda fatura lida do gateway traz `$invoice->refunds`, uma lista de
`Refund` (vazia quando nada foi estornado). Os dois gateways preenchem a lista de formas
diferentes, e a conciliação precisa saber disso:

- **Stripe**: um `Refund` por estorno feito, com `id`, `status` e `createdAt` próprios, lido
  dos refunds do charge.
- **Iugu**: a API só informa o total estornado (`refunded_cents`), então a lista tem no máximo
  um `Refund`, sem `id` e sem `createdAt`, com o acumulado. Dois estornos parciais de 2.000 e
  3.000 aparecem como um único registro de 5.000. O `Refund` devolvido por cada chamada de
  `refundInvoice()` traz o valor daquela chamada.

```php
$invoice = $payment->getInvoice($invoiceId);

foreach ($invoice->refunds as $refund) {
    $ledger->record($invoice->id, $refund->id, $refund->amount, $refund->status, $refund->createdAt);
}
```

**Recusa antes da requisição.** O pacote recusa, **antes de chamar o gateway**, o estorno que a
regra do gateway já garante que seria negado, e o faz com `RefundNotSupportedException` nos
dois drivers, para a aplicação não precisar interpretar a mensagem da Iugu ou da Stripe. A
classe herda direto de `MultiPaymentException`: `isCapabilityLimitation()` separa a recusa que
é limitação do gateway (boleto, Pix parcial; `capability` preenchida) da recusa por estado da
fatura (já estornada, valor acima do restante, prazo vencido; `capability` nula). Um
`catch (UnsupportedOperationException $e)` usado para rotear a operação para outro gateway não
captura estorno recusado: rotear um estorno de fatura já estornada não faria sentido.

```php
use Potelo\MultiPayment\Exceptions\RefundNotSupportedException;

try {
    $refund = $payment->refundInvoice($invoiceId, 5000);
} catch (RefundNotSupportedException $e) {
    // a lib recusou sem chamar o gateway; $e->reason diz por quê
    if ($e->manualRefundRequired) {
        // boleto ou prazo vencido: o dinheiro só volta por fora do gateway
        ManualRefund::dispatch($invoiceId, $e->paymentMethod, $e->reason);
    } elseif ($e->isCapabilityLimitation()) {
        // Pix parcial na Iugu: $e->capability é PARTIAL_REFUND_PIX; repita sem valor
    }
}
```

| `$e->reason` | Quando | `$e->manualRefundRequired` | `$e->isCapabilityLimitation()` |
|---|---|---|---|
| `boleto_no_refund` | Fatura paga com boleto, nos dois gateways | `true` | `true` (`REFUND_BANK_SLIP`) |
| `pix_partial_not_supported` | Iugu: valor pedido diferente do valor pago numa fatura Pix. Repita sem valor para estornar o total | `false` | `true` (`PARTIAL_REFUND_PIX`) |
| `already_refunded` | Fatura já lida como `refunded` | `false` | `false` |
| `amount_exceeds_refundable` | Valor pedido acima de `refundableAmount()` (o restante vai na mensagem). Repita com valor até o restante | `false` | `false` |
| `refund_window_expired` | Iugu: depois do fim do 90º dia após `paidAt` | `true` | `false` |
| `no_gateway_charge` | Stripe: fatura de assinatura quitada sem cobrança pela Stripe (paga fora dela, ou sem valor a cobrar); a API não tem o que estornar | `true` quando houve pagamento fora do gateway a devolver | `false` |

Uma fatura `partially_refunded` aceita novos estornos até zerar o restante; pedir exatamente
o que resta é estorno integral. Valor zero ou negativo é recusado com
`ModelAttributeValidationException` antes de qualquer requisição.

**Custo da leitura prévia.** As guardas precisam do método de pagamento, do status, na Iugu da
data de pagamento e, no estorno por valor, do quanto ainda pode ser estornado. Chamar
`refundInvoice($id)` só com o id custa **um GET a mais** para ler a fatura antes do estorno, nos
dois gateways; chamar `$invoice->refund()` num model já lido do gateway não paga esse GET,
inclusive numa fatura `partially_refunded`, porque `refundedAmount` traz o acumulado que o
gateway informou. Essa leitura não altera o model do chamador: ele só muda quando o estorno
acontece.

> **Mudança de comportamento (versão 5.0.0).** Até a 4.1.0, `refundInvoice()` e
> `$invoice->refund()` devolviam a `Invoice` atualizada; agora devolvem o `Refund`, e a fatura
> fica em `$refund->invoice()` (ou na própria instância, no caso de `$invoice->refund()`). O
> campo provisório `Invoice::$lastRefundId` foi removido: o id está em `$refund->id`. Na mesma
> versão, estorno de boleto, Pix parcial, fatura já estornada, valor acima do restante e fora do
> prazo de 90 dias na Iugu deixaram de ir até a API e voltar como `GatewayException`: lançam
> `RefundNotSupportedException`, que herda de `MultiPaymentException`, fora da árvore de
> `GatewayException` e fora da de `UnsupportedOperationException`: um `catch (GatewayException $e)`
> sozinho deixa de capturar esses casos.
>
> Ainda na 5.0.0, o valor do estorno virou argumento: `$invoice->refund(amount: 5000)` e
> `refundInvoice(Invoice $invoice, ?int $amount, ?string $idempotencyKey)` no contract.
> `Invoice::$refundedAmount` passou a ser só de leitura (o total já estornado, como o gateway
> informa); escrever nela antes de chamar `refund()` continua funcionando como pedido de estorno
> parcial, com aviso `E_USER_DEPRECATED`, e some em uma versão futura. Consequência: num model
> lido do gateway já `partially_refunded`, `refund()` sem valor passou a estornar o restante em
> vez de reenviar o acumulado como novo estorno parcial.

#### charge (alternativa por array)

`charge(array)` monta a mesma fatura a partir de um array em `snake_case`, para integrações que
recebem os dados prontos (o `MultiPaymentTrait` usa este caminho). As chaves espelham as
propriedades do model e seguem as mesmas regras do builder; `customer` é obrigatório e é
conferido antes de qualquer conversão. O exemplo abaixo vale para os dois gateways:

```php
$invoice = (new \Potelo\MultiPayment\MultiPayment('iugu'))->charge([
    'customer' => [
        'name' => 'Nome do cliente',
        'email' => 'email@example.com',
        'tax_document' => '20176996915',
        'phone_area' => '71',
        'phone_number' => '999999999',
        'address' => ['street' => 'Rua', 'number' => '123', 'district' => 'Bairro', 'city' => 'Salvador', 'state' => 'BA', 'zip_code' => '41820330'],
    ],
    'items' => [
        ['description' => 'Produto 1', 'quantity' => 1, 'price' => 10000],
        ['description' => 'Produto 2', 'quantity' => 2, 'price' => 5000],
    ],
    'payment_method' => 'credit_card',
    'credit_card' => ['token' => $token],    // token do gateway; na Iugu também number, month, year, cvv, first_name, last_name
], idempotencyKey: $order->uuid);
```

A lista completa de chaves está no [apêndice](#apêndice-chaves-do-array-de-charge).

### Models
#### Customer
```php
$customer = new Customer();
$customer->name = 'Teste';
$customer->email = 'teste@email.com';
$customer->taxDocument = '12345678901';
$customer->save('iugu');
echo $customer->id; // 7D96C7C932F2427CAF54F042345A13C60CD7
```
#### Invoice
```php
use Potelo\MultiPayment\Enums\PaymentMethod;

$invoice = new Invoice();
$invoice->customer = $customer;
$item = new InvoiceItem();
$item->description = 'Teste';
$item->price = 10000;
$item->quantity = 1;
$invoice->items[] = $item;
$invoice->paymentMethod = PaymentMethod::CREDIT_CARD; // a string 'credit_card' também é aceita; decide como a fatura é criada
$invoice->creditCard = new CreditCard();
$invoice->creditCard->number = '4111111111111111';
$invoice->creditCard->firstName = 'João';
$invoice->creditCard->lastName = 'Silva';
$invoice->creditCard->month = '11';
$invoice->creditCard->year = '2022';
$invoice->creditCard->cvv = '123';
$invoice->creditCard->customer = $customer;
$invoice->save('iugu');      // cobra o cartão
echo $invoice->id; // CB1FA9B5BD1C42B287F4AC7F6259E45D
$invoice->originType; // InvoiceOriginType::INVOICE (na Iugu sempre; no Stripe, PAYMENT_INTENT ou INVOICE)
$invoice->dueDate;    // vencimento; $invoice->pixExpiresAt é a expiração do QR Code do Pix
$invoice->currency;   // 'BRL', preenchida na leitura
$invoice->lastPaymentError;   // PaymentError ou null (ver "Motivo da recusa na leitura")
```
#### Refund
```php
$invoice = $payment->getInvoice($invoiceId);
$invoice->refundableAmount();          // quanto ainda pode ser estornado, em centavos
$refund = $invoice->refund(5000);      // Refund; $invoice já reflete o estado posterior
$refund = $invoice->refund();          // sem valor: o restante

$refund->amount;                        // 5000
$invoice->refundedAmount;               // total já estornado, só de leitura
$invoice->refunds;                      // Refund[] (ver "Estorno")
```
#### Subscription
```php
$subscription = new Subscription();
$subscription->planId = 'plano_mensal';
$subscription->customer = $customer;
$subscription->creditCard = $card;      // ou $subscription->paymentMethod = PaymentMethod::PIX
$subscription->trialDays = 7;
$subscription->save('iugu');
echo $subscription->id;

$subscription = $payment->getSubscription($subscription->id);
$subscription->creditCard?->lastDigits;   // '4242' no Stripe; null na Iugu, que não informa o cartão
$subscription->currency;                  // 'BRL', preenchida na leitura
```
#### Plan
```php
$plan = new Plan();
$plan->name = 'Mensal';
$plan->identifier = 'plano_mensal';
$plan->amount = 10000;
$plan->interval = PlanInterval::MONTH;
$plan->save('iugu');
echo $plan->id;
```

## Apêndice: chaves do array de `charge()`

Chaves aceitas por `charge(array)` (e por `Invoice::fill()`), em `snake_case`; ver
[charge (alternativa por array)](#charge-alternativa-por-array).

| atributo                      | obrigatório                                                         | tipo                           | descrição                                 | exemplo                               |
|-------------------------------|---------------------------------------------------------------------|--------------------------------|-------------------------------------------|---------------------------------------|
| `amount`                      | **obrigatório** caso `items` não seja informado                     | int                            | valor em centavos; junto de `items`, precisa ser a soma deles | `10000`                               |
| `currency`                    |                                                                     | string                         | moeda ISO 4217, `BRL` por padrão; a Iugu só aceita `BRL` | `'BRL'`                               |
| `customer`                    | **obrigatório**                                                     | array                          | array com os dados do cliente             | `['name' => 'Nome do cliente'...]`    |
| `customer.name`               | **obrigatório**                                                     | string                         | nome do cliente                           | `'Nome do cliente'`                   |
| `customer.email`              | **obrigatório**                                                     | string                         | email do cliente                          | `'joaomaria@email.com'`               |
| `customer.tax_document`       | **obrigatório** no Stripe para faturas pix                          | string                         | cpf ou cnpj do cliente                    | `'12345678901'`                       |
| `customer.birth_date`         |                                                                     | string formato `yyyy-mm-dd`    | data de nascimento                        | `'1990-01-01'`                        |
| `customer.phone_number`       |                                                                     | string                         | telefone                                  | `'999999999'`                         |
| `customer.phone_area`         |                                                                     | string                         | DDD                                       | `'999999999'`                         |
| `customer.address`            | **obrigatório** para o método de pagamento `bank_slip`              | array                          | array com os dados do endereço do cliente | `['street' => 'Rua do cliente'...]`   |
| `customer.address.street`     | **obrigatório**                                                     | string                         | nome da rua                               | `'Nome da rua'`                       |
| `customer.address.number`     | **obrigatório**                                                     | string                         | número da casa                            | `'123'`                               |
| `customer.address.district`   | **obrigatório**                                                     | string                         | bairro                                    | `'Bairro do cliente'`                 |
| `customer.address.city`       | **obrigatório**                                                     | string                         | cidade                                    | `'Salvador'`                          |
| `customer.address.state`      | **obrigatório**                                                     | string                         | estado                                    | `'Bahia'`                             |
| `customer.address.complement` | **obrigatório**                                                     | string                         | complemento                               | `'Apto. 123'`                         |
| `customer.address.zip_code`   | **obrigatório**                                                     | string                         | cep                                       | `'12345678'`                          |
| `items`                       | **obrigatório** caso `amount` não tenha sido informado              | array                          | array com os itens da compra              | `[['description' => 'Produto 1',...`  |
| `items.description`           | **obrigatório**                                                     | string                         | descrição do item                         | `'Produto 1'`                         |
| `items.quantity`              | **obrigatório**                                                     | int                            | quantidade do item                        | `1`                                   |
| `items.price`                 | **obrigatório**                                                     | int                            | valor do item                             | `10000`                               |
| `payment_method`              | **obrigatório** no Stripe quando não há `available_payment_methods` nem `credit_card` | `PaymentMethod` ou a string `'credit_card'`, `'bank_slip'`, `'pix'` | método com que a fatura é criada quando `available_payment_methods` está vazia | `'credit_card'`                       |
| `available_payment_methods`   |                                                                     | array de `PaymentMethod` ou de strings | métodos aceitos pela fatura (mais de um só na Iugu); tem precedência sobre `payment_method` | `['pix']`                             |
| `capture_method`              |                                                                     | `CaptureMethod` ou a string `'automatic'`, `'manual'` | momento da captura no cartão; `'manual'` cria a fatura autorizada (ver [Captura em duas etapas](#captura-em-duas-etapas)) | `'manual'`                            |
| `due_date`                    |                                                                     | string em `yyyy-mm-dd` ou ISO 8601 | vencimento (ver [Datas da fatura](#datas-da-fatura)); na Iugu, hoje quando omitido | `'2026-10-10'`                        |
| `pix_expires_at`              |                                                                     | string em ISO 8601             | expiração do QR Code do Pix (Stripe: entre 10 segundos e 14 dias no futuro) | `'2026-10-10T18:00:00-03:00'`         |
| `expires_at`                  | obsoleto desde 2026-09-02                                           | string em `yyyy-mm-dd`         | alias de `due_date`, com aviso `E_USER_DEPRECATED` | `'2026-10-10'`                        |
| `credit_card`                 | **obrigatório** caso `payment_method` seja `'credit_card'`; sozinho, implica cartão | array                          | array com os dados do cartão de crédito   | `['token' => 'pm_...']`               |
| `credit_card.token`           |                                                                     | string                         | token do cartão para o gateway escolhido  | `'abc123...'` (Iugu) / `'pm_...'` (Stripe) |
| `credit_card.number`          | **obrigatório** caso `token` não tenha sido informado (somente Iugu — o Stripe é token-only) | string                         | número do cartão de crédito               | `'1234567890123456'`                  |
| `credit_card.month`           | **obrigatório** caso `token` não tenha sido informado (somente Iugu) | string                         | mês de expiração do cartão de crédito     | `'12'`                                |
| `credit_card.year`            | **obrigatório** caso `token` não tenha sido informado (somente Iugu) | string                         | ano de expiração do cartão de crédito     | `'2022'`                              |
| `credit_card.cvv`             | **obrigatório** caso `token` não tenha sido informado (somente Iugu) | string                         | código de segurança do cartão de crédito  | `'123'`                               |
| `credit_card.first_name`      |                                                                     | string                         | primeiro nome no cartão de crédito        | `'João'`                              |
| `credit_card.last_name`       |                                                                     | string                         | último nome no cartão de crédito          | `'Maria'`                             |
| `bank_slip`                   |                                                                     | array                          | dados do boleto devolvidos na leitura (`url`, `number`, `barcode_data`, `barcode_image`) | `['number' => '...', 'url' => '...']` |
| `gateway_options`             |                                                                     | array                          | opções específicas do gateway mescladas ao payload (ver [Opções extras do gateway](#opções-extras-do-gateway)) | `['expires_in' => 3]`                 |
