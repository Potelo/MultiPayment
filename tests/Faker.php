<?php
namespace Potelo\MultiPayment\Tests;

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
        $address['street'] = 'Rua Exemplo';
        $address['number'] = '123';
        $address['district'] = 'Bairro Exemplo';
        $address['complement'] = 'Apto. 123';
        $address['city'] = 'Cidade Exemplo';
        $address['state'] = 'Estado';
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
        return $creditCard;
    }
}