<?php

namespace Potelo\MultiPayment\Tests\Unit\Gateways;

use Carbon\Carbon;
use Iugu_APIRequest;
use PHPUnit\Framework\TestCase;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\AutomaticPix;
use Potelo\MultiPayment\Models\AutomaticPixCharge;
use Potelo\MultiPayment\Models\AutomaticPixCancellation;
use Potelo\MultiPayment\Gateways\IuguGateway;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

class IuguGatewayAutomaticPixTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new Repository([
            'multi-payment.gateways.iugu.api_key' => 'test-api-key',
        ]));
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testMapsGenericAutomaticPixFieldsToIuguInvoiceFields(): void
    {
        $automaticPix = new AutomaticPix();
        $automaticPix->authorizationType = AutomaticPix::AUTHORIZATION_TYPE_QR_CODE_WITH_PAYMENT;
        $automaticPix->frequency = AutomaticPix::FREQUENCY_MONTHLY;
        $automaticPix->startsAt = now()->startOfDay();
        $automaticPix->contractReference = 'contract-123';
        $automaticPix->endsAt = now()->addYear()->startOfDay();
        $automaticPix->retryPolicy = AutomaticPix::RETRY_POLICY_ALLOWED;

        $method = new \ReflectionMethod(IuguGateway::class, 'automaticPixToIuguData');
        $method->setAccessible(true);
        $data = $method->invoke(new IuguGateway(new RecordingIuguApiRequest((object) [])), $automaticPix);

        $this->assertSame([
            'journey' => 3,
            'frequency' => 'monthly',
            'recurrence_beginning' => $automaticPix->startsAt->format('Y-m-d'),
            'contract_number' => 'contract-123',
            'end_date' => $automaticPix->endsAt->format('Y-m-d'),
            'retry_policy' => 'retry_allowed',
        ], $data);
    }

    public function testMapsExistingAutomaticPixRecurrenceWithoutCreationFields(): void
    {
        $automaticPix = new AutomaticPix();
        $automaticPix->id = 'recurrence-id';

        $method = new \ReflectionMethod(IuguGateway::class, 'automaticPixToIuguData');
        $method->setAccessible(true);
        $data = $method->invoke(new IuguGateway(new RecordingIuguApiRequest((object) [])), $automaticPix);

        $this->assertSame(['receiver_recurrence_id' => 'recurrence-id'], $data);
    }

    public function testMapsGenericChargeDescriptionToIuguRemittanceInformation(): void
    {
        $charge = new AutomaticPixCharge();
        $charge->description = 'Monthly subscription';

        $method = new \ReflectionMethod(IuguGateway::class, 'automaticPixChargeToIuguData');
        $method->setAccessible(true);
        $data = $method->invoke(new IuguGateway(new RecordingIuguApiRequest((object) [])), $charge);

        $this->assertSame(['pix_remittance_info' => 'Monthly subscription'], $data);
    }

    public function testRejectsAuthorizationTypeUnsupportedByIugu(): void
    {
        $automaticPix = new AutomaticPix();
        $automaticPix->authorizationType = 'push';
        $automaticPix->frequency = AutomaticPix::FREQUENCY_MONTHLY;
        $automaticPix->startsAt = now()->startOfDay();
        $automaticPix->contractReference = 'contract-123';

        $method = new \ReflectionMethod(IuguGateway::class, 'automaticPixToIuguData');
        $method->setAccessible(true);

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessage('authorizationType is not supported by the Iugu gateway');

        $method->invoke(new IuguGateway(new RecordingIuguApiRequest((object) [])), $automaticPix);
    }

    public function testCancelsScheduledPaymentAndReturnsCancellationModel(): void
    {
        $apiRequest = new RecordingIuguApiRequest((object) [
            'success' => true,
            'cancellation_id' => 'cancellation-id',
        ]);
        $charge = new AutomaticPixCharge();
        $charge->id = 'payment-id';
        $charge->endToEndId = 'end-to-end-id';
        $result = (new IuguGateway($apiRequest))
            ->cancelAutomaticPixScheduledPayment($charge);

        $this->assertInstanceOf(AutomaticPixCancellation::class, $result);
        $this->assertSame('cancellation-id', $result->id);
        $this->assertSame('payment-id', $result->paymentId);
        $this->assertSame('POST', $apiRequest->method);
        $this->assertSame('/v1/automatic_pix/receiver_recurrence_payments/cancel', parse_url($apiRequest->url, PHP_URL_PATH));
        parse_str((string) parse_url($apiRequest->url, PHP_URL_QUERY), $query);
        $this->assertSame([
            'receiver_recurrence_payment_id' => 'payment-id',
            'end_to_end_id' => 'end-to-end-id',
        ], $query);
        $this->assertSame([], $apiRequest->data);
    }

    public function testRejectsUnsuccessfulScheduledPaymentCancellation(): void
    {
        $apiRequest = new RecordingIuguApiRequest((object) [
            'success' => false,
            'errors' => [(object) ['message' => 'Pagamento não pode ser cancelado']],
        ]);
        $charge = new AutomaticPixCharge();
        $charge->id = 'payment-id';
        $charge->endToEndId = 'end-to-end-id';
        $this->expectException(GatewayException::class);

        (new IuguGateway($apiRequest))
            ->cancelAutomaticPixScheduledPayment($charge);
    }

    public function testCancelsRecurrenceAndReturnsRequestedCancellation(): void
    {
        $apiRequest = new RecordingIuguApiRequest((object) [
            'success' => true,
            'message' => 'Recurrence cancellation requested',
        ]);
        $automaticPix = new AutomaticPix();
        $automaticPix->id = 'recurrence-id';

        $result = (new IuguGateway($apiRequest))->cancelAutomaticPixRecurrence($automaticPix);

        $this->assertSame('PUT', $apiRequest->method);
        $this->assertSame(
            '/v1/automatic_pix/receiver_recurrences/recurrence-id/cancel',
            parse_url($apiRequest->url, PHP_URL_PATH)
        );
        $this->assertSame('recurrence-id', $result->recurrenceId);
        $this->assertSame(AutomaticPixCancellation::STATUS_REQUESTED, $result->status);
    }

    public function testReschedulesPaymentFromInvoice(): void
    {
        $apiRequest = new RecordingIuguApiRequest((object) ['success' => true]);
        $invoice = new Invoice();
        $invoice->id = 'invoice-id';

        $result = (new IuguGateway($apiRequest))->rescheduleAutomaticPixPayment($invoice);

        $this->assertSame($invoice, $result);
        $this->assertSame('POST', $apiRequest->method);
        $this->assertSame(
            '/v1/invoices/invoice-id/reschedule_automatic_pix_payment',
            parse_url($apiRequest->url, PHP_URL_PATH)
        );
    }

    public function testGetsCancellationMappedToGenericFields(): void
    {
        $apiRequest = new RecordingIuguApiRequest((object) [
            'id' => 'cancellation-id',
            'receiver_recurrence_id' => 'recurrence-id',
            'receiver_recurrence_payment_id' => 'payment-id',
            'end_to_end_id' => 'end-to-end-id',
            'status' => 'cancelled',
            'amount' => 1250,
            'payer_account' => '12345-6',
        ]);
        $cancellation = new AutomaticPixCancellation();
        $cancellation->id = 'cancellation-id';
        $cancellation->recurrenceId = 'recurrence-id';

        $result = (new IuguGateway($apiRequest))->getAutomaticPixCancellation($cancellation);

        $this->assertSame(
            '/v1/automatic_pix/receiver_recurrences/recurrence-id/cancellations/cancellation-id',
            parse_url($apiRequest->url, PHP_URL_PATH)
        );
        $this->assertSame('payment-id', $result->paymentId);
        $this->assertSame('end-to-end-id', $result->endToEndId);
        $this->assertSame(1250, $result->amount);
        $this->assertSame('12345-6', $result->payerAccount);
    }

    public function testListsMappedCancellationsWithPagination(): void
    {
        $apiRequest = new RecordingIuguApiRequest((object) [
            'cancellations' => [
                (object) ['id' => 'one', 'amount' => 100],
                (object) ['id' => 'two', 'amount' => 200],
            ],
        ]);
        $automaticPix = new AutomaticPix();
        $automaticPix->id = 'recurrence-id';

        $result = (new IuguGateway($apiRequest))->listAutomaticPixCancellations($automaticPix, 2, 25);

        parse_str((string) parse_url($apiRequest->url, PHP_URL_QUERY), $query);
        $this->assertSame(['limit' => '25', 'page' => '2'], $query);
        $this->assertCount(2, $result);
        $this->assertContainsOnlyInstancesOf(AutomaticPixCancellation::class, $result);
        $this->assertSame('one', $result[0]->id);
        $this->assertSame('recurrence-id', $result[0]->recurrenceId);
    }

    public function testCancelsInvoiceAndReturnsParsedInvoice(): void
    {
        $apiRequest = new RecordingIuguApiRequest($this->cancelledInvoiceResponse());
        $invoice = new Invoice();
        $invoice->id = 'invoice-id';

        $result = (new IuguGateway($apiRequest))->cancelInvoice($invoice);

        $this->assertSame('PUT', $apiRequest->method);
        $this->assertSame('/v1/invoices/invoice-id/cancel', parse_url($apiRequest->url, PHP_URL_PATH));
        $this->assertSame(Invoice::STATUS_CANCELED, $result->status);
        $this->assertSame('invoice-id', $result->id);
    }

    public function testMapsIuguScheduledPaymentToGenericCharge(): void
    {
        $response = $this->cancelledInvoiceResponse();
        $response->automatic_pix = (object) [
            'receiver_recurrence_id' => 'recurrence-id',
            'recurrence_receiver_payment' => (object) [
                'receiver_recurrence_payment_id' => 'charge-id',
                'receiver_recurrence_payment_end_to_end_id' => 'end-to-end-id',
                'scheduled_payment_at' => '2026-08-01T10:00:00-03:00',
                'status' => 'scheduled',
            ],
        ];
        $response->pix_remittance_info = 'Monthly subscription';

        $invoice = new Invoice();
        $invoice->id = 'invoice-id';
        $result = (new IuguGateway(new RecordingIuguApiRequest($response)))->cancelInvoice($invoice);

        $this->assertInstanceOf(AutomaticPixCharge::class, $result->automaticPixCharge);
        $this->assertSame('charge-id', $result->automaticPixCharge->id);
        $this->assertSame('recurrence-id', $result->automaticPixCharge->recurrenceId);
        $this->assertSame('end-to-end-id', $result->automaticPixCharge->endToEndId);
        $this->assertSame('Monthly subscription', $result->automaticPixCharge->description);
        $this->assertSame('scheduled', $result->automaticPixCharge->status);
        $this->assertInstanceOf(Carbon::class, $result->automaticPixCharge->scheduledAt);
        $this->assertSame('iugu', $result->automaticPixCharge->gateway);
    }

    private function cancelledInvoiceResponse(): object
    {
        return (object) [
            'id' => 'invoice-id',
            'status' => 'canceled',
            'total_cents' => 100,
            'paid_at' => null,
            'secure_url' => null,
            'taxes_paid_cents' => null,
            'created_at_iso' => '2026-07-16T10:20:03-03:00',
            'paid_cents' => 0,
            'refunded_cents' => 0,
            'due_date' => '2026-07-17',
            'payment_method' => null,
            'payable_with' => 'pix',
            'customer_id' => 'customer-id',
            'customer_name' => 'Cliente',
            'email' => 'cliente@example.com',
            'payer_phone' => null,
            'payer_phone_prefix' => null,
            'items' => [],
            'payer_address_zip_code' => null,
            'bank_slip' => null,
            'pix' => null,
            'automatic_pix' => null,
            'credit_card_transaction' => null,
        ];
    }
}

class RecordingIuguApiRequest extends Iugu_APIRequest
{
    public ?string $method = null;
    public ?string $url = null;
    public array $data = [];

    public function __construct(private object|array $response)
    {
    }

    public function request($method, $url, $data = [])
    {
        $this->method = $method;
        $this->url = $url;
        $this->data = $data;

        return $this->response;
    }
}
