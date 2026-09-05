<?php

namespace Potelo\MultiPayment\Tests\Integration;

use Stripe\StripeClient;
use Illuminate\Support\Facades\Config;
use Potelo\MultiPayment\Tests\TestCase;
use Potelo\MultiPayment\Models\Plan;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Gateways\StripeGateway;
use Potelo\MultiPayment\Facades\MultiPayment;
use Potelo\MultiPayment\Enums\PlanInterval;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Enums\ProrationBehavior;
use Potelo\MultiPayment\Enums\SubscriptionStatus;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Plano e assinatura no gateway Stripe, na sandbox real: o plano como Product e Price, a
 * assinatura com trial no cartão de teste, a troca de plano com crédito e o cancelamento ao
 * fim do período. O que é recusado antes de qualquer requisição fica na suíte Unit.
 */
class StripeSubscriptionTest extends TestCase
{
    /** @var string[] ids de Price criados, arquivados no tearDown */
    private array $pricesCriados = [];

    /** @var string[] ids de assinatura criados, cancelados no tearDown */
    private array $subscriptionsCriadas = [];

    /** @var string[] ids de Coupon criados, apagados no tearDown */
    private array $couponsCriados = [];

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

        foreach ($this->couponsCriados as $id) {
            try {
                $client->coupons->delete($id);
            } catch (\Throwable $e) {
                // limpeza é best effort, como acima
            }
        }

