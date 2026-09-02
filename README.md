## Introdução

MultiPayment permite gerenciar pagamentos de diversos gateways de pagamento. Atualmente suporta Iugu e Stripe.

- [Introdução](#introdução)
- [Requisitos](#requisitos)
- [Instalação](#instalação)
- [Configuração](#configuração)
- [Gateways](#gateways)
  - [Suporte por gateway](#suporte-por-gateway)
  - [Status da fatura](#status-da-fatura)
  - [Particularidades do Stripe](#particularidades-do-stripe)
- [Utilizando](#utilizando)
  - [MultiPayment](#multipayment)
    - [InvoiceBuilder](#invoicebuilder)
    - [Pix Automático](#pix-automático)
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

### Suporte por gateway

| Operação | Iugu | Stripe |
|---|---|---|
| Fatura com cartão de crédito | ✅ | ✅ (token-only) |
| Fatura com pix | ✅ | ✅ |
| Fatura com boleto | ✅ | ❌ lança `GatewayException` |
| Fatura multi-método (`available_payment_methods` com mais de um) | ✅ | ❌ exatamente 1 método por fatura |
| Estorno de cartão (total e parcial) | ✅ | ✅ |
| Estorno de Pix | ✅ somente integral; parcial lança `RefundNotSupportedException` | ✅ total e parcial |
| Estorno de boleto | ❌ lança `RefundNotSupportedException` (devolução manual) | ❌ lança `RefundNotSupportedException` (devolução manual) |
| Cancelamento | ✅ | ✅ |
| Duplicar fatura (`duplicateInvoice`) | ✅ | ✅ somente pix pendente |
| Cobrar fatura pendente com cartão | ✅ | ✅ (inclusive pix expirado) |
| Customer (criar/atualizar/buscar) e cartões salvos | ✅ | ✅ |
| Pix Automático | ✅ | 🚧 em desenvolvimento |
| Assinatura (criar, buscar, atualizar, suspender, retomar, cancelar, listar) | ✅ | 🚧 em desenvolvimento |
| Cancelar assinatura ao fim do período (`cancel(atPeriodEnd: true)`) | ❌ lança `GatewayException` | 🚧 em desenvolvimento |
| Troca de plano e simulação (`changePlan`, `previewPlanChange`) | ✅ | 🚧 em desenvolvimento |
| Desconto na assinatura | ✅ somente valor fixo (`amountOff`), com `cycles` 1 ou `null` | 🚧 em desenvolvimento |
| Plano (criar, buscar, listar) | ✅ (`year` é enviado como 12 meses) | 🚧 em desenvolvimento |
| Desativar plano (`deactivatePlan`) | ❌ lança `GatewayException` | 🚧 em desenvolvimento |

🚧 = ainda não implementado no gateway; hoje a chamada lança `GatewayException`.

### Status da fatura

`Invoice::$status` usa sempre o vocabulário do pacote; o status específico de cada gateway fica
em `original`. Mapa atual:

| Status genérico | Significado | Iugu | Stripe |
|---|---|---|---|
| `pending` | Aguardando pagamento | `pending`, `in_analysis`, `draft`, `partially_paid` | PaymentIntent em `processing`, `requires_action`, `requires_confirmation`, `requires_payment_method`, `requires_capture` |
| `paid` | Valor recebido | `paid`, `externally_paid`, `authorized` | PaymentIntent `succeeded` sem estorno nem contestação |
| `canceled` | Cancelada ou vencida sem pagamento | `canceled`, `expired` | PaymentIntent `canceled` |
| `refunded` | Estorno voluntário, integral | `refunded` | charge com `refunded = true` |
| `partially_refunded` | Estorno voluntário, parcial | `partially_refunded` | charge com `amount_refunded` menor que o total |
| `disputed` | Contestação aberta sobre fatura paga, resolução pendente | `in_protest` | charge `disputed` com dispute em `warning_needs_response`, `warning_under_review`, `needs_response` ou `under_review` |
| `chargeback` | Contestação perdida: valor devolvido ao cliente pelo gateway. Terminal | `chargeback` | dispute em `lost` |

Dispute ganha (`won`), encerrada sem virar chargeback (`warning_closed`) ou prevenida
(`prevented`) não altera o status: a fatura volta a ler como `paid` (ou como estornada, se
houve estorno). Um status fora do mapa lança `GatewayException`. Estados próprios para captura
tardia, vencimento e pagamento parcial estão planejados para uma versão futura.

Para não comparar status um a um, a `Invoice` traz dois helpers estáticos:

```php
Invoice::isSettled($invoice->status);   // recebi o dinheiro? paid ou partially_refunded
Invoice::isContested($invoice->status); // tem briga aberta? disputed ou chargeback
```

> **Mudança de comportamento (versão 5.0.0).** Até a 4.1.0, fatura Iugu em `in_protest` lia como
> `paid` e fatura em `chargeback` lia como `refunded`; no Stripe, charge contestado lia como
> `paid`. A partir desta versão elas leem como `disputed` e `chargeback`. Quem compara com
> `Invoice::STATUS_PAID` para decidir se recebeu **deixa de ver faturas em disputa como pagas**,
> e quem compara com `STATUS_REFUNDED` deixa de confundir chargeback com estorno voluntário. Se
> a aplicação precisava do comportamento antigo, use `Invoice::isSettled()` para "pago" e trate
> `disputed` e `chargeback` explicitamente.

### Particularidades do Stripe

- **Cartão é token-only.** O Stripe não aceita dados crus de cartão pela API (exigiria
  liberação de "raw card data" e escopo PCI SAQ D). Tokenize o cartão no navegador com
  Stripe.js e envie o id resultante (`pm_...`) em `credit_card.token` / `CreditCard::$token`
  (tokens legados `tok_...` também são aceitos). O caminho com `number`/`cvv` lança
  `GatewayException` orientando o uso de token.
- **Bandeiras aceitas no Brasil: somente Visa e Mastercard crédito.** Elo, Hipercard, Amex e
  débito nacional não são suportados pelo Stripe BR. Para essas bandeiras, roteie a cobrança
  para outro gateway (ex.: Iugu) — de preferência detectando a bandeira pelo BIN antes de
  tokenizar. Para decidir o fallback programaticamente, use `ChargingException::$reason`,
  que traz a razão normalizada da recusa (`card_declined`, `brand_not_supported`,
  `authentication_required`, `expired_card`, `insufficient_funds`, `incorrect_cvc`...).
  `GatewayNotAvailableException` também sinaliza "tente outro gateway".
- **Pix exige `tax_document` do cliente** (CPF/CNPJ vai nos billing details do pagamento).
- **`expires_at` do pix é opcional** (default do Stripe: 4 horas) e, quando informado, deve
  ficar entre 10 segundos e 14 dias no futuro — diferente da Iugu, onde `expires_at` é a
  data de vencimento e é obrigatório para pix/boleto.
- **Pix expirado continua pendente e re-cobrável.** Na Iugu, fatura expirada vira `canceled`;
  no Stripe ela volta a aguardar pagamento (`pending`) e pode ser paga com cartão via
  `chargeInvoiceWithCreditCard` ou duplicada com `duplicateInvoice` (nova expiração;
  a original é cancelada).
- **`url` da fatura**: no pix é a página hospedada com instruções de pagamento
  (`hosted_instructions_url`); em fatura de cartão é `null` — não assuma `url` preenchida
  como na Iugu (`secure_url`).
- **`fee` é assíncrono para cartão**: pode vir `null` logo após a cobrança e preenchido em um
  `getInvoice` posterior.
- **Contestação custa uma requisição a mais.** O charge da Stripe só traz a flag `disputed`;
  quando ela é verdadeira, o pacote consulta `/v1/disputes` do charge para decidir entre
  `disputed` e `chargeback` (ver [Status da fatura](#status-da-fatura)). Fatura sem contestação
  não paga esse GET.
- **Idempotência**: envie `gateway_adicional_options['idempotency_key']` na criação de
  faturas e estornos para repassar o cabeçalho `Idempotency-Key` da Stripe.

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
$multiPayment = new \Potelo\MultiPayment\MultiPayment('iugu');
$invoiceBuilder = $multiPayment->newInvoice();
$invoice = $invoiceBuilder->setPaymentMethod('payment_method')
    ->addCustomer('name', 'email', 'tax_document', 'phone_area', 'phone_number')
    ->addCustomerAddress('zip_code', 'street', 'number')
    ->addItem('description', 'quantity', 'price')
    ->create();
```
Confira `src/MultiPayment/Builders/InvoiceBuilder.php` para saber quais métodos estão disponíveis.

#### Pix Automático

O Pix Automático está disponível no gateway Iugu. No Stripe o suporte está **pendente** (aguardando a habilitação do recurso na conta): todas as operações de Pix Automático — inclusive criar fatura com `automatic_pix` — lançam `GatewayException` com mensagem "not yet implemented" até que essa integração seja concluída.

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

Assinatura recorrente está disponível no gateway Iugu. No Stripe as operações ainda não existem
e o `StripeGateway` não implementa `SubscriptionContract` nem `PlanContract`: `save()` e `get()`
lançam `GatewayException::methodNotFound`, e os métodos de domínio (`suspend()`, `resume()`,
`cancel()`, `changePlan()`, `previewPlanChange()`) lançam `GatewayException` avisando que o
gateway não implementa o contract.

```php
use Potelo\MultiPayment\Models\Plan;

$plan = new Plan();
$plan->name = 'Mensal';
$plan->identifier = 'plano_mensal';
$plan->amount = 10000; // centavos
$plan->interval = Plan::INTERVAL_MONTH; // week, month ou year
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

- **Cancelar é suspender.** `cancel(atPeriodEnd: true)` lança `GatewayException`; para encerrar
  ao fim do período, suspenda na data.
- **Desconto é sempre valor fixo.** `percentOff` lança `GatewayException`, e `cycles` só aceita
  `1` (uma fatura) ou `null` (até ser removido).
- **Plano anual é 12 meses.** A Iugu só tem intervalos em semanas e meses, então
  `Plan::INTERVAL_YEAR` é enviado como `12 * intervalCount` meses. Na leitura vale a heurística
  inversa: todo plano em meses cujo intervalo é múltiplo de 12 volta como `year` com
  `intervalCount` dividido por 12 (um plano criado direto na Iugu com 24 meses lê como 2 anos).
  Quem precisar do valor cru lê `original`. A Iugu aceita intervalo de 1 a 599, então um plano
  anual vai até `intervalCount` 49; acima disso o driver lança `GatewayException` antes de
  chamar a API.
- **Planos não são desativáveis.** `deactivatePlan` lança `GatewayException`.
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
- **Fatura vencida lê como `canceled`.** A Iugu chama de `expired` a fatura que venceu sem
  pagamento, e o pacote a mapeia para `Invoice::STATUS_CANCELED` — mas ela ainda conta como
  dívida na derivação de `past_due`. Para decidir se há pendência, olhe o `status` da assinatura,
  não o da fatura.
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

    $invoice->status;        // refunded ou partially_refunded
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
> `GatewayException` com a mensagem do gateway. Agora lançam `RefundNotSupportedException`, que herda de `MultiPaymentException` e **não** de
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
| `payment_method`              |                                                                     | `'credit_card'`,`'bank_slip'`,`'pix'` | método de pagamento                | `'credit_card'`                       |
| `available_payment_methods`   | **obrigatório** no Stripe (exatamente um método) quando não há `credit_card` | array de métodos               | métodos aceitos pela fatura               | `['pix']`                             |
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
$invoice = new Invoice();
$invoice->customer = $customer;
$item = new InvoiceItem();
$item->description = 'Teste';
$item->price = 10000;
$item->quantity = 1;
$invoice->items[] = $item;
$invoice->paymentMethod = Invoice::PAYMENT_METHOD_CREDIT_CARD;
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
$plan->interval = Plan::INTERVAL_MONTH;
$plan->save('iugu');
echo $plan->id;
```

