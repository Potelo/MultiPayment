<?php

namespace Potelo\MultiPayment\Tests;

use Carbon\Carbon;

class DataProvider
{
    /**
     * @return array[]
     */
    public function shouldCreateInvoiceDataProvider(): array
    {
        return [
            'iugu - without payment method' => [
                'gateway' => 'iugu',
                'data' => [
                    'expirationDate' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
                    'customer' => Faker::customerWithAddress(),
                ]
            ],
            'moip - without payment method' => [
                'gateway' => 'moip',
                'data' => [
                    'expirationDate' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
                    'customer' => Faker::customerWithAddress(),
                ]
            ],
            'iugu - company with address without payment method' => [
                'gateway' => 'iugu',
                'data' => [
                    'expirationDate' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
                    'customer' => Faker::companyWithAddress(),
                ]
            ],
            'moip - company with address without payment method' => [
                'gateway' => 'moip',
                'data' => [
                    'expirationDate' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
                    'customer' => Faker::companyWithAddress(),
                ]
            ],
            'moip - without payment method and without address' => [
                'gateway' => 'moip',
                'data' => [
                    'expirationDate' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
                    'customer' => Faker::customerWithoutAddress(),
                ]
            ],
            'iugu - credit card without address' => [
                'gateway' => 'iugu',
                'data' => [
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
                    'customer' => Faker::customerWithoutAddress(),
                    'paymentMethod' => 'credit_card',
                    'creditCard' => Faker::creditCard(),
                ]
            ],
            'moip - credit card without address' => [
                'gateway' => 'moip',
                'data' => [
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
                    'customer' => Faker::customerWithoutAddress(),
                    'paymentMethod' => 'credit_card',
                    'creditCard' => Faker::creditCard(),
                ]
            ],
            'iugu - credit card with address' => [
                'gateway' => 'iugu',
                'data' => [
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
                    'customer' => Faker::customerWithAddress(),
                    'paymentMethod' => 'credit_card',
                    'creditCard' => Faker::creditCard(),
                ]
            ],
            'moip - credit card with address' => [
                'gateway' => 'moip',
                'data' => [
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
                    'customer' => Faker::customerWithAddress(),
                    'paymentMethod' => 'credit_card',
                    'creditCard' => Faker::creditCard(),
                ]
            ],
            'iugu - bank slip with address' => [
                'gateway' => 'iugu',
                'data' => [
                    'expirationDate' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
                    'customer' => Faker::customerWithAddress(),
                    'paymentMethod' => 'bank_slip',
                ]
            ],
            'moip - bank slip with address' => [
                'gateway' => 'moip',
                'data' => [
                    'expirationDate' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
                    'customer' => Faker::customerWithAddress(),
                    'paymentMethod' => 'bank_slip',
                ]
            ],
            'iugu - pix with address' => [
                'gateway' => 'iugu',
                'data' => [
                    'expirationDate' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
                    'customer' => Faker::customerWithAddress(),
                    'paymentMethod' => 'pix',
                ]
            ],
            'iugu - pix without address' => [
                'gateway' => 'iugu',
                'data' => [
                    'expirationDate' => Carbon::now()->addWeekday()->format('Y-m-d'),
                    'items' => [['description' => 'Teste', 'quantity' => 1, 'price' => 100,],],
                    'customer' => Faker::customerWithoutAddress(),
                    'paymentMethod' => 'pix',
                ]
            ],
        ];
    }
}