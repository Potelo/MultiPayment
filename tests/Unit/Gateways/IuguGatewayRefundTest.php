<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Carbon\Carbon;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Gateways\IuguGateway;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\NotFoundException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;
use Potelo\MultiPayment\Exceptions\RefundNotSupportedException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Enums\PaymentMethod;

class IuguGatewayRefundTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment.gateways.iugu.api_key' => 'test-api-key',
        ]));
        Facade::setFacadeApplication($app);

        Carbon::setTestNow('2026-09-02 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testRefundInvoiceRequiresId(): void
    {
        $this->expectException(ModelAttributeValidationException::class);

        (new IuguGateway(new QueuedIuguApiRequest([])))->refundInvoice(new Invoice());
    }

    public function testBoletoRefundThrowsBeforeTheNetworkAfterReadingTheInvoice(): void
    {
        $api = new QueuedIuguApiRequest([$this->paidInvoiceResponse([
            'payment_method' => 'iugu_bank_slip',
            'payable_with' => 'bank_slip',
        ])]);

        $exception = $this->refundExpectingRefusal($api, $this->invoiceWithId());

        $this->assertSame(RefundNotSupportedException::REASON_BOLETO_NO_REFUND, $exception->reason);
        $this->assertSame(PaymentMethod::BANK_SLIP->value, $exception->paymentMethod);
        $this->assertTrue($exception->manualRefundRequired);
        $this->assertOnlyTheInvoiceWasRead($api);
    }

    public function testBoletoRefundWithThePaymentMethodInHandMakesNoRequest(): void
    {
        $api = new QueuedIuguApiRequest([]);
        $invoice = $this->invoiceWithId();
        $invoice->paymentMethod = PaymentMethod::BANK_SLIP;
        $invoice->status = InvoiceStatus::PAID;
        $invoice->paidAt = Carbon::parse('2026-08-20');

        $exception = $this->refundExpectingRefusal($api, $invoice);

        $this->assertSame(RefundNotSupportedException::REASON_BOLETO_NO_REFUND, $exception->reason);
        $this->assertSame([], $api->calls);
    }

    public function testPartialPixRefundThrowsBeforeTheNetwork(): void
    {
        $api = new QueuedIuguApiRequest([$this->paidPixInvoiceResponse()]);
        $invoice = $this->invoiceWithId();
        $invoice->refundedAmount = 5000;

        $exception = $this->refundExpectingRefusal($api, $invoice);

        $this->assertSame(RefundNotSupportedException::REASON_PIX_PARTIAL_NOT_SUPPORTED, $exception->reason);
        $this->assertSame(PaymentMethod::PIX->value, $exception->paymentMethod);
        $this->assertFalse($exception->manualRefundRequired);
        $this->assertStringContainsString('5000', $exception->getMessage());
        $this->assertStringContainsString('10000', $exception->getMessage());
        $this->assertOnlyTheInvoiceWasRead($api);
    }

    public function testFullPixRefundWithoutAmountGoesToTheGateway(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->paidPixInvoiceResponse(),
            $this->paidPixInvoiceResponse(['status' => 'refunded', 'refunded_cents' => 10000, 'paid_cents' => 0]),
        ]);

        $result = (new IuguGateway($api))->refundInvoice($this->invoiceWithId());

        $this->assertCount(2, $api->calls);
        $this->assertSame('POST', $api->calls[1]['method']);
        $this->assertStringEndsWith('/invoices/inv_1/refund', $api->calls[1]['url']);
        $this->assertSame([], $api->calls[1]['data']);
        $this->assertSame(InvoiceStatus::REFUNDED, $result->status);
        $this->assertSame(10000, $result->refundedAmount);
        $this->assertNull($result->lastRefundId);
    }

    /**
     * Pix só aceita estorno integral: pedir exatamente o valor pago não pode virar um
     * `partial_value_refund_cents` que a Iugu recusaria.
     */
    public function testPixRefundOfTheFullPaidAmountIsSentAsIntegral(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->paidPixInvoiceResponse(),
            $this->paidPixInvoiceResponse(['status' => 'refunded', 'refunded_cents' => 10000, 'paid_cents' => 0]),
        ]);
        $invoice = $this->invoiceWithId();
        $invoice->refundedAmount = 10000;

        $result = (new IuguGateway($api))->refundInvoice($invoice);

        $this->assertSame([], $api->calls[1]['data']);
        $this->assertSame(InvoiceStatus::REFUNDED, $result->status);
    }

    public function testPartialCardRefundSendsThePartialValue(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->paidInvoiceResponse(),
            $this->paidInvoiceResponse(['status' => 'partially_refunded', 'refunded_cents' => 2500, 'paid_cents' => 7500]),
        ]);
        $invoice = $this->invoiceWithId();
        $invoice->refundedAmount = 2500;

        $result = (new IuguGateway($api))->refundInvoice($invoice);

        $this->assertSame('POST', $api->calls[1]['method']);
        $this->assertSame(['partial_value_refund_cents' => 2500], $api->calls[1]['data']);
        $this->assertSame(InvoiceStatus::PARTIALLY_REFUNDED, $result->status);
        $this->assertSame(2500, $result->refundedAmount);
        $this->assertSame(7500, $result->paidAmount);
    }

    /**
     * Fatura parcialmente estornada aceita novo estorno: a guarda `already_refunded` olha só
     * `refunded`. Pedir exatamente o que resta vai como integral.
     */
    public function testPartiallyRefundedInvoiceAcceptsAnotherRefund(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->paidInvoiceResponse(['status' => 'partially_refunded', 'refunded_cents' => 2500, 'paid_cents' => 7500]),
            $this->paidInvoiceResponse(['status' => 'refunded', 'refunded_cents' => 10000, 'paid_cents' => 0]),
        ]);
        $invoice = $this->invoiceWithId();
        $invoice->refundedAmount = 7500;

        $result = (new IuguGateway($api))->refundInvoice($invoice);

        $this->assertCount(2, $api->calls);
        $this->assertSame([], $api->calls[1]['data']);
        $this->assertSame(InvoiceStatus::REFUNDED, $result->status);
    }

    /**
     * Regressão: a leitura prévia não pode vazar para o model do chamador. Se o estorno falha,
     * `refundedAmount` continua sendo o valor pedido, senão um retry viraria estorno integral.
     */
    public function testFailedRefundLeavesTheCallerModelUntouched(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->paidInvoiceResponse(),
            (object) ['errors' => 'Fatura não pode ser reembolsada'],
        ]);
        $invoice = $this->invoiceWithId();
        $invoice->refundedAmount = 2500;

        try {
            (new IuguGateway($api))->refundInvoice($invoice);
            $this->fail('Esperava GatewayException');
        } catch (GatewayException $e) {
        }

        $this->assertSame(2500, $invoice->refundedAmount);
        $this->assertNull($invoice->status);
        $this->assertNull($invoice->paymentMethod);
    }

    public function testRefusedRefundLeavesTheCallerModelUntouched(): void
    {
        $api = new QueuedIuguApiRequest([$this->paidPixInvoiceResponse()]);
        $invoice = $this->invoiceWithId();
        $invoice->refundedAmount = 5000;

        $this->refundExpectingRefusal($api, $invoice);

        $this->assertSame(5000, $invoice->refundedAmount);
        $this->assertNull($invoice->status);
    }

    /**
     * Regressão: model montado pela aplicação com método, status e data, mas sem o valor pago.
     * Estorno por valor precisa do `paidAmount` para decidir entre integral e parcial, então o
     * driver lê a fatura mesmo assim, e o valor cheio de um Pix segue como integral.
     */
    public function testPixRefundByAmountWithoutPaidAmountReadsTheInvoiceFirst(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->paidPixInvoiceResponse(),
            $this->paidPixInvoiceResponse(['status' => 'refunded', 'refunded_cents' => 10000, 'paid_cents' => 0]),
        ]);
        $invoice = $this->invoiceWithId();
        $invoice->paymentMethod = PaymentMethod::PIX;
        $invoice->status = InvoiceStatus::PAID;
        $invoice->paidAt = Carbon::parse('2026-08-20');
        $invoice->refundedAmount = 10000;

        $result = (new IuguGateway($api))->refundInvoice($invoice);

        $this->assertCount(2, $api->calls);
        $this->assertSame('GET', $api->calls[0]['method']);
        $this->assertSame([], $api->calls[1]['data']);
        $this->assertSame(InvoiceStatus::REFUNDED, $result->status);
    }

    /**
     * Boleto pendente chega da Iugu sem `payment_method` (só preenchido após o pagamento), então
     * a guarda de boleto não dispara e é a API que recusa. Documenta o comportamento atual.
     */
    public function testPendingInvoiceWithoutPaymentMethodGoesToTheGateway(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->paidInvoiceResponse([
                'status' => 'pending',
                'payment_method' => null,
                'payable_with' => 'bank_slip',
                'paid_at' => null,
                'paid_cents' => 0,
            ]),
            (object) ['errors' => 'Fatura não pode ser reembolsada'],
        ]);

        try {
            (new IuguGateway($api))->refundInvoice($this->invoiceWithId());
            $this->fail('Esperava GatewayException');
        } catch (GatewayException $e) {
        }

        $this->assertCount(2, $api->calls);
        $this->assertSame('POST', $api->calls[1]['method']);
    }

    public function testAlreadyRefundedInvoiceThrowsBeforeTheNetwork(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->paidInvoiceResponse(['status' => 'refunded', 'refunded_cents' => 10000, 'paid_cents' => 0]),
        ]);

        $exception = $this->refundExpectingRefusal($api, $this->invoiceWithId());

        $this->assertSame(RefundNotSupportedException::REASON_ALREADY_REFUNDED, $exception->reason);
        $this->assertSame(PaymentMethod::CREDIT_CARD->value, $exception->paymentMethod);
        $this->assertFalse($exception->manualRefundRequired);
        $this->assertOnlyTheInvoiceWasRead($api);
    }

    public function testRefundAfterTheNinetyDayWindowThrowsBeforeTheNetwork(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->paidInvoiceResponse(['paid_at' => '2026-06-03T12:00:00-03:00']),
        ]);

        $exception = $this->refundExpectingRefusal($api, $this->invoiceWithId());

        $this->assertSame(RefundNotSupportedException::REASON_REFUND_WINDOW_EXPIRED, $exception->reason);
        $this->assertSame(PaymentMethod::CREDIT_CARD->value, $exception->paymentMethod);
        $this->assertTrue($exception->manualRefundRequired);
        $this->assertStringContainsString('2026-06-03', $exception->getMessage());
        $this->assertOnlyTheInvoiceWasRead($api);
    }

    public function testRefundInsideTheNinetyDayWindowGoesToTheGateway(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->paidInvoiceResponse(['paid_at' => '2026-06-05T12:00:00-03:00']),
            $this->paidInvoiceResponse(['status' => 'refunded', 'refunded_cents' => 10000, 'paid_cents' => 0]),
        ]);

        $result = (new IuguGateway($api))->refundInvoice($this->invoiceWithId());

        $this->assertCount(2, $api->calls);
        $this->assertSame(InvoiceStatus::REFUNDED, $result->status);
    }

    /**
     * O prazo conta em dias: no 90º dia após o pagamento a chamada ainda segue, mesmo que a
     * hora do pagamento já tenha passado, e é a API que decide no limite.
     */
    public function testRefundOnTheLastDayOfTheWindowGoesToTheGateway(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->paidInvoiceResponse(['paid_at' => '2026-06-04T08:00:00-03:00']),
            $this->paidInvoiceResponse(['status' => 'refunded', 'refunded_cents' => 10000, 'paid_cents' => 0]),
        ]);

        $result = (new IuguGateway($api))->refundInvoice($this->invoiceWithId());

        $this->assertCount(2, $api->calls);
        $this->assertSame(InvoiceStatus::REFUNDED, $result->status);
    }

    public function testRefundOnTheDayAfterTheWindowThrowsBeforeTheNetwork(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->paidInvoiceResponse(['paid_at' => '2026-06-03T23:30:00-03:00']),
        ]);

        $exception = $this->refundExpectingRefusal($api, $this->invoiceWithId());

        $this->assertSame(RefundNotSupportedException::REASON_REFUND_WINDOW_EXPIRED, $exception->reason);
        $this->assertOnlyTheInvoiceWasRead($api);
    }

    /**
     * Um model já lido do gateway (método, status e data de pagamento em mãos) não paga o GET
     * extra antes do estorno.
     */
    public function testInvoiceReadFromTheGatewayDoesNotPayTheExtraGet(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->paidInvoiceResponse(),
            $this->paidInvoiceResponse(['status' => 'refunded', 'refunded_cents' => 10000, 'paid_cents' => 0]),
        ]);
        $gateway = new IuguGateway($api);

        $invoice = $gateway->getInvoice($this->invoiceWithId());
        $result = $gateway->refundInvoice($invoice);

        $this->assertCount(2, $api->calls);
        $this->assertSame('GET', $api->calls[0]['method']);
        $this->assertSame('POST', $api->calls[1]['method']);
        $this->assertSame(InvoiceStatus::REFUNDED, $result->status);
    }

    public function testGatewayErrorOnRefundBecomesGatewayException(): void
    {
        $api = new QueuedIuguApiRequest([
            $this->paidInvoiceResponse(),
            (object) ['errors' => 'Fatura não pode ser reembolsada'],
        ]);

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/^Error refunding invoice - .*Fatura não pode ser reembolsada/');

        (new IuguGateway($api))->refundInvoice($this->invoiceWithId());
    }

    public function testGetInvoiceReadsTheInvoiceById(): void
    {
        $api = new QueuedIuguApiRequest([$this->paidInvoiceResponse()]);

        $result = (new IuguGateway($api))->getInvoice($this->invoiceWithId());

        $this->assertCount(1, $api->calls);
        $this->assertSame('GET', $api->calls[0]['method']);
        $this->assertStringEndsWith('/invoices/inv_1', $api->calls[0]['url']);
        $this->assertSame('inv_1', $result->id);
        $this->assertSame(InvoiceStatus::PAID, $result->status);
        $this->assertSame(PaymentMethod::CREDIT_CARD, $result->paymentMethod);
        $this->assertSame(10000, $result->paidAmount);
        $this->assertSame('2026-08-20', $result->paidAt->toDateString());
        $this->assertNull($result->lastRefundId);
    }

    public function testGetInvoiceErrorBecomesGatewayException(): void
    {
        $api = new QueuedIuguApiRequest([(object) ['errors' => 'Not Found']]);

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessageMatches('/^Error getting invoice - .*Not Found/');

        (new IuguGateway($api))->getInvoice($this->invoiceWithId());
    }

    public function testGetInvoiceNotFoundBecomesNotFoundException(): void
    {
        $api = new QueuedIuguApiRequest([new \IuguObjectNotFound('{"errors":"Not Found"}', 404)]);

        try {
            (new IuguGateway($api))->getInvoice($this->invoiceWithId());
            $this->fail('Esperava NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertNotInstanceOf(GatewayNotAvailableException::class, $e);
        }

        $this->assertCount(1, $api->calls);
    }

    public function testGetInvoiceOn502BecomesGatewayNotAvailableException(): void
    {
        $api = new QueuedIuguApiRequest([new \IuguRequestException('502 Bad Gateway', 502)]);

        $this->expectException(GatewayNotAvailableException::class);

        (new IuguGateway($api))->getInvoice($this->invoiceWithId());
    }

    public function testRefundOfUnknownInvoiceBecomesNotFoundExceptionWithoutPosting(): void
    {
        $api = new QueuedIuguApiRequest([new \IuguObjectNotFound('{"errors":"Not Found"}', 404)]);

        try {
            (new IuguGateway($api))->refundInvoice($this->invoiceWithId());
            $this->fail('Esperava NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame(404, $e->httpStatus);
        }

        $this->assertOnlyTheInvoiceWasRead($api);
    }

    private function invoiceWithId(): Invoice
    {
        $invoice = new Invoice();
        $invoice->id = 'inv_1';

        return $invoice;
    }

    private function refundExpectingRefusal(QueuedIuguApiRequest $api, Invoice $invoice): RefundNotSupportedException
    {
        try {
            (new IuguGateway($api))->refundInvoice($invoice);
        } catch (RefundNotSupportedException $e) {
            return $e;
        }

        $this->fail('Esperava RefundNotSupportedException');
    }

    private function assertOnlyTheInvoiceWasRead(QueuedIuguApiRequest $api): void
    {
        $this->assertCount(1, $api->calls);
        $this->assertSame('GET', $api->calls[0]['method']);
        $this->assertStringEndsWith('/invoices/inv_1', $api->calls[0]['url']);
    }

    private function paidPixInvoiceResponse(array $overrides = []): object
    {
        return $this->paidInvoiceResponse(array_merge([
            'payment_method' => 'iugu_pix',
            'payable_with' => 'pix',
            'pix' => (object) ['qrcode' => 'https://example.com/qr.png', 'qrcode_text' => '000201'],
        ], $overrides));
    }

    private function paidInvoiceResponse(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 'inv_1',
            'status' => 'paid',
            'total_cents' => 10000,
            'paid_at' => '2026-08-20T10:00:00-03:00',
            'secure_url' => 'https://faturas.iugu.com/inv_1',
            'taxes_paid_cents' => 250,
            'created_at_iso' => '2026-08-20T09:00:00-03:00',
            'paid_cents' => 10000,
            'refunded_cents' => 0,
            'due_date' => '2026-08-25',
            'payment_method' => 'iugu_credit_card',
            'payable_with' => 'credit_card',
            'customer_id' => 'cus_1',
            'customer_name' => 'Cliente',
            'email' => 'cliente@example.com',
            'payer_phone' => null,
            'payer_phone_prefix' => null,
            'items' => [
                (object) ['description' => 'Item', 'price_cents' => 10000, 'quantity' => 1],
            ],
            'payer_address_zip_code' => null,
            'bank_slip' => null,
            'pix' => null,
            'automatic_pix' => null,
            'credit_card_transaction' => null,
            'variables' => [],
        ], $overrides);
    }
}
