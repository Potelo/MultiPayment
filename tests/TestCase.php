<?php
namespace Potelo\MultiPayment\Tests;

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Potelo\MultiPayment\Providers\MultiPaymentServiceProvider;

class TestCase extends \Orchestra\Testbench\TestCase
{
    public function __construct(?string $name = null, array $data = [], $dataName = '')
    {
        parent::__construct($name, $data, $dataName);
        \Iugu::setLogErrors(false);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // pausa para evitar problemas com o Iugu
        sleep(12);
    }

    protected function getPackageProviders($app): array
    {
        return [
            MultiPaymentServiceProvider::class,
        ];
    }

    public static function customerWithoutAddress(): array
    {
        $customer['name'] = 'Fake Customer';
        $customer['email'] = 'email@exemplo.com';
        $customer['taxDocument'] = '20176996915';
        $customer['birthDate'] = Carbon::createFromFormat('Y-m-d', '1980-01-01');
        $customer['phoneArea'] = '71';
        $customer['phoneNumber'] = '982345678';
        return $customer;
    }

    public static function companyWithAddress(): array
    {
        $customer = self::customerWithoutAddress();
        $customer['address'] = self::address();
        return $customer;
    }

    public static function companyWithoutAddress(): array
    {
        $customer['name'] = 'Fake Company';
        $customer['email'] = 'email@exemplo.com';
        $customer['taxDocument'] = '28585583000189';
        return $customer;
    }

    public static function customerWithAddress(): array
    {
        $customer = self::customerWithoutAddress();
        $customer['address'] = self::address();
        return $customer;
    }

    public static function address(): array
    {
        $address['zipCode'] = '41820330';
        $address['street'] = 'Rua Deputado Mário Lima';
        $address['number'] = '123';
        $address['district'] = 'Caminho das Árvores';
        $address['complement'] = 'Apto. 123';
        $address['city'] = 'Salvador';
        $address['state'] = 'BA';
        $address['country'] = 'Brasil';
        return $address;
    }

    public static function creditCard(): array
    {
        $creditCard['number'] = '4111111111111111';
        $creditCard['month'] = '12';
        $creditCard['year'] = \Carbon\Carbon::now()->addYear()->format('Y');
        $creditCard['cvv'] = '123';
        $creditCard['firstName'] = 'Faker';
        $creditCard['lastName'] = 'Teste';
        $creditCard['description'] = 'card description';
        $creditCard['default'] = true;
        return $creditCard;
    }

    public static function iuguCreditCardToken() {
        $creditCard = self::creditCard();
        \Iugu::setApiKey(Config::get('multi-payment.gateways.iugu.api_key'));
        return \Iugu_PaymentToken::create([
            'account_id' => Config::get('multi-payment.gateways.iugu.id'),
            'method' => 'credit_card',
            'test' => Config::get('multi-payment.environment') != 'production',
            'data' => [
                'number' => $creditCard['number'],
                'verification_value' => $creditCard['cvv'],
                'first_name' => $creditCard['firstName'],
                'last_name' => $creditCard['lastName'],
                'month' => $creditCard['month'],
                'year' => $creditCard['year'],
            ],
        ]);
    }
}