        parent::tearDown();
    }

    private function createPlan(string $gateway, int $amount, string $sufixo, PlanInterval $interval = PlanInterval::MONTH): Plan
    {
        $plan = new Plan();
        $plan->name = 'MultiPayment teste ' . $sufixo;
        $plan->identifier = 'multipayment-teste-' . $sufixo . '-' . now()->format('YmdHisu');
        $plan->amount = $amount;
        $plan->interval = $interval;
        $plan->save($gateway);
        $this->pricesCriados[] = $plan->id;

        return $plan;
    }

    /**
     * Deve criar o plano como Price recorrente, buscá-lo pelo identificador e arquivá-lo.
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldCreateGetAndDeactivateAPlan($gateway)
    {
        $plan = $this->createPlan($gateway, 10000, 'plano');

        $this->assertStringStartsWith('price_', $plan->id);
        $this->assertSame(PlanInterval::MONTH, $plan->interval);
        $this->assertSame('BRL', $plan->currency);
        $this->assertTrue($plan->active);

        $found = MultiPayment::setGateway($gateway)->getPlan($plan->identifier);
        $this->assertSame($plan->id, $found->id);
        $this->assertSame($plan->identifier, $found->identifier);
        $this->assertSame(10000, $found->amount);
    }

    /**
     * Deve arquivar o plano por deactivatePlan, mantendo-o legível.
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldDeactivateAPlan($gateway)
    {
        $plan = $this->createPlan($gateway, 10000, 'arquivar');

        $deactivated = MultiPayment::setGateway($gateway)->gateway()->deactivatePlan($plan);

        $this->assertFalse($deactivated->active);

        $found = MultiPayment::setGateway($gateway)->getPlan($plan->id);
        $this->assertFalse($found->active);
    }

    /**
     * Deve criar a assinatura com trial em dias no cartão de teste, trocar o plano com
     * crédito, agendar o cancelamento ao fim do período, desfazê-lo e cancelar de vez.
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldRunTheSubscriptionLifecycleWithTrialAndCredit($gateway)
    {
        $mensal = $this->createPlan($gateway, 10000, 'mensal');
        $anual = $this->createPlan($gateway, 90000, 'anual', PlanInterval::YEAR);

        $customerData = self::customerWithoutAddress();
        $customer = new Customer();
        $customer->name = $customerData['name'];
        $customer->email = $customerData['email'];
        $customer->taxDocument = $customerData['taxDocument'];

        $creditCard = new CreditCard();
        $creditCard->token = 'pm_card_visa';

        $subscription = MultiPayment::setGateway($gateway)->newSubscription()
            ->setPlanId($mensal->identifier)
            ->setCustomer($customer)
            ->setCreditCard($creditCard)
            ->setTrialDays(7)
            ->create();
        $this->subscriptionsCriadas[] = $subscription->id;

        $this->assertSame(SubscriptionStatus::TRIALING, $subscription->status);
        $this->assertNull($subscription->trialDays);
        // uma hora de tolerância, para o teste não depender de fuso nem da virada do dia
        $this->assertEqualsWithDelta(
            now()->addDays(7)->getTimestamp(),
            $subscription->trialEndsAt->getTimestamp(),
            3600
        );
        $this->assertSame(PaymentMethod::CREDIT_CARD, $subscription->paymentMethod);
        $this->assertSame($mensal->identifier, $subscription->planId);

        // troca com crédito: a pró-rata fica para a próxima fatura, nada é cobrado agora
        $subscription = $subscription->changePlan($anual->identifier, ProrationBehavior::CREDIT, $gateway);
        $this->assertSame($anual->identifier, $subscription->planId);

        $subscription = $subscription->cancel(true, $gateway);
        $this->assertTrue($subscription->cancelAtPeriodEnd);
        $this->assertNotNull($subscription->canceledAt);
        $this->assertNotSame(SubscriptionStatus::CANCELED, $subscription->status);

        $subscription = $subscription->resume($gateway);
        $this->assertFalse($subscription->cancelAtPeriodEnd);
        $this->assertNull($subscription->canceledAt);

        $subscription = $subscription->cancel(false, $gateway);
        $this->assertSame(SubscriptionStatus::CANCELED, $subscription->status);
        $this->assertNotNull($subscription->canceledAt);
    }

    /**
     * O desconto vira um Coupon aplicado à assinatura: `cycles` acima de 1 num plano mensal é
     * `repeating` com o fim em `validUntil`, a primeira fatura sai com o abatimento e a
     * leitura devolve o desconto com o id do Coupon.
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldCreateASubscriptionWithACouponDiscount($gateway)
    {
        $mensal = $this->createPlan($gateway, 10000, 'cupom');

        $customerData = self::customerWithoutAddress();
        $customer = new Customer();
        $customer->name = $customerData['name'];
        $customer->email = $customerData['email'];
        $customer->taxDocument = $customerData['taxDocument'];

        $creditCard = new CreditCard();
        $creditCard->token = 'pm_card_visa';

        $subscription = MultiPayment::setGateway($gateway)->newSubscription()
            ->setPlanId($mensal->identifier)
            ->setCustomer($customer)
            ->setCreditCard($creditCard)
            ->addAmountDiscount('Promo', 500, 3)
            ->create();
        $this->subscriptionsCriadas[] = $subscription->id;

        $this->assertCount(1, $subscription->discounts);
        $discount = $subscription->discounts[0];
        $this->couponsCriados[] = $discount->id;
        $this->assertNotEmpty($discount->id);
        $this->assertSame('Promo', $discount->description);
        $this->assertSame(500, $discount->amountOff);
        // duração repeating de 3 meses: a Stripe informa o fim do desconto
        $this->assertNotNull($discount->validUntil);
        $this->assertEqualsWithDelta(
            now()->addMonths(3)->getTimestamp(),
            $discount->validUntil->getTimestamp(),
            86400 * 4
        );

        $this->assertSame(9500, $subscription->latestInvoice->paidAmount);

        $lida = MultiPayment::setGateway($gateway)->getSubscription($subscription->id);
        $this->assertSame($discount->id, $lida->discounts[0]->id);
        $this->assertSame(500, $lida->discounts[0]->amountOff);
    }

    /**
     * Deve simular a troca de plano com as linhas reais de pró-rata da Stripe.
     */
    #[DataProvider('stripeGatewayDataProvider')]
    public function testShouldPreviewAPlanChangeWithRealLines($gateway)
    {
        $mensal = $this->createPlan($gateway, 10000, 'mensal-preview');
        $anual = $this->createPlan($gateway, 90000, 'anual-preview', PlanInterval::YEAR);

        $customerData = self::customerWithoutAddress();
        $customer = new Customer();
        $customer->name = $customerData['name'];
        $customer->email = $customerData['email'];
        $customer->taxDocument = $customerData['taxDocument'];

        $creditCard = new CreditCard();
        $creditCard->token = 'pm_card_visa';

        $subscription = MultiPayment::setGateway($gateway)->newSubscription()
            ->setPlanId($mensal->identifier)
            ->setCustomer($customer)
            ->setCreditCard($creditCard)
            ->create();
        $this->subscriptionsCriadas[] = $subscription->id;

        $preview = $subscription->previewPlanChange($anual->identifier, $gateway);

        $this->assertNotEmpty($preview->items);
        $this->assertTrue($preview->appliesImmediately);
        $this->assertSame($preview->amount, array_sum(array_map(
            static fn ($item) => $item->price * ($item->quantity ?? 1),
            $preview->items
        )));
        $credito = array_filter($preview->items, static fn ($item) => $item->price < 0);
        $this->assertNotEmpty($credito, 'a prévia deve trazer a linha de crédito do período não usado');
    }
}
