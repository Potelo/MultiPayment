<?php
namespace Potelo\MultiPayment\Tests;

use Potelo\MultiPayment\Models\Address;

class Faker
{
    public static function customerWithoutAddress(): array
    {
        $customer['name'] = 'Fake Customer';
        $customer['email'] = 'email@exemplo.com';
        $customer['taxDocument'] = '20176996915';
        $customer['birthDate'] = '1980-01-01';
        $customer['phoneArea'] = '71';
        $customer['phoneNumber'] = '982345678';
        return $customer;
    }

    public static function customerWithAddress(): array
    {
        $customer = self::customerWithoutAddress();
        $customer['address']['zipCode'] = '41820330';
        $customer['address']['street'] = 'Rua Exemplo';
        $customer['address']['number'] = '123';
        $customer['address']['district'] = 'Bairro Exemplo';
        $customer['address']['complement'] = 'Apto. 123';
        $customer['address']['city'] = 'Cidade Exemplo';
        $customer['address']['state'] = 'Estado';
        $customer['address']['country'] = 'Brasil';
        return $customer;
    }

    public static function creditCard(): array
    {
        $creditCard['number'] = '4111111111111111';
        $creditCard['month'] = '12';
        $creditCard['year'] = \Carbon\Carbon::now()->addYear()->format('Y');
        $creditCard['cvv'] = '123';
        $creditCard['firstName'] = 'Faker';
        $creditCard['lastName'] = 'Teste';
        return $creditCard;
    }
}