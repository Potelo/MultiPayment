<?php

namespace Potelo\MultiPayment\Facades;

use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Assert;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Builders\InvoiceBuilder;
use Potelo\MultiPayment\Builders\CustomerBuilder;
use Potelo\MultiPayment\Builders\CreditCardBuilder;
use Potelo\MultiPayment\Testing\FakeGateway;


/**
 * @method static Invoice charge(array $attributes, ?string $idempotencyKey = null)
 * @method static InvoiceBuilder newInvoice()
 * @method static CustomerBuilder newCustomer()
 * @method static CreditCardBuilder newCreditCard()
 * @method static \Potelo\MultiPayment\Builders\SubscriptionBuilder newSubscription()
 * @method static \Potelo\MultiPayment\Listing\SubscriptionList|\Potelo\MultiPayment\Models\Subscription[] listSubscriptions(\Potelo\MultiPayment\Listing\SubscriptionFilter|\Potelo\MultiPayment\Models\Customer|string $filter, int $page = 1, int $limit = 100)
 * @method static \Potelo\MultiPayment\Listing\InvoiceList listInvoices(\Potelo\MultiPayment\Listing\InvoiceFilter $filter)
 * @method static \Potelo\MultiPayment\Models\SubscriptionPlanChange previewSubscriptionPlanChange(\Potelo\MultiPayment\Models\Subscription|string $subscription, string $planId, \Potelo\MultiPayment\Enums\ProrationBehavior $proration = \Potelo\MultiPayment\Enums\ProrationBehavior::CHARGE_DIFFERENCE)
 * @method static \Potelo\MultiPayment\Models\Plan[] listPlans(int $page = 1, int $limit = 100)
 * @method static \Potelo\MultiPayment\Models\WebhookEvent parseWebhook(string $rawBody, array $headers)
 * @method static \Potelo\MultiPayment\Models\WebhookEvent parseWebhookRequest(\Illuminate\Http\Request $request)
 * @method static \Potelo\MultiPayment\Webhooks\WebhookHandler webhooks()
 * @method static Invoice getInvoice(string $id)
 * @method static \Potelo\MultiPayment\Models\Subscription getSubscription(string $id)
 * @method static \Potelo\MultiPayment\Models\Plan getPlan(string $idOrIdentifier)
 * @method static \Potelo\MultiPayment\Models\Customer getCustomer(string $id)
 * @method static \Potelo\MultiPayment\Models\Dispute getDispute(string $id)
 * @method static \Potelo\MultiPayment\Models\Dispute[] listDisputes(int $page = 1, int $limit = 100)
 * @method static \Potelo\MultiPayment\Models\Dispute contestDispute(string $id, array $evidence, ?string $idempotencyKey = null)
 * @method static \Potelo\MultiPayment\Models\Dispute acceptDispute(string $id, ?string $idempotencyKey = null)
 * @method static \Potelo\MultiPayment\Models\Refund refundInvoice(string $id, ?int $partialValueCents = null, ?string $idempotencyKey = null)
 * @method static int refundableAmount(string $id)
 * @method static Invoice duplicateInvoice(Invoice|string $invoice, \Carbon\Carbon $expiresAt, array $gatewayOptions = [], ?string $idempotencyKey = null)
 * @method static CreditCard getCard(string $customerId, string $creditCardId)
 * @method static CreditCard confirmCreditCardSetup(string $setupId, ?string $idempotencyKey = null)
 * @method static void deleteCard(string $customerId, string $creditCardId, ?string $idempotencyKey = null)
 * @method static \Potelo\MultiPayment\MultiPayment setGateway($gateway)
 * @method static \Potelo\MultiPayment\Contracts\GatewayContract gateway($gateway = null)
 * @method static bool supports(\Potelo\MultiPayment\Enums\Capability $capability, $gateway = null)
 * @method static \Potelo\MultiPayment\Enums\Capability[] capabilities($gateway = null)
 * @method static \Potelo\MultiPayment\Enums\Capability[] notYetImplemented($gateway = null)
 * @method static \Potelo\MultiPayment\Enums\Capability[] emulated($gateway = null)
 * @method static bool isEmulated(\Potelo\MultiPayment\Enums\Capability $capability, $gateway = null)
 * @method static bool supportsAll(\Potelo\MultiPayment\Enums\Capability ...$capabilities)
 * @method static \Potelo\MultiPayment\Capabilities\CapabilityRestriction|null restriction(\Potelo\MultiPayment\Enums\Capability $capability, $gateway = null)
 * @method static array<string, \Potelo\MultiPayment\Capabilities\CapabilityRestriction> restrictions($gateway = null)
 * @method static Invoice chargeInvoiceWithCreditCard($invoice, ?string $creditCardToken = null, ?string $creditCardId = null, ?string $idempotencyKey = null)
 * @method static \Potelo\MultiPayment\Models\Customer setDefaultCard(string $customerId, string $creditCardId, ?string $idempotencyKey = null)
 * @method static Invoice captureInvoice(Invoice|string $invoice, ?int $amount = null, ?string $idempotencyKey = null)
 * @method static Invoice cancelInvoice(Invoice|string $invoice, ?string $idempotencyKey = null)
 * @method static Invoice rescheduleAutomaticPixPayment(Invoice|string $invoice, ?string $idempotencyKey = null)
 * @method static \Potelo\MultiPayment\Models\AutomaticPixCancellation cancelAutomaticPixRecurrence(\Potelo\MultiPayment\Models\AutomaticPix|string $automaticPix, ?string $idempotencyKey = null)
 * @method static \Potelo\MultiPayment\Models\AutomaticPixCancellation cancelAutomaticPixScheduledPayment(\Potelo\MultiPayment\Models\AutomaticPixCharge|string $charge, ?string $endToEndId = null, ?string $idempotencyKey = null)
 * @method static \Potelo\MultiPayment\Models\AutomaticPixCancellation getAutomaticPixCancellation(\Potelo\MultiPayment\Models\AutomaticPixCancellation|string $cancellation, ?string $cancellationId = null)
 * @method static \Potelo\MultiPayment\Models\AutomaticPixCancellation[] listAutomaticPixCancellations(\Potelo\MultiPayment\Models\AutomaticPix|string $automaticPix, int $page = 1, int $limit = 100)
 */
