<?php

namespace Potelo\MultiPayment\Gateways;

use Iugu;
use Iugu_Customer;
use Carbon\Carbon;
use Iugu_PaymentToken;
use Iugu_PaymentMethod;
use IuguObjectNotFound;
use Potelo\MultiPayment\Models\Pix;
use Illuminate\Support\Facades\Config;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Address;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\BankSlip;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\InvoiceItem;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\ChargingException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

class IuguGateway implements GatewayContract
{
    private const STATUS_PENDING = 'pending';
    private const STATUS_PAID = 'paid';
    private const STATUS_EXTERNALLY_PAID = 'externally_paid';
    private const STATUS_CANCELED = 'canceled';
    private const STATUS_IN_ANALYSIS = 'in_analysis';
    private const STATUS_DRAFT = 'draft';
    private const STATUS_PARTIALLY_PAID = 'partially_paid';
    private const STATUS_REFUNDED = 'refunded';
    private const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';
    private const STATUS_EXPIRED = 'expired';
    private const STATUS_IN_PROTEST = 'in_protest';
    private const STATUS_CHARGEBACK = 'chargeback';
    private const STATUS_AUTHORIZED = 'authorized';

    /**
     * Set iugu api key.
     */
    public function __construct()
    {
        Iugu::setApiKey(Config::get('multi-payment.gateways.iugu.api_key'));
    }

    /**
     * @inheritDoc
     * @throws ModelAttributeValidationException|ChargingException
     */
    public function createInvoice(Invoice $invoice): Invoice
    {
        $iuguInvoiceData = [];

        $iuguInvoiceData['customer_id'] = $invoice->customer->id;
        $iuguInvoiceData['payer']['name'] = $invoice->customer->name;
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
        $iuguInvoiceData['due_date'] = !empty($invoice->expiresAt)
            ? $invoice->expiresAt->format('Y-m-d')
            : Carbon::now()->format('Y-m-d');
        $iuguInvoiceData['expires_in'] = 0;

        if (!empty($invoice->customer->address)) {
            $iuguInvoiceData['payer']['address'] = $invoice->customer->address->toArray();
            if (empty($invoice->customer->address->number)) {
                $iuguInvoiceData['payer']['address']['number'] = 'S/N';
            }
        }

        if (!empty($invoice->availablePaymentMethods)) {
            $iuguInvoiceData['payable_with'] = $invoice->availablePaymentMethods;
        }

        if (!empty($invoice->gatewayAdicionalOptions)) {
            foreach ($invoice->gatewayAdicionalOptions as $option => $value) {
                $iuguInvoiceData[$option] = $value;
            }
        }

        if (
            !empty($invoice->availablePaymentMethods) &&
            in_array(Invoice::PAYMENT_METHOD_CREDIT_CARD, $invoice->availablePaymentMethods) &&
            !empty($invoice->creditCard)
        ) {
            if (empty($invoice->creditCard->id)) {
                $invoice->creditCard = $this->createCreditCard($invoice->creditCard);
            }
            $iuguInvoiceData['customer_payment_method_id'] = $invoice->creditCard->id;
            $iuguInvoice = $this->chargeIuguInvoice($iuguInvoiceData);
        } else {
            try {
                $iuguInvoice = \Iugu_Invoice::create($iuguInvoiceData);
            } catch (\IuguRequestException|IuguObjectNotFound $e) {
                if (str_contains($e->getMessage(), '502 Bad Gateway')) {
                    throw new GatewayNotAvailableException($e->getMessage());
                } else {
                    throw new GatewayException($e->getMessage());
                }
            } catch (\IuguAuthenticationException $e) {
                throw new GatewayNotAvailableException($e->getMessage());
            } catch (\Exception $e) {
                throw new GatewayException($e->getMessage());
            }
            if ($iuguInvoice->errors) {
                throw new GatewayException('Error creating invoice', $iuguInvoice->errors);
            }
        }

        return $this->parseInvoice($iuguInvoice, $invoice);
    }

