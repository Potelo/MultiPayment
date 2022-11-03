<?php

namespace Potelo\MultiPayment\Tests\Unity\Builders;

use Potelo\MultiPayment\Tests\TestCase;
use Potelo\MultiPayment\Facades\MultiPayment;

class CreditCardBuilderTest extends TestCase
{
    /**
     * Should create a credit card.
     *
     * @dataProvider shouldCreateACreditCardDataProvider
     *
     * @return void
     */
    public function testShouldCreateACreditCard($gateway, $data)
    {

        $creditCardBuilder = MultiPayment::setGateway($gateway)->newCreditCard();
        $customer = $this->createCustomer($gateway, $data['customer']);
        $creditCardBuilder->setCustomerId($customer->id);
        if (!empty($data['token'])) {
            $creditCardBuilder->setToken($data['token']);
        }
        if (!empty($data['description'])) {
            $creditCardBuilder->setDescription($data['description']);
        }

        if (!empty($data['number'])) {
            $creditCardBuilder->setNumber($data['number']);
        }
        if (!empty($data['firstName'])) {
            $creditCardBuilder->setFirstName($data['firstName']);
        }
        if (!empty($data['lastName'])) {
            $creditCardBuilder->setLastName($data['lastName']);
        }
        if (!empty($data['month'])) {
            $creditCardBuilder->setMonth($data['month']);
        }
        if (!empty($data['year'])) {
            $creditCardBuilder->setYear($data['year']);
        }
        if (!empty($data['cvv'])) {
            $creditCardBuilder->setCvv($data['cvv']);
        }

        $creditCard = $creditCardBuilder->create();
        $this->assertNotNull($creditCard->id);
        $this->assertEquals($gateway, $creditCard->gateway);
    }

    public function shouldCreateACreditCardDataProvider(): array
    {
        return [
            'iugu - with credit card data' => [
                'iugu',
                array_merge(self::creditCard(), ['customer' => self::customerWithoutAddress()])
            ],
            'moip - with credit card data' => [
                'moip',
                array_merge(self::creditCard(), ['customer' => self::customerWithoutAddress()])
            ],
        ];
    }

    /**
     * Should create a credit card using token.
     *
     * @return void
     */
    public function testShouldCreateACreditCardWithHash()
    {
        $dataProvider = [
            'moip - with hash' => [
                'moip',
                [
                    'token' => 'CmDt2IP+4s9GDnL9xlc5TZSw8vcA3BCNrTg/7kgWtcp6TSERmRZxxlg9pMrVeoGxAVz8WtceZtKEL1GJfDcJJMYFkSqapxRThFrtT36BX5ZIy9hMl3IhvLULjDYgz6Ax3v1pUY3dudqaT9jQQJtdjasTbYgwxw11HNqBBOGZRhJgpNB1IRXCb1z40pL3VTB+Ox2fvd1MlC/ZVdgmGpcZ00+GI8MKhvHPSbIRn6Qk2BnDqis4NdrFDARZheIyAo1ABKyXaEFKgLURDEdqtplXzN7ycQ/EPJDVoPsBoOub0vlzLYk0NaWmIFPvUdd6/tYXvqSu/aEyJe3Yaj9PrZUxZQ==',
                    'description' => 'Test credit card',
                    'customer' => self::customerWithoutAddress(),
                ],
            ],
            'iugu - with hash' => [
                'iugu',
                [
                    'token' => self::iuguCreditCardToken(),
                    'description' => 'Test credit card',
                    'customer' => self::customerWithoutAddress(),
                ],
            ],
        ];

        foreach ($dataProvider as $data) {
            $gateway = $data[0];
            $data = $data[1];
            $creditCardBuilder = MultiPayment::setGateway($gateway)->newCreditCard();
            $customer = $this->createCustomer($gateway, $data['customer']);
            $creditCardBuilder->setCustomerId($customer->id);
            if (!empty($data['token'])) {
                $creditCardBuilder->setToken($data['token']);
            }
            if (!empty($data['description'])) {
                $creditCardBuilder->setDescription($data['description']);
            }
            $creditCard = $creditCardBuilder->create();
            $this->assertNotNull($creditCard->id);
            $this->assertEquals($gateway, $creditCard->gateway);
        }

    }

    public function createCustomer($gateway, $data): \Potelo\MultiPayment\Models\Customer
    {
        $customerBuilder = MultiPayment::setGateway($gateway)->newCustomer();
        if (!empty($data['email'])) {
            $customerBuilder->setEmail($data['email']);
        }
        if (!empty($data['name'])) {
            $customerBuilder->setName($data['name']);
        }
        if (!empty($data['taxDocument'])) {
            $customerBuilder->setTaxDocument($data['taxDocument']);
        }
        if (!empty($data['phoneNumber']) && !empty($data['phoneArea'])) {
            $customerBuilder->setPhone($data['phoneNumber'], $data['phoneArea']);
        }
        if (!empty($data['birthDate'])) {
            $customerBuilder->setBirthDate($data['birthDate']);
        }
        if (!empty($data['address'])) {
            $customerBuilder->addAddress(
                $data['address']['zipCode'],
                $data['address']['street'],
                $data['address']['number'],
                $data['address']['complement'],
                $data['address']['district'],
                $data['address']['city'],
                $data['address']['state'],
                $data['address']['country']
            );
        }
        return $customerBuilder->create();
    }
}