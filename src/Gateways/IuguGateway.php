<?php

namespace Potelo\MultiPayment\Gateways;

use Iugu;
use Exception;
use Iugu_Charge;
use Iugu_Customer;
use Iugu_PaymentToken;
use DateTimeImmutable;
use Iugu_PaymentMethod;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Contracts\Gateway;

class IuguGateway implements Gateway
{
    private const STATUS_PENDING = 'pending';
    private const STATUS_PAID = 'paid';
    private const STATUS_DRAFT = 'draft';
    private const STATUS_CANCELED = 'canceled';
    private const STATUS_PARTIALLY_PAID = 'partially_paid';
    private const STATUS_REFUNDED = 'refunded';
    private const STATUS_EXPIRED = 'expired';
    private const STATUS_IN_PROTEST = 'in_protest';
    private const STATUS_CHARGEBACK = 'chargeback';
    private const STATUS_IN_ANALYSIS = 'in_analysis';

    /**
     * Set iugu api key.
     */
    public function __construct()
    {
        Iugu::setApiKey(config('multi-payment.gateways.iugu.api_key'));
    }

    /**
     * @inheritDoc
     */
    public function createInvoice(Invoice $invoice): Invoice
    {
        $iuguInvoiceData = [];
        $iuguInvoiceData['customer_id'] = $invoice->customer->id;
        $iuguInvoiceData['payer']['cpf_cnpj'] = $invoice->customer->taxDocument;
        $iuguInvoiceData['email'] = $invoice->customer->email;

        $iuguInvoiceData['items'] = [];
        foreach ($invoice->items as $item) {
            $iuguInvoiceData['items'][] = [
                'description' => $item->description,
                'quantity' => $item->quantity,
                'price_cents' => $item->price,
            ];
        }
        if ($invoice->paymentMethod == Invoice::PAYMENT_METHOD_CREDIT_CARD) {
            if (is_null($invoice->creditCard->id)) {
                $invoice->creditCard = $this->createCreditCard($invoice->creditCard);
            }
            $iuguInvoiceData['customer_payment_method_id'] = $invoice->creditCard->id;
        } elseif ($invoice->paymentMethod == Invoice::PAYMENT_METHOD_BANK_SLIP) {
            $iuguInvoiceData['due_date'] = $invoice->bankSlip->expirationDate->format('Y-m-d');
            $iuguInvoiceData['method'] = $invoice->paymentMethod;
            $iuguInvoiceData['payer']['address'] = $invoice->customer->address->toArrayWithoutEmpty();
        }

        $iuguCharge = Iugu_Charge::create($iuguInvoiceData);
        if ($iuguCharge->errors) {
            throw new Exception($iuguCharge->errors);
        }
        $iuguInvoice = $iuguCharge->invoice();
        $invoice->id = $iuguInvoice->id;
        $invoice->gateway = 'iugu';

        $invoice->status = $this->iuguStatusToMultiPayment($iuguInvoice->status);
        $invoice->amount = $iuguInvoice->total_cents;
        $invoice->orderId = $iuguInvoice->order_id;

        if ($iuguCharge->method == Invoice::PAYMENT_METHOD_BANK_SLIP) {
            $invoice->bankSlip->url = $iuguInvoice->secure_url . '.pdf';
            $invoice->bankSlip->number = $iuguInvoice->bank_slip->digitable_line;
            $invoice->bankSlip->barcodeData = $iuguInvoice->bank_slip->barcode_data;
            $invoice->bankSlip->barcodeImage = $iuguInvoice->bank_slip->barcode;
        }
        $invoice->url = $iuguInvoice->secure_url;
        $invoice->fee = $iuguInvoice->taxes_paid_cents ?? null;
        $invoice->original = $iuguCharge;
        $invoice->createdAt = new DateTimeImmutable($iuguInvoice->created_at_iso);
        return $invoice;
    }

    /**
     * @inheritDoc
     */
    public function createCustomer(Customer $customer): Customer
    {
        if (is_null($customer->name)) {
            throw new Exception('The name os Costumer is required.');
        }
        if (is_null($customer->email)) {
            throw new Exception('The email os Costumer is required.');
        }

        $iuguCustomerData = $this->multiPaymentToIuguData($customer->toArrayWithoutEmpty());

        if (!is_null($customer->address)) {
            $iuguCustomerData = array_merge($iuguCustomerData, $customer->address->toArrayWithoutEmpty());
        }
        $iuguCustomer = Iugu_Customer::create($iuguCustomerData);

        $customer->id = $iuguCustomer->id;
        $customer->gateway = 'iugu';
        $customer->createdAt = new DateTimeImmutable($iuguCustomer->created_at_iso);
        $customer->original = $iuguCustomer;

        return $customer;
    }

