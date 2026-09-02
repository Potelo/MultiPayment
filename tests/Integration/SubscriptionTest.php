<?php

namespace Potelo\MultiPayment\Tests\Integration;

use Iugu;
use Iugu_APIRequest;
use Carbon\Carbon;
use Potelo\MultiPayment\Models\Plan;
use Potelo\MultiPayment\Tests\TestCase;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Models\SubscriptionItem;
use Potelo\MultiPayment\Facades\MultiPayment;
use Potelo\MultiPayment\Models\SubscriptionDiscount;

/**
 * Cobre o que só a sandbox prova: a serialização do SDK, os endpoints de plano e assinatura e
 * o formato das respostas que o gateway traduz. O que é recusado antes de qualquer requisição
 * fica na suíte Unit.
 */
class SubscriptionTest extends TestCase
{
    private const GATEWAY = 'iugu';

    /** @var array<string, string[]> ids criados, removidos no tearDown */
    private array $criados = ['subscriptions' => [], 'plans' => []];

    /** @var string[] ids de fatura, que a Iugu cancela em vez de apagar */
    private array $faturasCriadas = [];

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        foreach ($this->criados as $recurso => $ids) {
            foreach ($ids as $id) {
                try {
                    (new Iugu_APIRequest())->request(
                        'DELETE',
                        Iugu::getBaseURI() . "/{$recurso}/" . rawurlencode($id),
                        []
                    );
                } catch (\Throwable $e) {
                    // limpeza é best effort: falha ao remover não invalida o teste
                }
            }
        }

        foreach ($this->faturasCriadas as $id) {
            try {
                (new Iugu_APIRequest())->request(
                    'PUT',
                    Iugu::getBaseURI() . '/invoices/' . rawurlencode($id) . '/cancel',
                    []
                );
            } catch (\Throwable $e) {
                // limpeza é best effort, como acima
            }
        }

