<?php

namespace Potelo\MultiPayment\Tests\Unit;

use Mockery;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\InvoiceItem;
use Potelo\MultiPayment\Builders\InvoiceBuilder;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Cobre os helpers estáticos obsoletos de `Invoice`, as datas `dueDate` e `pixExpiresAt` com
 * o alias deprecado `expiresAt`, a derivação do método de pagamento e a validação de `amount`
 * contra os itens.
 */
class InvoiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public static function settledProvider(): array
    {
        return [
            'paga (enum)' => [InvoiceStatus::PAID, true],
            'paga (string antiga)' => [Invoice::STATUS_PAID, true],
            'parcialmente estornada' => [Invoice::STATUS_PARTIALLY_REFUNDED, true],
            'parcialmente paga' => ['partially_paid', true],
            'paga por fora' => ['externally_paid', true],
            'pendente' => [Invoice::STATUS_PENDING, false],
            'cancelada' => [Invoice::STATUS_CANCELED, false],
            'estornada' => [Invoice::STATUS_REFUNDED, false],
            'em disputa' => [Invoice::STATUS_DISPUTED, false],
            'chargeback' => [Invoice::STATUS_CHARGEBACK, false],
            'status desconhecido' => ['qualquer_coisa', false],
        ];
    }

    #[DataProvider('settledProvider')]
    #[IgnoreDeprecations]
    public function testIsSettledDelegatesToTheEnumAndAcceptsTheOldString(InvoiceStatus|string $status, bool $expected): void
    {
        $this->assertSame($expected, Invoice::isSettled($status));
    }

    /**
     * `amount` sem `items` vira um único item com o valor; a lista precisa sobreviver ao
     * restante do `fill()`.
     */
    public function testFillWithAmountAndNoItemsCreatesASingleItem(): void
    {
        $invoice = new Invoice();
        $invoice->fill(['amount' => 10000, 'available_payment_methods' => ['pix']]);

        $this->assertCount(1, $invoice->items);
        $this->assertSame(10000, $invoice->items[0]->price);
        $this->assertSame(1, $invoice->items[0]->quantity);
        $this->assertNull($invoice->amount);
    }

    public static function contestedProvider(): array
    {
        return [
            'em disputa (enum)' => [InvoiceStatus::DISPUTED, true],
            'em disputa (string antiga)' => [Invoice::STATUS_DISPUTED, true],
            'chargeback' => [Invoice::STATUS_CHARGEBACK, true],
            'paga' => [Invoice::STATUS_PAID, false],
            'estornada' => [Invoice::STATUS_REFUNDED, false],
            'parcialmente estornada' => [Invoice::STATUS_PARTIALLY_REFUNDED, false],
            'pendente' => [Invoice::STATUS_PENDING, false],
            'cancelada' => [Invoice::STATUS_CANCELED, false],
            'status desconhecido' => ['qualquer_coisa', false],
        ];
    }

    #[DataProvider('contestedProvider')]
    #[IgnoreDeprecations]
    public function testIsContestedDelegatesToTheEnumAndAcceptsTheOldString(InvoiceStatus|string $status, bool $expected): void
    {
        $this->assertSame($expected, Invoice::isContested($status));
    }

    #[IgnoreDeprecations]
    public function testIsSettledTriggersADeprecationNotice(): void
    {
        $this->expectUserDeprecationMessage('Invoice::isSettled() está obsoleto desde 2026-09-02; use $invoice->status->isSettled()');

        Invoice::isSettled(InvoiceStatus::PAID);
    }

    #[IgnoreDeprecations]
    public function testIsContestedTriggersADeprecationNotice(): void
    {
        $this->expectUserDeprecationMessage('Invoice::isContested() está obsoleto desde 2026-09-02; use $invoice->status->isContested()');

        Invoice::isContested(InvoiceStatus::DISPUTED);
    }

    /**
     * As constantes antigas continuam existindo com o mesmo valor do enum, então quem compara
     * `$invoice->status->value` com `Invoice::STATUS_PAID` continua obtendo verdadeiro.
     */
    public static function oldConstantProvider(): array
    {
        return [
            [Invoice::STATUS_PENDING, InvoiceStatus::PENDING],
            [Invoice::STATUS_PAID, InvoiceStatus::PAID],
            [Invoice::STATUS_CANCELED, InvoiceStatus::CANCELED],
            [Invoice::STATUS_REFUNDED, InvoiceStatus::REFUNDED],
            [Invoice::STATUS_PARTIALLY_REFUNDED, InvoiceStatus::PARTIALLY_REFUNDED],
            [Invoice::STATUS_DISPUTED, InvoiceStatus::DISPUTED],
            [Invoice::STATUS_CHARGEBACK, InvoiceStatus::CHARGEBACK],
        ];
    }

    #[DataProvider('oldConstantProvider')]
    public function testOldStatusConstantsKeepTheEnumValue(string $constant, InvoiceStatus $status): void
    {
        $invoice = new Invoice();
        $invoice->status = $constant;

        $this->assertSame($status, $invoice->status);
        $this->assertSame($constant, $invoice->status->value);
        $this->assertTrue($invoice->status->value === $constant);
    }

    #[IgnoreDeprecations]
    public function testExpiresAtIsADeprecatedAliasOfDueDate(): void
    {
        $this->expectUserDeprecationMessage('Invoice::$expiresAt está obsoleto desde 2026-09-02; use $dueDate (vencimento) ou $pixExpiresAt (expiração do QR Code)');

        $invoice = new Invoice();
        $invoice->expiresAt = Carbon::parse('2026-10-01');

        $this->assertSame('2026-10-01', $invoice->dueDate->format('Y-m-d'));
        $this->assertSame($invoice->dueDate, $invoice->expiresAt);
        $this->assertTrue(isset($invoice->expiresAt));
        $this->assertArrayHasKey('due_date', $invoice->toArray());
        $this->assertArrayNotHasKey('expires_at', $invoice->toArray());
    }

    public function testIssetOnExpiresAtDoesNotWarnAndFollowsDueDate(): void
    {
        $invoice = new Invoice();

        $this->assertFalse(isset($invoice->expiresAt));
        $this->assertTrue(empty($invoice->expiresAt));
    }

    #[IgnoreDeprecations]
    public function testFillAcceptsExpiresAtAsAnAliasWithDueDateTakingPrecedence(): void
    {
        $this->expectUserDeprecationMessage('Invoice::$expiresAt está obsoleto desde 2026-09-02; use $dueDate (vencimento) ou $pixExpiresAt (expiração do QR Code)');

        $invoice = new Invoice();
        $invoice->fill(['expires_at' => '2026-10-01']);
        $this->assertSame('2026-10-01', $invoice->dueDate->format('Y-m-d'));

        $both = new Invoice();
        $both->fill(['expires_at' => '2026-10-01', 'due_date' => '2026-10-05']);
        $this->assertSame('2026-10-05', $both->dueDate->format('Y-m-d'));
    }

    #[DataProvider('dateInputProvider')]
    public function testFillAcceptsDateOnlyIso8601AndCarbonInBothDates(mixed $value, string $expected): void
    {
        $invoice = new Invoice();
        $invoice->fill(['due_date' => $value, 'pix_expires_at' => $value]);

        $this->assertSame($expected, $invoice->dueDate->toIso8601String());
        $this->assertSame($expected, $invoice->pixExpiresAt->toIso8601String());
        $this->assertSame(['due_date' => $invoice->dueDate, 'pix_expires_at' => $invoice->pixExpiresAt], array_intersect_key($invoice->toArray(), ['due_date' => 1, 'pix_expires_at' => 1]));
    }

    public static function dateInputProvider(): array
    {
        return [
            'so data' => ['2026-10-01', Carbon::parse('2026-10-01')->toIso8601String()],
            'ISO 8601 com hora' => ['2026-10-01T18:30:00-03:00', '2026-10-01T18:30:00-03:00'],
            'Carbon' => [Carbon::parse('2026-10-01 18:30:00', 'America/Bahia'), '2026-10-01T18:30:00-03:00'],
        ];
    }

    public function testResolvedPaymentMethodsFollowThePrecedenceListMethodCard(): void
    {
        $invoice = new Invoice();
        $this->assertSame([], $invoice->resolvedPaymentMethods());

        $invoice->creditCard = new CreditCard();
        $invoice->creditCard->id = 'pm_1';
        $this->assertSame([PaymentMethod::CREDIT_CARD], $invoice->resolvedPaymentMethods());

        $invoice->paymentMethod = 'credit_card';
        $this->assertSame([PaymentMethod::CREDIT_CARD], $invoice->resolvedPaymentMethods());

        $invoice->creditCard = null;
        $invoice->paymentMethod = 'pix';
        $this->assertSame([PaymentMethod::PIX], $invoice->resolvedPaymentMethods());

        $invoice->paymentMethod = null;
        $invoice->availablePaymentMethods = [PaymentMethod::BANK_SLIP];
        $invoice->availablePaymentMethods[] = 'bank_slip';
        $this->assertSame([PaymentMethod::BANK_SLIP], $invoice->resolvedPaymentMethods());
    }

    #[DataProvider('conflictingPaymentProvider')]
    public function testValidationRejectsAPaymentMethodOutsideTheListAndACardWithoutCardAmongTheMethods(callable $mutate, string $message): void
    {
        $invoice = new Invoice();
        $invoice->fill([
            'customer' => ['name' => 'Ana', 'email' => 'ana@example.com'],
            'amount' => 10000,
        ]);
        $mutate($invoice);

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches($message);

        $invoice->validate();
    }

    public static function conflictingPaymentProvider(): array
    {
        $card = function (Invoice $i) {
            $i->creditCard = new CreditCard();
            $i->creditCard->id = 'pm_1';
        };

        return [
            'metodo fora da lista' => [
                function (Invoice $i) {
                    $i->paymentMethod = PaymentMethod::CREDIT_CARD;
                    $i->availablePaymentMethods = [PaymentMethod::PIX];
                },
                '/paymentMethod \[credit_card\] must be one of availablePaymentMethods/',
            ],
            'cartao com metodo pix' => [
                function (Invoice $i) use ($card) {
                    $card($i);
                    $i->paymentMethod = PaymentMethod::PIX;
                },
                '/creditCard was given but credit_card is not among the payment methods/',
            ],
            'cartao com lista sem cartao' => [
                function (Invoice $i) use ($card) {
                    $card($i);
                    $i->availablePaymentMethods = [PaymentMethod::PIX, PaymentMethod::BANK_SLIP];
                },
                '/creditCard was given but credit_card is not among the payment methods/',
            ],
        ];
    }

    public function testValidationRejectsANonSelectablePaymentMethod(): void
    {
        $invoice = new Invoice();
        $invoice->fill([
            'customer' => ['name' => 'Ana', 'email' => 'ana@example.com'],
            'amount' => 10000,
        ]);
        $invoice->paymentMethod = PaymentMethod::AUTOMATIC_PIX;

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/paymentMethod must be one of: credit_card, bank_slip, pix/');

        $invoice->validate();
    }

    /**
     * `amount` junto de `items` só é aceito quando é a soma deles: `itemsTotal()` devolve a soma
     * e `validate()` lança `ModelAttributeValidationException` citando os dois valores.
     */
    public function testValidationRejectsAnAmountDifferentFromTheItemsTotal(): void
    {
        $invoice = new Invoice();
        $invoice->fill([
            'customer' => ['name' => 'Ana', 'email' => 'ana@example.com'],
            'amount' => 10000,
            'items' => [
                ['description' => 'Produto 1', 'price' => 10000, 'quantity' => 1],
                ['description' => 'Produto 2', 'price' => 5000, 'quantity' => 2],
            ],
        ]);
        $this->assertSame(20000, $invoice->itemsTotal());

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/amount \[10000\] must equal the sum of the items \[20000\]/');

        $invoice->validate();
    }

    public function testValidationAcceptsAnAmountEqualToTheItemsTotal(): void
    {
        $invoice = new Invoice();
        $invoice->fill([
            'customer' => ['name' => 'Ana', 'email' => 'ana@example.com'],
            'amount' => 20000,
            'items' => [
                ['description' => 'Produto 1', 'price' => 10000, 'quantity' => 1],
                ['description' => 'Produto 2', 'price' => 5000, 'quantity' => 2],
            ],
        ]);

        $invoice->validate();

        $this->assertSame(20000, $invoice->amount);
    }

    public function testBuilderSetsPaymentMethodAndBothDates(): void
    {
        $gateway = Mockery::mock(GatewayContract::class);

        $invoice = (new InvoiceBuilder($gateway))
            ->setPaymentMethod('pix')
            ->setDueDate(CarbonImmutable::parse('2026-10-01'))
            ->setPixExpiresAt('2026-09-30T18:00:00-03:00')
            ->get();

        $this->assertSame(PaymentMethod::PIX, $invoice->paymentMethod);
        $this->assertInstanceOf(Carbon::class, $invoice->dueDate);
        $this->assertSame('2026-10-01', $invoice->dueDate->format('Y-m-d'));
        $this->assertSame('2026-09-30T18:00:00-03:00', $invoice->pixExpiresAt->toIso8601String());
    }

    #[IgnoreDeprecations]
    public function testBuilderSetExpiresAtIsADeprecatedAliasOfSetDueDate(): void
    {
        $this->expectUserDeprecationMessage('InvoiceBuilder::setExpiresAt() está obsoleto desde 2026-09-02; use setDueDate() ou setPixExpiresAt()');

        $invoice = (new InvoiceBuilder(Mockery::mock(GatewayContract::class)))
            ->setExpiresAt('2026-10-01')
            ->get();

        $this->assertSame('2026-10-01', $invoice->dueDate->format('Y-m-d'));
        $this->assertNull($invoice->pixExpiresAt);
    }

    /**
     * `refundedAmount` é só de leitura: os drivers a preenchem por `setRefundedAmountFromGateway()`,
     * que não marca o valor como pedido de estorno.
     */
    public function testRefundedAmountWrittenByTheDriverIsNotARefundRequest(): void
    {
        $invoice = new Invoice();
        $invoice->setRefundedAmountFromGateway(3000);

        $this->assertSame(3000, $invoice->refundedAmount);
        $this->assertTrue(isset($invoice->refundedAmount));
        $this->assertNull($invoice->requestedRefundAmount());
        $this->assertNull($invoice->resolveRefundAmount(null));
        $this->assertSame(500, $invoice->resolveRefundAmount(500));
        $this->assertSame(['refunded_amount' => 3000], $invoice->toArray());
        $this->assertSame(3000, json_decode(json_encode($invoice), true)['refundedAmount']);
    }

    /**
     * Escrever em `refundedAmount` é o caminho antigo de pedir estorno parcial: o valor fica
     * como pedido, com aviso de deprecação, e o argumento de `refund()` prevalece sobre ele.
     */
    public function testWritingRefundedAmountIsTheDeprecatedWayOfRequestingAPartialRefund(): void
    {
        $invoice = new Invoice();

        $this->expectUserDeprecationMessage('Invoice::$refundedAmount é só de leitura desde 2026-09-02; passe o valor do estorno em refund(amount:) ou refundInvoice($id, $amount)');
        $invoice->refundedAmount = 2500;

        $this->assertSame(2500, $invoice->refundedAmount);
        $this->assertSame(2500, $invoice->requestedRefundAmount());
        $this->assertSame(2500, $invoice->resolveRefundAmount(null));
        $this->assertSame(1000, $invoice->resolveRefundAmount(1000));

        $invoice->setRefundedAmountFromGateway(2500);
        $this->assertNull($invoice->requestedRefundAmount(), 'a leitura do gateway apaga o pedido');
    }

    #[DataProvider('refundedAmountKeyProvider')]
    public function testFillWithRefundedAmountFollowsTheDeprecatedPathInBothSpellings(string $key): void
    {
        $invoice = new Invoice();

        $this->expectUserDeprecationMessage('Invoice::$refundedAmount é só de leitura desde 2026-09-02; passe o valor do estorno em refund(amount:) ou refundInvoice($id, $amount)');
        $invoice->fill(['id' => 'inv_1', $key => 700]);

        $this->assertSame('inv_1', $invoice->id);
        $this->assertSame(700, $invoice->refundedAmount);
        $this->assertSame(700, $invoice->requestedRefundAmount());
    }

    public static function refundedAmountKeyProvider(): array
    {
        return ['snake_case' => ['refunded_amount'], 'camelCase' => ['refundedAmount']];
    }

    /**
     * A propriedade privada que guarda o pedido do caminho antigo é estado interno: a chave é
     * desconhecida para `fill()`, como qualquer outra fora de `fillableKeys()`.
     */
    public function testFillRejectsTheKeyOfThePrivateRequestedRefundAmount(): void
    {
        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/`requested_refund_amount` key is unknown/');

        (new Invoice())->fill(['requested_refund_amount' => 123]);
    }

    #[IgnoreDeprecations]
    public function testZeroWrittenInRefundedAmountMeansNoPartialRequest(): void
    {
        $invoice = new Invoice();
        $invoice->refundedAmount = 0;

        $this->assertSame(0, $invoice->refundedAmount);
        $this->assertNull($invoice->requestedRefundAmount());
    }

    public function testResolveRefundAmountRejectsZeroAndNegative(): void
    {
        $invoice = new Invoice();

        foreach ([0, -1] as $amount) {
            try {
                $invoice->resolveRefundAmount($amount);
                $this->fail("Esperava ModelAttributeValidationException para {$amount}");
            } catch (ModelAttributeValidationException $e) {
                $this->assertStringContainsString('positive', $e->getMessage());
            }
        }
    }

    /**
     * `currency` aceita um código ISO 4217 de três letras; qualquer outro valor falha na
     * validação.
     */
    public function testCurrencyMustBeAThreeLetterCode(): void
    {
        $invoice = new Invoice();
        $invoice->currency = 'BRL';
        $invoice->validate(['currency']);

        $invoice->currency = 'REAIS';

        $this->expectException(ModelAttributeValidationException::class);
        $this->expectExceptionMessageMatches('/ISO 4217/');
        $invoice->validate(['currency']);
    }
}
