<?php

namespace Potelo\MultiPayment\Gateways;

use Iugu;
use Iugu_Charge;
use Iugu_Customer;
use InvalidArgumentException;
use Potelo\MultiPayment\MultiPayment;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Contracts\Gateway;

class IuguGateway implements Gateway
{
    private const STATUS_PENDING = 'pending';
    private const STATUS_PAID = 'paid';
    private const STATUS_DRAF = 'draft';
    private const STATUS_CANCELED = 'canceled';
    private const STATUS_PARTIALLY_PAID = 'partially_paid';
    private const STATUS_REFUNDED = 'refunded';
    private const STATUS_EXPIRED = 'expired';
    private const STATUS_IN_PROTEST = 'in_protest';
    private const STATUS_CHARGEBACK = 'chargeback';
    private const STATUS_IN_ANALISYS = 'in_analysis';

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
        $iuguInvoiceData['items'] = [];
        foreach ($invoice->items as $item) {
            $iuguInvoiceData['items'][] = [
                'description' => $item->description,
                'quantity' => $item->quantity,
                'price_cents' => $item->price,
            ];
        }
        if ($invoice->paymentMethod == MultiPayment::PAYMENT_METHOD_CREDIT_CARD) {
            if (is_null($invoice->creditCard->token)) {
                $token = \Iugu_PaymentToken::create([
                    'account_id' => config('multi-payment.gateways.iugu.id'),
                    'method' => 'credit_card',
                    'test' => config('multi-payment.environment') != 'production',
                    'data' => [
                        'number' => $invoice->creditCard->number,
                        'verification_value' => $invoice->creditCard->cvv,
                        'first_name' => $invoice->creditCard->firstName,
                        'last_name' => $invoice->creditCard->lastName,
                        'month' => $invoice->creditCard->month,
                        'year' => $invoice->creditCard->year,
                    ],
                ]);
                $invoice->creditCard->token = $token->id;
            }
            $iuguInvoiceData['token'] = $invoice->creditCard->token;
        } elseif ($invoice->paymentMethod == MultiPayment::PAYMENT_METHOD_BANK_SLIP) {
            $iuguInvoiceData['due_date'] = $invoice->bankSlip->expirationDate->format('Y-m-d');
            $iuguInvoiceData['method'] = $invoice->paymentMethod;
            $iuguInvoiceData = array_merge($iuguInvoiceData, $invoice->customer->address->toArrayWithoutEmpty());
        }

        $iuguCharge = Iugu_Charge::create($iuguInvoiceData);
        $iuguInvoice = $iuguCharge->invoice();

        $invoice->id = $iuguInvoice->id;
        $invoice->gateway = 'iugu';

        $invoice->status = $this->iuguStatusToMultiPayment($iuguInvoice->status);
        $invoice->amount = $iuguInvoice->total_cents;
        $invoice->orderId = $iuguInvoice->order_id;

        if ($iuguCharge->method == MultiPayment::PAYMENT_METHOD_BANK_SLIP) {
            $invoice->bankSlip->url = $iuguInvoice->secure_url . '.pdf';
            $invoice->bankSlip->number = $iuguInvoice->bank_slip->digitable_line;
            $invoice->bankSlip->barcodeData = $iuguInvoice->bank_slip->barcode_data;
            $invoice->bankSlip->barcodeImage = $iuguInvoice->bank_slip->barcode;
        }
        $invoice->url = $iuguInvoice->secure_url;
        $invoice->fee = $iuguInvoice->taxes_paid_cents ?? null;
        $invoice->original = $iuguCharge;
        $invoice->createdAt = new \DateTime($iuguInvoice->created_at_iso);
        return $invoice;
    }

    /**
     * @param $iuguStatus
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
     * @inheritDoc
     */
    public function createCustomer(Customer $customer): Customer
    {
        if (is_null($customer->name)) {
            throw new InvalidArgumentException('The name os Costumer is required.');
        }
        if (is_null($customer->email)) {
            throw new InvalidArgumentException('The email os Costumer is required.');
        }

        $iuguCustomerData = $this->multiPaymentToIuguData($customer->toArrayWithoutEmpty());

        if (! is_null($customer->address)) {
            $iuguCustomerData = array_merge($iuguCustomerData, $customer->address->toArrayWithoutEmpty());
        }
        $iuguCustomer = Iugu_Customer::create($iuguCustomerData);

        $customer->id = $iuguCustomer->id;
        $customer->gateway = 'iugu';
        $customer->createdAt = new \DateTimeImmutable($iuguCustomer->created_at_iso);
        $customer->original = $iuguCustomer;

        return $customer;
    }

    /**
     * @param  array  $data
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
}