        parent::tearDown();
    }

    private function createPlan(
        int $amount,
        string $sufixo,
        string $interval = Plan::INTERVAL_MONTH,
        int $intervalCount = 1
    ): Plan {
        $plan = new Plan();
        $plan->name = 'MultiPayment teste ' . $sufixo;
        $plan->identifier = 'multipayment-teste-' . $sufixo . '-' . now()->format('YmdHisu');
        $plan->amount = $amount;
        $plan->interval = $interval;
        $plan->intervalCount = $intervalCount;
        $plan->save(self::GATEWAY);
        $this->criados['plans'][] = $plan->id;

        return $plan;
    }

    private function createSubscription(Plan $plan, ?Carbon $nextBillingAt = null): Subscription
    {
        $customer = $this->createCustomer(self::GATEWAY, $this->customerWithoutAddress());

        $builder = MultiPayment::setGateway(self::GATEWAY)->newSubscription()
            ->setPlanId($plan->identifier)
            ->setCustomerId($customer->id)
            ->setAvailablePaymentMethods([Invoice::PAYMENT_METHOD_PIX]);

        if ($nextBillingAt) {
            $builder->setNextBillingAt($nextBillingAt);
        }

        $subscription = $builder->create();
        $this->criados['subscriptions'][] = $subscription->id;

        return $subscription;
    }

    /**
     * Deve criar o plano, buscá-lo por id e por identifier e paginar a listagem.
     *
     * @return void
     */
    public function testShouldCreateGetAndListPlans(): void
    {
        $plan = $this->createPlan(12345, 'plano');

        $this->assertNotEmpty($plan->id);
        $this->assertSame(12345, $plan->amount);
        $this->assertSame(Plan::INTERVAL_MONTH, $plan->interval);
        $this->assertSame('iugu', $plan->gateway);

        $porIdentifier = new Plan();
        $porIdentifier->identifier = $plan->identifier;
        $this->assertSame($plan->id, $porIdentifier->get(self::GATEWAY)->id);

        $porId = new Plan();
        $porId->id = $plan->id;
        $this->assertSame($plan->identifier, $porId->get(self::GATEWAY)->identifier);

        $this->createPlan(500, 'plano2');

        $primeira = MultiPayment::setGateway(self::GATEWAY)->listPlans(1, 1);
        $segunda = MultiPayment::setGateway(self::GATEWAY)->listPlans(2, 1);

        $this->assertCount(1, $primeira);
        $this->assertCount(1, $segunda);
        $this->assertInstanceOf(Plan::class, $primeira[0]);
        $this->assertNotSame($primeira[0]->id, $segunda[0]->id);
    }

    /**
     * A Iugu não tem intervalo anual; o plano anual deve ser aceito como 12 meses e voltar como
     * `year` tanto na resposta da criação quanto numa leitura posterior.
     *
     * @return void
     */
    public function testShouldCreateAYearlyPlanAsTwelveMonths(): void
    {
        $plan = $this->createPlan(120000, 'anual', Plan::INTERVAL_YEAR);

        $this->assertNotEmpty($plan->id);
        $this->assertSame(Plan::INTERVAL_YEAR, $plan->interval);
        $this->assertSame(1, $plan->intervalCount);
        $this->assertSame(12, $plan->original->interval);
        $this->assertSame('months', $plan->original->interval_type);

        $lido = new Plan();
        $lido->id = $plan->id;
        $lido = $lido->get(self::GATEWAY);

        $this->assertSame(Plan::INTERVAL_YEAR, $lido->interval);
        $this->assertSame(1, $lido->intervalCount);
    }

    /**
     * Deve criar, ler, suspender, reativar, cancelar e listar a assinatura.
     *
     * @return void
     */
    public function testShouldRunTheSubscriptionLifecycle(): void
    {
        $nextBillingAt = now()->addMonth();
        $plan = $this->createPlan(10000, 'ciclo');
        $subscription = $this->createSubscription($plan, $nextBillingAt);

        $this->assertNotEmpty($subscription->id);
        $this->assertSame($plan->identifier, $subscription->planId);
        $this->assertSame('iugu', $subscription->gateway);
        $this->assertSame(
            $nextBillingAt->format('Y-m-d'),
            $subscription->nextBillingAt->format('Y-m-d')
        );
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);

        $lida = new Subscription();
        $lida->id = $subscription->id;
        $lida = $lida->get(self::GATEWAY);
        $this->assertSame($subscription->id, $lida->id);
        $this->assertSame($subscription->planId, $lida->planId);

        $suspensa = $lida->suspend(self::GATEWAY);
        $this->assertSame(Subscription::STATUS_SUSPENDED, $suspensa->status);

        $reativada = $suspensa->resume(self::GATEWAY);
        $this->assertSame(Subscription::STATUS_ACTIVE, $reativada->status);

        $cancelada = $reativada->cancel(false, self::GATEWAY);
        $this->assertSame(Subscription::STATUS_SUSPENDED, $cancelada->status);

        $doCliente = MultiPayment::setGateway(self::GATEWAY)
            ->listSubscriptions($subscription->customer->id);
        $this->assertCount(1, $doCliente);
        $this->assertSame($subscription->id, $doCliente[0]->id);
    }

    /**
     * Assinatura sem data de cobrança não volta com resume(): a Iugu responde sem erro e sem
     * mudar o estado.
     *
     * @return void
     */
    public function testShouldNotResumeASubscriptionWithoutABillingDate(): void
    {
        $plan = $this->createPlan(10000, 'semdata');
        $subscription = $this->createSubscription($plan);

        $this->assertNull($subscription->nextBillingAt);

        $suspensa = $subscription->suspend(self::GATEWAY);
        $this->assertSame(Subscription::STATUS_SUSPENDED, $suspensa->status);

        $reativada = $suspensa->resume(self::GATEWAY);
        $this->assertSame(Subscription::STATUS_SUSPENDED, $reativada->status);
    }

    /**
     * Itens e descontos são declarativos: a lista informada vira o estado da assinatura, e a
     * lista que ficar em `null` é preservada.
     *
     * @return void
     */
    public function testShouldReplaceItemsWithoutTouchingDiscounts(): void
    {
        $plan = $this->createPlan(10000, 'itens');
        $subscription = $this->createSubscription($plan, now()->addMonth());

        $avulso = $this->item('Setup', 900, 1);
        $avulso->recurring = false;

        $subscription->items = [$this->item('Consultas', 2500, 2), $avulso];
        $subscription->discounts = [$this->discount('Promo', 500)];
        $subscription->save(self::GATEWAY);

        $lida = new Subscription();
        $lida->id = $subscription->id;
        $lida = $lida->get(self::GATEWAY);

        $this->assertCount(2, $lida->items);
        $porDescricao = [];
        foreach ($lida->items as $item) {
            $porDescricao[$item->description] = $item;
        }
        $this->assertSame(2500, $porDescricao['Consultas']->amount);
        $this->assertSame(2, $porDescricao['Consultas']->quantity);
        $this->assertTrue($porDescricao['Consultas']->recurring);
        // o encoder do SDK transforma false em string vazia, por isso o gateway manda inteiro
        $this->assertFalse($porDescricao['Setup']->recurring);
        $this->assertCount(1, $lida->discounts);
        $this->assertSame(500, $lida->discounts[0]->amountOff);

        $idDoDesconto = $lida->discounts[0]->id;

        $lida->items = [$this->item('Monitoramentos', 700, 1)];
        $lida->discounts = null;
        $lida->save(self::GATEWAY);

        $depois = new Subscription();
        $depois->id = $subscription->id;
        $depois = $depois->get(self::GATEWAY);

        $this->assertCount(1, $depois->items);
        $this->assertSame('Monitoramentos', $depois->items[0]->description);
        $this->assertCount(1, $depois->discounts);
        $this->assertSame($idDoDesconto, $depois->discounts[0]->id);
        $this->assertSame(500, $depois->discounts[0]->amountOff);
    }

    /**
     * Deve simular a troca de plano e aplicá-la sem gerar cobrança.
     *
     * @return void
     */
    public function testShouldChangePlanAndPreviewIt(): void
    {
        $plan = $this->createPlan(10000, 'origem');
        $planoNovo = $this->createPlan(30000, 'destino');
        $subscription = $this->createSubscription($plan, now()->addMonth());

        $preview = $subscription->previewPlanChange($planoNovo->identifier, self::GATEWAY);
        $this->assertSame('iugu', $preview->gateway);
        $this->assertSame(30000, $preview->amount);
        $this->assertNull($preview->items);
        $this->assertSame($planoNovo->identifier, $preview->original->new_plan);
        $this->assertSame($plan->identifier, $preview->original->old_plan);

        $trocada = $subscription->changePlan($planoNovo->identifier, false, self::GATEWAY);
        $this->assertSame($planoNovo->identifier, $trocada->planId);
        $this->assertSame(30000, $trocada->amount);
    }

    /**
     * Deve trocar o plano pelo endpoint change_plan, que gera na hora uma fatura pendente,
     * resumida e com vencimento anterior ao próximo ciclo.
     *
     * @return void
     */
    public function testShouldChangePlanGeneratingTheCharge(): void
    {
        $proximaCobranca = now()->addMonth();
        $plan = $this->createPlan(10000, 'cobra-origem');
        $planoNovo = $this->createPlan(30000, 'cobra-destino');
        $subscription = $this->createSubscription($plan, $proximaCobranca);

        // guarda: sem isto a asserção de latestInvoice abaixo passaria com a da leitura anterior
        $this->assertNull($subscription->latestInvoice);

        $trocada = $subscription->changePlan($planoNovo->identifier, true, self::GATEWAY);

        $this->assertSame($planoNovo->identifier, $trocada->planId);
        $this->assertSame(30000, $trocada->amount);
        $this->assertSame(Subscription::STATUS_ACTIVE, $trocada->status);

        $this->assertNotNull($trocada->latestInvoice);
        $this->faturasCriadas[] = $trocada->latestInvoice->id;

        $this->assertSame(Invoice::STATUS_PENDING, $trocada->latestInvoice->status);
        // a cobrança é imediata, e não a do próximo ciclo; comparar contra now() traria o fuso
        // do gateway para dentro do teste
        $this->assertTrue($trocada->latestInvoice->expiresAt->lessThan($proximaCobranca));
        // o resumo de recent_invoices traz o valor formatado, sem centavos, e sem secure_url
        $this->assertSame('R$ 300,00', $trocada->latestInvoice->original->total);
        $this->assertNull($trocada->latestInvoice->amount);
        $this->assertNull($trocada->latestInvoice->url);
    }

    private function item(string $description, int $amount, int $quantity): SubscriptionItem
    {
        $item = new SubscriptionItem();
        $item->description = $description;
        $item->amount = $amount;
        $item->quantity = $quantity;

        return $item;
    }

    private function discount(string $description, int $amountOff): SubscriptionDiscount
    {
        $discount = new SubscriptionDiscount();
        $discount->description = $description;
        $discount->amountOff = $amountOff;

        return $discount;
    }
}
