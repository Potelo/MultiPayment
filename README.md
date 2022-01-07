
1. [Introdução](#introdução)
2. [Instalação](#instalação)
3. [Exemplos](#exemplos)
4. [Documentação](#documentação)
   1. [MultiPayment](#multipayment)
      1. [charge](#charge)
   2. [Models](#models)
      1. [Customer](#customer)
      2. [Invoice](#invoice)



## Introdução

MultiPayment permite gerenciar pagamentos de diversos gateways de pagamento. Atualmente suporta o Iugu e Moip.

## Instalação

1. Adicione em `repositories` no composer.json o seguinte:
```json  
"repositories": [  
 { "type": "git", "url": "https://github.com/Potelo/MultiPayment.git" }]  
```  
2. Instale esse pacote pelo composer:

```  
composer require potelo/multi-payment "dev-main"  
```  

Agora, configure as variáveis necessárias para cada gateway que deseja usar no seu `.env`:

```ini  
MULTIPAYMENT_DEFAULT=iugu  
  
#iugu  
IUGU_ID=  
IUGU_APIKEY=  
  
#moip  
MOIP_APITOKEN=  
MOIP_APIKEY=  
```  

```  
php artisan vendor:publish --provider="Potelo\MultiPayment\Providers\MultiPaymentServiceProvider"  
```  
Verifique se o arquivo `multi-payment.php` foi criado no diretório `config/app`.

Opcionalmente você pode configurar o Trait, para facilitar o uso do metodo `charge` junto a um usuário.

```php  
use Potelo\MultiPayment\MultiPaymentTrait;  
  
class User extends Authenticatable  
{  
 use MultiPaymentTrait;}  
```  

## Exemplos

Utilize o método `charge` para criar uma transação de pagamento.

Usando a classe `MultiPayment`:

```php  
$payment = new \Potelo\MultiPayment\MultiPayment(); // gateway default será usado  
$payment->charge($options);  
  
// ou  
$payment = new \Potelo\MultiPayment\MultiPayment('iugu');  
$payment->charge($options);  
  
// ou  
$payment = new \Potelo\MultiPayment\MultiPayment();  
$payment->setGateway('moip')->charge($options);  
  
```  

Usando o Facade:

```php  
\Potelo\MultiPayment\MultiPayment::charge($options);  
```  

Usando o Trait:

```php  
$usuario->charge(10000, $options, 'moip');  
```  

## Documentação

### MultiPayment:
#### charge

```php  
$options = [  
    'amount' => 10000,
    'customer' => [
        'name' => 'Nome do cliente',
        'email' => 'email@example.com',
        'cpf' => '12345678901',
        'phone_number' => '11 99999-9999',
        'address' => [ 
            'street' => 'Rua do cliente',
            'number' => '123',
            'complement' => 'Apto. 123',
            'district' => 'Bairro do cliente',
            'city' => 'Cidade do cliente',
            'state' => 'SP',
            'zip_code' => '12345-678',
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
```  

| atributo                      | obrigatório                                                | tipo                           | descrição                                 | exemplo                                  |
|-------------------------------|------------------------------------------------------------|--------------------------------|-------------------------------------------|------------------------------------------|
| `amount`                      | **obrigatório** caso `items` não seja informado            | int                            | valor em centavos                         | `10000`                                  |
| `customer`                    | **obrigatório**                                            | array                          | array com os dados do cliente             | `['name' => 'Nome do cliente'...]`       |
| `customer.name`               | **obrigatório**                                            | string                         | nome do cliente                           | `'Nome do cliente'`                      |
| `customer.email`              | **obrigatório**                                            | string                         | email do cliente                          | `'joaomaria@email.com'`                  |
| `customer.tax_document`       | **obrigatório** no gateway moip                            | string                         | cpf ou cnpj do cliente                    | `'12345678901'`                          |
| `birth_date`                  |                                                            | string formato `yyyy-mm-dd`    | data de nascimento                        | `'01/01/1990'`                           |
| `customer.phone_number`       |                                                            | string                         | telefone                                  | `'999999999'`                            |
| `customer.phone_area`         |                                                            | string                         | DDD                                       | `'999999999'`                            |
| `customer.address`            | **obrigatório** para o método de pagamento `bank_slip`     | array                          | array com os dados do endereço do cliente | `['street' => 'Rua do cliente'...]`      |
| `customer.address.street`     | **obrigatório**                                            | string                         | nome da rua                               | `'Nome da rua'`                          |
| `customer.address.number`     | **obrigatório**                                            | string                         | número da casa                            | `'123'`                                  |
| `customer.address.district`   | **obrigatório**                                            | string                         | bairro                                    | `'Bairro do cliente'`                    |
| `customer.address.city`       | **obrigatório**                                            | string                         | cidade                                    | `'Salvador'`                             |
| `customer.address.state`      | **obrigatório**                                            | string                         | estado                                    | `'Bahia'`                                |
| `customer.address.complement` | **obrigatório**                                            | string                         | complemento                               | `'Apto. 123'`                            |
| `customer.address.zip_code`   | **obrigatório**                                            | string                         | cep                                       | `'12345678'`                             |
| `items`                       | **obrigatório** caso `amount` não tenha sido informado     | array                          | array com os itens da compra              | `[['description' => 'Produto 1',...`     |
| `items.description`           | **obrigatório**                                            | string                         | descrição do item                         | `'Produto 1'`                            |
| `items.quantity`              | **obrigatório**                                            | int                            | quantidade do item                        | `1`                                      |
| `items.price`                 | **obrigatório**                                            | int                            | valor do item                             | `10000`                                  |
| `payment_method`              | **obrigatório**                                            | `'credit_card'`,`'bank_slip'`  | método de pagamento                       | `'credit_card'`                          |
| `credit_card`                 | **obrigatório** caso `payment_method` seja `'credit_card'` | array                          | array com os dados do cartão de crédito   | `['number' => '1234567890123456',...`    |
| `credit_card.token`           |                                                            | string                         | token do cartão para o gateway escolhido  | `'abcdefghijklmnopqrstuvwxyz'`           |
| `credit_card.number`          | **obrigatório** caso `token` não tenha sido informado      | string                         | número do cartão de crédito               | `'1234567890123456'`                     |
| `credit_card.month`           | **obrigatório** caso `token` não tenha sido informado      | string                         | mês de expiração do cartão de crédito     | `'12'`                                   |
| `credit_card.year`            | **obrigatório** caso `token` não tenha sido informado      | string                         | ano de expiração do cartão de crédito     | `'2022'`                                 |
| `credit_card.cvv`             | **obrigatório** caso `token` não tenha sido informado      | string                         | código de segurança do cartão de crédito  | `'123'`                                  |
| `credit_card.first_name`      |                                                            | string                         | primeiro nome no cartão de crédito        | `'João'`                                 |
| `credit_card.last_name`       |                                                            | string                         | último nome no cartão de crédito          | `'Maria'`                                |
| `bank_slip`                   |                                                            | array                          | array com os dados do boleto              | `['expiration_date' => '2022-12-31',...` |
| `bank_slip.expiration_date`   |                                                            | string no formato `yyyy-mm-dd` | data de expiração do boleto               | `2021-10-10`                             |


### Models
#### Customer
```php
$customer = new Customer('iugu');
$customer->name = 'Teste';
$customer->email = 'teste@email.com';
$customer->taxDocument = '12345678901';
$customer->save();
echo $customer->id; // 7D96C7C932F2427CAF54F042345A13C60CD7
```
#### Invoice
```php
$invoice = new Invoice('iugu');
$invoice->customer = $customer;
$item = new InvoiceItem();
$item->description = 'Teste';
$item->price = 10000;
$item->quantity = 1;
$invoice->items[] = $item;
$invoice->paymentMethod = Invoice::PAYMENT_METHOD_CREDIT_CARD;
$invoice->creditCard = new CreditCard('iugu');
$invoice->creditCard->number = '4111111111111111';
$invoice->creditCard->firstName = 'João';
$invoice->creditCard->lastName = 'Silva';
$invoice->creditCard->month = '11';
$invoice->creditCard->year = '2022';
$invoice->creditCard->cvv = '123';
$invoice->creditCard->customer = $customer;
$invoice->save();
echo $invoice->id; // CB1FA9B5BD1C42B287F4AC7F6259E45D
```