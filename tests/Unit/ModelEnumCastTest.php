<?php

namespace Potelo\MultiPayment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Models\Plan;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Enums\PlanInterval;
use Potelo\MultiPayment\Builders\InvoiceBuilder;
use Potelo\MultiPayment\Gateways\StripeGateway;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Cobre a conversão das propriedades de enum dos models (`Model::ENUM_CASTS`): escrita com
 * string ou enum, leitura sempre como enum, `fill()`, `toArray()`, `json_encode()` e
 * `isset()`.
 */
class ModelEnumCastTest extends TestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $app = new Container();
        $app->instance('config', new \Illuminate\Config\Repository([
            'multi-payment.gateways.stripe.api_key' => 'sk_test_fake',
        ]));
        $app->instance('log', $this->logger = new RecordingLogger());
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testStatusAcceptsTheOldStringAndReadsAsTheEnum(): void
    {
        $invoice = new Invoice();
        $invoice->status = Invoice::STATUS_PAID;

        $this->assertSame(InvoiceStatus::PAID, $invoice->status);
        $this->assertTrue($invoice->status === InvoiceStatus::PAID);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status->value);
    }

    public function testStatusAcceptsTheEnumCaseAndNull(): void
    {
        $invoice = new Invoice();
        $invoice->status = InvoiceStatus::DISPUTED;
        $this->assertSame(InvoiceStatus::DISPUTED, $invoice->status);

        $invoice->status = null;
        $this->assertNull($invoice->status);
    }

    public function testUnknownStatusStringBecomesUnknownWithAWarningNamingTheGateway(): void
    {
        $invoice = new Invoice();
        $invoice->gateway = 'iugu';
        $invoice->status = 'status_inventado';

        $this->assertSame(InvoiceStatus::UNKNOWN, $invoice->status);
        $this->assertCount(1, $this->logger->records);
        $this->assertSame('warning', $this->logger->records[0]['level']);
        $this->assertSame(['status' => 'status_inventado', 'gateway' => 'iugu'], $this->logger->records[0]['context']);
    }

    public function testFillConvertsStatusPaymentMethodAndAvailablePaymentMethods(): void
    {
        $invoice = new Invoice();
        $invoice->fill([
            'status' => 'partially_paid',
            'payment_method' => 'pix',
            'available_payment_methods' => ['pix', PaymentMethod::BANK_SLIP],
        ]);

        $this->assertSame(InvoiceStatus::PARTIALLY_PAID, $invoice->status);
        $this->assertSame(PaymentMethod::PIX, $invoice->paymentMethod);
        $this->assertSame([PaymentMethod::PIX, PaymentMethod::BANK_SLIP], $invoice->availablePaymentMethods);
    }

    public function testUnknownPaymentMethodStringIsRejectedOnWrite(): void
    {
        $invoice = new Invoice();

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessage('paymentMethod must be one of: credit_card, bank_slip, pix, automatic_pix');

        $invoice->paymentMethod = 'dinheiro';
    }

    public function testUnknownPaymentMethodInTheListIsRejectedOnWrite(): void
    {
        $invoice = new Invoice();

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessage('availablePaymentMethods must be one of');

        $invoice->availablePaymentMethods = ['pix', 'cripto'];
    }

    public function testNonArrayAvailablePaymentMethodsIsRejectedOnWrite(): void
    {
        $invoice = new Invoice();

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessage('availablePaymentMethods must be an array');

        $invoice->availablePaymentMethods = 'pix';
    }

    public function testNonStringValueIsRejectedOnWrite(): void
    {
        $plan = new Plan();

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessage('interval must be one of: day, week, month, year');

        $plan->interval = 12;
    }

    /**
     * `__get` devolve o array por referência, então um `[]=` entra sem conversão; a validação
     * normaliza e recusa o que não é selecionável.
     */
    public function testValidationNormalizesStringsAppendedToAvailablePaymentMethods(): void
    {
        $invoice = new Invoice();
        $invoice->availablePaymentMethods = [PaymentMethod::PIX];
        $invoice->availablePaymentMethods[] = 'credit_card';

        $invoice->validateAvailablePaymentMethodsAttribute();

        $this->assertSame([PaymentMethod::PIX, PaymentMethod::CREDIT_CARD], $invoice->availablePaymentMethods);
    }

    public function testValidationRejectsAutomaticPixInAvailablePaymentMethods(): void
    {
        $invoice = new Invoice();
        $invoice->availablePaymentMethods = [PaymentMethod::AUTOMATIC_PIX];

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessage('availablePaymentMethods must be one of: credit_card, bank_slip, pix');

        $invoice->validateAvailablePaymentMethodsAttribute();
    }

    public function testPlanIntervalAcceptsTheOldConstantAndReadsAsTheEnum(): void
    {
        $plan = new Plan();
        $plan->interval = Plan::INTERVAL_YEAR;

        $this->assertSame(PlanInterval::YEAR, $plan->interval);

        $plan->fill(['interval' => 'week']);
        $this->assertSame(PlanInterval::WEEK, $plan->interval);
    }

    public function testUnknownPlanIntervalIsRejectedOnWrite(): void
    {
        $plan = new Plan();

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessage('interval must be one of: day, week, month, year');

        $plan->interval = 'quinzena';
    }

    public function testSubscriptionPaymentMethodsAreConvertedToo(): void
    {
        $subscription = new Subscription();
        $subscription->fill(['payment_method' => 'credit_card', 'available_payment_methods' => ['pix']]);

        $this->assertSame(PaymentMethod::CREDIT_CARD, $subscription->paymentMethod);
        $this->assertSame([PaymentMethod::PIX], $subscription->availablePaymentMethods);
    }

    public function testToArrayEmitsTheStringValues(): void
    {
        $invoice = new Invoice();
        $invoice->id = 'inv_1';
        $invoice->status = InvoiceStatus::PAID;
        $invoice->paymentMethod = PaymentMethod::CREDIT_CARD;
        $invoice->availablePaymentMethods = [PaymentMethod::CREDIT_CARD, PaymentMethod::PIX];

        $this->assertSame([
            'id' => 'inv_1',
            'status' => 'paid',
            'payment_method' => 'credit_card',
            'available_payment_methods' => ['credit_card', 'pix'],
        ], $invoice->toArray());

        $plan = new Plan();
        $plan->interval = PlanInterval::MONTH;
        $this->assertSame('month', $plan->toArray()['interval']);
    }

    public function testToArrayRoundTripsThroughFill(): void
    {
        $invoice = new Invoice();
        $invoice->status = 'paid';
        $invoice->availablePaymentMethods = ['pix'];

        $copy = new Invoice();
        $copy->fill($invoice->toArray());

        $this->assertSame(InvoiceStatus::PAID, $copy->status);
        $this->assertSame([PaymentMethod::PIX], $copy->availablePaymentMethods);
    }

    public function testJsonEncodeKeepsTheCamelCaseKeysWithTheStringValues(): void
    {
        $invoice = new Invoice();
        $invoice->id = 'inv_1';
        $invoice->status = InvoiceStatus::EXPIRED;
        $invoice->paymentMethod = PaymentMethod::PIX;
        $invoice->availablePaymentMethods = [PaymentMethod::PIX, PaymentMethod::BANK_SLIP];

        $json = json_decode(json_encode($invoice), true);

        $this->assertSame('inv_1', $json['id']);
        $this->assertSame('expired', $json['status']);
        $this->assertSame('pix', $json['paymentMethod']);
        $this->assertSame(['pix', 'bank_slip'], $json['availablePaymentMethods']);
        $this->assertArrayHasKey('gatewayOptions', $json);

        $plan = new Plan();
        $plan->interval = PlanInterval::YEAR;
        $this->assertSame('year', json_decode(json_encode($plan), true)['interval']);
    }

    public function testIssetAndEmptyWorkOnEnumProperties(): void
    {
        $invoice = new Invoice();

        $this->assertFalse(isset($invoice->status));
        $this->assertTrue(empty($invoice->status));
        $this->assertFalse(isset($invoice->availablePaymentMethods));
        $this->assertTrue(empty($invoice->availablePaymentMethods));

        $invoice->status = 'paid';
        $invoice->availablePaymentMethods = ['pix'];

        $this->assertTrue(isset($invoice->status));
        $this->assertFalse(empty($invoice->status));
        $this->assertTrue(isset($invoice->availablePaymentMethods));
        $this->assertFalse(empty($invoice->availablePaymentMethods));
    }

    public function testBuilderAcceptsStringsAndEnumCasesForAvailablePaymentMethods(): void
    {
        $builder = new InvoiceBuilder(new StripeGateway());
        $builder->addAvailablePaymentMethod('pix')->addAvailablePaymentMethod(PaymentMethod::CREDIT_CARD);

        $this->assertSame([PaymentMethod::PIX, PaymentMethod::CREDIT_CARD], $builder->get()->availablePaymentMethods);

        $builder->setAvailablePaymentMethods(['bank_slip']);
        $this->assertSame([PaymentMethod::BANK_SLIP], $builder->get()->availablePaymentMethods);
    }

    public function testValidateStillSeesEnumPropertiesAsAttributes(): void
    {
        $plan = new Plan();
        $plan->name = 'Plano';
        $plan->amount = 1000;

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/interval/');

        $plan->validate();
    }
}
