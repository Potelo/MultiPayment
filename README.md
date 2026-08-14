## Introdução

MultiPayment permite gerenciar pagamentos de diversos gateways de pagamento. Atualmente suporta o Iugu.

- [Introdução](#introdução)
- [Requisitos](#requisitos)
- [Instalação](#instalação)
- [Configuração](#configuração)
- [Utilizando](#utilizando)
  - [MultiPayment](#multipayment)
    - [InvoiceBuilder](#invoicebuilder)
    - [Pix Automático](#pix-automático)
    - [CustomerBuilder](#customerbuilder)
    - [getInvoice](#getinvoice)
    - [charge](#charge)
  - [Models](#models)
    - [Customer](#customer)
    - [Invoice](#invoice)

## Requisitos
  - PHP 8.0+
  - Laravel 8.0+

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

O Pix Automático está disponível no gateway Iugu e é configurado como parte da fatura:

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

Atualmente, a sandbox responde que Pix Automático não está disponível no modo de
teste. Os cenários que dependem desse recurso estão identificados com o grupo
`iugu-sandbox-limitation` e usam um `skip` explícito com a razão da limitação. Os
testes permanecem junto das classes responsáveis pelo builder e pela facade para
que possam ser reativados quando o ambiente passar a suportar o fluxo.

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
| `customer.tax_document`       |                                                                     | string                         | cpf ou cnpj do cliente                    | `'12345678901'`                       |
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
| `payment_method`              |                                                                     | `'credit_card'`,`'bank_slip'`  | método de pagamento                       | `'credit_card'`                       |
| `expires_at`                  | **obrigatório** caso `payment_method` seja `'bank_slip'` ou `'pix'` | string no formato `yyyy-mm-dd` | data de expiração da fatura               | `2021-10-10`                          |
| `credit_card`                 | **obrigatório** caso `payment_method` seja `'credit_card'`          | array                          | array com os dados do cartão de crédito   | `['number' => '1234567890123456',...` |
| `credit_card.token`           |                                                                     | string                         | token do cartão para o gateway escolhido  | `'abcdefghijklmnopqrstuvwxyz'`        |
| `credit_card.number`          | **obrigatório** caso `token` não tenha sido informado               | string                         | número do cartão de crédito               | `'1234567890123456'`                  |
| `credit_card.month`           | **obrigatório** caso `token` não tenha sido informado               | string                         | mês de expiração do cartão de crédito     | `'12'`                                |
| `credit_card.year`            | **obrigatório** caso `token` não tenha sido informado               | string                         | ano de expiração do cartão de crédito     | `'2022'`                              |
| `credit_card.cvv`             | **obrigatório** caso `token` não tenha sido informado               | string                         | código de segurança do cartão de crédito  | `'123'`                               |
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
