<?php

namespace Potelo\MultiPayment\Tests\Integration\Builders;

use Potelo\MultiPayment\Tests\TestCase;
use Potelo\MultiPayment\Facades\MultiPayment;
use PHPUnit\Framework\Attributes\DataProvider;

class CreditCardBuilderTest extends TestCase
{
    /**
     * Should create a credit card.
     *
     * @return void
     */
    #[DataProvider('shouldCreateACreditCardDataProvider')]
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
        if (!empty($data['description'])) {
            $creditCardBuilder->setDescription($data['description']);
        }
        if (!empty($data['default'])) {
            $creditCardBuilder->setAsDefault($data['default']);
        }

        $creditCard = $creditCardBuilder->create();

        $this->assertNotNull($creditCard->id);
        $this->assertEquals(substr($data['number'], -4), $creditCard->lastDigits);
        $this->assertEquals('Visa', $creditCard->brand);
        $this->assertEquals($data['firstName'], $creditCard->firstName);
        $this->assertEquals($data['lastName'], $creditCard->lastName);
        $this->assertEquals($data['description'], $creditCard->description);
        $this->assertEquals($data['default'], $creditCard->default);

        if ($data['default']) {
            $customer = $customer->refresh();
            $this->assertEquals($creditCard->id, $customer->defaultCard->id);
        }

        $this->assertEquals($gateway, $creditCard->gateway);
    }

    public static function shouldCreateACreditCardDataProvider(): array
    {
        return [
            'iugu - with credit card data' => [
                'iugu',
                array_merge(self::creditCard(), ['customer' => self::customerWithoutAddress()])
            ],
        ];
    }

    /**
     * creditCard hash dataDrovider.
     *
     * @return array[]
     */
    public static function shouldCreateACreditCardWithHashDataProvider(): array
    {
        return [
            'iugu - with hash' => [
                'iugu',
                [
                    'createToken' => true,
                    'description' => 'Test credit card',
                    'customer' => self::customerWithoutAddress(),
                ],
            ],
        ];
    }

    /**
     * Should create a credit card using token.
     *
     * @param $gateway
     * @param $data
     *
     * @return void
     */
    #[DataProvider('shouldCreateACreditCardWithHashDataProvider')]
    public function testShouldCreateACreditCardWithHash($gateway, $data)
    {

        $creditCardBuilder = MultiPayment::setGateway($gateway)->newCreditCard();
        $customer = $this->createCustomer($gateway, $data['customer']);
        $creditCardBuilder->setCustomerId($customer->id);
        if (!empty($data['createToken'])) {
            $creditCardBuilder->setToken(self::iuguCreditCardToken());
        }
        if (!empty($data['description'])) {
            $creditCardBuilder->setDescription($data['description']);
        }
        $creditCard = $creditCardBuilder->create();
        $this->assertNotNull($creditCard->id);
        $this->assertEquals($gateway, $creditCard->gateway);

    }
}
