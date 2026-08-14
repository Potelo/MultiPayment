<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Carbon\Carbon;
use Stripe\ApiRequestor;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Gateways\StripeGateway;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

class StripeGatewayCustomerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment.gateways.stripe.api_key' => 'sk_test_fake',
        ]));
        Facade::setFacadeApplication($app);

        // fake vazio por padrão: teste que esquecer withResponses() estoura em vez de ir à rede
        RecordingStripeHttpClient::withResponses([]);
    }

    protected function tearDown(): void
    {
        // o hook de HTTP do stripe-php é estático — sem o reset, o fake vazaria para outros testes
        ApiRequestor::setHttpClient(null);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testCreatesCustomerSendingGenericFieldsAsStripeData(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->stripeCustomerResponse()]);

        $customer = $this->customerModel();
        $result = (new StripeGateway())->createCustomer($customer);

        $this->assertCount(1, $httpClient->calls);
        [$method, $url, $params] = $httpClient->calls[0];
        $this->assertSame('post', $method);
        $this->assertSame('/v1/customers', parse_url($url, PHP_URL_PATH));

        // payload completo: uma chave extra vazando para o request também deve falhar
        $this->assertSame([
            'name' => 'Fake Customer',
            'email' => 'email@exemplo.com',
            'phone' => '+5571982345678',
            'address' => [
                'line1' => 'Rua Deputado Mário Lima, 123',
                'line2' => 'Apto. 123',
                'city' => 'Salvador',
                'state' => 'BA',
                'postal_code' => '41820330',
            ],
            'metadata' => [
                'birth_date' => '1980-01-01',
                'district' => 'Caminho das Árvores',
                'country' => 'Brasil',
            ],
            'tax_id_data' => [['type' => 'br_cpf', 'value' => '20176996915']],
            'expand' => ['tax_ids'],
        ], $params);

        $this->assertSame('cus_fake123', $result->id);
        $this->assertSame('stripe', $result->gateway);
        $this->assertInstanceOf(Carbon::class, $result->createdAt);
    }

    public function testCreatesCustomerWithCnpjUsingBrCnpjTaxIdType(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->stripeCustomerResponse()]);

        $customer = new Customer();
        $customer->name = 'Fake Company';
        $customer->email = 'email@exemplo.com';
        $customer->taxDocument = '28585583000189';
        (new StripeGateway())->createCustomer($customer);

        [, , $params] = $httpClient->calls[0];
        $this->assertSame([['type' => 'br_cnpj', 'value' => '28585583000189']], $params['tax_id_data']);
    }

    public function testParsesStripeCustomerIntoGenericModel(): void
    {
        RecordingStripeHttpClient::withResponses([$this->stripeCustomerResponse()]);

        $customer = new Customer();
        $customer->id = 'cus_fake123';
        $result = (new StripeGateway())->getCustomer($customer);

        $this->assertSame('cus_fake123', $result->id);
        $this->assertSame('Fake Customer', $result->name);
        $this->assertSame('email@exemplo.com', $result->email);
        $this->assertSame('20176996915', $result->taxDocument);
        $this->assertSame('55', $result->phoneCountryCode);
        $this->assertSame('71', $result->phoneArea);
        $this->assertSame('982345678', $result->phoneNumber);
        $this->assertTrue($result->birthDate->isSameDay(Carbon::createFromFormat('Y-m-d', '1980-01-01')));
        $this->assertSame('Rua Deputado Mário Lima', $result->address->street);
        $this->assertSame('123', $result->address->number);
        $this->assertSame('Apto. 123', $result->address->complement);
        $this->assertSame('Caminho das Árvores', $result->address->district);
        $this->assertSame('Salvador', $result->address->city);
        $this->assertSame('BA', $result->address->state);
        $this->assertSame('41820330', $result->address->zipCode);
        $this->assertSame('Brasil', $result->address->country);
        $this->assertSame('stripe', $result->gateway);
        $this->assertNotNull($result->original);
    }

    public function testParsesCustomerWithoutAddressKeepingAddressNull(): void
    {
        $response = $this->stripeCustomerResponse();
        $response['address'] = null;
        $response['metadata'] = ['birth_date' => '1980-01-01'];
        RecordingStripeHttpClient::withResponses([$response]);

        $customer = new Customer();
        $customer->id = 'cus_fake123';
        $result = (new StripeGateway())->getCustomer($customer);

        $this->assertNull($result->address);
        $this->assertTrue($result->birthDate->isSameDay(Carbon::createFromFormat('Y-m-d', '1980-01-01')));
    }

    public function testUpdateCustomerRequiresId(): void
    {
        RecordingStripeHttpClient::withResponses([]);

        $this->expectException(ModelAttributeValidationException::class);

        (new StripeGateway())->updateCustomer(new Customer());
    }

    public function testUpdateCustomerReplacesChangedTaxDocument(): void
    {
        $staleCustomer = $this->stripeCustomerResponse();
        $freshCustomer = $this->stripeCustomerResponse();
        $freshCustomer['tax_ids']['data'][0]['value'] = '68419761001';
        $httpClient = RecordingStripeHttpClient::withResponses([
            $staleCustomer,
            ['id' => 'txi_fake2', 'object' => 'tax_id', 'type' => 'br_cpf', 'value' => '68419761001'],
            ['id' => 'txi_fake1', 'object' => 'tax_id', 'deleted' => true],
            $freshCustomer,
        ]);

        $customer = new Customer();
        $customer->id = 'cus_fake123';
        $customer->taxDocument = '68419761001';
        $result = (new StripeGateway())->updateCustomer($customer);

        $paths = array_map(static fn ($call) => $call[0] . ' ' . parse_url($call[1], PHP_URL_PATH), $httpClient->calls);
        $this->assertSame([
            'post /v1/customers/cus_fake123',
            'post /v1/customers/cus_fake123/tax_ids',
            'delete /v1/customers/cus_fake123/tax_ids/txi_fake1',
            'get /v1/customers/cus_fake123',
        ], $paths);
        $this->assertSame(['type' => 'br_cpf', 'value' => '68419761001'], $httpClient->calls[1][2]);
        $this->assertSame('68419761001', $result->taxDocument);
    }

    public function testUpdateCustomerKeepsUnchangedTaxDocumentWithoutExtraRequests(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->stripeCustomerResponse()]);

        $customer = new Customer();
        $customer->id = 'cus_fake123';
        $customer->taxDocument = '20176996915';
        (new StripeGateway())->updateCustomer($customer);

        $this->assertCount(1, $httpClient->calls);
    }

    public function testSetCustomerDefaultCardSendsInvoiceSettingsAndParsesDefaultCard(): void
    {
        $response = $this->stripeCustomerResponse();
        $response['invoice_settings'] = ['default_payment_method' => 'pm_fake123'];
        $httpClient = RecordingStripeHttpClient::withResponses([$response]);

        $customer = new Customer();
        $customer->id = 'cus_fake123';
        $result = (new StripeGateway())->setCustomerDefaultCard($customer, 'pm_fake123');

        [, , $params] = $httpClient->calls[0];
        $this->assertSame('pm_fake123', $params['invoice_settings']['default_payment_method']);
        $this->assertSame('pm_fake123', $result->defaultCard->id);
    }

    public function testGatewayAdicionalOptionsReachThePayloadAndExpandIsMerged(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->stripeCustomerResponse()]);

        $customer = new Customer();
        $customer->name = 'Fake Customer';
        $customer->taxDocument = '20176996915';
        $customer->gatewayAdicionalOptions = [
            'preferred_locales' => ['pt-BR'],
            'expand' => ['subscriptions'],
        ];
        (new StripeGateway())->createCustomer($customer);

        [, , $params] = $httpClient->calls[0];
        $this->assertSame(['pt-BR'], $params['preferred_locales']);
        // o expand do usuário não pode descartar o tax_ids exigido pelo parse/sync
        $this->assertSame(['subscriptions', 'tax_ids'], $params['expand']);
    }

    public function testCustomerWithExplicitPhoneCountryCodeIsConcatenated(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->stripeCustomerResponse()]);

        $customer = new Customer();
        $customer->phoneCountryCode = '01';
        $customer->phoneArea = '71';
        $customer->phoneNumber = '982345678';
        (new StripeGateway())->createCustomer($customer);

        [, , $params] = $httpClient->calls[0];
        $this->assertSame('+0171982345678', $params['phone']);
    }

    public function testCustomerWithoutTaxDocumentOmitsTaxIdData(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->stripeCustomerResponse()]);

        $customer = new Customer();
        $customer->name = 'Fake Customer';
        (new StripeGateway())->createCustomer($customer);

        [, , $params] = $httpClient->calls[0];
        $this->assertArrayNotHasKey('tax_id_data', $params);
    }

    public function testAddressWithoutNumberUsesSNPlaceholderAndClearsAbsentMetadata(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([$this->stripeCustomerResponse()]);

        $customer = new Customer();
        $customer->fill(['address' => ['street' => 'Rua Deputado Mário Lima', 'zip_code' => '41820330']]);
        (new StripeGateway())->createCustomer($customer);

        [, , $params] = $httpClient->calls[0];
        $this->assertSame('Rua Deputado Mário Lima, S/N', $params['address']['line1']);
        // bairro/país ausentes limpam as chaves no metadata (que faz merge por chave na Stripe)
        $this->assertSame(['district' => '', 'country' => ''], $params['metadata']);
    }

    public function testParsesNumberOnlyLine1IntoAddressNumber(): void
    {
        $response = $this->stripeCustomerResponse();
        $response['address']['line1'] = '123';
        RecordingStripeHttpClient::withResponses([$response]);

        $customer = new Customer();
        $customer->id = 'cus_fake123';
        $result = (new StripeGateway())->getCustomer($customer);

        $this->assertNull($result->address->street);
        $this->assertSame('123', $result->address->number);
    }

    public function testUnimplementedOperationThrowsClearGatewayException(): void
    {
        RecordingStripeHttpClient::withResponses([]);

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('Operation [createInvoice] is not yet implemented by the stripe gateway');

        (new StripeGateway())->createInvoice(new Invoice());
    }

    public function testAuthenticationErrorBecomesGatewayNotAvailable(): void
    {
        RecordingStripeHttpClient::withResponses([
            [['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided']], 401],
        ]);

        $customer = new Customer();
        $customer->id = 'cus_fake123';

        $this->expectException(GatewayNotAvailableException::class);

        (new StripeGateway())->getCustomer($customer);
    }

    public function testApiErrorBecomesGatewayExceptionWithNormalizedErrors(): void
    {
        RecordingStripeHttpClient::withResponses([
            [['error' => [
                'type' => 'invalid_request_error',
                'code' => 'parameter_unknown',
                'param' => 'foo',
                'message' => 'Received unknown parameter: foo',
            ]], 400],
        ]);

        $customer = new Customer();
        $customer->id = 'cus_fake123';

        try {
            (new StripeGateway())->getCustomer($customer);
            $this->fail('Expected GatewayException was not thrown');
        } catch (GatewayException $exception) {
            $this->assertSame([
                'type' => 'invalid_request_error',
                'code' => 'parameter_unknown',
                'param' => 'foo',
            ], $exception->getErrors());
        }
    }

    public function testToStringReturnsGatewayName(): void
    {
        $this->assertSame('stripe', (string) new StripeGateway());
    }

    private function customerModel(): Customer
    {
        $customer = new Customer();
        $customer->fill([
            'name' => 'Fake Customer',
            'email' => 'email@exemplo.com',
            'tax_document' => '20176996915',
            'phone_area' => '71',
            'phone_number' => '982345678',
            'address' => [
                'zip_code' => '41820330',
                'street' => 'Rua Deputado Mário Lima',
                'number' => '123',
                'district' => 'Caminho das Árvores',
                'complement' => 'Apto. 123',
                'city' => 'Salvador',
                'state' => 'BA',
                'country' => 'Brasil',
            ],
        ]);
        $customer->birthDate = Carbon::createFromFormat('Y-m-d', '1980-01-01');

        return $customer;
    }

    private function stripeCustomerResponse(): array
    {
        return [
            'id' => 'cus_fake123',
            'object' => 'customer',
            'name' => 'Fake Customer',
            'email' => 'email@exemplo.com',
            'phone' => '+5571982345678',
            'created' => 1786700000,
            'metadata' => [
                'birth_date' => '1980-01-01',
                'district' => 'Caminho das Árvores',
                'country' => 'Brasil',
            ],
            'address' => [
                'line1' => 'Rua Deputado Mário Lima, 123',
                'line2' => 'Apto. 123',
                'city' => 'Salvador',
                'state' => 'BA',
                'postal_code' => '41820330',
                'country' => null,
            ],
            'invoice_settings' => ['default_payment_method' => null],
            'tax_ids' => [
                'object' => 'list',
                'data' => [
                    ['id' => 'txi_fake1', 'object' => 'tax_id', 'type' => 'br_cpf', 'value' => '20176996915'],
                ],
            ],
        ];
    }
}

