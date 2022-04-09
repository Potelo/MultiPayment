<?php

namespace Potelo\MultiPayment\Tests\Unity\Builders;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Potelo\MultiPayment\Tests\TestCase;
use Potelo\MultiPayment\Facades\MultiPayment;

class CustomerBuilderTest extends TestCase
{
    /**
     * Should create a customer.
     *
     * @dataProvider shouldCreateACostumerDataProvider
     *
     * @return void
     */
    public function testShouldCreateACostumer($gateway, $data)
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
        $customer = $customerBuilder->create();
        $this->assertNotNull($customer->id);
    }

    public function shouldCreateACostumerDataProvider(): array
    {
        return [
            'iugu - only email and name' => [
                'iugu',
                [
                    'name' => 'Fake Customer',
                    'email' => 'email@exemplo.com',
                ],
            ],
            'moip - only email, name and cpf' => [
                'moip',
                [
                    'name' => 'Fake Customer',
                    'email' => 'email@exemplo.com',
                ],
            ],
            'iugu - all data' => [
                'iugu',
                self::customerWithoutAddress()
            ],
            'moip - all data' => [
                'moip',
                self::customerWithoutAddress()
            ],
            'iugu - with address' => [
                'iugu',
                self::customerWithAddress()
            ],
            'moip - with address' => [
                'moip',
                self::customerWithAddress()
            ],
        ];
    }

    /**
     * Should create many customers
     *
     * @dataProvider shouldCreateManyCostumersDataProvider
     *
     * @return void
     */
    public function testShouldCreateManyCostumers($data)
    {
        $customerBuilder = MultiPayment::newCustomer();
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
        $customers = $customerBuilder->createMany();
        $this->assertEquals(Collection::class, get_class($customers));
        $this->assertSameSize(Config::get('multi-payment.gateways'), $customers);
        foreach ($customers as $customer) {
            $this->assertNotNull($customer->id);
        }
    }

    public function shouldCreateManyCostumersDataProvider(): array
    {
        return [
            'with address' => [
                self::customerWithAddress()
            ],
        ];
    }

    /**
     * Test shold create many costumers, with specific gateway
     *
     * @return void
     */
    public function testShouldCreateManyCostumersWIthSpecificGateways()
    {
        $customers = MultiPayment::newCustomer()
            ->setEmail('email@exemplo.com')
            ->setName('Fake Customer')
            ->createMany(['iugu']);
        $this->assertEquals(Collection::class, get_class($customers));
        $this->assertEquals(1, $customers->count());
        $this->assertNotNull($customers->first()->id);
    }
}