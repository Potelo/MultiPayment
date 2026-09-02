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
- [Utilizando](#utilizando)
  - [MultiPayment](#multipayment)
    - [InvoiceBuilder](#invoicebuilder)
    - [Pix Automático](#pix-automático)
    - [Pix Automático: quem agenda a cobrança](#pix-automático-quem-agenda-a-cobrança)
    - [Assinaturas e planos](#assinaturas-e-planos)
    - [CustomerBuilder](#customerbuilder)
    - [getInvoice](#getinvoice)
    - [Outras operações de fatura](#outras-operações-de-fatura)
    - [Estorno](#estorno)
    - [charge](#charge)
  - [Models](#models)
    - [Customer](#customer)
    - [Invoice](#invoice)
    - [Subscription](#subscription)
    - [Plan](#plan)

## Requisitos
  - PHP 8.3+
  - Laravel 10.0+

## Instalação

Instale esse pacote pelo composer:

```  
composer require potelo/multi-payment "dev-main"  
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

#stripe
STRIPE_APIKEY=
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

Cada driver declara o que suporta em dois níveis, pelo contract `DeclaresCapabilities`:
`capabilities()` lista o que o gateway oferece e a lib implementa; `notYetImplemented()` lista o
que o gateway oferece mas a lib ainda não construiu (planejado para uma versão futura). O que não
aparece em nenhuma das duas listas é limitação do gateway. `supports(Capability $c)` responde
sobre a primeira lista. Os valores são o enum `Potelo\MultiPayment\Enums\Capability`.

Consulte a capability **antes** de montar a interface de checkout ou de escolher o gateway, em
vez de capturar a exceção depois:

```php
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Facades\MultiPayment;

if (!MultiPayment::gateway('stripe')->supports(Capability::BANK_SLIP)) {
    $gateway = 'iugu';                                   // roteia antes de exibir a opção
}

MultiPayment::supports(Capability::INSTALLMENTS, 'iugu');  // true
MultiPayment::capabilities('stripe');                       // Capability[] que a lib implementa
MultiPayment::notYetImplemented('stripe');                  // Capability[] planejadas
```

Toda operação fora das capabilities do gateway lança `UnsupportedOperationException` **antes de
qualquer requisição**, inclusive antes de criar o cliente que acompanha a fatura ou a
assinatura. A exceção traz `capability`, `gateway` e `reason` (`not_implemented` quando o
gateway oferece e a lib ainda não implementou; `gateway_limitation` quando o gateway não
oferece). Ver [Tratamento de erros](#tratamento-de-erros).

A matriz abaixo é gerada a partir das declarações dos drivers com `composer capabilities:table`;
o teste `GatewayCapabilitiesTest` falha quando o README fica defasado em relação ao código.

| Capability | Significado | Iugu | Stripe |
|---|---|---|---|
| `CREDIT_CARD` | Fatura paga com cartão de crédito. | sim | sim |
| `PIX` | Fatura paga com Pix avulso, com QR Code de pagamento único. | sim | sim |
| `BANK_SLIP` | Fatura paga com boleto bancário. | sim | não implementado |
| `AUTOMATIC_PIX` | Recorrência de Pix Automático criada junto com a fatura, com reagendamento e cancelamento pela lib. | sim | não implementado |
| `MULTIPLE_PAYMENT_METHODS` | Fatura aberta a mais de um método de pagamento, escolhido pelo pagador na hora de pagar. | sim | não implementado |
| `RAW_CARD_DATA` | Cartão informado com número e CVV pela API; sem ela, o cartão é tokenizado no navegador e só o token chega à lib. | sim | limitação do gateway |
| `INSTALLMENTS` | Parcelamento da cobrança no cartão de crédito. | sim | limitação do gateway |
| `DELAYED_CAPTURE` | Cobrança em duas etapas no cartão: reserva do valor agora e captura depois. | não implementado | não implementado |
| `PARTIAL_REFUND_CARD` | Estorno de parte do valor numa fatura paga com cartão. | sim | sim |
| `PARTIAL_REFUND_PIX` | Estorno de parte do valor numa fatura paga com Pix. | limitação do gateway | sim |
| `REFUND_BANK_SLIP` | Estorno pela API de uma fatura paga com boleto. | limitação do gateway | limitação do gateway |
| `INVOICE_DUPLICATION` | Segunda via de uma fatura pendente com nova data de vencimento (`duplicateInvoice`). | sim | sim |
| `IDEMPOTENCY` | Chave de idempotência (`gateway_options['idempotency_key']`) honrada na criação de fatura e no estorno. | não implementado | sim |
| `IDEMPOTENCY_ALL_ENDPOINTS` | Chave de idempotência honrada em toda operação de escrita, inclusive cliente, cartão, cancelamento e troca de plano. | limitação do gateway | não implementado |
| `SUBSCRIPTIONS` | Assinatura recorrente: criar, buscar, atualizar, suspender, retomar, cancelar, trocar de plano e listar. | sim | não implementado |
| `PLANS` | Plano de assinatura: criar, buscar e listar. | sim | não implementado |
| `PLAN_DEACTIVATION` | Desativar um plano sem apagá-lo (`deactivatePlan`). | limitação do gateway | não implementado |
| `CANCEL_AT_PERIOD_END` | Cancelar a assinatura só no fim do período já pago (`cancel(atPeriodEnd: true)`). | limitação do gateway | não implementado |
| `NATIVE_COUPONS` | Cupom de primeira classe na assinatura: desconto percentual e desconto limitado a vários ciclos. | limitação do gateway | não implementado |
| `PLAN_CHANGE_PRORATION` | Crédito proporcional do período não usado, calculado pelo gateway, ao trocar de plano. | limitação do gateway | não implementado |
| `SUBSCRIPTION_CREDITS` | Assinatura com saldo de créditos consumíveis, abatidos a cada uso. | não implementado | limitação do gateway |
| `MANAGES_RECURRENCE` | O gateway agenda as cobranças do Pix Automático por conta própria; sem ela, a aplicação é o motor de recorrência e chama as operações de `AutomaticPixContract` na periodicidade certa. | limitação do gateway | não implementado |

Restrições dentro de uma célula "sim":

- **`INVOICE_DUPLICATION` no Stripe** vale só para fatura Pix pendente; cartão ou fatura em outro
  estado lança `UnsupportedOperationException` com `gateway_limitation` (ver
  [Particularidades do Stripe](#particularidades-do-stripe)).
- **`INSTALLMENTS` na Iugu** é informado em `gateway_options['months']`; a lib não modela parcelas
  nem lê os campos da fatura parcelada.
- **`IDEMPOTENCY` no Stripe** cobre criação de fatura e estorno; nas demais operações de escrita a
  chave não é repassada (`IDEMPOTENCY_ALL_ENDPOINTS`).
- **`PARTIAL_REFUND_PIX` e `REFUND_BANK_SLIP`** chegam como `RefundNotSupportedException`, que
  herda de `UnsupportedOperationException` (ver [Estorno](#estorno)).
- **`MANAGES_RECURRENCE`** é informativa: diz quem agenda a cobrança do Pix Automático (ver
  [Pix Automático: quem agenda a cobrança](#pix-automático-quem-agenda-a-cobrança)).

### Status da fatura

`Invoice::$status` é o enum `Potelo\MultiPayment\Enums\InvoiceStatus`, sempre no vocabulário do
pacote; o status específico de cada gateway fica em `original`. Os treze estados:

| `InvoiceStatus` | Significado | Iugu | Stripe | Helper que responde |
|---|---|---|---|---|
| `PENDING` | Aguardando pagamento | `pending`, `draft` | PaymentIntent em `requires_payment_method`, `requires_action`, `requires_confirmation` | `isOpen()` |
| `AUTHORIZED` | Valor reservado no cartão, aguardando captura ou análise | `in_analysis`, `authorized` | PaymentIntent `requires_capture` | `isOpen()` |
| `PROCESSING` | Pagamento em processamento no gateway | (não emite) | PaymentIntent `processing` | `isOpen()` |
| `PAID` | Valor recebido | `paid` | PaymentIntent `succeeded` sem estorno nem contestação | `isSettled()` |
| `PARTIALLY_PAID` | Parte do valor recebida, restante em aberto | `partially_paid` | (não emite em venda avulsa) | `isSettled()` e `isOpen()` |
| `EXTERNALLY_PAID` | Quitada fora do gateway, por baixa manual | `externally_paid` | (não emite em venda avulsa) | `isSettled()` |
| `PARTIALLY_REFUNDED` | Estorno voluntário, parcial | `partially_refunded` | charge com `amount_refunded` menor que o total | `isSettled()` |
| `REFUNDED` | Estorno voluntário, integral | `refunded` | charge com `refunded = true` | `isTerminal()` |
| `DISPUTED` | Contestação aberta sobre fatura paga, resolução pendente | `in_protest` | charge `disputed` com dispute em `warning_needs_response`, `warning_under_review`, `needs_response` ou `under_review` | `isContested()` |
| `CHARGEBACK` | Contestação perdida: valor devolvido ao cliente pelo gateway | `chargeback` | dispute em `lost` | `isContested()` e `isTerminal()` |
| `CANCELED` | Cancelada antes do pagamento | `canceled` | PaymentIntent `canceled` | `isTerminal()` |
| `EXPIRED` | Venceu sem pagamento | `expired` | (não emite em venda avulsa) | `isTerminal()` |
| `UNKNOWN` | Status que a lib não reconhece | qualquer outro | qualquer outro | nenhum responde verdadeiro |

Dispute ganha (`won`), encerrada sem virar chargeback (`warning_closed`) ou prevenida
(`prevented`) não altera o status: a fatura volta a ler como `PAID` (ou como estornada, se
houve estorno). Um status fora do mapa vira `UNKNOWN`, com um aviso no log da aplicação (nível
`warning`) contendo o valor original e o gateway, e o valor cru continua em `original`.

Os helpers do enum respondem às perguntas de negócio sem comparar status um a um:

```php
use Potelo\MultiPayment\Enums\InvoiceStatus;

$invoice->status->isSettled();    // recebi dinheiro? PAID, PARTIALLY_PAID, EXTERNALLY_PAID, PARTIALLY_REFUNDED
$invoice->status->isOpen();       // ainda pode receber pagamento? PENDING, AUTHORIZED, PROCESSING, PARTIALLY_PAID
$invoice->status->isContested();  // tem briga? DISPUTED, CHARGEBACK
$invoice->status->isTerminal();   // acabou? REFUNDED, CHARGEBACK, CANCELED, EXPIRED

match ($invoice->status) {
    InvoiceStatus::DISPUTED => $this->openDisputeTicket($invoice),
    InvoiceStatus::CHARGEBACK => $this->writeOff($invoice),
    InvoiceStatus::EXPIRED => $this->offerNewPix($invoice),
    default => null,
};
```

`PARTIALLY_PAID` responde verdadeiro a `isSettled()` e a `isOpen()` ao mesmo tempo: parte do
dinheiro entrou e o restante segue cobrável. Os helpers estáticos `Invoice::isSettled()` e
`Invoice::isContested()` continuam existindo, delegam ao enum e estão obsoletos (emitem
`E_USER_DEPRECATED`).

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

### Migração das constantes para enum

Status da fatura, método de pagamento e intervalo do plano são enums do namespace
`Potelo\MultiPayment\Enums`: `InvoiceStatus`, `PaymentMethod` (`CREDIT_CARD`, `BANK_SLIP`,
`PIX`, `AUTOMATIC_PIX`) e `PlanInterval` (`DAY`, `WEEK`, `MONTH`, `YEAR`). As propriedades
`Invoice::$status`, `Invoice::$paymentMethod`, `Invoice::$availablePaymentMethods`,
`Subscription::$paymentMethod`, `Subscription::$availablePaymentMethods` e `Plan::$interval`
devolvem o enum na leitura e aceitam, na escrita, tanto o caso do enum quanto a string do valor
(as constantes antigas). O mesmo vale para `fill()` e para os builders.

As constantes antigas (`Invoice::STATUS_*`, `Invoice::PAYMENT_METHOD_*`, `Plan::INTERVAL_*`)
continuam existindo, com os mesmos valores de string dos enums, e estão marcadas como
`@deprecated`. O que muda é a **comparação**: a propriedade agora devolve um enum, então
compará-la diretamente com a string antiga é sempre falso.

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
com aviso no log; em `paymentMethod`, `availablePaymentMethods` e `interval`, lança
`ModelAttributeValidationException` na escrita, com a lista de valores aceitos. Em
`availablePaymentMethods` só entram `CREDIT_CARD`, `BANK_SLIP` e `PIX`; `AUTOMATIC_PIX` é
recusado na validação, porque a fatura com Pix Automático é criada com `PIX` e o objeto
`automaticPix` preenchido. Nenhum driver emite `AUTOMATIC_PIX` em `paymentMethod` hoje.

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
  tokenizar. Para decidir o fallback programaticamente, use `ChargingException::$reason`,
  que traz a razão normalizada da recusa (`card_declined`, `brand_not_supported`,
  `authentication_required`, `expired_card`, `insufficient_funds`, `incorrect_cvc`...).
  `GatewayNotAvailableException` também sinaliza "tente outro gateway"; `AuthenticationException`
  sinaliza credencial errada e não deve gerar fallback (ver [Tratamento de erros](#tratamento-de-erros)).
- **Cartão salvo não garante cobrança futura.** Salvar o cartão (`newCreditCard()->create()`)
  faz só o `attach` do PaymentMethod ao cliente, sem autenticar com o emissor. Um cartão que
  exige autenticação (3DS) é salvo normalmente e recusado na primeira cobrança `off_session`,
  com `ChargingException::$reason` igual a `authentication_required`. Essa razão pede ação do
  pagador (autenticar o cartão ou informar outro); o gateway respondeu normalmente e não cabe
  fallback. Autenticar no momento de salvar (SetupIntent) está planejado para uma versão futura.
- **Pix exige `tax_document` do cliente** (CPF/CNPJ vai nos billing details do pagamento).
- **`expires_at` do pix é opcional** (default do Stripe: 4 horas) e, quando informado, deve
  ficar entre 10 segundos e 14 dias no futuro — diferente da Iugu, onde `expires_at` é a
  data de vencimento e é obrigatório para pix/boleto.
- **Pix expirado continua pendente e re-cobrável.** Na Iugu, fatura expirada vira `canceled`;
  no Stripe ela volta a aguardar pagamento (`pending`) e pode ser paga com cartão via
  `chargeInvoiceWithCreditCard` ou duplicada com `duplicateInvoice` (nova expiração;
  a original é cancelada). Só fatura Pix pendente é duplicável: cartão ou fatura em outro
  estado lança `UnsupportedOperationException` (`INVOICE_DUPLICATION`, `gateway_limitation`).
- **`url` da fatura**: no pix é a página hospedada com instruções de pagamento
  (`hosted_instructions_url`); em fatura de cartão é `null` — não assuma `url` preenchida
  como na Iugu (`secure_url`).
- **`fee` é assíncrono para cartão**: pode vir `null` logo após a cobrança e preenchido em um
  `getInvoice` posterior.
- **Contestação custa uma requisição a mais.** O charge da Stripe só traz a flag `disputed`;
  quando ela é verdadeira, o pacote consulta `/v1/disputes` do charge para decidir entre
  `disputed` e `chargeback` (ver [Status da fatura](#status-da-fatura)). Fatura sem contestação
  não paga esse GET.
- **Idempotência**: envie `gateway_options['idempotency_key']` (ou `$invoice->gatewayOptions`)
  na criação de faturas e estornos para repassar o cabeçalho `Idempotency-Key` da Stripe.

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

## Tratamento de erros

Toda exceção do pacote herda de `MultiPaymentException`. Nenhuma exceção dos SDKs da Iugu ou da
Stripe sai do pacote: os drivers traduzem cada falha para uma das classes abaixo, anexam a
exceção original em `getPrevious()` (quando o SDK lançou uma; a Iugu devolve alguns erros como
corpo JSON sem exceção) e expõem o status HTTP da resposta em `httpStatus` (nulo quando não
houve resposta HTTP, como numa falha de rede ou numa validação local).

| Exceção | Quando | O que fazer |
|---|---|---|
| `AuthenticationException` | Chave de API inválida, revogada, sem permissão (401 ou 403) ou não configurada | Registrar e alertar. Repetir a chamada ou trocar de gateway não resolve |
| `GatewayNotAvailableException` | Erro 5xx, falha de conexão ou timeout | Repetir mais tarde ou tentar outro gateway |
| `ChargingException` | Cobrança recusada pelo gateway (cartão negado etc.); `reason` traz a razão normalizada quando o gateway a informa | Tratar como recusa do pagador; `reason` decide o fallback |
| `UnsupportedOperationException` | Operação fora das capabilities do gateway, antes de qualquer requisição; `capability`, `gateway` e `reason` (`not_implemented` ou `gateway_limitation`) dizem qual e por quê | Rotear para um gateway que declare a capability; melhor ainda, consultar `supports()` antes (ver [Capabilities](#capabilities)) |
| `RefundNotSupportedException` | Estorno recusado pela lib antes de chamar o gateway (boleto, Pix parcial, já estornada, prazo vencido); herda de `UnsupportedOperationException` e refina `reason` | Ver [Estorno](#estorno) |
| `ModelAttributeValidationException` | Atributo obrigatório ausente ou inválido, antes de qualquer requisição | Corrigir a chamada |
| `ConfigurationException` | Gateway não configurado ou classe inválida | Corrigir a configuração |
| `GatewayException` | Qualquer outra resposta de erro do gateway (validação, 404, 409, 429); `getErrors()` traz o corpo de erro | Depende do caso; `httpStatus` e `getErrors()` dizem o que aconteceu |

```php
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\ChargingException;
use Potelo\MultiPayment\Exceptions\AuthenticationException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;

try {
    $invoice = $payment->newInvoice()->/* ... */->create();
} catch (ChargingException $e) {
    return back()->withErrors('Pagamento recusado.');
} catch (UnsupportedOperationException $e) {
    report($e);            // $e->capability e $e->gateway dizem o que faltou; nada foi enviado
    return $this->chargeOn('iugu');
} catch (AuthenticationException $e) {
    report($e);            // credencial errada: alerta, sem retry e sem fallback
    abort(500);
} catch (GatewayNotAvailableException $e) {
    return $this->queueForRetry();
} catch (GatewayException $e) {
    if ($e->httpStatus === 429) {
        return $this->retryLater();
    }
    report($e);            // $e->getPrevious() é a exceção do SDK, com stack trace e corpo
    throw $e;
}
```

Rate limit (429) e conflito de idempotência (409) ainda chegam como `GatewayException`; o status
está em `httpStatus` para a aplicação ramificar. Exceções próprias para esses casos estão
previstas para uma versão futura.

> **Mudança de comportamento (versão 5.0.0).** Até a 4.1.0, credencial inválida chegava como
> `GatewayNotAvailableException` (Stripe e chave Iugu não configurada) ou como `GatewayException`
> genérica (chave Iugu recusada com 401), e um cartão inválido no caminho de dados crus da Iugu
> podia deixar escapar uma `IuguRequestException` do SDK. Agora os três casos lançam
> `AuthenticationException` ou `GatewayException` do pacote. Quem repetia toda
> `GatewayNotAvailableException` deixa de repetir credencial errada; quem capturava
> `GatewayException` para chave recusada na Iugu precisa capturar `AuthenticationException`.
>
> Na mesma versão, operação não suportada ou ainda não implementada (boleto, Pix Automático,
> assinatura e plano no Stripe; cancelamento ao fim do período, desconto percentual e desativação
> de plano na Iugu; cartão com dados crus e duplicação fora de Pix pendente no Stripe) deixou de
> chegar como `GatewayException` (ou `GatewayException::methodNotFound`) e passou a lançar
> `UnsupportedOperationException`, que herda de `MultiPaymentException`. Um
> `catch (GatewayException $e)` sozinho deixa de capturar esses casos.

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
#### InvoiceBuilder
```php
use Potelo\MultiPayment\Enums\PaymentMethod;

$multiPayment = new \Potelo\MultiPayment\MultiPayment('iugu');
$invoiceBuilder = $multiPayment->newInvoice();
$invoice = $invoiceBuilder->addAvailablePaymentMethod(PaymentMethod::PIX) // ou a string 'pix'
    ->addCustomer('name', 'email', 'tax_document', 'phone_area', 'phone_number')
    ->addCustomerAddress('zip_code', 'street', 'number')
    ->addItem('description', 'price', 'quantity')
    ->create();
```
Confira `src/MultiPayment/Builders/InvoiceBuilder.php` para saber quais métodos estão disponíveis.

#### Pix Automático

O Pix Automático está disponível no gateway Iugu. No Stripe ele ainda **não está implementado
nesta lib** (planejado para uma versão futura; a conta Stripe da empresa também aguarda a
liberação do recurso). Até lá, todas as operações de Pix Automático no Stripe, inclusive criar
fatura com `automatic_pix`, lançam `UnsupportedOperationException` com `capability`
`AUTOMATIC_PIX` e `reason` `not_implemented`, antes de qualquer requisição.

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

Os dois gateways dividem a responsabilidade pela recorrência de forma oposta, e a lib ainda não
expõe essa diferença em código (uma capability declarada pelo gateway está planejada para uma
versão futura). Até lá, a regra é esta:

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
  início do mandato precisa respeitar esse prazo de três dias.

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

Assinatura recorrente está disponível no gateway Iugu. No Stripe ela ainda **não está
implementada nesta lib** (planejada para uma versão futura; o Stripe Billing oferece o
recurso). O `StripeGateway` lista `SUBSCRIPTIONS` e `PLANS` em `notYetImplemented()`, então
`save()`, `get()`, os métodos de domínio (`suspend()`, `resume()`, `cancel()`, `changePlan()`,
`previewPlanChange()`) e `listSubscriptions()`/`listPlans()` lançam
`UnsupportedOperationException` com `reason` `not_implemented`, antes de qualquer requisição.

```php
use Potelo\MultiPayment\Models\Plan;
use Potelo\MultiPayment\Enums\PlanInterval;

$plan = new Plan();
$plan->name = 'Mensal';
$plan->identifier = 'plano_mensal';
$plan->amount = 10000; // centavos
$plan->interval = PlanInterval::MONTH; // DAY, WEEK, MONTH ou YEAR (a Iugu recusa DAY)
$plan->intervalCount = 1;
$plan->save('iugu');

$subscription = (new \Potelo\MultiPayment\MultiPayment('iugu'))
    ->newSubscription()
    ->setPlanId('plano_mensal')
    ->setCustomerId($customer->id)
    ->setNextBillingAt('2026-10-01')
    ->addItem('Consultas extras', 2500, 2)         // item recorrente, valor em centavos
    ->addAmountDiscount('Promo', 500, cycles: 1)   // desconto só na próxima fatura
    ->setAvailablePaymentMethods(['pix'])
    ->create();

echo $subscription->status; // na Iugu: trialing, active, suspended, pending ou past_due
```

Operações sobre a assinatura:

```php
$subscription->suspend();
$subscription->resume();
$subscription->cancel();                        // na Iugu, cancelar é suspender
$subscription->changePlan('plano_anual');       // aplica a troca e gera cobrança imediata
$subscription->changePlan('plano_anual', charge: false);
$preview = $subscription->previewPlanChange('plano_anual'); // simula, não aplica

// itens e descontos são declarativos: a lista informada vira o estado da assinatura, e a lista
// que ficar em null é preservada como está no gateway
$mantido = new SubscriptionItem();
$mantido->id = $subscription->items[0]->id;
$subscription->items = [$mantido];
$subscription->save();

$assinaturas = (new \Potelo\MultiPayment\MultiPayment('iugu'))->listSubscriptions($customer->id);
$planos = (new \Potelo\MultiPayment\MultiPayment('iugu'))->listPlans();
```

Particularidades da Iugu:

- **Cancelar é suspender.** `cancel(atPeriodEnd: true)` lança `UnsupportedOperationException`
  (`CANCEL_AT_PERIOD_END`, `gateway_limitation`); para encerrar ao fim do período, suspenda na
  data.
- **Desconto é sempre valor fixo.** `percentOff` e `cycles` maior que `1` lançam
  `UnsupportedOperationException` (`NATIVE_COUPONS`, `gateway_limitation`); `cycles` aceita `1`
  (uma fatura) ou `null` (até ser removido).
- **Plano anual é 12 meses, e plano diário não existe.** A Iugu só tem intervalos em semanas e
  meses, então `PlanInterval::YEAR` é enviado como `12 * intervalCount` meses e
  `PlanInterval::DAY` lança `GatewayException` antes de chamar a API. Na leitura vale a
  heurística inversa: todo plano em meses cujo intervalo é múltiplo de 12 volta como `YEAR` com
  `intervalCount` dividido por 12 (um plano criado direto na Iugu com 24 meses lê como 2 anos).
  Quem precisar do valor cru lê `original`. A Iugu aceita intervalo de 1 a 599, então um plano
  anual vai até `intervalCount` 49; acima disso o driver lança `GatewayException` antes de
  chamar a API.
- **Planos não são desativáveis.** `deactivatePlan` lança `UnsupportedOperationException`
  (`PLAN_DEACTIVATION`, `gateway_limitation`).
- **`nextBillingAt` e `trialEndsAt` são o mesmo campo** (`expires_at`); informar os dois com
  datas diferentes lança `GatewayException`. Ao prorrogar um trial lido do gateway, zere
  `nextBillingAt` antes, porque a leitura preenche os dois.
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
- **`paymentMethod`, `cancelAtPeriodEnd` e `canceledAt` não são mapeados** na Iugu, nas duas
  direções.
- **Reativar exige data de cobrança.** Assinatura criada sem `nextBillingAt` fica sem data no
  gateway e, por isso, não volta com `resume()`: a Iugu responde sem erro e sem mudar nada, e o
  `status` devolvido segue `suspended`.
- **`active` e `suspended` são flags independentes.** Assinatura suspensa pode continuar com
  `active: true` na Iugu; o pacote dá precedência a `suspended` e reporta `suspended`.
- **A simulação de troca não traz linhas.** `previewPlanChange()` preenche só `amount` e
  `effectiveAt`; `items` fica `null` e o resto (`discount`, `cycles`, `old_plan`, `new_plan`)
  está em `original`.
- **Trocar de plano com cobrança gera fatura pendente, não pagamento.** `changePlan()` com
  `charge: true` (o padrão) faz a Iugu emitir a fatura na hora, com vencimento imediato e não na
  data do próximo ciclo. Ela volta resumida em `latestInvoice`, com status `pending`; use
  `getInvoice()` pelo id para o valor em centavos. Com `charge: false` nada é cobrado.
- **O plano de uma assinatura existente não muda por `save()`**; use `changePlan()`.
- **Plano não é atualizável.** `save()` num `Plan` que já tem `id` lança `GatewayException`; para
  mudar preço ou intervalo, crie outro plano e troque as assinaturas com `changePlan()`.
- **Fatura vencida lê como `EXPIRED` e continua sendo dívida.** A Iugu chama de `expired` a
  fatura que venceu sem pagamento; ela conta como fatura em aberto na derivação de `past_due`
  da assinatura, embora `InvoiceStatus::EXPIRED->isOpen()` seja falso (na Iugu a fatura vencida
  ainda pode ser paga; em outros gateways, vencida é terminal).
- **A assinatura lida traz o cliente resumido.** `Subscription::get()` preenche `customer` com
  id, nome e e-mail — documento, endereço e telefone não vêm da Iugu. Eles sobrevivem se o
  `customer` local já tiver o mesmo id; se o id for outro, ou o local não tiver id, o pacote
  troca o objeto para não misturar dados de dois clientes.

No update, data de cobrança e métodos de pagamento só são enviados quando mudaram em relação
ao que veio na leitura — um `save()` que mexeu só nos itens não altera a data de cobrança.

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

#### getInvoice
```php
$invoiceId = '312ASDHGZXSGRTET312ASDHGZXSGRTET';
$payment = new \Potelo\MultiPayment\MultiPayment('iugu');
$foundInvoice = $payment->getInvoice($invoiceId);
```

#### Outras operações de fatura
```php
$payment = new \Potelo\MultiPayment\MultiPayment('stripe');

// estorno total ou parcial (valor em centavos); guardas e exceção na seção "Estorno"
$payment->refundInvoice($invoiceId);
$payment->refundInvoice($invoiceId, 5000);

// cancelamento de fatura pendente
$payment->cancelInvoice($invoiceId);

// duplicar fatura pendente com nova expiração (no Stripe: somente pix; a original é cancelada)
$payment->duplicateInvoice($invoiceId, \Carbon\Carbon::now()->addDays(3));

// cobrar uma fatura pendente com cartão (token OU id de cartão salvo)
$payment->chargeInvoiceWithCreditCard($invoiceId, 'pm_...');
$payment->chargeInvoiceWithCreditCard($invoiceId, null, $creditCardId);
```

#### Estorno

Sem valor, o estorno é integral; com valor em centavos, é parcial. O pacote recusa, **antes de
chamar o gateway**, o estorno que a regra do gateway já garante que seria negado, e o faz com
`RefundNotSupportedException` nos dois drivers, para a aplicação não precisar interpretar a
mensagem da Iugu ou da Stripe.

```php
use Potelo\MultiPayment\Exceptions\RefundNotSupportedException;

$payment = new \Potelo\MultiPayment\MultiPayment('iugu');

try {
    $invoice = $payment->refundInvoice($invoiceId);         // integral
    $invoice = $payment->refundInvoice($invoiceId, 5000);   // parcial

    $invoice->status;        // InvoiceStatus::REFUNDED ou InvoiceStatus::PARTIALLY_REFUNDED
    $invoice->lastRefundId;  // id do estorno no gateway (Stripe: re_...; a Iugu não devolve id)
} catch (RefundNotSupportedException $e) {
    // a lib recusou sem chamar o gateway; $e->reason diz por quê
    if ($e->manualRefundRequired) {
        // boleto ou prazo vencido: o dinheiro só volta por fora do gateway
        ManualRefund::dispatch($invoiceId, $e->paymentMethod, $e->reason);
    }
}
```

| `$e->reason` | Quando | `$e->manualRefundRequired` |
|---|---|---|
| `boleto_no_refund` | Fatura paga com boleto, nos dois gateways | `true` |
| `pix_partial_not_supported` | Iugu: valor pedido diferente do valor pago numa fatura Pix. Repita sem valor para estornar o total | `false` |
| `already_refunded` | Fatura já lida como `refunded` | `false` |
| `refund_window_expired` | Iugu: depois do fim do 90º dia após `paidAt` | `true` |

Na Iugu, as guardas precisam do método de pagamento, do status, da data de pagamento e, no
estorno por valor, do valor pago. Chamar `refundInvoice($id)` só com o id custa **um GET a mais**
para ler a fatura antes do estorno; chamar `$invoice->refund()` num model já lido do gateway e já
pago não paga esse GET. Essa leitura não altera o model do chamador: ele só muda quando o
estorno acontece. No Stripe não há leitura prévia: as guardas usam o que já está no model, e o
estorno parcial de Pix é aceito.

`lastRefundId` é preenchido só pela operação de estorno (a leitura da fatura o deixa `null`) e é
provisório: dá lugar a um objeto `Refund` numa versão futura.

> **Mudança de comportamento (versão 5.0.0).** Até a 4.1.0, estorno de boleto, Pix parcial,
> fatura já estornada e fora do prazo de 90 dias na Iugu iam até a API e voltavam como
> `GatewayException` com a mensagem do gateway. Agora lançam `RefundNotSupportedException`, que herda de `UnsupportedOperationException` (e por ela de `MultiPaymentException`) e **não** de
> `GatewayException`: um `catch (GatewayException $e)` sozinho deixa de capturar esses casos.

#### charge

```php  
$options = [
    'amount' => 10000,
    'customer' => [
        'name' => 'Nome do cliente',
        'email' => 'email@example.com',
        'tax_document' => '12345678901',
        'phone_area' => '71',
        'phone_number' => '999999999',
        'address' => [ 
            'street' => 'Rua do cliente',
            'number' => '123',
            'complement' => 'Apto. 123',
            'district' => 'Bairro do cliente',
            'city' => 'Cidade do cliente',
            'state' => 'SP',
            'zip_code' => '12345678',
        ],
    ],
    'items' => [
        [
            'description' => 'Produto 1',
            'quantity' => 1,
            'price' => 10000,
        ],
        [ 
            'description' => 'Produto 2',
            'quantity' => 2,
            'price' => 5000,
        ],
    ],
    'payment_method' => 'credit_card',
    'credit_card' => [
        'number' => '1234567890123456',
        'month' => '12',
        'year' => '2022',
        'cvv' => '123',
        'first_name' => 'João',
        'last_name' => 'Maria' 
    ],
];

$payment = new \Potelo\MultiPayment\MultiPayment();
$payment->setGateway('iugu')->charge($options);
```

| atributo                      | obrigatório                                                         | tipo                           | descrição                                 | exemplo                               |
|-------------------------------|---------------------------------------------------------------------|--------------------------------|-------------------------------------------|---------------------------------------|
| `amount`                      | **obrigatório** caso `items` não seja informado                     | int                            | valor em centavos                         | `10000`                               |
| `customer`                    | **obrigatório**                                                     | array                          | array com os dados do cliente             | `['name' => 'Nome do cliente'...]`    |
| `customer.name`               | **obrigatório**                                                     | string                         | nome do cliente                           | `'Nome do cliente'`                   |
| `customer.email`              | **obrigatório**                                                     | string                         | email do cliente                          | `'joaomaria@email.com'`               |
| `customer.tax_document`       | **obrigatório** no Stripe para faturas pix                          | string                         | cpf ou cnpj do cliente                    | `'12345678901'`                       |
| `birth_date`                  |                                                                     | string formato `yyyy-mm-dd`    | data de nascimento                        | `'01/01/1990'`                        |
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
| `payment_method`              |                                                                     | `PaymentMethod` ou a string `'credit_card'`, `'bank_slip'`, `'pix'` | método de pagamento                | `'credit_card'`                       |
| `available_payment_methods`   | **obrigatório** no Stripe (exatamente um método) quando não há `credit_card` | array de `PaymentMethod` ou de strings | métodos aceitos pela fatura               | `['pix']`                             |
| `expires_at`                  | **obrigatório** na Iugu caso `payment_method` seja `'bank_slip'` ou `'pix'`; opcional no Stripe (pix — a data precisa cair na janela de 10 segundos a 14 dias no futuro) | string no formato `yyyy-mm-dd` | data de expiração da fatura               | `2021-10-10`                          |
| `credit_card`                 | **obrigatório** caso `payment_method` seja `'credit_card'`          | array                          | array com os dados do cartão de crédito   | `['number' => '1234567890123456',...` |
| `credit_card.token`           |                                                                     | string                         | token do cartão para o gateway escolhido  | `'abc123...'` (Iugu) / `'pm_...'` (Stripe) |
| `credit_card.number`          | **obrigatório** caso `token` não tenha sido informado (somente Iugu — o Stripe é token-only) | string                         | número do cartão de crédito               | `'1234567890123456'`                  |
| `credit_card.month`           | **obrigatório** caso `token` não tenha sido informado (somente Iugu) | string                         | mês de expiração do cartão de crédito     | `'12'`                                |
| `credit_card.year`            | **obrigatório** caso `token` não tenha sido informado (somente Iugu) | string                         | ano de expiração do cartão de crédito     | `'2022'`                              |
| `credit_card.cvv`             | **obrigatório** caso `token` não tenha sido informado (somente Iugu) | string                         | código de segurança do cartão de crédito  | `'123'`                               |
| `credit_card.first_name`      |                                                                     | string                         | primeiro nome no cartão de crédito        | `'João'`                              |
| `credit_card.last_name`       |                                                                     | string                         | último nome no cartão de crédito          | `'Maria'`                             |
| `bank_slip`                   |                                                                     | array                          | array com os dados do boleto              | `['expires_at' => '2022-12-31',...`   |
| `gateway_options`             |                                                                     | array                          | opções específicas do gateway mescladas ao payload (ver [Opções extras do gateway](#opções-extras-do-gateway)) | `['expires_in' => 3]`                 |

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
$invoice->paymentMethod = PaymentMethod::CREDIT_CARD; // a string 'credit_card' também é aceita
$invoice->creditCard = new CreditCard();
$invoice->creditCard->number = '4111111111111111';
$invoice->creditCard->firstName = 'João';
$invoice->creditCard->lastName = 'Silva';
$invoice->creditCard->month = '11';
$invoice->creditCard->year = '2022';
$invoice->creditCard->cvv = '123';
$invoice->creditCard->customer = $customer;
$invoice->save('iugu');
echo $invoice->id; // CB1FA9B5BD1C42B287F4AC7F6259E45D
```
#### Subscription
```php
$subscription = new Subscription();
$subscription->planId = 'plano_mensal';
$subscription->customer = $customer;
$subscription->save('iugu');
echo $subscription->id;
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

