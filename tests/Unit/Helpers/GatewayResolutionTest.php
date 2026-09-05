<?php

namespace Potelo\MultiPayment\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Gateways\IuguGateway;
use Potelo\MultiPayment\Gateways\StripeGateway;
use Potelo\MultiPayment\Helpers\ConfigurationHelper;
use Potelo\MultiPayment\Testing\FakeGateway;
use Potelo\MultiPayment\Exceptions\ConfigurationException;

/**
 * Resolução de gateway por chave registrada: cada chave entrega a própria config ao driver,
 * então duas chaves com a mesma classe e credenciais próprias coexistem, e um bind no
 * container (da chave `multi-payment.gateway.{nome}` ou da classe) substitui o driver real.
 */
class GatewayResolutionTest extends TestCase
{
    private Container $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Container();
        $this->app->instance('config', new Repository([
            'multi-payment' => [
                'default' => 'iugu_a',
                'gateways' => [
                    'iugu_a' => ['api_key' => 'chave-a', 'class' => IuguGateway::class],
                    'iugu_b' => ['api_key' => 'chave-b', 'class' => IuguGateway::class],
                    'stripe_a' => ['api_key' => 'sk_test_a', 'class' => StripeGateway::class],
                    'stripe_b' => ['api_key' => 'sk_test_b', 'class' => StripeGateway::class],
                ],
            ],
        ]));
        Facade::setFacadeApplication($this->app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testTwoIuguKeysResolveToInstancesWithTheirOwnApiKeys(): void
    {
        $a = ConfigurationHelper::resolveGateway('iugu_a');
        $b = ConfigurationHelper::resolveGateway('iugu_b');

        $this->assertInstanceOf(IuguGateway::class, $a);
        $this->assertInstanceOf(IuguGateway::class, $b);
        $this->assertNotSame($a, $b);
        $this->assertSame('chave-a', self::iuguApiKey($a));
        $this->assertSame('chave-b', self::iuguApiKey($b));
    }

    public function testTwoStripeKeysResolveToClientsWithTheirOwnApiKeys(): void
    {
        $a = ConfigurationHelper::resolveGateway('stripe_a');
        $b = ConfigurationHelper::resolveGateway('stripe_b');

        $this->assertSame('sk_test_a', self::stripeApiKey($a));
        $this->assertSame('sk_test_b', self::stripeApiKey($b));
    }

    public function testABindOfTheDriverClassReplacesTheRealDriver(): void
    {
        $fake = new FakeGateway('iugu_a');
        $this->app->instance(IuguGateway::class, $fake);

        $this->assertSame($fake, ConfigurationHelper::resolveGateway('iugu_a'));
        $this->assertSame($fake, ConfigurationHelper::resolveGateway('iugu_b'));
    }

    public function testABindOfTheGatewayNameKeyPrevailsOverTheClassBind(): void
    {
        $byName = new FakeGateway('iugu_a');
        $byClass = new FakeGateway('iugu_qualquer');
        $this->app->instance('multi-payment.gateway.iugu_a', $byName);
        $this->app->instance(IuguGateway::class, $byClass);

        $this->assertSame($byName, ConfigurationHelper::resolveGateway('iugu_a'));
        $this->assertSame($byClass, ConfigurationHelper::resolveGateway('iugu_b'));
    }

    public function testAClosureBindReceivesTheKeyConfigAsParameter(): void
    {
        $received = null;
        $this->app->bind(IuguGateway::class, function ($app, array $parameters = []) use (&$received) {
            $received = $parameters['config'] ?? null;

            return new FakeGateway('iugu_b');
        });

        ConfigurationHelper::resolveGateway('iugu_b');

        $this->assertSame('chave-b', $received['api_key'] ?? null);
        $this->assertSame('iugu_b', $received['gateway_name'] ?? null);
    }

    /**
     * O driver resolvido por uma chave identifica a conta pelo nome dela: `(string)` devolve o
     * nome da chave, que preenche o `gateway` dos models e fecha a releitura na conta certa. O
     * driver construído direto, sem config, continua respondendo o nome convencional.
     */
    public function testTheResolvedDriverReportsTheKeyNameAndTheBareDriverTheConventionalOne(): void
    {
        $this->assertSame('iugu_b', (string) ConfigurationHelper::resolveGateway('iugu_b'));
        $this->assertSame('stripe_a', (string) ConfigurationHelper::resolveGateway('stripe_a'));
        $this->assertSame('iugu', (string) new IuguGateway());
        $this->assertSame('stripe', (string) new StripeGateway());
    }

    /**
     * A config da instância prevalece sobre a chave convencional: um campo ausente nela usa o
     * padrão do driver, sem cair em `multi-payment.gateways.iugu.{campo}`.
     */
    public function testTheInstanceConfigWinsOverTheConventionalKey(): void
    {
        $this->app['config']->set('multi-payment.gateways.iugu', [
            'api_key' => 'chave-convencional',
            'max_installments' => 6,
            'class' => IuguGateway::class,
        ]);

        $resolved = ConfigurationHelper::resolveGateway('iugu_a');
        $restriction = $resolved->restriction(\Potelo\MultiPayment\Enums\Capability::INSTALLMENTS);

        $this->assertSame('chave-a', self::iuguApiKey($resolved));
        $this->assertSame(12, $restriction->maxInstallments);
    }

    /**
     * Um singleton de closure mantém a semântica de instância única: a resolução não passa
     * parâmetros, porque `make()` com parâmetros reconstruiria o abstract a cada chamada.
     */
    public function testASingletonClosureBindResolvesToTheSameInstance(): void
    {
        $built = 0;
        $this->app->singleton('multi-payment.gateway.iugu_a', function () use (&$built) {
            $built++;

            return new FakeGateway('iugu_a');
        });

        $first = ConfigurationHelper::resolveGateway('iugu_a');
        $second = ConfigurationHelper::resolveGateway('iugu_a');

        $this->assertSame($first, $second);
        $this->assertSame(1, $built);
    }

    public function testNullResolvesTheDefaultGateway(): void
    {
        $gateway = ConfigurationHelper::resolveGateway(null);

        $this->assertInstanceOf(IuguGateway::class, $gateway);
        $this->assertSame('chave-a', self::iuguApiKey($gateway));
    }

    public function testUnknownGatewayNameIsRefused(): void
    {
        $this->expectException(ConfigurationException::class);

        ConfigurationHelper::resolveGateway('inexistente');
    }

    /**
     * Lê a chave de API do requester da instância do driver da Iugu (não há superfície
     * pública para credencial).
     */
    private static function iuguApiKey(IuguGateway $gateway): ?string
    {
        $request = (new \ReflectionProperty(IuguGateway::class, 'apiRequest'))->getValue($gateway);

        return (new \ReflectionProperty(\Iugu_APIRequest::class, 'apiKey'))->getValue($request);
    }

    /**
     * Lê a chave de API do client da instância do driver do Stripe (não há superfície
     * pública para credencial).
     */
    private static function stripeApiKey(StripeGateway $gateway): ?string
    {
        return (new \ReflectionProperty(StripeGateway::class, 'client'))->getValue($gateway)->getApiKey();
    }
}
