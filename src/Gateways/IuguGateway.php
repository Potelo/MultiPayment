<?php

namespace Potelo\MultiPayment\Gateways;

use Iugu;
use Iugu_Customer;
use Carbon\Carbon;
use Iugu_PaymentToken;
use Iugu_PaymentMethod;
use Potelo\MultiPayment\Models\Pix;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\BankSlip;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Contracts\Gateway;
use Potelo\MultiPayment\Exceptions\GatewayException;

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
        $iuguInvoiceData['due_date'] = !is_null($invoice->expirationDate)
            ? $invoice->expirationDate->format('Y-m-d')
            : Carbon::now()->format('Y-m-d');

        if (!empty($invoice->customer->address)) {
            $iuguInvoiceData['payer']['address'] = $invoice->customer->address->toArrayWithoutEmpty();
            if (empty($invoice->customer->address->number)) {
                $iuguInvoiceData['payer']['address']['number'] = 'S/N';
            }
        }
        $iuguInvoice = null;
        if ($invoice->paymentMethod == Invoice::PAYMENT_METHOD_BANK_SLIP || $invoice->paymentMethod == Invoice::PAYMENT_METHOD_PIX) {
            $iuguInvoiceData['payable_with'] = $invoice->paymentMethod;
            try {
                $iuguInvoice = \Iugu_Invoice::create($iuguInvoiceData);
            } catch (\Exception $e) {
                throw new GatewayException($e->getMessage());
            }
            if ($iuguInvoice->errors) {
                throw new GatewayException('Error creating invoice', $iuguInvoice->errors);
            }
        } elseif ($invoice->paymentMethod == Invoice::PAYMENT_METHOD_CREDIT_CARD) {
            if (is_null($invoice->creditCard->id)) {
                $invoice->creditCard = $this->createCreditCard($invoice->creditCard);
            }
            $iuguInvoiceData['customer_payment_method_id'] = $invoice->creditCard->id;
            try {
                $iuguCharge = \Iugu_Charge::create($iuguInvoiceData);
            } catch (\Exception $e) {
                throw new GatewayException($e->getMessage());
            }
            if ($iuguCharge->errors) {
                throw new GatewayException('Error charging invoice', $iuguCharge->errors);
            }
            $iuguInvoice = $iuguCharge->invoice();
        }
        if (is_null($iuguInvoice)) {
            throw new GatewayException('Error creating invoice');
        }
        $invoice->id = $iuguInvoice->id;
        $invoice->gateway = 'iugu';
        $invoice->status = $this->iuguStatusToMultiPayment($iuguInvoice->status);
        $invoice->amount = $iuguInvoice->total_cents;
        $invoice->orderId = $iuguInvoice->order_id;

        if ($invoice->paymentMethod == Invoice::PAYMENT_METHOD_BANK_SLIP) {
            $invoice->bankSlip = new BankSlip();
            $invoice->bankSlip->url = $iuguInvoice->secure_url . '.pdf';
            $invoice->bankSlip->number = $iuguInvoice->bank_slip->digitable_line;
            $invoice->bankSlip->barcodeData = $iuguInvoice->bank_slip->barcode_data;
            $invoice->bankSlip->barcodeImage = $iuguInvoice->bank_slip->barcode;
        } elseif ($invoice->paymentMethod == Invoice::PAYMENT_METHOD_PIX) {
            $invoice->pix = new Pix();
            $invoice->pix->qrCodeImageUrl = $iuguInvoice->pix->qrcode;
            $invoice->pix->qrCodeText = $iuguInvoice->pix->qrcode_text;
        }
        $invoice->url = $iuguInvoice->secure_url;
        $invoice->fee = $iuguInvoice->taxes_paid_cents ?? null;
        $invoice->original = $iuguInvoice;
        $invoice->createdAt = new Carbon($iuguInvoice->created_at_iso);
        return $invoice;
    }


    /**
     * @inheritDoc
     */
    public function requiredInvoiceAttributes(): array
    {
        return [
            'customer',
            'items',
            'paymentMethod',
        ];
    }

    /**
     * @inheritDoc
     */
    public function createCustomer(Customer $customer): Customer
    {
        $iuguCustomerData = $this->multiPaymentToIuguData($customer->toArrayWithoutEmpty());

        if (!is_null($customer->address)) {
            $iuguCustomerData = array_merge($iuguCustomerData, $customer->address->toArrayWithoutEmpty());
            if (empty($customer->address->number)) {
                $iuguCustomerData['number'] = 'S/N';
            }
        }
        try {
            $iuguCustomer = Iugu_Customer::create($iuguCustomerData);
        } catch (\Exception $e) {
            throw new GatewayException($e->getMessage());
        }
        if ($iuguCustomer->errors) {
            throw new GatewayException('Error creating customer', $iuguCustomer->errors);
        }
        $customer->id = $iuguCustomer->id;
        $customer->gateway = 'iugu';
        $customer->createdAt = new Carbon($iuguCustomer->created_at);
        $customer->original = $iuguCustomer;

        return $customer;
    }

    /**
     * @inheritDoc
     */
    public function requiredCustomerAttributes(): array
    {
        return [
            'name',
            'email',
        ];
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
     * @throws GatewayException
     */
    public function createCreditCard(CreditCard $creditCard): CreditCard
    {
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
        try {
            $iuguCreditCard = Iugu_PaymentMethod::create([
                'token' => $creditCard->token,
                'customer_id' => $creditCard->customer->id,
                'description' => $creditCard->description ?? 'CREDIT CARD',
            ]);
        } catch (\Exception $e) {
            throw new GatewayException($e->getMessage());
        }
        $creditCard->id = $iuguCreditCard->id ?? null;
        $creditCard->brand = $iuguCreditCard->data->brand ?? null;
        $creditCard->year = $iuguCreditCard->data->year ?? null;
        $creditCard->month = $iuguCreditCard->data->month ?? null;
        if (!empty($iuguCreditCard->data->holder_name)) {
            $names = explode(' ', $iuguCreditCard->data->holder_name);
            $creditCard->firstName = $names[0] ?? null;
            $creditCard->lastName = $names[array_key_last($names)] ?? null;
        }
        $creditCard->lastDigits = $iuguCreditCard->data->last_digits ?? null;
        $creditCard->gateway = 'iugu';
        $creditCard->createdAt = new Carbon($iuguCreditCard->created_at_iso) ?? null;
        return $creditCard;
    }
}
