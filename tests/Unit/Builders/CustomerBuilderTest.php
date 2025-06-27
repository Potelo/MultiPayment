<?php

namespace Potelo\MultiPayment\Tests\Unit\Builders;

use Carbon\Carbon;
use Potelo\MultiPayment\Tests\TestCase;
use Potelo\MultiPayment\Facades\MultiPayment;

class CustomerBuilderTest extends TestCase
{

    /**
     * Should create a customer data provider.
     *
     * @return array[]
     */
    public static function shouldCreateACustomerDataProvider(): array
    {
        return [
            ['iugu'],
        ];
    }

    /**
     * Should create a credit card.
     *
     * @dataProvider shouldCreateACustomerDataProvider
     *
     * @return void
     */
    public function testShouldCreateACustomer($gateway)
    {
        $data = self::customerWithAddress();
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
        $customerCreated = $customerBuilder->create();

        $this->assertNotNull($customerCreated->id);
        sleep(1); // Wait for the customer to be created in the gateway
        $customerFetched = MultiPayment::setGateway($gateway)->getCustomer($customerCreated->id);

        $this->assertNotNull($customerFetched);
        $this->assertEquals($gateway, $customerFetched->gateway);

        $this->assertEquals($data['name'], $customerFetched->name);
        $this->assertEquals($data['email'], $customerFetched->email);
        $this->assertEquals($data['taxDocument'], $customerFetched->taxDocument);
        $this->assertEquals($data['phoneArea'], $customerFetched->phoneArea);
        $this->assertEquals($data['phoneNumber'], $customerFetched->phoneNumber);
        $this->assertTrue($customerFetched->birthDate->isSameDay($data['birthDate']));

        $this->assertEquals($data['address']['zipCode'], $customerFetched->address->zipCode);
        $this->assertEquals($data['address']['street'], $customerFetched->address->street);
        $this->assertEquals($data['address']['number'], $customerFetched->address->number);
        $this->assertEquals($data['address']['complement'], $customerFetched->address->complement);
        $this->assertEquals($data['address']['district'], $customerFetched->address->district);
        $this->assertEquals($data['address']['city'], $customerFetched->address->city);
        $this->assertEquals($data['address']['state'], $customerFetched->address->state);
        $this->assertEquals($data['address']['country'], $customerFetched->address->country);

        $this->assertEquals($customerCreated->gateway, $customerFetched->gateway);
        $this->assertEquals($customerCreated->id, $customerFetched->id);
        $this->assertEquals($customerCreated->name, $customerFetched->name);
        $this->assertEquals($customerCreated->email, $customerFetched->email);
        $this->assertEquals($customerCreated->taxDocument, $customerFetched->taxDocument);
        $this->assertEquals($customerCreated->phoneArea, $customerFetched->phoneArea);
        $this->assertEquals($customerCreated->phoneNumber, $customerFetched->phoneNumber);
        $this->assertTrue($customerFetched->birthDate->isSameDay($customerCreated->birthDate));


        $this->assertEquals($customerCreated->address->zipCode, $customerFetched->address->zipCode);
        $this->assertEquals($customerCreated->address->street, $customerFetched->address->street);
        $this->assertEquals($customerCreated->address->number, $customerFetched->address->number);
        $this->assertEquals($customerCreated->address->complement, $customerFetched->address->complement);
        $this->assertEquals($customerCreated->address->district, $customerFetched->address->district);
        $this->assertEquals($customerCreated->address->city, $customerFetched->address->city);
        $this->assertEquals($customerCreated->address->state, $customerFetched->address->state);
        $this->assertEquals($customerCreated->address->country, $customerFetched->address->country);

    }

    /**
     * Should create a customer without address.
     *
     * @dataProvider shouldCreateACustomerDataProvider
     *
     * @return void
     */
    public function testShouldCreateACustomerWithoutAddress($gateway)
    {
        $data = self::customerWithoutAddress();
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
        $customerCreated = $customerBuilder->create();

        $this->assertNotNull($customerCreated->id);
        sleep(1); // Wait for the customer to be created in the gateway
        $customerFetched = MultiPayment::setGateway($gateway)->getCustomer($customerCreated->id);

        $this->assertNotNull($customerFetched);
        $this->assertEquals($gateway, $customerFetched->gateway);

        $this->assertEquals($data['name'], $customerFetched->name);
        $this->assertEquals($data['email'], $customerFetched->email);
        $this->assertEquals($data['taxDocument'], $customerFetched->taxDocument);
        $this->assertEquals($data['phoneArea'], $customerFetched->phoneArea);
        $this->assertEquals($data['phoneNumber'], $customerFetched->phoneNumber);
        $this->assertTrue($customerFetched->birthDate->isSameDay($data['birthDate']));


        // Address should be null
        $this->assertEmpty($customerFetched->address);

    }

