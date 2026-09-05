<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Carbon\Carbon;
use Stripe\ApiRequestor;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Gateways\StripeGateway;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Enums\DeclineCode;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Enums\InvoiceOriginType;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\ChargingException;
use Potelo\MultiPayment\Exceptions\ValidationException;
use Potelo\MultiPayment\Exceptions\RefundNotSupportedException;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;
use Potelo\MultiPayment\Tests\Unit\RecordingLogger;

/**
 * Fatura de assinatura do Stripe (objeto Invoice, origem `INVOICE`) pelo caminho público:
 * `getInvoice()` com id `in_`, a tabela de precedência de status, o cancelamento por `void`,
 * o estorno pelo PaymentIntent da fatura, a cobrança manual por `invoices.pay` e as recusas
 * de duplicação e de captura. As respostas em `tests/fixtures/stripe/`
 * foram gravadas na sandbox (ver o README da pasta); as variações que a sandbox não produz
 * são montadas sobre elas.
 */
class StripeGatewayStripeInvoiceTest extends TestCase
{
    private const INVOICE_EXPAND = ['payments.data.payment.payment_intent'];

    private const PAYMENT_INTENT_EXPAND = ['latest_charge.balance_transaction', 'latest_charge.refunds'];

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment.gateways.stripe.api_key' => 'sk_test_fake',
        ]));
        $app->instance('log', $this->logger = new RecordingLogger());
        Facade::setFacadeApplication($app);

        RecordingStripeHttpClient::withResponses([]);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testGetInvoiceWithAnInvoiceIdReadsTheStripeInvoiceWithItsPaymentsExpanded(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([self::fixture('invoices/open_requires_payment_method')]);

        $result = $this->getInvoice('in_1UBHTnPjx0CusuMrjxjg8WhK');

        $this->assertSame(['get /v1/invoices/in_1UBHTnPjx0CusuMrjxjg8WhK'], self::calledPaths($httpClient));
        $this->assertSame(['expand' => self::INVOICE_EXPAND], $httpClient->calls[0][2]);

        $this->assertSame('in_1UBHTnPjx0CusuMrjxjg8WhK', $result->id);
        $this->assertSame(InvoiceOriginType::INVOICE, $result->originType);
        $this->assertSame(InvoiceStatus::PENDING, $result->status);
        $this->assertSame('stripe', $result->gateway);
        $this->assertSame(12345, $result->amount);
        $this->assertNull($result->paidAmount);
        $this->assertNull($result->refundedAmount);
        $this->assertSame([], $result->refunds);
        $this->assertNull($result->paidAt);
        $this->assertNull($result->fee);
        $this->assertSame(1788368263, $result->createdAt->getTimestamp());
        $this->assertNull($result->dueDate);
        $this->assertNull($result->pixExpiresAt);
        $this->assertStringStartsWith('https://invoice.stripe.com/i/', $result->url);
        $this->assertSame('cus_VBen1v8T4Qa6XX', $result->customer->id);
        $this->assertSame(PaymentMethod::CREDIT_CARD, $result->paymentMethod);
        $this->assertSame([PaymentMethod::CREDIT_CARD], $result->availablePaymentMethods);
        $this->assertNull($result->creditCard);
        $this->assertNull($result->pix);
        $this->assertInstanceOf(\Stripe\Invoice::class, $result->original);
        $this->assertSame([], $this->logger->records);
    }

    public function testLineItemsComeFromTheInvoiceLines(): void
    {
        RecordingStripeHttpClient::withResponses([self::fixture('invoices/open_requires_payment_method')]);

        $result = $this->getInvoice('in_1UBHTnPjx0CusuMrjxjg8WhK');

        $this->assertCount(1, $result->items);
        $this->assertSame('Assinatura mensal', $result->items[0]->description);
        $this->assertSame(12345, $result->items[0]->price);
        $this->assertSame(1, $result->items[0]->quantity);
    }

    /**
     * Linha com quantidade maior que um usa o valor unitário de `pricing`; sem `pricing`, o
     * `amount` da linha dividido pela quantidade.
     */
    public function testLineItemPriceIsTheUnitAmount(): void
    {
        $response = self::fixture('invoices/open_requires_payment_method');
        $line = $response['lines']['data'][0];
        $withPricing = array_merge($line, ['id' => 'il_1', 'amount' => 20000, 'quantity' => 2, 'description' => 'Com pricing']);
        $withPricing['pricing']['unit_amount_decimal'] = '10000';
        $withoutPricing = array_merge($line, ['id' => 'il_2', 'amount' => 3000, 'quantity' => 3, 'description' => 'Sem pricing', 'pricing' => null]);
        $response['lines']['data'] = [$withPricing, $withoutPricing];
        RecordingStripeHttpClient::withResponses([$response]);

        $items = $this->getInvoice('in_1UBHTnPjx0CusuMrjxjg8WhK')->items;

        $this->assertSame([10000, 2], [$items[0]->price, $items[0]->quantity]);
        $this->assertSame([1000, 3], [$items[1]->price, $items[1]->quantity]);
    }

    public function testDueDateBecomesDueDate(): void
    {
        $response = self::fixture('invoices/open_requires_payment_method');
        $response['due_date'] = 1789000000;
        RecordingStripeHttpClient::withResponses([$response]);

        $result = $this->getInvoice('in_1UBHTnPjx0CusuMrjxjg8WhK');

        $this->assertInstanceOf(Carbon::class, $result->dueDate);
        $this->assertSame(1789000000, $result->dueDate->getTimestamp());
        $this->assertNull($result->pixExpiresAt);
    }

    public function testGetInvoiceWithAPaymentIntentIdKeepsThePaymentIntentOrigin(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([self::fixture('payment_intents/paid')]);

        $result = $this->getInvoice('pi_3UBHTpPjx0CusuMr1JTEiHGi');

        $this->assertSame(['get /v1/payment_intents/pi_3UBHTpPjx0CusuMr1JTEiHGi'], self::calledPaths($httpClient));
        $this->assertSame(InvoiceOriginType::PAYMENT_INTENT, $result->originType);
        $this->assertSame(InvoiceStatus::PAID, $result->status);
        $this->assertInstanceOf(\Stripe\PaymentIntent::class, $result->original);
    }

    /**
     * Uma linha da tabela de precedência por caso: fixture do Invoice, respostas seguintes
     * (PaymentIntent relido e lista de disputes, quando há), status esperado e requisições.
     *
     * @return array<string, array{string, string[], InvoiceStatus, string[]}>
     */
    public static function precedenceTableProvider(): array
    {
        $invoice = 'get /v1/invoices/in_1UBHTnPjx0CusuMrjxjg8WhK';
        $paymentIntent = 'get /v1/payment_intents/pi_3UBHTpPjx0CusuMr1JTEiHGi';

        return [
            'draft' => ['invoices/draft', [], InvoiceStatus::PENDING, [$invoice]],
            'open sem PaymentIntent' => ['invoices/open_without_payment_intent', [], InvoiceStatus::PENDING, [$invoice]],
            'open + requires_payment_method' => ['invoices/open_requires_payment_method', [], InvoiceStatus::PENDING, [$invoice]],
            'open + requires_payment_method após recusa' => [
                'invoices/open_after_declined_attempt',
                ['payment_intents/after_declined_attempt'],
                InvoiceStatus::PENDING,
                [$invoice, $paymentIntent],
            ],
            'open + requires_action' => [
                'invoices/open_requires_action',
                ['payment_intents/requires_action'],
                InvoiceStatus::PENDING,
                [$invoice, $paymentIntent],
            ],
            'open + requires_confirmation' => ['invoices/open_requires_confirmation', [], InvoiceStatus::PENDING, [$invoice]],
            'open + requires_capture' => ['invoices/open_requires_capture', [], InvoiceStatus::AUTHORIZED, [$invoice]],
            'open + processing' => ['invoices/open_processing', [], InvoiceStatus::PROCESSING, [$invoice]],
            'open parcialmente paga' => ['invoices/open_partially_paid', [], InvoiceStatus::PARTIALLY_PAID, [$invoice]],
            'paid + succeeded sem estorno' => ['invoices/paid', ['payment_intents/paid'], InvoiceStatus::PAID, [$invoice, $paymentIntent]],
            'paid + estorno parcial' => [
                'invoices/paid',
                ['payment_intents/partially_refunded'],
                InvoiceStatus::PARTIALLY_REFUNDED,
                [$invoice, $paymentIntent],
            ],
            'paid + estorno total' => ['invoices/paid', ['payment_intents/refunded'], InvoiceStatus::REFUNDED, [$invoice, $paymentIntent]],
            'paid + dispute aberta' => [
                'invoices/paid_disputed',
                ['payment_intents/disputed', 'disputes/needs_response'],
                InvoiceStatus::DISPUTED,
                ['get /v1/invoices/in_1UBHU4Pjx0CusuMrOTB0POaR', 'get /v1/payment_intents/pi_3UBHU5Pjx0CusuMr1cNe2BIo', 'get /v1/disputes'],
            ],
            'paid + dispute perdida' => [
                'invoices/paid_disputed',
                ['payment_intents/disputed', 'disputes/lost'],
                InvoiceStatus::CHARGEBACK,
                ['get /v1/invoices/in_1UBHU4Pjx0CusuMrOTB0POaR', 'get /v1/payment_intents/pi_3UBHU5Pjx0CusuMr1cNe2BIo', 'get /v1/disputes'],
            ],
            'paid fora da Stripe' => ['invoices/paid_out_of_band', [], InvoiceStatus::EXTERNALLY_PAID, ['get /v1/invoices/in_1UBHUHPjx0CusuMrD81KgsNd']],
            'paid sem cobrança (amount_due zero)' => ['invoices/paid_zero_amount_due', [], InvoiceStatus::PAID, ['get /v1/invoices/in_1UBHUNPjx0CusuMrEG6etDb8']],
            'void' => ['invoices/void', [], InvoiceStatus::CANCELED, ['get /v1/invoices/in_1UBHUBPjx0CusuMrdZ8CWaQW']],
            'uncollectible' => ['invoices/uncollectible', [], InvoiceStatus::EXPIRED, ['get /v1/invoices/in_1UBHUEPjx0CusuMrnkQe23fT']],
        ];
    }

    #[DataProvider('precedenceTableProvider')]
    public function testDerivesTheStatusFromTheInvoiceThenThePaymentIntentThenTheCharge(
        string $invoiceFixture,
        array $followingFixtures,
        InvoiceStatus $expected,
        array $expectedPaths
    ): void {
        $responses = [self::fixture($invoiceFixture)];
        foreach ($followingFixtures as $fixture) {
            $responses[] = self::fixture($fixture);
        }
        $httpClient = RecordingStripeHttpClient::withResponses($responses);

        $result = $this->getInvoice($responses[0]['id']);

        $this->assertSame($expected, $result->status);
        $this->assertSame(InvoiceOriginType::INVOICE, $result->originType);
        $this->assertSame($expectedPaths, self::calledPaths($httpClient));
        $this->assertSame([], $this->logger->records);
    }

    /**
     * O PaymentIntent da fatura só é relido quando já tem charge, e aí com o mesmo expand da
     * cobrança avulsa; a Stripe limita o expand a quatro níveis e o charge fica no quinto.
     */
    public function testReadsThePaymentIntentSeparatelyOnlyWhenItHasACharge(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::fixture('invoices/paid'),
            self::fixture('payment_intents/paid'),
        ]);

        $this->getInvoice('in_1UBHTnPjx0CusuMrjxjg8WhK');

        $this->assertSame(['expand' => self::PAYMENT_INTENT_EXPAND], $httpClient->calls[1][2]);
    }

    public function testPaidInvoiceCarriesTheChargeAmountsFeeAndCard(): void
    {
        RecordingStripeHttpClient::withResponses([
            self::fixture('invoices/paid'),
            self::fixture('payment_intents/paid'),
        ]);

        $result = $this->getInvoice('in_1UBHTnPjx0CusuMrjxjg8WhK');

        $this->assertSame(12345, $result->paidAmount);
        $this->assertSame(0, $result->refundedAmount);
        $this->assertSame([], $result->refunds);
        $this->assertSame(520, $result->fee);
        // paidAt vem de status_transitions.paid_at do Invoice
        $this->assertSame(1788368273, $result->paidAt->getTimestamp());
        $this->assertSame(PaymentMethod::CREDIT_CARD, $result->paymentMethod);
        $this->assertSame('visa', $result->creditCard->brand);
        $this->assertSame('4242', $result->creditCard->lastDigits);
    }

    public function testRefundedInvoiceListsTheRefundsOfTheCharge(): void
    {
        RecordingStripeHttpClient::withResponses([
            self::fixture('invoices/paid'),
            self::fixture('payment_intents/refunded'),
        ]);

        $result = $this->getInvoice('in_1UBHTnPjx0CusuMrjxjg8WhK');

        $this->assertSame(12345, $result->refundedAmount);
        $this->assertCount(2, $result->refunds);
        // a Stripe lista os estornos do mais recente para o mais antigo
        $this->assertSame([10000, 2345], array_map(static fn ($refund) => $refund->amount, $result->refunds));
        $this->assertSame('in_1UBHTnPjx0CusuMrjxjg8WhK', $result->refunds[0]->invoiceId);
        $this->assertStringStartsWith('re_', $result->refunds[0]->id);
    }

    public function testDisputedInvoiceListsTheDisputesOfTheCharge(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::fixture('invoices/paid_disputed'),
            self::fixture('payment_intents/disputed'),
            self::fixture('disputes/needs_response'),
        ]);

        $result = $this->getInvoice('in_1UBHU4Pjx0CusuMrOTB0POaR');

        $this->assertSame(InvoiceStatus::DISPUTED, $result->status);
        $this->assertSame(12345, $result->paidAmount);
        $this->assertSame(['charge' => 'ch_3UBHU5Pjx0CusuMr1q2mLg5W', 'limit' => 100], $httpClient->calls[2][2]);

        // em Invoice::$disputes a contestação aponta a fatura lida, aqui o objeto Invoice
        $this->assertCount(1, $result->disputes);
        $this->assertSame('du_1UBHU8Pjx0CusuMr3hVB1hzt', $result->disputes[0]->id);
        $this->assertSame('in_1UBHU4Pjx0CusuMrOTB0POaR', $result->disputes[0]->invoiceId);
        $this->assertTrue($result->disputes[0]->status->isOpen());
    }

    /**
     * Paga fora da Stripe, a fatura tem o PaymentIntent padrão cancelado e um InvoicePayment
     * do tipo `payment_record`; o valor recebido vem do Invoice e o método fica em aberto.
     */
    public function testExternallyPaidInvoiceReadsTheAmountFromTheInvoice(): void
    {
        RecordingStripeHttpClient::withResponses([self::fixture('invoices/paid_out_of_band')]);

        $result = $this->getInvoice('in_1UBHUHPjx0CusuMrD81KgsNd');

        $this->assertSame(InvoiceStatus::EXTERNALLY_PAID, $result->status);
        $this->assertSame(12345, $result->paidAmount);
        $this->assertNull($result->refundedAmount);
        $this->assertNull($result->paymentMethod);
        $this->assertSame(1788368296, $result->paidAt->getTimestamp());
    }

    /**
     * Paga fora da Stripe, a fatura não traz `lastPaymentError`: o erro do PaymentIntent
     * cancelado não descreve o pagamento recebido, como vale para o método de pagamento.
     */
    public function testExternallyPaidInvoiceHasNoLastPaymentError(): void
    {
        $response = self::fixture('invoices/paid_out_of_band');
        foreach ($response['payments']['data'] as $index => $payment) {
            if (($payment['payment']['type'] ?? null) === 'payment_intent') {
                $response['payments']['data'][$index]['payment']['payment_intent']['last_payment_error'] = [
                    'code' => 'card_declined',
                    'decline_code' => 'generic_decline',
                    'message' => 'Your card was declined.',
                ];
            }
        }
        RecordingStripeHttpClient::withResponses([$response]);

        $result = $this->getInvoice('in_1UBHUHPjx0CusuMrD81KgsNd');

        $this->assertSame(InvoiceStatus::EXTERNALLY_PAID, $result->status);
        $this->assertNull($result->lastPaymentError);
    }

    /**
     * `amount_paid_off_stripe`, quando a versão da API o devolve, também sinaliza pagamento
     * externo.
     */
    public function testAmountPaidOffStripeAlsoReadsAsExternallyPaid(): void
    {
        $response = self::fixture('invoices/paid_out_of_band');
        $response['payments']['data'] = [];
        $response['amount_paid_off_stripe'] = 12345;
        RecordingStripeHttpClient::withResponses([$response]);

        $this->assertSame(InvoiceStatus::EXTERNALLY_PAID, $this->getInvoice($response['id'])->status);
    }

    public function testInvoicePaidWithoutAChargeHasZeroPaidAmount(): void
    {
        RecordingStripeHttpClient::withResponses([self::fixture('invoices/paid_zero_amount_due')]);

        $result = $this->getInvoice('in_1UBHUNPjx0CusuMrEG6etDb8');

        $this->assertSame(InvoiceStatus::PAID, $result->status);
        $this->assertSame(0, $result->amount);
        $this->assertSame(0, $result->paidAmount);
        $this->assertNull($result->paymentMethod);
        $this->assertSame('Período de teste', $result->items[0]->description);
    }

    public function testPartiallyPaidInvoiceReadsTheAmountPaidSoFar(): void
    {
        RecordingStripeHttpClient::withResponses([self::fixture('invoices/open_partially_paid')]);

        $result = $this->getInvoice('in_1UBHTnPjx0CusuMrjxjg8WhK');

        $this->assertSame(InvoiceStatus::PARTIALLY_PAID, $result->status);
        $this->assertSame(5000, $result->paidAmount);
        $this->assertTrue($result->status->isSettled());
        $this->assertTrue($result->status->isOpen());
    }

    /**
     * Combinações fora da tabela: cada uma vira `UNKNOWN` com um aviso no log que traz os três
     * status e o id da fatura.
     *
     * @return array<string, array{string, string}>
     */
    public static function unknownCombinationProvider(): array
    {
        return [
            'open + succeeded (transição)' => ['open', 'succeeded'],
            'open + canceled' => ['open', 'canceled'],
            'paid + requires_payment_method sem pagamento externo' => ['paid', 'requires_payment_method'],
            'status de Invoice desconhecido' => ['partially_funded', 'requires_payment_method'],
        ];
    }

    #[DataProvider('unknownCombinationProvider')]
    public function testCombinationOutsideTheTableReadsAsUnknownWithAWarning(string $invoiceStatus, string $paymentIntentStatus): void
    {
        $response = self::fixture('invoices/open_requires_payment_method');
        $response['status'] = $invoiceStatus;
        $response['payments']['data'][0]['payment']['payment_intent']['status'] = $paymentIntentStatus;
        RecordingStripeHttpClient::withResponses([$response]);

        $result = $this->getInvoice('in_1UBHTnPjx0CusuMrjxjg8WhK');

        $this->assertSame(InvoiceStatus::UNKNOWN, $result->status);
        $this->assertSame($invoiceStatus, $result->original->status);
        $this->assertCount(1, $this->logger->records);
        $this->assertSame('warning', $this->logger->records[0]['level']);
        $this->assertSame([
            'gateway' => 'stripe',
            'invoice_id' => 'in_1UBHTnPjx0CusuMrjxjg8WhK',
            'invoice_status' => $invoiceStatus,
            'payment_intent_status' => $paymentIntentStatus,
            'charge_status' => null,
        ], $this->logger->records[0]['context']);
        $this->assertStringContainsString('in_1UBHTnPjx0CusuMrjxjg8WhK', $this->logger->records[0]['message']);
    }

    /**
     * O aviso traz o status do charge quando o PaymentIntent relido tem um.
     */
    public function testUnknownCombinationLogsTheChargeStatusWhenThereIsACharge(): void
    {
        $paymentIntent = self::fixture('payment_intents/after_declined_attempt');
        $paymentIntent['status'] = 'succeeded';
        RecordingStripeHttpClient::withResponses([self::fixture('invoices/open_after_declined_attempt'), $paymentIntent]);

        $result = $this->getInvoice('in_1UBHTnPjx0CusuMrjxjg8WhK');

        $this->assertSame(InvoiceStatus::UNKNOWN, $result->status);
        $this->assertSame('succeeded', $this->logger->records[0]['context']['payment_intent_status']);
        $this->assertSame('failed', $this->logger->records[0]['context']['charge_status']);
    }

    /**
     * A fatura de assinatura com tentativa recusada traz `lastPaymentError` preenchido do
     * `last_payment_error` do PaymentIntent, com o mesmo `DeclineCode` da recusa síncrona; a
     * data vem do charge recusado e a orientação de retentativa vem do `advice_code`.
     */
    public function testADeclinedAttemptFillsTheLastPaymentErrorOnTheInvoice(): void
    {
        RecordingStripeHttpClient::withResponses([
            self::fixture('invoices/open_after_declined_attempt'),
            self::fixture('payment_intents/after_declined_attempt'),
        ]);

        $result = $this->getInvoice('in_1UBHTnPjx0CusuMrjxjg8WhK');

        $error = $result->lastPaymentError;
        $this->assertNotNull($error);
        $this->assertSame(DeclineCode::GENERIC, $error->declineCode);
        $this->assertSame('generic_decline', $error->gatewayCode);
        $this->assertSame('Your card was declined.', $error->message);
        $this->assertTrue($error->retryable());
        $this->assertSame(1788368268, $error->occurredAt->getTimestamp());
        $this->assertSame('stripe', $error->gateway);
        $this->assertSame('BRL', $result->currency);
        $this->assertSame([], $this->logger->records);
    }

    /**
     * O `last_finalization_error` da fatura tem precedência sobre o `last_payment_error` do
     * PaymentIntent; um erro de finalização não aponta um charge e fica sem `occurredAt`.
     */
    public function testTheFinalizationErrorTakesPrecedenceOverThePaymentIntentError(): void
    {
        $stripeInvoice = self::fixture('invoices/open_after_declined_attempt');
        $stripeInvoice['last_finalization_error'] = [
            'code' => 'expired_card',
            'message' => 'A finalização da fatura falhou.',
            'type' => 'card_error',
        ];
        RecordingStripeHttpClient::withResponses([$stripeInvoice, self::fixture('payment_intents/after_declined_attempt')]);

        $error = $this->getInvoice('in_1UBHTnPjx0CusuMrjxjg8WhK')->lastPaymentError;

        $this->assertSame(DeclineCode::EXPIRED_CARD, $error->declineCode);
        $this->assertSame('expired_card', $error->gatewayCode);
        $this->assertSame('A finalização da fatura falhou.', $error->message);
        $this->assertNull($error->occurredAt);
        $this->assertSame([], $this->logger->records);
    }

    /**
     * `paid` sem PaymentIntent e com `amount_due` acima de zero não está na tabela.
     */
    public function testPaidInvoiceWithoutAnyPaymentAndAnAmountDueReadsAsUnknown(): void
    {
        $response = self::fixture('invoices/paid_zero_amount_due');
        $response['amount_due'] = 12345;
        RecordingStripeHttpClient::withResponses([$response]);

        $this->assertSame(InvoiceStatus::UNKNOWN, $this->getInvoice($response['id'])->status);
        $this->assertCount(1, $this->logger->records);
        $this->assertNull($this->logger->records[0]['context']['payment_intent_status']);
    }

    /**
     * Entre vários pagamentos do Invoice sem nenhum pago, o padrão (`is_default`) é o
     * PaymentIntent da fatura.
     */
    public function testTheDefaultPaymentIsTheInvoicePaymentIntent(): void
    {
        $response = self::fixture('invoices/open_requires_payment_method');
        $default = $response['payments']['data'][0];
        $other = $default;
        $other['id'] = 'inpay_outro';
        $other['is_default'] = false;
        $other['payment']['payment_intent']['id'] = 'pi_outro';
        $other['payment']['payment_intent']['status'] = 'processing';
        $response['payments']['data'] = [$other, $default];
        RecordingStripeHttpClient::withResponses([$response]);

        $this->assertSame(InvoiceStatus::PENDING, $this->getInvoice($response['id'])->status);
    }

    /**
     * O pagamento pago é o PaymentIntent da fatura, mesmo quando outro pagamento é o padrão.
     */
    public function testThePaidPaymentIsTheInvoicePaymentIntentEvenWhenAnotherOneIsTheDefault(): void
    {
        $response = self::fixture('invoices/paid');
        $paid = $response['payments']['data'][0];
        $paid['is_default'] = false;
        $open = $paid;
        $open['id'] = 'inpay_aberto';
        $open['is_default'] = true;
        $open['status'] = 'open';
        $open['payment']['payment_intent']['id'] = 'pi_aberto';
        $open['payment']['payment_intent']['status'] = 'requires_payment_method';
        $open['payment']['payment_intent']['latest_charge'] = null;
        $response['payments']['data'] = [$open, $paid];
        $httpClient = RecordingStripeHttpClient::withResponses([$response, self::fixture('payment_intents/paid')]);

        $result = $this->getInvoice($response['id']);

        $this->assertSame(InvoiceStatus::PAID, $result->status);
        $this->assertSame('/v1/payment_intents/pi_3UBHTpPjx0CusuMr1JTEiHGi', parse_url($httpClient->calls[1][1], PHP_URL_PATH));
    }

    /**
     * Sem pagamento padrão nem pago, vale o primeiro do tipo PaymentIntent.
     */
    public function testWithoutADefaultOrPaidPaymentTheFirstOneIsUsed(): void
    {
        $response = self::fixture('invoices/open_requires_payment_method');
        $first = $response['payments']['data'][0];
        $first['is_default'] = false;
        $first['payment']['payment_intent']['status'] = 'processing';
        $second = $first;
        $second['id'] = 'inpay_segundo';
        $second['payment']['payment_intent']['id'] = 'pi_segundo';
        $second['payment']['payment_intent']['status'] = 'requires_capture';
        $record = ['id' => 'inpay_registro', 'object' => 'invoice_payment', 'status' => 'open', 'is_default' => false, 'payment' => ['type' => 'payment_record', 'payment_record' => 'pr_x']];
        $response['payments']['data'] = [$record, $first, $second];
        RecordingStripeHttpClient::withResponses([$response]);

        $this->assertSame(InvoiceStatus::PROCESSING, $this->getInvoice($response['id'])->status);
    }

    /**
     * InvoicePayment de PaymentIntent sem objeto nem id deixa a fatura sem PaymentIntent.
     */
    public function testPaymentIntentPaymentWithoutAnIdIsIgnored(): void
    {
        $response = self::fixture('invoices/open_requires_payment_method');
        $response['payments']['data'][0]['payment'] = ['type' => 'payment_intent'];
        $httpClient = RecordingStripeHttpClient::withResponses([$response]);

        $result = $this->getInvoice($response['id']);

        $this->assertSame(InvoiceStatus::PENDING, $result->status);
        $this->assertNull($result->paymentMethod);
        $this->assertCount(1, $httpClient->calls);
    }

    /**
     * Pagamento do tipo `charge` anexado à fatura lê como paga, sem refinamento de estorno ou
     * contestação.
     */
    public function testPaidInvoiceWhosePaymentIsAChargeReadsAsPaid(): void
    {
        $response = self::fixture('invoices/paid_out_of_band');
        $response['payments']['data'][0]['payment'] = ['type' => 'charge', 'charge' => 'ch_anexado'];
        $httpClient = RecordingStripeHttpClient::withResponses([$response]);

        $result = $this->getInvoice($response['id']);

        $this->assertSame(InvoiceStatus::PAID, $result->status);
        $this->assertSame(12345, $result->paidAmount);
        $this->assertCount(1, $httpClient->calls);
        $this->assertSame([], $this->logger->records);
    }

    public function testPaidAtFallsBackToTheChargeCreationWhenTheInvoiceHasNoPaidAt(): void
    {
        $response = self::fixture('invoices/paid');
        $response['status_transitions']['paid_at'] = null;
        $paymentIntent = self::fixture('payment_intents/paid');
        RecordingStripeHttpClient::withResponses([$response, $paymentIntent]);

        $result = $this->getInvoice($response['id']);

        $this->assertSame($paymentIntent['latest_charge']['created'], $result->paidAt->getTimestamp());
    }

    /**
     * Um `next_action` de 3DS não tem `pix_display_qr_code`; o parse não pode ler a chave às
     * cegas, senão o StripeObject registra "Undefined property" no logger da Stripe. Vale nas
     * duas origens.
     *
     * @return array<string, array{string, array}>
     */
    public static function threeDSecureNextActionProvider(): array
    {
        return [
            'PaymentIntent' => ['pi_3UBHTpPjx0CusuMr1JTEiHGi', ['payment_intents/requires_action']],
            'Invoice' => ['in_1UBHTnPjx0CusuMrjxjg8WhK', ['invoices/open_requires_action', 'payment_intents/requires_action']],
        ];
    }

    #[DataProvider('threeDSecureNextActionProvider')]
    public function testThreeDSecureNextActionDoesNotLogAnUndefinedProperty(string $id, array $fixtures): void
    {
        RecordingStripeHttpClient::withResponses(array_map([self::class, 'fixture'], $fixtures));
        $previousLogger = \Stripe\Stripe::getLogger();
        \Stripe\Stripe::setLogger($stripeLogger = new RecordingStripeLogger());

        try {
            $result = $this->getInvoice($id);
        } finally {
            \Stripe\Stripe::setLogger($previousLogger);
        }

        $this->assertSame(InvoiceStatus::PENDING, $result->status);
        $this->assertNull($result->pix);
        $this->assertSame([], $stripeLogger->messages);
    }

    /**
     * PaymentIntent que veio só como id (sem expand) é lido num GET.
     */
    public function testPaymentIntentGivenAsIdIsRead(): void
    {
        $response = self::fixture('invoices/open_requires_payment_method');
        $response['payments']['data'][0]['payment']['payment_intent'] = 'pi_3UBHTpPjx0CusuMr1JTEiHGi';
        $paymentIntent = self::fixture('payment_intents/after_declined_attempt');
        $paymentIntent['status'] = 'processing';
        $httpClient = RecordingStripeHttpClient::withResponses([$response, $paymentIntent]);

        $result = $this->getInvoice($response['id']);

        $this->assertSame(InvoiceStatus::PROCESSING, $result->status);
        $this->assertCount(2, $httpClient->calls);
        $this->assertSame('/v1/payment_intents/pi_3UBHTpPjx0CusuMr1JTEiHGi', parse_url($httpClient->calls[1][1], PHP_URL_PATH));
    }

    /**
     * Fatura de assinatura paga por Pix: o QR Code e a expiração dele vêm do PaymentIntent, o
     * vencimento vem da fatura e `url` continua sendo a página hospedada da fatura.
     */
    public function testPixQrCodeComesFromThePaymentIntentAndUrlStaysTheHostedInvoicePage(): void
    {
        $response = self::fixture('invoices/open_requires_payment_method');
        $response['due_date'] = 1789000000;
        $paymentIntent = &$response['payments']['data'][0]['payment']['payment_intent'];
        $paymentIntent['status'] = 'requires_action';
        $paymentIntent['payment_method_types'] = ['pix'];
        $paymentIntent['next_action'] = [
            'type' => 'pix_display_qr_code',
            'pix_display_qr_code' => [
                'data' => '00020126pixcopiaecola',
                'image_url_png' => 'https://qr.stripe.com/test.png',
                'expires_at' => 1788400000,
                'hosted_instructions_url' => 'https://payments.stripe.com/qr/instructions/test',
            ],
        ];
        unset($paymentIntent);
        RecordingStripeHttpClient::withResponses([$response]);

        $result = $this->getInvoice($response['id']);

        $this->assertSame(InvoiceStatus::PENDING, $result->status);
        $this->assertSame(PaymentMethod::PIX, $result->paymentMethod);
        $this->assertSame('00020126pixcopiaecola', $result->pix->qrCodeText);
        $this->assertSame('https://qr.stripe.com/test.png', $result->pix->qrCodeImageUrl);
        $this->assertSame(1788400000, $result->pixExpiresAt->getTimestamp());
        $this->assertSame(1789000000, $result->dueDate->getTimestamp());
        $this->assertStringStartsWith('https://invoice.stripe.com/i/', $result->url);
    }

    public function testCancelInvoiceVoidsTheStripeInvoiceAfterReadingIt(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::fixture('invoices/open_requires_payment_method'),
            self::fixture('invoices/void'),
        ]);

        $invoice = new Invoice();
        $invoice->id = 'in_1UBHTnPjx0CusuMrjxjg8WhK';
        $result = (new StripeGateway())->cancelInvoice($invoice, 'chave-void');

        $this->assertSame([
            'get /v1/invoices/in_1UBHTnPjx0CusuMrjxjg8WhK',
            'post /v1/invoices/in_1UBHTnPjx0CusuMrjxjg8WhK/void',
        ], self::calledPaths($httpClient));
        $this->assertSame(['expand' => self::INVOICE_EXPAND], $httpClient->calls[1][2]);
        $this->assertSame('chave-void', $httpClient->header(1, 'Idempotency-Key'));
        $this->assertNull($httpClient->header(0, 'Idempotency-Key'));

        $this->assertSame($invoice, $result);
        $this->assertSame(InvoiceStatus::CANCELED, $result->status);
        $this->assertSame(InvoiceOriginType::INVOICE, $result->originType);
    }

    public function testCancelInvoiceRefusesADraftWithoutVoiding(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([self::fixture('invoices/draft')]);

        $invoice = new Invoice();
        $invoice->id = 'in_1UBHTnPjx0CusuMrjxjg8WhK';

        try {
            (new StripeGateway())->cancelInvoice($invoice);
            $this->fail('Rascunho deveria lançar UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertStringContainsString('rascunho', $e->getMessage());
            $this->assertStringContainsString('in_1UBHTnPjx0CusuMrjxjg8WhK', $e->getMessage());
            $this->assertSame(Capability::INVOICE_CANCELLATION, $e->capability);
            $this->assertSame(UnsupportedOperationException::REASON_GATEWAY_LIMITATION, $e->reason);
            $this->assertNull($e->httpStatus);
        }
        $this->assertSame(['get /v1/invoices/in_1UBHTnPjx0CusuMrjxjg8WhK'], self::calledPaths($httpClient));
    }

    public function testCancelPaidInvoiceBecomesValidationExceptionFromTheStripeRefusal(): void
    {
        RecordingStripeHttpClient::withResponses([
            self::fixture('invoices/paid'),
            [['error' => [
                'type' => 'invalid_request_error',
                'message' => 'Invoices with `paid` payments cannot be voided.',
            ]], 400],
        ]);

        $invoice = new Invoice();
        $invoice->id = 'in_1UBHTnPjx0CusuMrjxjg8WhK';

        try {
            (new StripeGateway())->cancelInvoice($invoice);
            $this->fail('Fatura paga deveria lançar ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(400, $e->httpStatus);
            $this->assertSame(['base' => ['Invoices with `paid` payments cannot be voided.']], $e->fieldErrors);
        }
    }

    public function testCancelInvoiceWithAPaymentIntentIdStillCancelsThePaymentIntent(): void
    {
        $response = self::fixture('payment_intents/after_declined_attempt');
        $response['status'] = 'canceled';
        $httpClient = RecordingStripeHttpClient::withResponses([$response]);

        $invoice = new Invoice();
        $invoice->id = 'pi_3UBHTpPjx0CusuMr1JTEiHGi';
        $result = (new StripeGateway())->cancelInvoice($invoice);

        $this->assertSame(['post /v1/payment_intents/pi_3UBHTpPjx0CusuMr1JTEiHGi/cancel'], self::calledPaths($httpClient));
        $this->assertSame(InvoiceStatus::CANCELED, $result->status);
        $this->assertSame(InvoiceOriginType::PAYMENT_INTENT, $result->originType);
    }

    public function testDuplicateInvoiceRefusesAStripeInvoiceBeforeAnyRequest(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);

        $invoice = new Invoice();
        $invoice->id = 'in_1UBHTnPjx0CusuMrjxjg8WhK';

        try {
            (new StripeGateway())->duplicateInvoice($invoice, Carbon::now()->addDay());
            $this->fail('Fatura de assinatura deveria lançar UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::INVOICE_DUPLICATION, $e->capability);
            $this->assertSame(UnsupportedOperationException::REASON_GATEWAY_LIMITATION, $e->reason);
            $this->assertSame('stripe', $e->gateway);
            $this->assertStringContainsString('in_1UBHTnPjx0CusuMrjxjg8WhK', $e->getMessage());
        }
        $this->assertSame([], $httpClient->calls);
    }

    /**
     * O estorno de uma fatura de assinatura relê a fatura, aponta o `POST /v1/refunds` para o
     * PaymentIntent dela e relê a fatura estornada; o status vem do charge (`REFUNDED`),
     * enquanto o objeto Invoice da Stripe segue `paid`.
     */
    public function testRefundInvoiceOnAStripeInvoiceRefundsItsPaymentIntent(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::fixture('invoices/paid'),
            self::fixture('payment_intents/paid'),
            self::refundResponse('re_fake123', 12345, 'pending'),
            self::fixture('invoices/paid'),
            self::fixture('payment_intents/refunded'),
        ]);

        $invoice = new Invoice();
        $invoice->id = 'in_1UBHTnPjx0CusuMrjxjg8WhK';
        $result = (new StripeGateway())->refundInvoice($invoice, null, 'chave-estorno');

        $this->assertSame([
            'get /v1/invoices/in_1UBHTnPjx0CusuMrjxjg8WhK',
            'get /v1/payment_intents/pi_3UBHTpPjx0CusuMr1JTEiHGi',
            'post /v1/refunds',
            'get /v1/invoices/in_1UBHTnPjx0CusuMrjxjg8WhK',
            'get /v1/payment_intents/pi_3UBHTpPjx0CusuMr1JTEiHGi',
        ], self::calledPaths($httpClient));
        $this->assertSame(['payment_intent' => 'pi_3UBHTpPjx0CusuMr1JTEiHGi'], $httpClient->calls[2][2]);
        $this->assertSame('chave-estorno', $httpClient->header(2, 'Idempotency-Key'));

        $this->assertSame('re_fake123', $result->id);
        $this->assertSame('in_1UBHTnPjx0CusuMrjxjg8WhK', $result->invoiceId);
        $this->assertSame(12345, $result->amount);
        $this->assertSame($invoice, $result->invoice());
        $this->assertSame(InvoiceStatus::REFUNDED, $invoice->status);
        $this->assertSame(12345, $invoice->refundedAmount);
    }

    public function testPartialRefundOnAStripeInvoiceSendsTheAmount(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::fixture('invoices/paid'),
            self::fixture('payment_intents/paid'),
            self::refundResponse('re_fake123', 2345, 'pending'),
            self::fixture('invoices/paid'),
            self::fixture('payment_intents/partially_refunded'),
        ]);

        $invoice = new Invoice();
        $invoice->id = 'in_1UBHTnPjx0CusuMrjxjg8WhK';
        $result = (new StripeGateway())->refundInvoice($invoice, 2345);

        $this->assertSame(
            ['payment_intent' => 'pi_3UBHTpPjx0CusuMr1JTEiHGi', 'amount' => 2345],
            $httpClient->calls[2][2]
        );
        $this->assertSame(2345, $result->amount);
        $this->assertSame(InvoiceStatus::PARTIALLY_REFUNDED, $result->invoice()->status);
    }

    /**
     * O valor acima do restante é recusado pela guarda depois da releitura, sem `POST`.
     */
    public function testRefundAboveTheRemainderOnAStripeInvoiceIsRefusedWithoutPosting(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::fixture('invoices/paid'),
            self::fixture('payment_intents/partially_refunded'),
        ]);

        $invoice = new Invoice();
        $invoice->id = 'in_1UBHTnPjx0CusuMrjxjg8WhK';

        try {
            (new StripeGateway())->refundInvoice($invoice, 12345);
            $this->fail('Esperava RefundNotSupportedException');
        } catch (RefundNotSupportedException $e) {
            $this->assertSame(RefundNotSupportedException::REASON_AMOUNT_EXCEEDS_REFUNDABLE, $e->reason);
        }
        $this->assertCount(2, $httpClient->calls);
    }

    /**
     * A fatura paga fora da Stripe não tem cobrança estornável pela API: a recusa vem antes do
     * `POST` e pede a devolução manual do pagamento recebido fora do gateway.
     */
    public function testRefundOnAnExternallyPaidStripeInvoiceIsRefused(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([self::fixture('invoices/paid_out_of_band')]);

        $invoice = new Invoice();
        $invoice->id = 'in_1UBHUHPjx0CusuMrD81KgsNd';

        try {
            (new StripeGateway())->refundInvoice($invoice);
            $this->fail('Esperava RefundNotSupportedException');
        } catch (RefundNotSupportedException $e) {
            $this->assertSame(RefundNotSupportedException::REASON_NO_GATEWAY_CHARGE, $e->reason);
            $this->assertTrue($e->manualRefundRequired);
            $this->assertFalse($e->isCapabilityLimitation());
        }
        $this->assertSame(['get /v1/invoices/in_1UBHUHPjx0CusuMrD81KgsNd'], self::calledPaths($httpClient));
    }

    /**
     * A fatura quitada sem cobrança (`amount_due` zero) não tem o que estornar; nada foi pago
     * fora do gateway, então a devolução manual não se aplica.
     */
    public function testRefundOnAnInvoicePaidWithoutAChargeIsRefused(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([self::fixture('invoices/paid_zero_amount_due')]);

        $invoice = new Invoice();
        $invoice->id = 'in_1UBHUNPjx0CusuMrEG6etDb8';

        try {
            (new StripeGateway())->refundInvoice($invoice);
            $this->fail('Esperava RefundNotSupportedException');
        } catch (RefundNotSupportedException $e) {
            $this->assertSame(RefundNotSupportedException::REASON_NO_GATEWAY_CHARGE, $e->reason);
            $this->assertFalse($e->manualRefundRequired);
        }
        $this->assertSame(['get /v1/invoices/in_1UBHUNPjx0CusuMrEG6etDb8'], self::calledPaths($httpClient));
    }

    public function testChargeInvoiceWithCreditCardOnAStripeInvoicePaysItWithTheCard(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::fixture('invoices/paid'),
            self::fixture('payment_intents/paid'),
        ]);

        $invoice = new Invoice();
        $invoice->id = 'in_1UBHTnPjx0CusuMrjxjg8WhK';
        $invoice->creditCard = new CreditCard();
        $invoice->creditCard->id = 'pm_fake123';
        $result = (new StripeGateway())->chargeInvoiceWithCreditCard($invoice, 'chave-pay');

        $this->assertSame([
            'post /v1/invoices/in_1UBHTnPjx0CusuMrjxjg8WhK/pay',
            'get /v1/payment_intents/pi_3UBHTpPjx0CusuMr1JTEiHGi',
        ], self::calledPaths($httpClient));
        $this->assertSame(
            ['payment_method' => 'pm_fake123', 'expand' => self::INVOICE_EXPAND],
            $httpClient->calls[0][2]
        );
        $this->assertSame('chave-pay', $httpClient->header(0, 'Idempotency-Key'));

        $this->assertSame($invoice, $result);
        $this->assertSame(InvoiceStatus::PAID, $result->status);
        $this->assertSame(InvoiceOriginType::INVOICE, $result->originType);
    }

    /**
     * O `invoices.pay` exige um PaymentMethod anexado ao cliente da fatura: um cartão por
     * token é salvo antes, com o cliente resolvido pela releitura da fatura. Um token que
     * exige autenticação do pagador derruba a operação antes do `pay`, com a orientação do
     * fluxo em duas etapas.
     */
    public function testPayAStripeInvoiceWithATokenThatRequiresAuthenticationFailsBeforeThePay(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([
            self::fixture('invoices/paid'),
            self::fixture('payment_intents/paid'),
            self::fixture('setup_intents/requires_action'),
        ]);

        $invoice = new Invoice();
        $invoice->id = 'in_1UBHTnPjx0CusuMrjxjg8WhK';
        $invoice->creditCard = new CreditCard();
        $invoice->creditCard->token = 'pm_fake123';

        try {
            (new StripeGateway())->chargeInvoiceWithCreditCard($invoice);
            $this->fail('Esperava ChargingException');
        } catch (ChargingException $e) {
            $this->assertSame(DeclineCode::AUTHENTICATION_REQUIRED, $e->declineCode);
            $this->assertStringContainsString('confirmCreditCardSetup', $e->getMessage());
        }

        $this->assertSame([
            'get /v1/invoices/in_1UBHTnPjx0CusuMrjxjg8WhK',
            'get /v1/payment_intents/pi_3UBHTpPjx0CusuMr1JTEiHGi',
            'post /v1/setup_intents',
        ], self::calledPaths($httpClient));
        // o cliente do SetupIntent vem da fatura relida
        $this->assertSame('cus_VBen1v8T4Qa6XX', $httpClient->calls[2][2]['customer']);
    }

    /**
     * `refundableAmount()` de uma fatura de assinatura é o restante do charge dela; a fatura
     * paga fora da Stripe devolve zero, porque o estorno a recusa.
     */
    public function testRefundableAmountOnAStripeInvoiceIsTheRemainderOfItsCharge(): void
    {
        RecordingStripeHttpClient::withResponses([
            self::fixture('invoices/paid'),
            self::fixture('payment_intents/partially_refunded'),
        ]);
        $invoice = new Invoice();
        $invoice->id = 'in_1UBHTnPjx0CusuMrjxjg8WhK';

        $this->assertSame(10000, (new StripeGateway())->refundableAmount($invoice));
    }

    public function testRefundableAmountOnAnExternallyPaidStripeInvoiceIsZero(): void
    {
        RecordingStripeHttpClient::withResponses([self::fixture('invoices/paid_out_of_band')]);
        $invoice = new Invoice();
        $invoice->id = 'in_1UBHUHPjx0CusuMrD81KgsNd';

        $this->assertSame(0, (new StripeGateway())->refundableAmount($invoice));
    }

    /**
     * A fatura de assinatura é cobrada pela Stripe com captura imediata; a captura em duas
     * etapas é restrição consultável de `DELAYED_CAPTURE` e a recusa vem antes de qualquer
     * requisição.
     */
    public function testCaptureInvoiceRefusesAStripeInvoiceBeforeAnyRequest(): void
    {
        $httpClient = RecordingStripeHttpClient::withResponses([]);
        $invoice = new Invoice();
        $invoice->id = 'in_1UBHTnPjx0CusuMrjxjg8WhK';

        try {
            (new StripeGateway())->captureInvoice($invoice);
            $this->fail('Esperava UnsupportedOperationException');
        } catch (UnsupportedOperationException $e) {
            $this->assertSame(Capability::DELAYED_CAPTURE, $e->capability);
            $this->assertSame(UnsupportedOperationException::REASON_GATEWAY_LIMITATION, $e->reason);
        }
        $this->assertSame([], $httpClient->calls);
    }

    /**
     * Resposta de `POST /v1/refunds`, montada com os campos que `parseRefund()` lê.
     */
    private static function refundResponse(string $id, int $amount, string $status): array
    {
        return [
            'id' => $id,
            'object' => 'refund',
            'amount' => $amount,
            'status' => $status,
            'created' => 1786700100,
            'reason' => 'requested_by_customer',
            'payment_intent' => 'pi_3UBHTpPjx0CusuMr1JTEiHGi',
        ];
    }

    private function getInvoice(string $id): Invoice
    {
        $invoice = new Invoice();
        $invoice->id = $id;

        return (new StripeGateway())->getInvoice($invoice);
    }

    /**
     * @return string[]  `método caminho` de cada chamada gravada
     */
    private static function calledPaths(RecordingStripeHttpClient $httpClient): array
    {
        return array_map(
            static fn (array $call) => $call[0] . ' ' . parse_url($call[1], PHP_URL_PATH),
            $httpClient->calls
        );
    }

    /**
     * Resposta gravada em `tests/fixtures/stripe/<caminho>.json`, como array.
     */
    private static function fixture(string $path): array
    {
        return json_decode(file_get_contents(__DIR__ . "/../../fixtures/stripe/{$path}.json"), true);
    }
}