/**
 * Fake da camada HTTP do stripe-php, no molde do RecordingIuguApiRequest: devolve respostas
 * enfileiradas e grava cada chamada para asserção. Cada resposta é um array (corpo JSON,
 * status 200) ou um par [corpo, status].
 */
class RecordingStripeHttpClient implements \Stripe\HttpClient\ClientInterface
{
    /** @var array<int, array{0: string, 1: string, 2: array}> */
    public array $calls = [];

    /** @var array<int, array{0: array, 1: int}> */
    private array $responses;

    private function __construct(array $responses)
    {
        $this->responses = array_map(static function ($response) {
            return isset($response[1]) && is_int($response[1])
                ? $response
                : [$response, 200];
        }, $responses);
    }

    /**
     * Cria o fake e o instala como client HTTP global do stripe-php.
     *
     * @param  array  $responses
     * @return static
     */
    public static function withResponses(array $responses): self
    {
        $httpClient = new self($responses);
        ApiRequestor::setHttpClient($httpClient);

        return $httpClient;
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->calls[] = [$method, $absUrl, $params];

        if (empty($this->responses)) {
            throw new \RuntimeException("Unexpected Stripe request: {$method} {$absUrl}");
        }
        [$body, $code] = array_shift($this->responses);

        return [json_encode($body), $code, []];
    }
}