    /**
     * Should update a customer.
     *
     * @dataProvider shouldCreateACustomerDataProvider
     * @param  string  $gateway
     * @return void
     */
    public function testShouldUpdateACustomer($gateway)
    {
        $data = self::customerWithAddress();
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
        $customerCreated = $customerBuilder->create();

        // Update customer
        $updatedData = [
            'name' => 'Updated Name',
            'email' => 'new_email@example.com',
            'taxDocument' => '68419761001',
            'phoneArea' => '11',
            'phoneNumber' => '987654321',
            'birthDate' => '1990-01-01',
            'address' => [
                'zipCode' => '123456789',
                'street' => 'Avenida Anita Garibaldi',
                'number' => '2229',
                'complement' => 'Apto. 207',
                'district' => 'Ondina',
                'city' => 'Salvador',
                'state' => 'BA',
                'country' => 'Brasil',
            ],
        ];
        $customerCreated->name = $updatedData['name'];
        $customerCreated->email = $updatedData['email'];
        $customerCreated->taxDocument = $updatedData['taxDocument'];
        $customerCreated->phoneArea = $updatedData['phoneArea'];
        $customerCreated->phoneNumber = $updatedData['phoneNumber'];
        $customerCreated->birthDate = Carbon::createFromFormat('Y-m-d', $updatedData['birthDate']);
        $customerCreated->address->zipCode = $updatedData['address']['zipCode'];
        $customerCreated->address->street = $updatedData['address']['street'];
        $customerCreated->address->number = $updatedData['address']['number'];
        $customerCreated->address->complement = $updatedData['address']['complement'];
        $customerCreated->address->district = $updatedData['address']['district'];
        $customerCreated->address->city = $updatedData['address']['city'];
        $customerCreated->address->state = $updatedData['address']['state'];
        $customerCreated->address->country = $updatedData['address']['country'];
        $customerCreated->save();

        sleep(1); // Wait for the customer to be updated in the gateway

        $customerFetched = MultiPayment::setGateway($gateway)->getCustomer($customerCreated->id);
        $this->assertNotNull($customerFetched);
        $this->assertEquals($gateway, $customerFetched->gateway);
        $this->assertEquals($updatedData['name'], $customerFetched->name);
        $this->assertEquals($updatedData['email'], $customerFetched->email);
        $this->assertEquals($updatedData['taxDocument'], $customerFetched->taxDocument);
        $this->assertEquals($updatedData['phoneArea'], $customerFetched->phoneArea);
        $this->assertEquals($updatedData['phoneNumber'], $customerFetched->phoneNumber);
        $this->assertTrue($customerFetched->birthDate->isSameDay(Carbon::createFromFormat('Y-m-d', $updatedData['birthDate'])));
        $this->assertEquals($updatedData['address']['zipCode'], $customerFetched->address->zipCode);
        $this->assertEquals($updatedData['address']['street'], $customerFetched->address->street);
        $this->assertEquals($updatedData['address']['number'], $customerFetched->address->number);
        $this->assertEquals($updatedData['address']['complement'], $customerFetched->address->complement);
        $this->assertEquals($updatedData['address']['district'], $customerFetched->address->district);
        $this->assertEquals($updatedData['address']['city'], $customerFetched->address->city);
        $this->assertEquals($updatedData['address']['state'], $customerFetched->address->state);
        $this->assertEquals($updatedData['address']['country'], $customerFetched->address->country);

        $this->assertEquals($customerCreated->gateway, $customerFetched->gateway);
        $this->assertEquals($customerCreated->id, $customerFetched->id);
        $this->assertEquals($customerCreated->name, $customerFetched->name);
        $this->assertEquals($customerCreated->email, $customerFetched->email);
        $this->assertEquals($customerCreated->taxDocument, $customerFetched->taxDocument);
        $this->assertEquals($customerCreated->phoneArea, $customerFetched->phoneArea);
        $this->assertEquals($customerCreated->phoneNumber, $customerFetched->phoneNumber);
        $this->assertTrue($customerFetched->birthDate->isSameDay($customerCreated->birthDate));
        $this->assertEquals($customerCreated->address->zipCode, $customerFetched->address->zipCode);
        $this->assertEquals($customerCreated->address->street, $customerFetched->address->street);
        $this->assertEquals($customerCreated->address->number, $customerFetched->address->number);
        $this->assertEquals($customerCreated->address->complement, $customerFetched->address->complement);
        $this->assertEquals($customerCreated->address->district, $customerFetched->address->district);
        $this->assertEquals($customerCreated->address->city, $customerFetched->address->city);
        $this->assertEquals($customerCreated->address->state, $customerFetched->address->state);
        $this->assertEquals($customerCreated->address->country, $customerFetched->address->country);
    }
}