class MultiPayment extends Facade
{

    /**
     * Fakes registrados pela última chamada de `fake()`, por nome de gateway.
     *
     * @var array<string, FakeGateway>
     */
    protected static array $fakes = [];

    /**
     * Container em que os fakes foram registrados. As asserções só valem enquanto ele for o
     * container corrente da Facade; um container novo (outro teste) invalida os fakes.
     *
     * @var \Illuminate\Contracts\Container\Container|null
     */
    protected static $fakedApp = null;

    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'multiPayment';
    }

    /**
     * Substitui os gateways pelo `FakeGateway` no container: todos os configurados em
     * `multi-payment.gateways` quando `$gateways` é nulo, ou só os nomeados. Cada fake é
     * registrado como instância na chave `multi-payment.gateway.{nome}` do container, que
     * `ConfigurationHelper::resolveGateway()` consulta antes de instanciar o driver real, e
     * fica disponível para as asserções da facade (`assertInvoiceCreated()`,
     * `assertNothingCharged()`...). Chamar de novo descarta os fakes da chamada anterior.
     *
     * @param  string[]|null  $gateways  nomes das chaves a substituir; nulo substitui todas
     * @return array<string, FakeGateway>  os fakes registrados, por nome
     */
    public static function fake(?array $gateways = null): array
    {
        $app = static::getFacadeApplication();
        $names = $gateways ?? array_keys((array) $app['config']->get('multi-payment.gateways', []));

        static::$fakes = [];
        static::$fakedApp = $app;
        foreach ($names as $name) {
            $fake = new FakeGateway($name);
            $app->instance("multi-payment.gateway.{$name}", $fake);
            static::$fakes[$name] = $fake;
        }

        static::clearResolvedInstance('multiPayment');

        return static::$fakes;
    }

    /**
     * Afirma que alguma fatura foi criada nos fakes e, com callback, que pelo menos uma das
     * criadas o satisfaz.
     *
     * @param  callable|null  $callback  recebe a `Invoice` e devolve verdadeiro quando ela é a esperada
     * @return void
     */
    public static function assertInvoiceCreated(?callable $callback = null): void
    {
        $invoices = static::collectFromFakes(fn (FakeGateway $fake) => $fake->createdInvoices());

        Assert::assertNotEmpty($invoices, 'Nenhuma fatura foi criada nos gateways falsos.');
        if (!is_null($callback)) {
            Assert::assertTrue(
                !empty(array_filter($invoices, fn (Invoice $invoice) => (bool) $callback($invoice))),
                'Nenhuma fatura criada satisfaz o callback.'
            );
        }
    }

    /**
     * Afirma que alguma assinatura foi criada nos fakes e, com callback, que pelo menos uma
     * das criadas o satisfaz.
     *
     * @param  callable|null  $callback  recebe a `Subscription` e devolve verdadeiro quando ela é a esperada
     * @return void
     */
    public static function assertSubscriptionCreated(?callable $callback = null): void
    {
        $subscriptions = static::collectFromFakes(fn (FakeGateway $fake) => $fake->createdSubscriptions());

        Assert::assertNotEmpty($subscriptions, 'Nenhuma assinatura foi criada nos gateways falsos.');
        if (!is_null($callback)) {
            Assert::assertTrue(
                !empty(array_filter($subscriptions, fn (Subscription $subscription) => (bool) $callback($subscription))),
                'Nenhuma assinatura criada satisfaz o callback.'
            );
        }
    }

    /**
     * Afirma que a fatura foi estornada nos fakes; com valor, que algum estorno dela foi desse
     * valor em centavos.
     *
     * @param  string  $invoiceId
     * @param  int|null  $amount  valor em centavos; nulo aceita qualquer valor
     * @return void
     */
    public static function assertRefunded(string $invoiceId, ?int $amount = null): void
    {
        $refunds = array_filter(
            static::collectFromFakes(fn (FakeGateway $fake) => $fake->refundsMade()),
            fn (array $refund) => $refund['invoice_id'] === $invoiceId
                && (is_null($amount) || $refund['amount'] === $amount)
        );

        $expected = is_null($amount) ? '' : " de {$amount} centavos";
        Assert::assertNotEmpty($refunds, "Nenhum estorno{$expected} da fatura [{$invoiceId}] nos gateways falsos.");
    }

    /**
     * Afirma que nenhuma operação de cobrança aconteceu nos fakes: nenhuma fatura criada,
     * nenhuma assinatura criada e nenhuma cobrança ou captura sobre fatura existente.
     *
     * @return void
     */
    public static function assertNothingCharged(): void
    {
        Assert::assertEmpty(
            static::collectFromFakes(fn (FakeGateway $fake) => $fake->createdInvoices()),
            'Uma fatura foi criada nos gateways falsos.'
        );
        Assert::assertEmpty(
            static::collectFromFakes(fn (FakeGateway $fake) => $fake->createdSubscriptions()),
            'Uma assinatura foi criada nos gateways falsos.'
        );
        Assert::assertEmpty(
            static::collectFromFakes(fn (FakeGateway $fake) => $fake->charges()),
            'Uma cobrança foi feita nos gateways falsos.'
        );
    }

    /**
     * Afirma que a capability foi consultada por `supports()` em algum fake, pela aplicação ou
     * pelas guardas dos models.
     *
     * @param  Capability  $capability
     * @return void
     */
    public static function assertCapabilityChecked(Capability $capability): void
    {
        $checked = static::collectFromFakes(fn (FakeGateway $fake) => $fake->checkedCapabilities());

        Assert::assertContains(
            $capability,
            $checked,
            "A capability [{$capability->value}] não foi consultada nos gateways falsos."
        );
    }

    /**
     * Reúne dados dos fakes registrados; falha quando `fake()` ainda não foi chamado no
     * container corrente da Facade (fakes de um container anterior não contam).
     *
     * @param  callable  $collector  recebe cada `FakeGateway` e devolve uma lista
     * @return array
     */
    protected static function collectFromFakes(callable $collector): array
    {
        if (empty(static::$fakes) || static::$fakedApp !== static::getFacadeApplication()) {
            Assert::fail('Chame MultiPayment::fake() antes das asserções.');
        }

        return array_merge(...array_map($collector, array_values(static::$fakes)));
    }
}