    /**
     * Convert Iugu status to MultiPayment status.
     *
     * @param $iuguStatus
     *
     * @return string
     */
    private static function iuguStatusToMultiPayment($iuguStatus): string
    {
        switch ($iuguStatus) {
            case self::STATUS_PAID:
                return Invoice::STATUS_PAID;
            case self::STATUS_CANCELED:
                return Invoice::STATUS_CANCELLED;
            case self::STATUS_REFUNDED:
                return Invoice::STATUS_REFUNDED;
//            case self::STATUS_EXPIRED:
//            case self::STATUS_IN_PROTEST:
//            case self::STATUS_CHARGEBACK:
            default:
                return Invoice::STATUS_PENDING;
        }
    }

    /**
     * Convert MultiPayment data to Iugu data
     *
     * @param  array  $data
     *
     * @return array
     */
    private function multiPaymentToIuguData(array $data): array
    {
        $iuguCustomerKeys = [
            'name' => 'name',
            'email' => 'email',
            'tax_document' => 'cpf_cnpj',
            'street' => 'street',
            'number' => 'number',
            'complement' => 'complement',
            'city' => 'city',
            'state' => 'state',
            'zip_code' => 'zip_code',
            'phone_area' => 'phone_prefix',
            'phone_number' => 'phone',
            'country' => 'country',
        ];
        $iuguCustomerData = [];
        foreach ($data as $key => $value) {
            if (array_key_exists($key, $iuguCustomerKeys)) {
                $iuguCustomerData[$iuguCustomerKeys[$key]] = $value;
            }
        }
        return $iuguCustomerData;
    }

    /**
     * Create a new Credit Card
     *
     * @param  CreditCard  $creditCard
     *
     * @return CreditCard
     * @throws Exception
     */
    public function createCreditCard(CreditCard $creditCard): CreditCard
    {
        if (is_null($creditCard->token) &&
            is_null($creditCard->number) &&
            is_null($creditCard->cvv) &&
            is_null($creditCard->firstName) &&
            is_null($creditCard->lastName) &&
            is_null($creditCard->month) &&
            is_null($creditCard->year)
            ) {
            throw new Exception('The token or the credit card data is required.');
        }
        if (is_null($creditCard->customer) || is_null($creditCard->customer->id)) {
            throw new Exception('The customer id is required.');
        }

        if (is_null($creditCard->token)) {
            $creditCard->token = Iugu_PaymentToken::create([
                'account_id' => config('multi-payment.gateways.iugu.id'),
                'method' => 'credit_card',
                'test' => config('multi-payment.environment') != 'production',
                'data' => [
                    'number' => $creditCard->number,
                    'verification_value' => $creditCard->cvv,
                    'first_name' => $creditCard->firstName,
                    'last_name' => $creditCard->lastName,
                    'month' => $creditCard->month,
                    'year' => $creditCard->year,
                ],
            ]);
        }
        $iuguCreditCard = Iugu_PaymentMethod::create([
            'token' => $creditCard->token,
            'customer_id' => $creditCard->customer->id,
            'description' => $creditCard->description ?? 'CREDIT CARD',
        ]);
        $creditCard->id = $iuguCreditCard->id ?? null;
        $creditCard->brand = $iuguCreditCard->data->brand ?? null;
        $creditCard->year = $iuguCreditCard->data->year ?? null;
        $creditCard->month = $iuguCreditCard->data->month ?? null;
        $names = explode(' ', $iuguCreditCard->data->holder_name);
        $creditCard->firstName = $names[0] ?? null;
        $creditCard->lastName = $names[array_key_last($names)] ?? null;
        $creditCard->lastDigits = $iuguCreditCard->data->last_digits ?? null;
        $creditCard->gateway = 'iugu';
        $creditCard->createdAt = new DateTimeImmutable($iuguCreditCard->created_at_iso) ?? null;
        return $creditCard;
    }
}