    /**
     * @inheritDoc
     */
    public function createCustomer(Customer $customer): Customer
    {
        $iuguCustomerData = $this->customerToIuguData($customer);

        try {
            $iuguCustomer = Iugu_Customer::create($iuguCustomerData);
        } catch (\IuguRequestException | IuguObjectNotFound $e) {
            if (str_contains($e->getMessage(), '502 Bad Gateway')) {
                throw new GatewayNotAvailableException($e->getMessage());
            } else {
                throw new GatewayException($e->getMessage());
            }
        } catch (\IuguAuthenticationException $e) {
            throw new GatewayNotAvailableException($e->getMessage());
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
     * Convert Iugu status to MultiPayment status.
     *
     * @param $iuguStatus
     *
     * @return string
     * @throws GatewayException
     */
    private static function iuguStatusToMultiPayment($iuguStatus): string
    {
        switch ($iuguStatus) {
            case self::STATUS_PENDING:
            case self::STATUS_IN_ANALYSIS:
            case self::STATUS_DRAFT:
            case self::STATUS_PARTIALLY_PAID:
                return Invoice::STATUS_PENDING;
            case self::STATUS_PAID:
            case self::STATUS_EXTERNALLY_PAID:
            case self::STATUS_AUTHORIZED:
            case self::STATUS_IN_PROTEST:
                return Invoice::STATUS_PAID;
            case self::STATUS_CANCELED:
            case self::STATUS_EXPIRED:
                return Invoice::STATUS_CANCELED;
            case self::STATUS_REFUNDED:
            case self::STATUS_CHARGEBACK:
                return Invoice::STATUS_REFUNDED;
            case self::STATUS_PARTIALLY_REFUNDED:
                return Invoice::STATUS_PARTIALLY_REFUNDED;
            default:
                throw new GatewayException('Unexpected Iugu status: ' . $iuguStatus);
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
            'district' => 'district',
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
     * @throws GatewayException|ModelAttributeValidationException
     * @throws GatewayNotAvailableException
     */
    public function createCreditCard(CreditCard $creditCard): CreditCard
    {
        if (empty($creditCard->customer) || empty($creditCard->customer->id)) {
            throw ModelAttributeValidationException::required('CreditCard', 'customer');
        }
        if (empty($creditCard->token)) {
            $creditCard->token = Iugu_PaymentToken::create([
                'account_id' => Config::get('multi-payment.gateways.iugu.id'),
                'method' => 'credit_card',
                'test' => Config::get('multi-payment.environment') != 'production',
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

        $options = [
            'token' => $creditCard->token,
            'customer_id' => $creditCard->customer->id,
            'description' => $creditCard->description ?? 'CREDIT CARD',
        ];

        if (!empty($creditCard->default)) {
            $options['set_as_default'] = $creditCard->default;
        }

        try {
            $iuguCreditCard = Iugu_PaymentMethod::create($options);
        } catch (\IuguRequestException | IuguObjectNotFound $e) {
            if (str_contains($e->getMessage(), '502 Bad Gateway')) {
                throw new GatewayNotAvailableException($e->getMessage());
            } else {
                throw new GatewayException($e->getMessage());
            }
        } catch (\IuguAuthenticationException $e) {
            throw new GatewayNotAvailableException($e->getMessage());
        } catch (\Exception $e) {
            throw new GatewayException($e->getMessage());
        }
        if ($iuguCreditCard->errors) {
            throw new GatewayException('Error creating creditCard: ', $iuguCreditCard->errors);
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
        $creditCard->lastDigits = $iuguCreditCard->data->last_digits ?? substr($iuguCreditCard->data->display_number, -4);
        $creditCard->gateway = 'iugu';
        $creditCard->original = $iuguCreditCard;
        $creditCard->createdAt = new Carbon($iuguCreditCard->created_at_iso) ?? null;
        return $creditCard;
    }

    /**
     * @inheritDoc
     */
    public function getInvoice(string $id): Invoice
    {
        try {
            $iuguInvoice = \Iugu_Invoice::fetch($id);
        } catch (\IuguRequestException | IuguObjectNotFound $e) {
            if (str_contains($e->getMessage(), '502 Bad Gateway')) {
                throw new GatewayNotAvailableException($e->getMessage());
            } else {
                throw new GatewayException($e->getMessage());
            }
        } catch (\Exception $e) {
            throw new GatewayException("Error getting invoice: {$e->getMessage()}");
        }
        if (!empty($iuguInvoice->errors)) {
            throw new GatewayException('Error getting invoice', $iuguInvoice->errors);
        }

        return $this->parseInvoice($iuguInvoice);
    }

    /**
     * Convert the iugu payment method to the MultiPayment payment method
     *
     * @param $iuguPaymentMethod
     *
     * @return string|null
     */
    private function iuguToMultiPaymentPaymentMethod($iuguPaymentMethod): ?string
    {
        $multiPaymentPaymentMethod = [
            Invoice::PAYMENT_METHOD_PIX,
            Invoice::PAYMENT_METHOD_BANK_SLIP,
            Invoice::PAYMENT_METHOD_CREDIT_CARD
        ];
        if (!empty($iuguPaymentMethod)) {
            foreach ($multiPaymentPaymentMethod as $paymentMethod) {
                if (str_contains($iuguPaymentMethod, $paymentMethod)) {
                    return $paymentMethod;
                }
            }
        }
        return null;
    }

    /**
     * @inheritDoc
     */
    public function refundInvoice(Invoice $invoice): Invoice
    {
        $iuguInvoice = new \Iugu_Invoice(['id' => $invoice->id]);

        try {
            $refunded = $iuguInvoice->refund($invoice->refundedAmount ?? null);
            if (!$refunded) {
                throw new GatewayException("Error refunding invoice", $iuguInvoice->errors ?? []);
            }
        } catch (GatewayException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new GatewayException("Error refunding invoice: {$e->getMessage()}");
        }

        return $this->parseInvoice($iuguInvoice, $invoice);
    }

    /**
     * @inheritDoc
     */
    public function duplicateInvoice(Invoice $invoice, Carbon $expiresAt, array $gatewayOptions = []): Invoice
    {
        $iuguInvoice = new \Iugu_Invoice(['id' => $invoice->id]);

        $params = array_merge($gatewayOptions, [
            'due_date' => $expiresAt->format('Y-m-d'),
        ]);
        try {
            $iuguInvoice = $iuguInvoice->duplicate($params);
        } catch (\IuguRequestException | IuguObjectNotFound $e) {
            if (str_contains($e->getMessage(), '502 Bad Gateway')) {
                throw new GatewayNotAvailableException($e->getMessage());
            } else {
                throw new GatewayException($e->getMessage());
            }
        } catch (\Exception $e) {
            throw new GatewayException("Error getting invoice: {$e->getMessage()}");
        }
        if (!empty($iuguInvoice->errors)) {
            throw new GatewayException('Error getting invoice', $iuguInvoice->errors);
        }

        return $this->parseInvoice($iuguInvoice);
    }

    /**
     * @inheritDoc
     */
    public function __toString()
    {
        return 'iugu';
    }

    /**
     * Convert the iugu invoice into a MultiPayment invoice
     *
     * @param $iuguInvoice
     * @param  \Potelo\MultiPayment\Models\Invoice|null  $invoice
     *
     * @return \Potelo\MultiPayment\Models\Invoice
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    private function parseInvoice($iuguInvoice, ?Invoice $invoice = null): Invoice
    {
        $invoice = $invoice ?? new Invoice();

        $invoice->id = $iuguInvoice->id;
        $invoice->gateway = 'iugu';
        $invoice->status = self::iuguStatusToMultiPayment($iuguInvoice->status);
        $invoice->amount = $iuguInvoice->total_cents;
        $invoice->paidAt = $iuguInvoice->paid_at ? new Carbon($iuguInvoice->paid_at) : null;
        $invoice->url = $iuguInvoice->secure_url;
        $invoice->fee = $iuguInvoice->taxes_paid_cents ?? null;
        $invoice->original = $iuguInvoice;
        $invoice->createdAt = new Carbon($iuguInvoice->created_at_iso);
        $invoice->paidAmount = $iuguInvoice->paid_cents;
        $invoice->refundedAmount = $iuguInvoice->refunded_cents;
        $invoice->expiresAt = !empty($iuguInvoice->due_date) ? new Carbon($iuguInvoice->due_date) : null;

        if (empty($invoice->paymentMethod)) {
            $invoice->paymentMethod = $this->iuguToMultiPaymentPaymentMethod($iuguInvoice->payment_method);
        }

        if (!empty(($iuguInvoice->payable_with))) {

            $payableWith = $iuguInvoice->payable_with;
            if (is_string($payableWith)) {
                $payableWith = [$payableWith];
            }

            foreach ($payableWith as $pm) {
                $method = $this->iuguToMultiPaymentPaymentMethod($pm);
                if (is_null($method) && $pm === 'all') {
                    $invoice->availablePaymentMethods = [
                        Invoice::PAYMENT_METHOD_CREDIT_CARD,
                        Invoice::PAYMENT_METHOD_BANK_SLIP,
                        Invoice::PAYMENT_METHOD_PIX,
                    ];
                }
            }
        }

        if (empty($invoice->customer)) {
            $invoice->customer = new Customer();
        }

        $invoice->customer->id = $iuguInvoice->customer_id;
        $invoice->customer->name = $iuguInvoice->customer_name;
        $invoice->customer->email = $iuguInvoice->email;
        $invoice->customer->phoneNumber = $iuguInvoice->payer_phone;
        $invoice->customer->phoneArea = $iuguInvoice->payer_phone_prefix;

        $invoice->items = [];

        foreach ($iuguInvoice->items as $itemIugu) {
            $invoiceItem = new InvoiceItem();
            $itemIugu = (object) $itemIugu;
            $invoiceItem->description = $itemIugu->description;
            $invoiceItem->price = $itemIugu->price_cents;
            $invoiceItem->quantity = $itemIugu->quantity;
            $invoice->items[] = $invoiceItem;
        }

        if (!empty($iuguInvoice->payer_address_zip_code)) {
            if (empty($invoice->customer->address)) {
                $invoice->customer->address = new Address();
            }
            $invoice->customer->address->zipCode = $iuguInvoice->payer_address_zip_code;
            $invoice->customer->address->street = $iuguInvoice->payer_address_street;
            $invoice->customer->address->number = $iuguInvoice->payer_address_number;
            $invoice->customer->address->district = $iuguInvoice->payer_address_district;
            $invoice->customer->address->city = $iuguInvoice->payer_address_city;
            $invoice->customer->address->state = $iuguInvoice->payer_address_state;
            $invoice->customer->address->complement = $iuguInvoice->payer_address_complement;
            $invoice->customer->address->country = $iuguInvoice->payer_address_country;
        }

        if (!empty($iuguInvoice->bank_slip)) {
            if (empty($invoice->bankSlip)) {
                $invoice->bankSlip = new BankSlip();
            }
            $invoice->bankSlip->url = $iuguInvoice->secure_url . '.pdf';
            $invoice->bankSlip->number = $iuguInvoice->bank_slip->digitable_line;
            $invoice->bankSlip->barcodeData = $iuguInvoice->bank_slip->barcode_data;
            $invoice->bankSlip->barcodeImage = $iuguInvoice->bank_slip->barcode;
        }

        if (!empty($iuguInvoice->pix)) {
            if (empty($invoice->pix)) {
                $invoice->pix = new Pix();
            }
            $invoice->pix->qrCodeImageUrl = $iuguInvoice->pix->qrcode;
            $invoice->pix->qrCodeText = $iuguInvoice->pix->qrcode_text;
        }

        if (!empty($iuguInvoice->credit_card_transaction)) {
            if (empty($invoice->creditCard)) {
                $invoice->creditCard = new CreditCard();
            }
            $invoice->creditCard->brand = $iuguInvoice->credit_card_brand ?? null;
            $invoice->creditCard->lastDigits = $iuguInvoice->credit_card_last_4 ?? $iuguInvoice->credit_card_transaction->last4;

            $holderName = null;
            foreach ($iuguInvoice->variables as $iuguInvoiceVariable) {
                if ($iuguInvoiceVariable->variable == 'payment_data.holder_name') {
                    $holderName = $iuguInvoiceVariable->value;
                } else if (empty($invoice->creditCard->lastDigits) && $iuguInvoiceVariable->variable == 'payment_data.display_number') {
                    $invoice->creditCard->lastDigits = substr($iuguInvoiceVariable->value, -4);
                }
            }

            if (!empty($holderName)) {
                $names = explode(' ', $holderName);
                $invoice->creditCard->firstName = $names[array_key_first($names)] ?? null;
                $invoice->creditCard->lastName = $names[array_key_last($names)] ?? null;
            }

            $invoice->creditCard->gateway = 'iugu';
        }

        return $invoice;
    }

    /**
     * @inheritDoc
     * @param  \Potelo\MultiPayment\Models\Invoice  $invoice
     * @return \Potelo\MultiPayment\Models\Invoice
     * @throws \Potelo\MultiPayment\Exceptions\ChargingException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\ModelAttributeValidationException
     */
    public function chargeInvoiceWithCreditCard(Invoice $invoice): Invoice
    {
        if (empty($invoice->id)) {
            throw ModelAttributeValidationException::required('Invoice', 'id');
        }

        if (empty($invoice->creditCard)) {
            throw ModelAttributeValidationException::required('Invoice', 'creditCard');
        }

        if (empty($invoice->creditCard->token) && empty($invoice->creditCard->id)) {
            throw new ModelAttributeValidationException('Credit card token or id is required');
        }

        $iuguInvoiceData = [];
        $iuguInvoiceData['invoice_id'] = $invoice->id;

        if (!empty($invoice->creditCard->id)) {
            $iuguInvoiceData['customer_payment_method_id'] = $invoice->creditCard->id;
        } else {
            $iuguInvoiceData['token'] = $invoice->creditCard->token;
        }

        $iuguInvoice = $this->chargeIuguInvoice($iuguInvoiceData);

        return $this->parseInvoice($iuguInvoice, $invoice);
    }

    /**
     * @param  array  $iuguInvoiceData
     * @return mixed
     * @throws \Potelo\MultiPayment\Exceptions\ChargingException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     */
    private function chargeIuguInvoice(array $iuguInvoiceData)
    {
        try {
            $iuguCharge = \Iugu_Charge::create($iuguInvoiceData);
        } catch (\Exception $e) {
            throw new GatewayException($e->getMessage());
        }
        if ($iuguCharge->errors) {
            throw new GatewayException('Error charging invoice', $iuguCharge->errors);
        } elseif (!$iuguCharge->success) {
            $exception = new ChargingException('Error charging invoice: ' . $iuguCharge->info_message);
            $exception->chargeResponse = $iuguCharge;
            throw $exception;
        }
        return $iuguCharge->invoice();
    }

    public function getCustomer(string $id): Customer
    {
        try {
            $iuguCustomer = Iugu_Customer::fetch($id);
        } catch (\IuguRequestException | IuguObjectNotFound $e) {
            if (str_contains($e->getMessage(), '502 Bad Gateway')) {
                throw new GatewayNotAvailableException($e->getMessage());
            } else {
                throw new GatewayException($e->getMessage());
            }
        } catch (\Exception $e) {
            throw new GatewayException("Error getting customer: {$e->getMessage()}");
        }

        if (!empty($iuguCustomer->errors)) {
            throw new GatewayException('Error getting customer', $iuguCustomer->errors);
        }

        return $this->parseCustomer($iuguCustomer);
    }

    public function updateCustomer(Customer $customer): Customer
    {
        if (empty($customer->id)) {
            throw ModelAttributeValidationException::required('Customer', 'id');
        }

        $iuguCustomerData = $this->customerToIuguData($customer);

        try {
            $iuguCustomer = Iugu_Customer::fetch($customer->id);
            foreach ($iuguCustomerData as $key => $value) {
                $iuguCustomer->{$key} = $value;
            }
            $iuguCustomer->save();
        } catch (\IuguRequestException | IuguObjectNotFound $e) {
            if (str_contains($e->getMessage(), '502 Bad Gateway')) {
                throw new GatewayNotAvailableException($e->getMessage());
            } else {
                throw new GatewayException($e->getMessage());
            }
        } catch (\IuguAuthenticationException $e) {
            throw new GatewayNotAvailableException($e->getMessage());
        } catch (\Exception $e) {
            throw new GatewayException($e->getMessage());
        }

        if ($iuguCustomer->errors) {
            throw new GatewayException('Error updating customer', $iuguCustomer->errors);
        }

        return $this->parseCustomer($iuguCustomer, $customer);
    }

    /**
     * Parse the Iugu customer into a MultiPayment customer
     *
     * @param $iuguCustomer
     * @param  \Potelo\MultiPayment\Models\Customer|null  $customer
     *
     * @return \Potelo\MultiPayment\Models\Customer
     */
    private function parseCustomer($iuguCustomer, ?Customer $customer = null): Customer
    {
        $customer = $customer ?? new Customer();

        $valuesInsideCustomVariables = ['birth_date' => null, 'country' => null];

        if (!empty($iuguCustomer->custom_variables)) {
            foreach ($iuguCustomer->custom_variables as $variable) {
                if (in_array($variable->name, array_keys($valuesInsideCustomVariables))) {
                    $valuesInsideCustomVariables[$variable->name] = $variable->value;
                }
            }
        }

        $customer->id = $iuguCustomer->id;
        $customer->name = $iuguCustomer->name;
        $customer->email = $iuguCustomer->email;
        $customer->taxDocument = $iuguCustomer->cpf_cnpj;
        $customer->phoneNumber = $iuguCustomer->phone;
        $customer->phoneArea = $iuguCustomer->phone_prefix;
        $customer->birthDate = !empty($valuesInsideCustomVariables['birth_date'])
            ? Carbon::createFromFormat('Y-m-d', $valuesInsideCustomVariables['birth_date'])
            : null;
        $customer->gateway = 'iugu';
        $customer->createdAt = new Carbon($iuguCustomer->created_at_iso);
        $customer->original = $iuguCustomer;

        if (!empty($iuguCustomer->zip_code) || !empty($iuguCustomer->street) || !empty($iuguCustomer->number) || !empty($iuguCustomer->district) || !empty($iuguCustomer->city) || !empty($iuguCustomer->state) || !empty($iuguCustomer->complement) || !empty($iuguCustomer->country)) {
            if (empty($customer->address)) {
                $customer->address = new Address();
            }
            $customer->address->zipCode = $iuguCustomer?->zip_code ?? null;
            $customer->address->street = $iuguCustomer?->street ?? null;
            $customer->address->number = $iuguCustomer?->number ?? null;
            $customer->address->district = $iuguCustomer?->district ?? null;
            $customer->address->city = $iuguCustomer?->city ?? null;
            $customer->address->state = $iuguCustomer?->state ?? null;
            $customer->address->complement = $iuguCustomer?->complement ?? null;
            $customer->address->country = $valuesInsideCustomVariables['country'] ?? null;
        }

        if (!empty($iuguCustomer->default_payment_method_id)) {
            $customer->defaultCard = new CreditCard();
            $customer->defaultCard->id = $iuguCustomer->default_payment_method_id;
        }

        return $customer;
    }

    /**
     * Convert a MultiPayment Customer model to Iugu data format.
     *
     * @param  \Potelo\MultiPayment\Models\Customer  $customer
     * @return array
     */
    private function customerToIuguData(Customer $customer): array
    {
        $iuguCustomerData = [
            'name' => $customer->name,
            'email' => $customer->email,
            'cpf_cnpj' => $customer->taxDocument,
            'phone_prefix' => $customer->phoneArea,
            'phone' => $customer->phoneNumber,
        ];

        if (!empty($customer->address)) {
            $iuguCustomerData = array_merge($iuguCustomerData, $this->multiPaymentToIuguData($customer->address->toArray()));
            if (empty($customer->address->number)) {
                $iuguCustomerData['number'] = 'S/N';
            }
        }

        if (!empty($customer->birthDate)) {
            if (empty($iuguCustomerData['custom_variables'])) {
                $iuguCustomerData['custom_variables'] = [];
            }
            $iuguCustomerData['custom_variables'][] = [
                'name' => 'birth_date',
                'value' => $customer->birthDate->format('Y-m-d'),
            ];
        }

        if (!empty($customer->address->country)) {
            if (empty($iuguCustomerData['custom_variables'])) {
                $iuguCustomerData['custom_variables'] = [];
            }
            $iuguCustomerData['custom_variables'][] = [
                'name' => 'country',
                'value' => $customer->address->country,
            ];
        }

        if (!empty($customer->gatewayAdicionalOptions)) {
            foreach ($customer->gatewayAdicionalOptions as $option => $value) {
                $iuguCustomerData[$option] = $value;
            }
        }

        return $iuguCustomerData;
    }
}
