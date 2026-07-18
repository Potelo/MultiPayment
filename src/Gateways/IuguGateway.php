<?php

namespace Potelo\MultiPayment\Gateways;

use Iugu;
use Iugu_Customer;
use Carbon\Carbon;
use Iugu_APIRequest;
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
use Potelo\MultiPayment\Models\AutomaticPix;
use Potelo\MultiPayment\Models\AutomaticPixCancellation;
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

    private Iugu_APIRequest $apiRequest;

    /**
     * Set iugu api key.
     */
    public function __construct(?Iugu_APIRequest $apiRequest = null)
    {
        Iugu::setApiKey(Config::get('multi-payment.gateways.iugu.api_key'));
        $this->apiRequest = $apiRequest ?? new Iugu_APIRequest();
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

        if (!empty($invoice->automaticPix)) {
            $iuguInvoiceData['automatic_pix'] = $this->automaticPixToIuguData($invoice->automaticPix);
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

        return $this->parseIuguCard($iuguCreditCard, $creditCard);
    }

    /**
     * @inheritDoc
     */
    public function getInvoice(Invoice $invoice): Invoice
    {
        try {
            $iuguInvoice = \Iugu_Invoice::fetch($invoice->id);
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

        return $this->parseInvoice($iuguInvoice, $invoice);
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
    public function cancelInvoice(Invoice $invoice): Invoice
    {
        $url = Iugu::getBaseURI() . '/invoices/' . rawurlencode($invoice->id) . '/cancel';

        try {
            $response = $this->apiRequest->request('PUT', $url);
        } catch (\IuguRequestException | IuguObjectNotFound $e) {
            if (str_contains($e->getMessage(), '502 Bad Gateway')) {
                throw new GatewayNotAvailableException($e->getMessage());
            }

            throw new GatewayException($e->getMessage());
        } catch (\IuguAuthenticationException $e) {
            throw new GatewayNotAvailableException($e->getMessage());
        } catch (\Exception $e) {
            throw new GatewayException("Error cancelling invoice: {$e->getMessage()}");
        }

        if (!empty($response->errors)) {
            throw new GatewayException('Error cancelling invoice', (array) $response->errors);
        }

        return $this->parseInvoice($response, $invoice);
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

    /** @inheritDoc */
    public function rescheduleAutomaticPixPayment(Invoice $invoice): Invoice
    {
        if (empty($invoice->id)) {
            throw ModelAttributeValidationException::required('Invoice', 'id');
        }

        $url = Iugu::getBaseURI() . '/invoices/' . rawurlencode($invoice->id)
            . '/reschedule_automatic_pix_payment';
        $response = $this->automaticPixRequest('POST', $url, [], 'rescheduling automatic pix payment');

        if (!empty($response->id) && !empty($response->status) && isset($response->total_cents)) {
            return $this->parseInvoice($response, $invoice);
        }

        $invoice->gateway = 'iugu';
        $invoice->original = $response;

        return $invoice;
    }

    /** @inheritDoc */
    public function cancelAutomaticPixRecurrence(
        AutomaticPix $automaticPix
    ): AutomaticPixCancellation {
        if (empty($automaticPix->id)) {
            throw ModelAttributeValidationException::required('AutomaticPix', 'id');
        }

        $url = Iugu::getBaseURI() . '/automatic_pix/receiver_recurrences/'
            . rawurlencode($automaticPix->id) . '/cancel';
        $response = $this->automaticPixRequest('PUT', $url, [], 'cancelling automatic pix recurrence');

        $cancellation = $this->parseAutomaticPixCancellation($response);
        $cancellation->recurrenceId = $automaticPix->id;
        $cancellation->status ??= AutomaticPixCancellation::STATUS_REQUESTED;

        return $cancellation;
    }

    /** @inheritDoc */
    public function cancelAutomaticPixScheduledPayment(
        string $paymentId,
        string $endToEndId
    ): AutomaticPixCancellation {
        if (empty($paymentId)) {
            throw ModelAttributeValidationException::required('AutomaticPixScheduledPayment', 'paymentId');
        }
        if (empty($endToEndId)) {
            throw ModelAttributeValidationException::required('AutomaticPixScheduledPayment', 'endToEndId');
        }

        $query = http_build_query([
            'receiver_recurrence_payment_id' => $paymentId,
            'end_to_end_id' => $endToEndId,
        ], '', '&', PHP_QUERY_RFC3986);
        $url = Iugu::getBaseURI() . '/automatic_pix/receiver_recurrence_payments/cancel?' . $query;
        $response = $this->automaticPixRequest(
            'POST',
            $url,
            [],
            'cancelling automatic pix scheduled payment'
        );

        $cancellation = $this->parseAutomaticPixCancellation($response);
        $cancellation->paymentId ??= $paymentId;
        $cancellation->endToEndId ??= $endToEndId;
        $cancellation->status ??= AutomaticPixCancellation::STATUS_REQUESTED;

        return $cancellation;
    }

    /** @inheritDoc */
    public function getAutomaticPixCancellation(
        AutomaticPixCancellation $cancellation
    ): AutomaticPixCancellation {
        if (empty($cancellation->recurrenceId)) {
            throw ModelAttributeValidationException::required('AutomaticPixCancellation', 'recurrenceId');
        }
        if (empty($cancellation->id)) {
            throw ModelAttributeValidationException::required('AutomaticPixCancellation', 'id');
        }

        $url = Iugu::getBaseURI() . '/automatic_pix/receiver_recurrences/'
            . rawurlencode($cancellation->recurrenceId) . '/cancellations/'
            . rawurlencode($cancellation->id);
        $response = $this->automaticPixRequest('GET', $url, [], 'getting automatic pix cancellation');

        return $this->parseAutomaticPixCancellation($response, $cancellation);
    }

    /** @inheritDoc */
    public function listAutomaticPixCancellations(
        AutomaticPix $automaticPix,
        int $page = 1,
        int $limit = 100
    ): array {
        if (empty($automaticPix->id)) {
            throw ModelAttributeValidationException::required('AutomaticPix', 'id');
        }
        if ($page < 1) {
            throw new GatewayException('Automatic Pix cancellation page must be at least 1');
        }
        if ($limit < 1 || $limit > 100) {
            throw new GatewayException('Automatic Pix cancellation limit must be between 1 and 100');
        }

        $query = http_build_query(['limit' => $limit, 'page' => $page], '', '&', PHP_QUERY_RFC3986);
        $url = Iugu::getBaseURI() . '/automatic_pix/receiver_recurrences/'
            . rawurlencode($automaticPix->id) . '/cancellations?' . $query;
        $response = $this->automaticPixRequest('GET', $url, [], 'listing automatic pix cancellations');

        $items = $this->automaticPixCancellationItems($response);

        return array_map(function ($item) use ($automaticPix) {
            $cancellation = $this->parseAutomaticPixCancellation($item);
            $cancellation->recurrenceId ??= $automaticPix->id;

            return $cancellation;
        }, $items);
    }

    /**
     * Convert the gateway-neutral recurrence model into Iugu invoice fields.
     */
    private function automaticPixToIuguData(AutomaticPix $automaticPix): array
    {
        $automaticPix->validateForInvoice();

        $journeys = [
            AutomaticPix::AUTHORIZATION_TYPE_QR_CODE_WITH_PAYMENT => 3,
            AutomaticPix::AUTHORIZATION_TYPE_QR_CODE_WITH_RECURRENCE_OFFER => 4,
        ];
        if (!isset($journeys[$automaticPix->authorizationType])) {
            throw ModelAttributeValidationException::invalid(
                'AutomaticPix',
                'authorizationType',
                'authorizationType is not supported by the Iugu gateway'
            );
        }

        $retryPolicies = [
            AutomaticPix::RETRY_POLICY_ALLOWED => 'retry_allowed',
            AutomaticPix::RETRY_POLICY_NOT_ALLOWED => 'retry_not_allowed',
        ];

        $data = [
            'journey' => $journeys[$automaticPix->authorizationType],
            'frequency' => $automaticPix->frequency,
            'recurrence_beginning' => $automaticPix->startsAt?->format('Y-m-d'),
            'contract_number' => $automaticPix->contractReference,
            'end_date' => $automaticPix->endsAt?->format('Y-m-d'),
            'receiver_recurrence_id' => $automaticPix->id,
            'retry_policy' => $retryPolicies[$automaticPix->retryPolicy] ?? $automaticPix->retryPolicy,
        ];

        return array_filter($data, static fn ($value) => !is_null($value));
    }

    /**
     * Convert Iugu recurrence fields back into the gateway-neutral model.
     */
    private function parseAutomaticPix($data, ?AutomaticPix $automaticPix = null): AutomaticPix
    {
        $data = (object) $data;
        $automaticPix ??= new AutomaticPix();
        $authorizationTypes = [
            3 => AutomaticPix::AUTHORIZATION_TYPE_QR_CODE_WITH_PAYMENT,
            4 => AutomaticPix::AUTHORIZATION_TYPE_QR_CODE_WITH_RECURRENCE_OFFER,
        ];
        $retryPolicies = [
            'retry_allowed' => AutomaticPix::RETRY_POLICY_ALLOWED,
            'retry_not_allowed' => AutomaticPix::RETRY_POLICY_NOT_ALLOWED,
        ];

        $automaticPix->id = $data->receiver_recurrence_id ?? $data->id ?? $automaticPix->id;
        if (isset($data->journey, $authorizationTypes[(int) $data->journey])) {
            $automaticPix->authorizationType = $authorizationTypes[(int) $data->journey];
        }
        $automaticPix->frequency = $data->frequency ?? $automaticPix->frequency;
        $automaticPix->startsAt = !empty($data->recurrence_beginning)
            ? new Carbon($data->recurrence_beginning)
            : $automaticPix->startsAt;
        $automaticPix->contractReference = $data->contract_number ?? $automaticPix->contractReference;
        $automaticPix->endsAt = !empty($data->end_date)
            ? new Carbon($data->end_date)
            : $automaticPix->endsAt;
        $automaticPix->retryPolicy = $retryPolicies[$data->retry_policy ?? '']
            ?? $automaticPix->retryPolicy;
        $automaticPix->status = $data->status ?? $automaticPix->status;
        $automaticPix->gateway = 'iugu';
        $automaticPix->original = $data;

        return $automaticPix;
    }

    /**
     * Perform a raw Iugu request while preserving the package exception contract.
     */
    private function automaticPixRequest(
        string $method,
        string $url,
        array $data,
        string $operation
    ): object|array {
        try {
            $response = $this->apiRequest->request($method, $url, $data);
        } catch (\IuguRequestException | IuguObjectNotFound $e) {
            if (str_contains($e->getMessage(), '502 Bad Gateway')) {
                throw new GatewayNotAvailableException($e->getMessage());
            }

            throw new GatewayException($e->getMessage());
        } catch (\IuguAuthenticationException $e) {
            throw new GatewayNotAvailableException($e->getMessage());
        } catch (\Exception $e) {
            throw new GatewayException("Error {$operation}: {$e->getMessage()}");
        }

        $responseObject = is_array($response) ? (object) $response : $response;
        if (
            !empty($responseObject->errors)
            || (isset($responseObject->success) && $responseObject->success !== true)
        ) {
            throw new GatewayException("Error {$operation}", (array) ($responseObject->errors ?? []));
        }

        return $response;
    }

    /**
     * @return array<int, mixed>
     */
    private function automaticPixCancellationItems(object|array $response): array
    {
        if (is_array($response)) {
            return array_values($response);
        }

        foreach (['cancellations', 'items', 'data', 'results'] as $property) {
            if (isset($response->{$property}) && is_array($response->{$property})) {
                return array_values($response->{$property});
            }
        }

        return [];
    }

    private function parseAutomaticPixCancellation(
        $data,
        ?AutomaticPixCancellation $cancellation = null
    ): AutomaticPixCancellation {
        $data = (object) $data;
        $cancellation ??= new AutomaticPixCancellation();

        $cancellation->id = $data->cancellation_id ?? $data->id ?? $cancellation->id;
        $cancellation->recurrenceId = $data->receiver_recurrence_id
            ?? $cancellation->recurrenceId;
        $cancellation->paymentId = $data->receiver_recurrence_payment_id
            ?? $cancellation->paymentId;
        $cancellation->endToEndId = $data->end_to_end_id ?? $cancellation->endToEndId;
        $cancellation->status = $data->status ?? $cancellation->status;
        $cancellation->amount = $data->amount ?? $cancellation->amount;
        $cancellation->payerAccount = $data->payer_account ?? $cancellation->payerAccount;
        $cancellation->createdAt = !empty($data->created_at)
            ? new Carbon($data->created_at)
            : $cancellation->createdAt;
        $cancellation->gateway = 'iugu';
        $cancellation->original = $data;

        return $cancellation;
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

        if (!empty($iuguInvoice->automatic_pix)) {
            $invoice->automaticPix = $this->parseAutomaticPix(
                $iuguInvoice->automatic_pix,
                $invoice->automaticPix
            );
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

    /**
     * @inheritDoc
     */
    public function getCustomer(Customer $customer): Customer
    {
        try {
            $iuguCustomer = Iugu_Customer::fetch($customer->id);
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

        return $this->parseCustomer($iuguCustomer, $customer);
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

        if (!empty($customer->defaultCard) && !empty($customer->defaultCard->id)) {
            $iuguCustomerData['default_payment_method_id'] = $customer->defaultCard->id;
        }

        return $iuguCustomerData;
    }

    /**
     * @inheritDoc
     */
    public function setCustomerDefaultCard(Customer $customer, string $cardId): Customer
    {
        $customer->defaultCard = new CreditCard();
        $customer->defaultCard->id = $cardId;

        return $this->updateCustomer($customer);
    }

    /**
     * @inheritDoc
     */
    public function deleteCreditCard(CreditCard $creditCard): void
    {
        try {
            $iuguCreditCard = new Iugu_PaymentMethod([
                'id' => $creditCard->id,
                'customer_id' => $creditCard->customer->id
            ]);
            $iuguCreditCard->delete();
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
    }

    /**
     * @inheritDoc
     */
    public function getCreditCard(CreditCard $creditCard): CreditCard
    {
        try {
            $iuguCustomer = new Iugu_Customer(['id' => $creditCard->customer->id]);
            $iuguCreditCard = $iuguCustomer->payment_methods()->fetch($creditCard->id);
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
            throw new GatewayException('Error getting creditCard: ', $iuguCreditCard->errors);
        }

        return $this->parseIuguCard($iuguCreditCard, $creditCard);
    }

    /**
     * @param  mixed  $iuguCreditCard
     * @param  \Potelo\MultiPayment\Models\CreditCard|null  $creditCard
     * @return \Potelo\MultiPayment\Models\CreditCard
     */
    private function parseIuguCard(mixed $iuguCreditCard, ?CreditCard $creditCard = null): CreditCard
    {
        if (is_null($creditCard)) {
            $creditCard = new CreditCard();
        }

        $creditCard->id = $iuguCreditCard->id ?? null;
        $creditCard->brand = $iuguCreditCard->data->brand ?? null;
        $creditCard->year = $iuguCreditCard->data->year ?? null;
        $creditCard->month = $iuguCreditCard->data->month ?? null;
        $creditCard->description = $iuguCreditCard->description ?? null;

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
}
