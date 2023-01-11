<?php

namespace Potelo\MultiPayment\Tests\Unit\Builders;

use Potelo\MultiPayment\Tests\TestCase;
use Potelo\MultiPayment\Facades\MultiPayment;

class CreditCardBuilderTest extends TestCase
{

    public function __construct(?string $name = null, array $data = [], $dataName = '')
    {
        parent::__construct($name, $data, $dataName);
        $this->createApplication();
    }

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
     * creditCard hash dataDrovider.
     *
     * @return array[]
     */
    public function shouldCreateACreditCardWithHashDataProvider(): array
    {
        return [
            'moip - with hash' => [
                'moip',
                [
                    'token' => 'Sx1VgvxJmgHpOR33qikO+91FHRSIGFArF1DeS4ln2f6YLxk7Km7wfeQWPQOg4SNVGIMvvXoBmra3t8v/dbmeF5QOQxIcyOEK1aM3aaQBq7s2g3BVc8/lvvtOVGg66qmFLinr/87cCNhJ3nYVTYnwbGi5ILYDtV28ysPpRWiXeNzQf8Vx6bH7XZlDwX/Llku9utsdhEsQsWU80L62AALWt10utpn60oQraciZQ7VGjnMpa/ILNp0mDBT7YxRWmYafzM9Sj0hoLo+9urUz/nbRfV9stOFi4E2KibT36xDVAGoaO5WOcp6rDYDGK63GSj5leFJZOO12ewSaOb/+oAJX3Q==',
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
    }

    /**
     * Should create a credit card using token.
     *
     * @dataProvider shouldCreateACreditCardWithHashDataProvider
     *
     * @param $gateway
     * @param $data
     *
     * @return void
     */
    public function testShouldCreateACreditCardWithHash($gateway, $data)
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
        $creditCard = $creditCardBuilder->create();
        $this->assertNotNull($creditCard->id);
        $this->assertEquals($gateway, $creditCard->gateway);

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