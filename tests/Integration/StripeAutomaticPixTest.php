<?php

namespace Potelo\MultiPayment\Tests\Integration;

use Carbon\Carbon;
use Stripe\StripeClient;
use Illuminate\Support\Facades\Config;
use Potelo\MultiPayment\Tests\TestCase;
use Potelo\MultiPayment\Models\Plan;
use Potelo\MultiPayment\Models\AutomaticPix;
use Potelo\MultiPayment\Gateways\StripeGateway;
use Potelo\MultiPayment\Facades\MultiPayment;
use Potelo\MultiPayment\Enums\PlanInterval;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Enums\SubscriptionStatus;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Pix Automático no gateway Stripe, na sandbox real. A conta da empresa ainda não tem o
 * recurso liberado, então o grupo inteiro pula até `STRIPE_PIX_AUTOMATICO_ENABLED=true` no
 * ambiente (ver `TestCase::setUp()`).
 */
#[Group('pix-automatico-stripe')]
class StripeAutomaticPixTest extends TestCase
{
    /** @var string[] ids de Price criados, arquivados no tearDown */
    private array $pricesCriados = [];

    /** @var string[] ids de assinatura criados, cancelados no tearDown */
    private array $subscriptionsCriadas = [];

    /**
     * Data provider de gateway: mantém o padrão por gateway da suíte e dispensa o sleep da Iugu.
     *
     * @return array[]
     */
    public static function stripeGatewayDataProvider(): array
    {
        return [
            ['stripe'],
        ];
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        $client = new StripeClient([
            'api_key' => Config::get('multi-payment.gateways.stripe.api_key'),
            'stripe_version' => StripeGateway::STRIPE_API_VERSION,
        ]);

        foreach ($this->subscriptionsCriadas as $id) {
            try {
                $client->subscriptions->cancel($id);
            } catch (\Throwable $e) {
                // limpeza é best effort: assinatura já cancelada pelo teste
            }
        }

        foreach ($this->pricesCriados as $id) {
            try {
                $client->prices->update($id, ['active' => false]);
            } catch (\Throwable $e) {
                // limpeza é best effort: falha ao arquivar não invalida o teste
            }
        }

        parent::tearDown();
    }

    /**
     * A assinatura com Pix Automático nasce com a primeira fatura em aberto
     * (`default_incomplete`) e o mandato registrado: o model volta com o método
     * `AUTOMATIC_PIX`, `automaticPix` preenchido e `startsAt` no mínimo três dias à frente.
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldCreateASubscriptionWithAPixMandate(string $gateway): void
    {
        $identifier = 'multipayment-pix-automatico-' . uniqid();
        $plan = new Plan();
        $plan->name = 'Plano Pix Automático';
        $plan->identifier = $identifier;
        $plan->amount = 10000;
        $plan->interval = PlanInterval::MONTH;
        $plan->save($gateway);
        $this->pricesCriados[] = $plan->id;

        $customer = $this->createCustomer($gateway, self::customerWithoutAddress());

        $subscription = MultiPayment::setGateway($gateway)->newSubscription()
            ->setPlanId($identifier)
            ->setCustomer($customer)
            ->setPaymentMethod(PaymentMethod::AUTOMATIC_PIX)
            ->create();
        $this->subscriptionsCriadas[] = $subscription->id;

        $this->assertSame(SubscriptionStatus::PENDING, $subscription->status);
        $this->assertSame(PaymentMethod::AUTOMATIC_PIX, $subscription->paymentMethod);
        $this->assertInstanceOf(AutomaticPix::class, $subscription->automaticPix);
        $this->assertSame(AutomaticPix::FREQUENCY_MONTHLY, $subscription->automaticPix->frequency);
        $this->assertTrue(
            $subscription->automaticPix->startsAt->greaterThanOrEqualTo(Carbon::now()->addDays(3)->startOfDay())
        );
        $this->assertNotNull($subscription->latestInvoice);
        $this->assertNotEmpty($subscription->latestInvoice->url);

        $read = MultiPayment::setGateway($gateway)->getSubscription($subscription->id);
        $this->assertSame(PaymentMethod::AUTOMATIC_PIX, $read->paymentMethod);
        $this->assertInstanceOf(AutomaticPix::class, $read->automaticPix);
    }
}
