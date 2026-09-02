<?php

namespace Potelo\MultiPayment\Gateways;

use Carbon\Carbon;
use Stripe\StripeClient;
use Stripe\Customer as StripeCustomer;
use Stripe\PaymentIntent as StripePaymentIntent;
use Stripe\PaymentMethod as StripePaymentMethod;
use Stripe\Exception\CardException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\PermissionException;
use Stripe\Exception\IdempotencyException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\UnexpectedValueException as StripeUnexpectedValueException;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\RateLimitException as StripeRateLimitException;
use Stripe\Exception\AuthenticationException as StripeAuthenticationException;
use Illuminate\Support\Facades\Config;
use Potelo\MultiPayment\Models\Pix;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Refund;
use Potelo\MultiPayment\Models\Address;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\InvoiceItem;
use Potelo\MultiPayment\Models\AutomaticPix;
use Potelo\MultiPayment\Models\AutomaticPixCharge;
use Potelo\MultiPayment\Models\AutomaticPixCancellation;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Enums\RefundStatus;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Enums\DeclineCode;
use Potelo\MultiPayment\Helpers\LogHelper;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Gateways\Concerns\ChecksCapabilities;
use Potelo\MultiPayment\Gateways\Stripe\DeclineCodes as StripeDeclineCodes;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\ChargingException;
use Potelo\MultiPayment\Exceptions\NotFoundException;
use Potelo\MultiPayment\Exceptions\RateLimitException;
use Potelo\MultiPayment\Exceptions\ValidationException;
use Potelo\MultiPayment\Exceptions\MultiPaymentException;
use Potelo\MultiPayment\Exceptions\AuthenticationException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;
use Potelo\MultiPayment\Exceptions\RefundNotSupportedException;
use Potelo\MultiPayment\Exceptions\UnsupportedOperationException;
use Potelo\MultiPayment\Exceptions\IdempotencyConflictException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

class StripeGateway implements GatewayContract
{
    use ChecksCapabilities;

    /**
     * Versão da API Stripe usada pelo pacote. Fixada no código (em vez de herdar o default da
     * conta no Dashboard) para que upgrades de versão sejam decisão de código, não de configuração.
     */
    public const STRIPE_API_VERSION = '2026-07-29.dahlia';

    /**
     * Código de país do telefone assumido quando o model não informa phoneCountryCode —
     * o Stripe armazena o telefone num campo único no formato +{país}{DDD}{número}.
     */
    private const DEFAULT_PHONE_COUNTRY_CODE = '55';

    /**
     * Expand padrão em toda leitura/criação de PaymentIntent: sem latest_charge expandido,
     * paidAmount/refundedAmount/fee ficam vazios no parse, e sem `refunds` do charge (que a
     * Stripe não inclui por padrão) a lista `Invoice::$refunds` seria reconstruída sem ids.
     */
    private const PAYMENT_INTENT_EXPAND = ['latest_charge.balance_transaction', 'latest_charge.refunds'];

    /**
     * Mapa de status do objeto Refund da Stripe para os genéricos do pacote. Lista oficial em
     * https://docs.stripe.com/api/refunds/object#refund_object-status; `requires_action`
     * (estorno aguardando ação do cliente) lê como pendente.
     */
    private const REFUND_STATUSES = [
        'pending' => RefundStatus::PENDING,
        'requires_action' => RefundStatus::PENDING,
        'succeeded' => RefundStatus::SUCCEEDED,
        'failed' => RefundStatus::FAILED,
        'canceled' => RefundStatus::CANCELED,
    ];

    /**
     * Status de dispute da Stripe que significam contestação em aberto: inquiry ou chargeback
     * formal aguardando resposta ou em análise. Lista oficial dos oito status em
     * https://docs.stripe.com/api/disputes/object#dispute_object-status; os demais são `won`,
     * `lost`, `warning_closed` e `prevented`.
     */
    private const OPEN_DISPUTE_STATUSES = [
        'warning_needs_response',
        'warning_under_review',
        'needs_response',
        'under_review',
    ];

    /** Dispute resolvida a favor do cliente: a Stripe devolveu o valor. */
    private const LOST_DISPUTE_STATUS = 'lost';

    /** Mapa de tipos de PaymentMethod da Stripe para os métodos genéricos do pacote. */
    private const PAYMENT_METHOD_TYPES = [
        'card' => PaymentMethod::CREDIT_CARD,
        'pix' => PaymentMethod::PIX,
        'boleto' => PaymentMethod::BANK_SLIP,
    ];

    private StripeClient $client;

    /**
     * Configura o client da Stripe.
     *
     * @param  StripeClient|null  $client
     */
    public function __construct(?StripeClient $client = null)
    {
        $this->client = $client ?? new StripeClient([
            'api_key' => Config::get('multi-payment.gateways.stripe.api_key'),
            'stripe_version' => self::STRIPE_API_VERSION,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function capabilities(): array
    {
        return [
            Capability::CREDIT_CARD,
            Capability::PIX,
            Capability::PARTIAL_REFUND_CARD,
            Capability::PARTIAL_REFUND_PIX,
            Capability::INVOICE_DUPLICATION,
            Capability::IDEMPOTENCY,
        ];
    }

    /**
     * @inheritDoc
     */
    public function notYetImplemented(): array
    {
        return [
            Capability::BANK_SLIP,
            Capability::AUTOMATIC_PIX,
            Capability::MULTIPLE_PAYMENT_METHODS,
            Capability::DELAYED_CAPTURE,
            Capability::IDEMPOTENCY_ALL_ENDPOINTS,
            Capability::SUBSCRIPTIONS,
            Capability::PLANS,
            Capability::PLAN_DEACTIVATION,
            Capability::CANCEL_AT_PERIOD_END,
            Capability::NATIVE_COUPONS,
            Capability::PLAN_CHANGE_PRORATION,
            Capability::MANAGES_RECURRENCE,
        ];
    }

    /**
     * @inheritDoc
     */
    public function createCustomer(Customer $customer): Customer
    {
        $stripeCustomerData = $this->customerToStripeData($customer);

        if (!empty($customer->taxDocument)) {
            $stripeCustomerData['tax_id_data'] = [[
                'type' => $this->taxDocumentType($customer->taxDocument),
                'value' => $customer->taxDocument,
            ]];
        }

        $stripeCustomer = $this->stripeRequest(function () use ($stripeCustomerData) {
            return $this->client->customers->create($this->withTaxIdsExpanded($stripeCustomerData));
        });

        return $this->parseCustomer($stripeCustomer, $customer);
    }

    /**
     * @inheritDoc
     * @throws ModelAttributeValidationException
     */
    public function updateCustomer(Customer $customer): Customer
    {
        if (empty($customer->id)) {
            throw ModelAttributeValidationException::required('Customer', 'id');
        }

        $stripeCustomerData = $this->customerToStripeData($customer);

        $stripeCustomer = $this->stripeRequest(function () use ($customer, $stripeCustomerData) {
            $stripeCustomer = $this->client->customers->update(
                $customer->id,
                $this->withTaxIdsExpanded($stripeCustomerData)
            );

            if ($this->syncCustomerTaxDocument($stripeCustomer, $customer->taxDocument)) {
                $stripeCustomer = $this->client->customers->retrieve(
                    $customer->id,
                    ['expand' => ['tax_ids']]
                );
            }

            return $stripeCustomer;
        });

        return $this->parseCustomer($stripeCustomer, $customer);
    }

    /**
     * @inheritDoc
     */
    public function getCustomer(Customer $customer): Customer
    {
        $stripeCustomer = $this->stripeRequest(function () use ($customer) {
            return $this->client->customers->retrieve($customer->id, ['expand' => ['tax_ids']]);
        });

        return $this->parseCustomer($stripeCustomer, $customer);
    }

    /**
     * @inheritDoc
     * @throws ModelAttributeValidationException
     */
    public function setCustomerDefaultCard(Customer $customer, string $cardId): Customer
    {
        $customer->defaultCard = new CreditCard();
        $customer->defaultCard->id = $cardId;

        return $this->updateCustomer($customer);
    }

    /**
     * Garante os expands exigidos pelo parse no payload sem descartar um expand vindo de
     * gatewayOptions. Sem eles a Stripe omite dados (tax ids, charge) e o parse/sync
     * corromperia silenciosamente.
     *
     * @param  array  $stripeData
     * @param  array  $expand
     * @return array
     */
    private function withExpand(array $stripeData, array $expand): array
    {
        $stripeData['expand'] = array_values(array_unique(array_merge(
            $stripeData['expand'] ?? [],
            $expand
        )));

        return $stripeData;
    }

    /**
     * Garante o expand de tax_ids no payload de customer.
     *
     * @param  array  $stripeCustomerData
     * @return array
     */
    private function withTaxIdsExpanded(array $stripeCustomerData): array
    {
        return $this->withExpand($stripeCustomerData, ['tax_ids']);
    }

    /**
     * Converte o model Customer do MultiPayment para o formato de dados da Stripe.
     *
     * O Stripe não tem campos nativos para bairro, país em texto livre e data de nascimento —
     * esses valores vão para metadata (mesma solução das custom_variables da Iugu) e voltam
     * de lá no parseCustomer.
     *
     * @param  \Potelo\MultiPayment\Models\Customer  $customer
     * @return array
     */
    private function customerToStripeData(Customer $customer): array
    {
        $stripeCustomerData = [];
        $metadata = [];

        if (!is_null($customer->name)) {
            $stripeCustomerData['name'] = $customer->name;
        }
        if (!is_null($customer->email)) {
            $stripeCustomerData['email'] = $customer->email;
        }
        if (!empty($customer->phoneArea) && !empty($customer->phoneNumber)) {
            $stripeCustomerData['phone'] = '+'
                . ($customer->phoneCountryCode ?? self::DEFAULT_PHONE_COUNTRY_CODE)
                . $customer->phoneArea
                . $customer->phoneNumber;
        }
        if (!empty($customer->birthDate)) {
            $metadata['birth_date'] = $customer->birthDate->format('Y-m-d');
        }

        if (!empty($customer->address)) {
            $address = $customer->address;
            $line1 = trim(($address->street ?? '') . ', ' . ($address->number ?: 'S/N'), ', ');
            $stripeCustomerData['address'] = array_filter([
                'line1' => $line1,
                'line2' => $address->complement,
                'city' => $address->city,
                'state' => $address->state,
                'postal_code' => $address->zipCode,
            ], static fn ($value) => !is_null($value) && $value !== '');

            // string vazia limpa a chave no metadata da Stripe (que faz merge por chave,
            // diferente do hash address, que é substituído inteiro) — mantém a semântica
            // de replace do endereço para os campos que vivem no metadata
            $metadata['district'] = $address->district ?? '';
            $metadata['country'] = $address->country ?? '';
        }

        if (!empty($metadata)) {
            $stripeCustomerData['metadata'] = $metadata;
        }

        if (!empty($customer->defaultCard) && !empty($customer->defaultCard->id)) {
            $stripeCustomerData['invoice_settings']['default_payment_method'] = $customer->defaultCard->id;
        }

        if (!empty($customer->gatewayOptions)) {
            foreach ($customer->gatewayOptions as $option => $value) {
                $stripeCustomerData[$option] = $value;
            }
        }

        return $stripeCustomerData;
    }

    /**
     * Converte o customer da Stripe em um Customer do MultiPayment.
     *
     * @param  \Stripe\Customer  $stripeCustomer
     * @param  \Potelo\MultiPayment\Models\Customer|null  $customer
     * @return \Potelo\MultiPayment\Models\Customer
     */
    private function parseCustomer(StripeCustomer $stripeCustomer, ?Customer $customer = null): Customer
    {
        $customer = $customer ?? new Customer();
        $metadata = !empty($stripeCustomer->metadata) ? $stripeCustomer->metadata->toArray() : [];

        $customer->id = $stripeCustomer->id;
        $customer->name = $stripeCustomer->name;
        $customer->email = $stripeCustomer->email;

        foreach ($stripeCustomer->tax_ids->data ?? [] as $taxId) {
            if (in_array($taxId->type, ['br_cpf', 'br_cnpj'], true)) {
                // a Stripe devolve o documento formatado (201.769.969-15) mesmo recebendo dígitos
                $customer->taxDocument = preg_replace('/\D/', '', $taxId->value);
                break;
            }
        }

        // desfaz a concatenação +{país}{DDD}{número} feita no customerToStripeData;
        // o reset evita reter dados velhos do model quando o telefone da Stripe está
        // ausente ou fora do formato gravado pelo pacote
        $customer->phoneCountryCode = null;
        $customer->phoneArea = null;
        $customer->phoneNumber = null;
        if (!empty($stripeCustomer->phone)
            && preg_match('/^\+(\d{2})(\d{2})(\d{8,9})$/', $stripeCustomer->phone, $phoneParts)) {
            $customer->phoneCountryCode = $phoneParts[1];
            $customer->phoneArea = $phoneParts[2];
            $customer->phoneNumber = $phoneParts[3];
        }

        $customer->birthDate = null;
        if (!empty($metadata['birth_date'])) {
            try {
                $customer->birthDate = Carbon::createFromFormat('Y-m-d', $metadata['birth_date']);
            } catch (\Exception $e) {
                // metadata pode ter sido editado fora do pacote; data ilegível vira null
            }
        }

        if (!empty($stripeCustomer->address)) {
            if (empty($customer->address)) {
                $customer->address = new Address();
            }
            $stripeAddress = $stripeCustomer->address;
            if (!empty($stripeAddress->line1)) {
                // desfaz a concatenação "rua, número" feita no customerToStripeData;
                // line1 sozinho no formato de número (endereço sem rua) volta para number
                $separatorPosition = strrpos($stripeAddress->line1, ', ');
                if ($separatorPosition !== false) {
                    $customer->address->street = substr($stripeAddress->line1, 0, $separatorPosition);
                    $customer->address->number = substr($stripeAddress->line1, $separatorPosition + 2);
                } elseif (preg_match('/^([0-9]+[a-zA-Z]*|S\/N)$/', $stripeAddress->line1)) {
                    $customer->address->number = $stripeAddress->line1;
                } else {
                    $customer->address->street = $stripeAddress->line1;
                }
            }
            $customer->address->complement = $stripeAddress->line2 ?? null;
            $customer->address->city = $stripeAddress->city ?? null;
            $customer->address->state = $stripeAddress->state ?? null;
            $customer->address->zipCode = $stripeAddress->postal_code ?? null;
            $customer->address->district = !empty($metadata['district']) ? $metadata['district'] : null;
            $customer->address->country = !empty($metadata['country']) ? $metadata['country'] : null;
        }

        if (!empty($stripeCustomer->invoice_settings?->default_payment_method)) {
            $customer->defaultCard = new CreditCard();
            $customer->defaultCard->id = $stripeCustomer->invoice_settings->default_payment_method;
        }

        $customer->gateway = 'stripe';
        $customer->createdAt = Carbon::createFromTimestamp($stripeCustomer->created);
        $customer->original = $stripeCustomer;

        return $customer;
    }

    /**
     * Garante que o tax id brasileiro do customer na Stripe reflita o taxDocument do model —
     * tax ids não são atualizáveis pelo update de customer, só criados/excluídos à parte.
     *
     * @param  \Stripe\Customer  $stripeCustomer  customer com `tax_ids` expandido
     * @param  string|null  $taxDocument
     * @return bool  true se algum tax id foi criado/excluído (o customer precisa de refetch)
     * @throws ApiErrorException
     */
    private function syncCustomerTaxDocument(StripeCustomer $stripeCustomer, ?string $taxDocument): bool
    {
        if (empty($taxDocument)) {
            return false;
        }

        $alreadyPresent = false;
        $staleTaxIds = [];
        foreach ($stripeCustomer->tax_ids->data ?? [] as $stripeTaxId) {
            if (!in_array($stripeTaxId->type, ['br_cpf', 'br_cnpj'], true)) {
                continue;
            }
            // comparação por dígitos: a Stripe devolve o documento formatado (201.769.969-15)
            if (preg_replace('/\D/', '', $stripeTaxId->value) === preg_replace('/\D/', '', $taxDocument)) {
                $alreadyPresent = true;
                continue;
            }
            $staleTaxIds[] = $stripeTaxId->id;
        }

        // cria o novo antes de excluir o antigo: se a criação falhar, o customer
        // não fica sem documento na Stripe
        if (!$alreadyPresent) {
            $this->client->customers->createTaxId($stripeCustomer->id, [
                'type' => $this->taxDocumentType($taxDocument),
                'value' => $taxDocument,
            ]);
        }
        foreach ($staleTaxIds as $staleTaxIdId) {
            $this->client->customers->deleteTaxId($stripeCustomer->id, $staleTaxIdId);
        }

        return !$alreadyPresent || !empty($staleTaxIds);
    }

    /**
     * Decide o tipo de tax id da Stripe pelo tamanho do documento (14 dígitos = CNPJ).
     *
     * @param  string  $taxDocument
     * @return string
     */
    private function taxDocumentType(string $taxDocument): string
    {
        return strlen(preg_replace('/\D/', '', $taxDocument)) === 14 ? 'br_cnpj' : 'br_cpf';
    }

    /**
     * Executa uma chamada à API da Stripe traduzindo as exceções para o contrato do pacote.
     *
     * @param  callable  $request
     * @return mixed
     * @throws GatewayException|GatewayNotAvailableException|AuthenticationException
     */
    private function stripeRequest(callable $request)
    {
        try {
            return $request();
        } catch (\Exception $e) {
            throw $this->translateStripeException($e);
        }
    }

    /**
     * Traduz uma exceção do stripe-php para a hierarquia do pacote, anexando a original como
     * `previous` e o status HTTP da resposta.
     *
     * Regras: 401 (`AuthenticationException` do SDK, inclusive chave não configurada) e 403
     * (`PermissionException`) viram `AuthenticationException`; falha de conexão
     * (`ApiConnectionException`) e 5xx viram `GatewayNotAvailableException`, inclusive o 5xx
     * com corpo não JSON, que o SDK lança como `UnexpectedValueException`; recusa de cartão
     * (`CardException`, `type` `card_error`, em qualquer operação, inclusive o attach do cartão)
     * vira `ChargingException` com `declineCode`; `rate_limit_error` vira `RateLimitException`;
     * `idempotency_error` vira `IdempotencyConflictException`; `invalid_request_error` vira
     * `NotFoundException` quando o `code` é `resource_missing` e `ValidationException` nos demais
     * casos; o restante vira `GatewayException`. Todas trazem `type`, `code`, `decline_code` e
     * `param` em `getErrors()` e o status em `httpStatus`. Exceção do próprio pacote passa
     * intacta.
     *
     * @param  \Throwable  $e
     * @return MultiPaymentException
     */
    private function translateStripeException(\Throwable $e): MultiPaymentException
    {
        if ($e instanceof MultiPaymentException) {
            return $e;
        }

        if ($e instanceof StripeAuthenticationException || $e instanceof PermissionException) {
            return AuthenticationException::invalidCredentials('stripe', $e->getMessage(), $e, $e->getHttpStatus());
        }

        if ($e instanceof ApiConnectionException) {
            return new GatewayNotAvailableException($e->getMessage(), $e, $e->getHttpStatus());
        }

        // corpo que não é JSON (página HTML de proxy num 5xx): o SDK lança esta classe com o
        // status HTTP em getCode(), ou sem código quando o JSON veio sem a chave `error`
        if ($e instanceof StripeUnexpectedValueException) {
            $httpStatus = $e->getCode() > 0 ? (int) $e->getCode() : null;
            if ($httpStatus >= 500) {
                return new GatewayNotAvailableException($e->getMessage(), $e, $httpStatus);
            }

            return new GatewayException($e->getMessage(), null, $e, $httpStatus);
        }

        if ($e instanceof ApiErrorException) {
            $httpStatus = $e->getHttpStatus();
            if ($httpStatus >= 500) {
                return new GatewayNotAvailableException($e->getMessage(), $e, $httpStatus);
            }

            $error = $e->getError();
            $errors = array_filter([
                'type' => $error?->type,
                'code' => $error?->code,
                'decline_code' => $error?->decline_code ?? null,
                'param' => $error?->param,
            ]);

            if ($e instanceof CardException) {
                return $this->cardDeclined($e);
            }

            if ($e instanceof StripeRateLimitException) {
                return RateLimitException::withRetryAfter(
                    $e->getMessage(),
                    $errors,
                    $e,
                    $httpStatus,
                    self::retryAfterFromHeaders($e->getHttpHeaders())
                );
            }

            if ($e instanceof IdempotencyException) {
                return new IdempotencyConflictException($e->getMessage(), $errors, $e, $httpStatus);
            }

            if ($e instanceof InvalidRequestException) {
                if (($error?->code ?? null) === 'resource_missing' || $httpStatus === 404) {
                    return new NotFoundException($e->getMessage(), $errors, $e, $httpStatus);
                }

                $field = is_string($error?->param) && $error->param !== '' ? $error->param : 'base';

                return ValidationException::withFieldErrors(
                    $e->getMessage(),
                    [$field => [$e->getMessage()]],
                    $errors,
                    $e,
                    $httpStatus
                );
            }

            return new GatewayException($e->getMessage(), $errors, $e, $httpStatus);
        }

        return new GatewayException($e->getMessage(), null, $e);
    }

    /**
     * Traduz uma recusa de cartão do stripe-php para `ChargingException`: o `decline_code` (ou,
     * na falta dele, o `code`) vira `DeclineCode`, o `advice_code` decide `retryable` quando
     * presente, e a resposta bruta vai em `chargeResponse`. Código fora da tabela vira
     * `DeclineCode::UNKNOWN`, com o original preservado em `gatewayCode` e registro em nível
     * `info`.
     *
     * @param  CardException  $e
     * @return ChargingException
     */
    private function cardDeclined(CardException $e): ChargingException
    {
        $error = $e->getError();
        $code = $error?->code ?? null;
        $stripeDeclineCode = $error?->decline_code ?? null;
        $gatewayCode = $stripeDeclineCode ?: $code;

        $declineCode = StripeDeclineCodes::toDeclineCode($gatewayCode);
        if ($declineCode === null) {
            $declineCode = DeclineCode::UNKNOWN;
            if (!empty($gatewayCode)) {
                LogHelper::info('Código de recusa da Stripe sem tradução para DeclineCode', ['gateway' => 'stripe', 'code' => $gatewayCode]);
            }
        }

        $exception = ChargingException::declined(
            'stripe',
            $declineCode,
            $gatewayCode,
            $e->getMessage(),
            $e,
            $e->getHttpStatus(),
            StripeDeclineCodes::retryableFromAdvice($error?->advice_code ?? null)
        );
        // array em vez do ErrorObject, para o formato ser o mesmo em qualquer operação
        $exception->chargeResponse = $error?->toArray();
        $exception->reason = self::chargeFailureReason($code, $stripeDeclineCode);

        return $exception;
    }

    /**
     * Lê o cabeçalho `Retry-After` da resposta, em segundos. Nulo quando ausente ou quando não
     * é um número inteiro (a forma em data HTTP não é interpretada). O SDK entrega os
     * cabeçalhos como `\Stripe\Util\CaseInsensitiveArray`, iterável; um array simples também
     * é aceito.
     *
     * @param  iterable|null  $headers
     * @return int|null
     */
    private static function retryAfterFromHeaders(?iterable $headers): ?int
    {
        foreach ($headers ?? [] as $name => $value) {
            if (strtolower((string) $name) !== 'retry-after') {
                continue;
            }
            $value = is_array($value) ? reset($value) : $value;

            return is_numeric($value) ? (int) $value : null;
        }

        return null;
    }

    /**
     * @inheritDoc
     * @throws ChargingException|ModelAttributeValidationException|UnsupportedOperationException
     */
    public function createInvoice(Invoice $invoice): Invoice
    {
        $this->assertSupportsAll($invoice->requiredCapabilities());

        $paymentMethod = $this->invoicePaymentMethod($invoice);

        return match ($paymentMethod) {
            PaymentMethod::CREDIT_CARD => $this->createCreditCardInvoice($invoice),
            PaymentMethod::PIX => $this->createPixInvoice($invoice),
            default => throw UnsupportedOperationException::forGateway($this, Capability::forPaymentMethod($paymentMethod)),
        };
    }

    /**
     * Resolve o único método de pagamento da fatura — no Stripe um PaymentIntent confirmado
     * server-side materializa a cobrança de um método só, então a fatura multi-método da
     * Iugu não tem equivalente aqui e este gateway é restrito a um método por fatura.
     *
     * @param  \Potelo\MultiPayment\Models\Invoice  $invoice
     * @return PaymentMethod
     * @throws ModelAttributeValidationException|UnsupportedOperationException
     */
    private function invoicePaymentMethod(Invoice $invoice): PaymentMethod
    {
        if (!empty($invoice->availablePaymentMethods)) {
            if (count($invoice->availablePaymentMethods) > 1) {
                throw UnsupportedOperationException::forGateway(
                    $this,
                    Capability::MULTIPLE_PAYMENT_METHODS,
                    'Informe exatamente um método em availablePaymentMethods.'
                );
            }

            // normaliza antes de ler: uma string apensada por `[]=` entra no array sem conversão
            $methods = PaymentMethod::normalizeSelectable($invoice->availablePaymentMethods, 'Invoice');

            return reset($methods);
        }

        if (!empty($invoice->creditCard)) {
            return PaymentMethod::CREDIT_CARD;
        }

        throw ModelAttributeValidationException::required('Invoice', 'availablePaymentMethods');
    }

    /**
     * Cria e confirma um PaymentIntent de cartão (síncrono: succeeded ou recusa na hora).
     *
     * @param  \Potelo\MultiPayment\Models\Invoice  $invoice
     * @return \Potelo\MultiPayment\Models\Invoice
     * @throws ChargingException|GatewayException|ModelAttributeValidationException
     */
    private function createCreditCardInvoice(Invoice $invoice): Invoice
    {
        if (empty($invoice->creditCard)) {
            throw ModelAttributeValidationException::required('Invoice', 'creditCard');
        }

        if (empty($invoice->creditCard->id)) {
            if (empty($invoice->creditCard->customer)) {
                $invoice->creditCard->customer = $invoice->customer;
            }
            // a Stripe valida o cartão já no attach; a recusa nesse ponto é ChargingException
            $invoice->creditCard = $this->createCreditCard($invoice->creditCard);
        }

        $stripePaymentIntentData = $this->invoiceToStripeData($invoice);
        $stripePaymentIntentData['payment_method_types'] = ['card'];
        $stripePaymentIntentData['payment_method'] = $invoice->creditCard->id;
        $stripePaymentIntentData['confirm'] = true;
        $stripePaymentIntentData['off_session'] = true;
        $stripePaymentIntentData = $this->mergeGatewayOptions($stripePaymentIntentData, $invoice);
        $requestOptions = $this->extractIdempotencyKey($stripePaymentIntentData);

        $stripePaymentIntent = $this->stripeRequest(function () use ($stripePaymentIntentData, $requestOptions) {
            return $this->client->paymentIntents->create(
                $this->withExpand($stripePaymentIntentData, self::PAYMENT_INTENT_EXPAND),
                $requestOptions
            );
        });

        return $this->parseInvoice($stripePaymentIntent, $invoice);
    }

    /**
     * Cria e confirma um PaymentIntent de pix 100% server-side. A fatura volta pendente
     * com o QR code em next_action; o pagamento é assíncrono (acompanhar via getInvoice).
     *
     * @param  \Potelo\MultiPayment\Models\Invoice  $invoice
     * @return \Potelo\MultiPayment\Models\Invoice
     * @throws GatewayException|ModelAttributeValidationException
     */
    private function createPixInvoice(Invoice $invoice): Invoice
    {
        // o pix exige CPF/CNPJ no billing_details em produção — falhar cedo evita um
        // erro obscuro da API (a sandbox não valida, produção sim)
        if (empty($invoice->customer) || empty($invoice->customer->taxDocument)) {
            throw ModelAttributeValidationException::required('Customer', 'taxDocument');
        }

        $stripePaymentIntentData = $this->invoiceToStripeData($invoice);
        $stripePaymentIntentData['payment_method_types'] = ['pix'];
        $stripePaymentIntentData['payment_method_data'] = [
            'type' => 'pix',
            'billing_details' => array_filter([
                'name' => $invoice->customer->name,
                'email' => $invoice->customer->email,
                'tax_id' => $invoice->customer->taxDocument,
            ]),
        ];
        $stripePaymentIntentData['confirm'] = true;
        if (!empty($invoice->expiresAt)) {
            // janela aceita pela Stripe: mais de 10 segundos e menos de 14 dias no futuro.
            // Na Iugu expires_at é due_date (date-only, "vence hoje" é válido) — falhar cedo
            // evita o erro obscuro de parâmetro da API para quem vem dessa semântica
            if ($invoice->expiresAt->lessThan(Carbon::now()->addSeconds(10))
                || $invoice->expiresAt->greaterThan(Carbon::now()->addDays(14))) {
                throw ModelAttributeValidationException::invalid(
                    'Invoice',
                    'expiresAt',
                    'expiresAt must be more than 10 seconds and less than 14 days in the future for pix invoices on the stripe gateway'
                );
            }
            $stripePaymentIntentData['payment_method_options']['pix']['expires_at'] = $invoice->expiresAt->getTimestamp();
        }
        $stripePaymentIntentData = $this->mergeGatewayOptions($stripePaymentIntentData, $invoice);
        $requestOptions = $this->extractIdempotencyKey($stripePaymentIntentData);

        $stripePaymentIntent = $this->stripeRequest(function () use ($stripePaymentIntentData, $requestOptions) {
            return $this->client->paymentIntents->create(
                $this->withExpand($stripePaymentIntentData, self::PAYMENT_INTENT_EXPAND),
                $requestOptions
            );
        });

        return $this->parseInvoice($stripePaymentIntent, $invoice);
    }

    /**
     * Recupera o CPF/CNPJ dos billing_details do PaymentMethod de um PaymentIntent pix —
     * o PaymentMethod pode já ter sido consumido (pix expirado), sobrando só a cópia
     * embutida em last_payment_error.
     *
     * @param  \Stripe\PaymentIntent  $stripePaymentIntent  com `payment_method` expandido
     * @return string|null
     */
    private function pixBillingTaxId(StripePaymentIntent $stripePaymentIntent): ?string
    {
        $stripePaymentMethod = $stripePaymentIntent->payment_method;
        if (is_object($stripePaymentMethod) && !empty($stripePaymentMethod->billing_details?->tax_id)) {
            return $stripePaymentMethod->billing_details->tax_id;
        }

        return $stripePaymentIntent->last_payment_error?->payment_method?->billing_details?->tax_id ?? null;
    }

    /**
     * Extrai a idempotency key das opções do consumidor para enviá-la como cabeçalho da
     * requisição (Idempotency-Key) — como parâmetro do payload a API a rejeitaria.
     *
     * @param  array  $stripeData  recebe o payload por referência e remove a chave dele
     * @return array
     */
    private function extractIdempotencyKey(array &$stripeData): array
    {
        if (!array_key_exists('idempotency_key', $stripeData)) {
            return [];
        }

        $requestOptions = ['idempotency_key' => $stripeData['idempotency_key']];
        unset($stripeData['idempotency_key']);

        return $requestOptions;
    }

    /**
     * Converte os campos comuns da fatura para o payload de PaymentIntent da Stripe.
     *
     * O PaymentIntent não tem line items: os items são serializados em metadata
     * (item_N_description/price/quantity) e reconstruídos no parseInvoice.
     *
     * @param  \Potelo\MultiPayment\Models\Invoice  $invoice
     * @return array
     */
    private function invoiceToStripeData(Invoice $invoice): array
    {
        $amount = $invoice->amount;
        if (empty($amount)) {
            $amount = array_sum(array_map(
                static fn (InvoiceItem $item) => $item->price * ($item->quantity ?? 1),
                $invoice->items ?? []
            ));
        }

        $stripePaymentIntentData = [
            'amount' => $amount,
            'currency' => 'brl', // o pacote inteiro é BRL implícito (valores em centavos)
        ];

        if (!empty($invoice->customer) && !empty($invoice->customer->id)) {
            $stripePaymentIntentData['customer'] = $invoice->customer->id;
        }

        foreach ($invoice->items ?? [] as $index => $item) {
            $stripePaymentIntentData['metadata']["item_{$index}_description"] = $item->description;
            $stripePaymentIntentData['metadata']["item_{$index}_price"] = $item->price;
            $stripePaymentIntentData['metadata']["item_{$index}_quantity"] = $item->quantity;
        }

        return $stripePaymentIntentData;
    }

    /**
     * Mescla as opções extras/override do consumidor por último, para que possam
     * sobrescrever qualquer chave montada pelo gateway (válvula de escape do pacote).
     *
     * @param  array  $stripeData
     * @param  \Potelo\MultiPayment\Models\Invoice  $invoice
     * @return array
     */
    private function mergeGatewayOptions(array $stripeData, Invoice $invoice): array
    {
        foreach ($invoice->gatewayOptions ?? [] as $option => $value) {
            $stripeData[$option] = $value;
        }

        return $stripeData;
    }

    /**
     * @inheritDoc
     */
    public function getInvoice(Invoice $invoice): Invoice
    {
        $stripePaymentIntent = $this->stripeRequest(function () use ($invoice) {
            return $this->client->paymentIntents->retrieve(
                $invoice->id,
                ['expand' => self::PAYMENT_INTENT_EXPAND]
            );
        });

        return $this->parseInvoice($stripePaymentIntent, $invoice);
    }

    /**
     * @inheritDoc
     *
     * As guardas de estorno precisam do método de pagamento, do status e, no estorno por valor,
     * do quanto ainda pode ser estornado. Um model que traz só o `id` custa um GET a mais para
     * ler a fatura antes do estorno; um model lido do gateway, pago e sem estorno anterior não
     * paga esse GET. No estorno por valor sobre uma fatura fora de `PAID` a leitura acontece
     * mesmo com o model preenchido, porque o restante estornável depende do acumulado que o
     * gateway guarda. A leitura prévia acontece numa cópia: o model do chamador só é alterado se
     * o estorno acontecer.
     *
     * @throws ModelAttributeValidationException|RefundNotSupportedException
     */
    public function refundInvoice(Invoice $invoice): Refund
    {
        if (empty($invoice->id)) {
            throw ModelAttributeValidationException::required('Invoice', 'id');
        }

        // guardado antes da leitura: parseInvoice() sobrescreve refundedAmount com o já estornado
        $requestedAmount = $invoice->refundedAmount ?: null;

        $current = $invoice;
        if (
            empty($invoice->paymentMethod)
            || empty($invoice->status)
            || (!is_null($requestedAmount) && (is_null($invoice->paidAmount) || $invoice->status !== InvoiceStatus::PAID))
        ) {
            $current = $this->getInvoice(clone $invoice);
        }

        $this->assertInvoiceIsRefundable($current, $requestedAmount, $current !== $invoice);

        // mesma semântica da Iugu: refundedAmount preenchido = estorno parcial; vazio = total
        $stripeRefundData = ['payment_intent' => $invoice->id];
        if (!is_null($requestedAmount)) {
            $stripeRefundData['amount'] = $requestedAmount;
        }
        $stripeRefundData = $this->mergeGatewayOptions($stripeRefundData, $invoice);
        $requestOptions = $this->extractIdempotencyKey($stripeRefundData);

        $stripeRefund = $this->stripeRequest(function () use ($stripeRefundData, $requestOptions) {
            return $this->client->refunds->create($stripeRefundData, $requestOptions);
        });

        // o refund não devolve o PaymentIntent: refetch para reparse com o charge atualizado
        $invoice = $this->getInvoice($invoice);

        $refund = $this->parseRefund($stripeRefund, $invoice->id);
        $refund->invoice = $invoice;

        return $refund;
    }

    /**
     * Lança antes da rede quando a Stripe certamente recusaria o estorno: boleto não tem estorno
     * pela API, fatura em `refunded` é terminal e o valor pedido não pode passar do que resta
     * (`amount_captured` menos `amount_refunded` do charge).
     *
     * @param  Invoice  $invoice
     * @param  int|null  $requestedAmount  valor pedido em centavos; nulo é estorno integral
     * @param  bool  $freshlyRead  verdadeiro quando `$invoice` acabou de ser lida do gateway e
     *                             `refundedAmount` é o acumulado; falso quando o model é do
     *                             chamador, em `PAID`, sem estorno anterior
     * @return void
     * @throws RefundNotSupportedException
     */
    private function assertInvoiceIsRefundable(Invoice $invoice, ?int $requestedAmount, bool $freshlyRead): void
    {
        if ($invoice->paymentMethod === PaymentMethod::BANK_SLIP) {
            throw RefundNotSupportedException::boletoNoRefund('stripe');
        }

        if ($invoice->status === InvoiceStatus::REFUNDED) {
            throw RefundNotSupportedException::alreadyRefunded('stripe', $invoice->paymentMethod?->value);
        }

        if (is_null($requestedAmount) || is_null($invoice->paidAmount)) {
            return;
        }

        $refundable = $invoice->paidAmount - ($freshlyRead ? ($invoice->refundedAmount ?? 0) : 0);
        if ($requestedAmount > $refundable) {
            throw RefundNotSupportedException::amountExceedsRefundable(
                'stripe',
                $invoice->paymentMethod?->value,
                $requestedAmount,
                $refundable
            );
        }
    }

    /**
     * @inheritDoc
     * @throws ChargingException|ModelAttributeValidationException
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

        // id = PaymentMethod salvo no customer; token = PaymentMethod criado client-side
        $paymentMethodId = !empty($invoice->creditCard->id)
            ? $invoice->creditCard->id
            : $invoice->creditCard->token;

        $stripePaymentIntent = $this->stripeRequest(function () use ($invoice, $paymentMethodId) {
            $paymentMethodId = $this->resolvePaymentMethodId($paymentMethodId);
            $stripePaymentMethod = $this->client->paymentMethods->retrieve($paymentMethodId);

            // o PaymentIntent pode ter sido criado para outro método (ex.: pix expirado):
            // é preciso aceitar cartão nos types — e, quando o PaymentMethod é salvo,
            // vincular o customer dele ao PaymentIntent antes do confirm; um PaymentIntent
            // que já pertence a outro customer não pode ser reatribuído silenciosamente
            $stripePaymentIntent = $this->client->paymentIntents->retrieve($invoice->id);
            $paymentIntentCustomer = is_object($stripePaymentIntent->customer)
                ? $stripePaymentIntent->customer->id
                : $stripePaymentIntent->customer;
            if (!empty($paymentIntentCustomer)
                && !empty($stripePaymentMethod->customer)
                && $stripePaymentMethod->customer !== $paymentIntentCustomer) {
                throw new GatewayException(
                    "Credit card [{$paymentMethodId}] does not belong to customer [{$paymentIntentCustomer}]"
                );
            }

            $updateParams = ['payment_method_types' => ['card']];
            if (empty($paymentIntentCustomer) && !empty($stripePaymentMethod->customer)) {
                $updateParams['customer'] = $stripePaymentMethod->customer;
            }
            $this->client->paymentIntents->update($invoice->id, $updateParams);

            return $this->client->paymentIntents->confirm($invoice->id, [
                'payment_method' => $paymentMethodId,
                'off_session' => true,
                'expand' => self::PAYMENT_INTENT_EXPAND,
            ]);
        });

        return $this->parseInvoice($stripePaymentIntent, $invoice);
    }

    /**
     * Converte o PaymentIntent da Stripe em uma Invoice do MultiPayment.
     *
     * @param  \Stripe\PaymentIntent  $stripePaymentIntent
     * @param  \Potelo\MultiPayment\Models\Invoice|null  $invoice
     * @return \Potelo\MultiPayment\Models\Invoice
     * @throws GatewayException|GatewayNotAvailableException
     */
    private function parseInvoice(StripePaymentIntent $stripePaymentIntent, ?Invoice $invoice = null): Invoice
    {
        $invoice = $invoice ?? new Invoice();

        // sem expand o latest_charge vem só como id; um charge failed (ex.: pix expirado)
        // não pode alimentar paidAmount/refundedAmount
        $stripeCharge = is_object($stripePaymentIntent->latest_charge) ? $stripePaymentIntent->latest_charge : null;
        $paidCharge = ($stripeCharge && $stripeCharge->status === 'succeeded') ? $stripeCharge : null;
        $disputeStatus = $paidCharge ? $this->disputeStatus($paidCharge) : null;

        $invoice->id = $stripePaymentIntent->id;
        $invoice->gateway = 'stripe';
        $invoice->status = self::stripeStatusToMultiPayment($stripePaymentIntent, $paidCharge, $disputeStatus);
        $invoice->amount = $stripePaymentIntent->amount;
        $invoice->paidAmount = $paidCharge?->amount_captured;
        $invoice->refundedAmount = $paidCharge?->amount_refunded;
        $invoice->refunds = $this->parseRefunds($paidCharge, $stripePaymentIntent->id);
        $invoice->paidAt = $paidCharge ? Carbon::createFromTimestamp($paidCharge->created) : null;
        $balanceTransaction = $paidCharge?->balance_transaction;
        // a balance transaction do cartão é assíncrona: pode vir nula logo após o confirm
        // e preenchida num getInvoice posterior
        $invoice->fee = is_object($balanceTransaction) ? $balanceTransaction->fee : null;
        $invoice->createdAt = Carbon::createFromTimestamp($stripePaymentIntent->created);
        $invoice->original = $stripePaymentIntent;

        if (!empty($stripePaymentIntent->customer)) {
            if (empty($invoice->customer)) {
                $invoice->customer = new Customer();
            }
            $invoice->customer->id = is_object($stripePaymentIntent->customer)
                ? $stripePaymentIntent->customer->id
                : $stripePaymentIntent->customer;
        }

        $detailsType = $stripeCharge?->payment_method_details?->type;
        if (!empty($detailsType)) {
            $invoice->paymentMethod = self::PAYMENT_METHOD_TYPES[$detailsType] ?? null;
        } elseif (count($stripePaymentIntent->payment_method_types ?? []) === 1) {
            $invoice->paymentMethod = self::PAYMENT_METHOD_TYPES[$stripePaymentIntent->payment_method_types[0]] ?? null;
        }
        if (!empty($invoice->paymentMethod)) {
            $invoice->availablePaymentMethods = [$invoice->paymentMethod];
        }

        // reconstrói os items serializados em metadata pelo invoiceToStripeData
        $metadata = !empty($stripePaymentIntent->metadata) ? $stripePaymentIntent->metadata->toArray() : [];
        $items = [];
        for ($index = 0; isset($metadata["item_{$index}_price"]); $index++) {
            $invoiceItem = new InvoiceItem();
            $invoiceItem->description = $metadata["item_{$index}_description"] ?? null;
            $invoiceItem->price = (int) $metadata["item_{$index}_price"];
            $invoiceItem->quantity = (int) ($metadata["item_{$index}_quantity"] ?? 1);
            $items[] = $invoiceItem;
        }
        if (!empty($items)) {
            $invoice->items = $items;
        }

        // `?->` não basta: em cobrança pix o payment_method_details existe e apenas não tem
        // a chave `card`, e o StripeObject loga "Undefined property" via Stripe::getLogger()
        // ao ler propriedade ausente. isset() passa pelo __isset e não polui o log.
        $paymentMethodDetails = $stripeCharge?->payment_method_details;
        $cardDetails = isset($paymentMethodDetails->card) ? $paymentMethodDetails->card : null;
        if (!empty($cardDetails)) {
            if (empty($invoice->creditCard)) {
                $invoice->creditCard = new CreditCard();
            }
            $invoice->creditCard->brand = $cardDetails->brand ?? null;
            $invoice->creditCard->lastDigits = $cardDetails->last4 ?? null;
            $invoice->creditCard->gateway = 'stripe';
        }

        $qrCode = $stripePaymentIntent->next_action?->pix_display_qr_code;
        if (!empty($qrCode)) {
            if (empty($invoice->pix)) {
                $invoice->pix = new Pix();
            }
            $invoice->pix->qrCodeText = $qrCode->data ?? null;
            $invoice->pix->qrCodeImageUrl = $qrCode->image_url_png ?? null;
            $invoice->url = $qrCode->hosted_instructions_url ?? null;
            $invoice->expiresAt = !empty($qrCode->expires_at)
                ? Carbon::createFromTimestamp($qrCode->expires_at)
                : $invoice->expiresAt;
        } else {
            // sem next_action de pix não há QR utilizável — limpa dados velhos de um model
            // reutilizado (ex.: fatura pix expirada re-cobrada com cartão)
            $invoice->pix = null;
            $invoice->url = null;
        }

        return $invoice;
    }

    /**
     * Monta a lista de estornos da fatura a partir de `refunds` do charge pago, um `Refund`
     * por estorno. Sem charge pago ou sem estorno a lista é vazia. Numa resposta em que a
     * lista não veio expandida mas `amount_refunded` é maior que zero, devolve um único
     * `Refund` sem id com o acumulado, para a lista nunca contradizer `refundedAmount`.
     *
     * @param  object|null  $paidCharge
     * @param  string  $invoiceId
     * @return Refund[]
     */
    private function parseRefunds(?object $paidCharge, string $invoiceId): array
    {
        if (!$paidCharge || empty($paidCharge->amount_refunded)) {
            return [];
        }

        // isset() passa pelo __isset e não loga "Undefined property" quando a chave falta
        $stripeRefunds = isset($paidCharge->refunds) ? $paidCharge->refunds : null;
        if (!is_object($stripeRefunds) || !isset($stripeRefunds->data)) {
            $refund = new Refund();
            $refund->invoiceId = $invoiceId;
            $refund->amount = $paidCharge->amount_refunded;
            $refund->status = RefundStatus::SUCCEEDED;
            $refund->gateway = 'stripe';

            return [$refund];
        }

        return array_map(
            fn (object $stripeRefund) => $this->parseRefund($stripeRefund, $invoiceId),
            $stripeRefunds->data
        );
    }

    /**
     * Converte o objeto Refund da Stripe em um `Refund` do MultiPayment. Status fora do mapa
     * vira `UNKNOWN` com aviso no log.
     *
     * @param  object  $stripeRefund
     * @param  string  $invoiceId
     * @return Refund
     */
    private function parseRefund(object $stripeRefund, string $invoiceId): Refund
    {
        $refund = new Refund();
        $refund->id = $stripeRefund->id;
        $refund->invoiceId = $invoiceId;
        $refund->amount = $stripeRefund->amount;
        $refund->status = self::REFUND_STATUSES[$stripeRefund->status ?? '']
            ?? RefundStatus::unknown((string) $stripeRefund->status, 'stripe');
        $refund->reason = $stripeRefund->reason ?? null;
        $refund->createdAt = !empty($stripeRefund->created)
            ? Carbon::createFromTimestamp($stripeRefund->created)
            : null;
        $refund->gateway = 'stripe';
        $refund->original = $stripeRefund;

        return $refund;
    }

    /**
     * Deriva o status genérico de contestação de um charge pago. Quando a flag `disputed` do
     * charge é verdadeira, lista as disputes dele em /v1/disputes (um GET a mais).
     * Contestação em aberto tem precedência sobre perdida; dispute ganha, encerrada sem virar
     * chargeback (`warning_closed`) ou prevenida não altera o status da fatura.
     *
     * @param  object  $stripeCharge
     * @return InvoiceStatus|null  `DISPUTED`, `CHARGEBACK` ou null
     * @throws GatewayException|GatewayNotAvailableException
     */
    private function disputeStatus(object $stripeCharge): ?InvoiceStatus
    {
        // isset() passa pelo __isset e não loga "Undefined property" quando a chave falta
        if (!isset($stripeCharge->disputed) || !$stripeCharge->disputed) {
            return null;
        }

        $disputes = $this->stripeRequest(function () use ($stripeCharge) {
            // uma página basta: um charge não acumula dezenas de disputes
            return $this->client->disputes->all(['charge' => $stripeCharge->id, 'limit' => 100]);
        });

        $statuses = array_map(static fn ($dispute) => $dispute->status, $disputes->data ?? []);
        if (!empty(array_intersect($statuses, self::OPEN_DISPUTE_STATUSES))) {
            return InvoiceStatus::DISPUTED;
        }
        if (in_array(self::LOST_DISPUTE_STATUS, $statuses, true)) {
            return InvoiceStatus::CHARGEBACK;
        }

        return null;
    }

    /**
     * Deriva o status genérico do par PaymentIntent + charge. Estorno não muda o status do
     * PaymentIntent na Stripe, então ele vem do charge. Contestação, quando existe, vence os
     * dois: uma fatura disputada não lê como paga nem como estornada. `requires_capture` lê
     * como `AUTHORIZED` e `processing` como `PROCESSING`; status de PaymentIntent fora do
     * mapa devolve `UNKNOWN` com aviso no log.
     *
     * @param  \Stripe\PaymentIntent  $stripePaymentIntent
     * @param  object|null  $paidCharge
     * @param  InvoiceStatus|null  $disputeStatus  resultado de disputeStatus() para o charge pago
     * @return InvoiceStatus
     */
    private static function stripeStatusToMultiPayment(StripePaymentIntent $stripePaymentIntent, ?object $paidCharge, ?InvoiceStatus $disputeStatus = null): InvoiceStatus
    {
        if ($disputeStatus !== null) {
            return $disputeStatus;
        }

        if ($paidCharge && $paidCharge->amount_refunded > 0) {
            return $paidCharge->refunded
                ? InvoiceStatus::REFUNDED
                : InvoiceStatus::PARTIALLY_REFUNDED;
        }

        return match ($stripePaymentIntent->status) {
            'succeeded' => InvoiceStatus::PAID,
            'canceled' => InvoiceStatus::CANCELED,
            'requires_capture' => InvoiceStatus::AUTHORIZED,
            'processing' => InvoiceStatus::PROCESSING,
            // pix expirado volta a requires_payment_method (não vira canceled) e segue
            // re-cobrável; reportar PENDING preserva essa funcionalidade
            'requires_action', 'requires_confirmation', 'requires_payment_method' => InvoiceStatus::PENDING,
            default => InvoiceStatus::unknown((string) $stripePaymentIntent->status, 'stripe'),
        };
    }

    /**
     * Normaliza o código de recusa da Stripe para o valor de `CardDeclinedException::$reason`, que
     * mantém o vocabulário das versões anteriores; `declineCode` é a normalização atual.
     *
     * @param  string|null  $code
     * @param  string|null  $declineCode
     * @return string|null
     */
    private static function chargeFailureReason(?string $code, ?string $declineCode): ?string
    {
        $normalized = [
            'card_not_supported' => 'brand_not_supported',
            'authentication_required' => 'authentication_required',
            'expired_card' => 'expired_card',
            'insufficient_funds' => 'insufficient_funds',
            'incorrect_cvc' => 'incorrect_cvc',
        ];

        return $normalized[$declineCode ?? '']
            ?? $normalized[$code ?? '']
            ?? $code;
    }

    /**
     * @inheritDoc
     *
     * O PaymentIntent não tem duplicate nativo: a fatura nova é criada com os dados da
     * original (customer, items, valor) e a nova expiração, e só então a original é
     * cancelada — se a criação falhar, o consumidor não fica sem fatura nenhuma.
     * Restrito a faturas pix pendentes (cartão é síncrono, não há o que duplicar).
     *
     * @throws ModelAttributeValidationException|UnsupportedOperationException
     */
    public function duplicateInvoice(Invoice $invoice, Carbon $expiresAt, array $gatewayOptions = []): Invoice
    {
        if (empty($invoice->id)) {
            throw ModelAttributeValidationException::required('Invoice', 'id');
        }

        $original = $this->stripeRequest(function () use ($invoice) {
            return $this->client->paymentIntents->retrieve(
                $invoice->id,
                ['expand' => array_merge(self::PAYMENT_INTENT_EXPAND, ['payment_method'])]
            );
        });
        $parsedOriginal = $this->parseInvoice($original, new Invoice());

        if ($parsedOriginal->status !== InvoiceStatus::PENDING) {
            throw UnsupportedOperationException::restricted(
                (string) $this,
                Capability::INVOICE_DUPLICATION,
                "No Stripe só uma fatura Pix pendente pode ser duplicada; a fatura [{$invoice->id}] está [{$parsedOriginal->status->value}]."
            );
        }
        if ($parsedOriginal->paymentMethod !== PaymentMethod::PIX) {
            throw UnsupportedOperationException::restricted(
                (string) $this,
                Capability::INVOICE_DUPLICATION,
                "No Stripe só uma fatura Pix pendente pode ser duplicada; a fatura [{$invoice->id}] não é Pix."
            );
        }
        if (empty($parsedOriginal->customer) || empty($parsedOriginal->customer->id)) {
            throw new GatewayException(
                "Invoice [{$invoice->id}] has no customer on the stripe gateway and cannot be duplicated"
            );
        }

        // o pix precisa dos billing_details (nome, e-mail, CPF/CNPJ), que vivem no customer
        $customer = new Customer();
        $customer->id = $parsedOriginal->customer->id;
        $customer = $this->getCustomer($customer);
        if (empty($customer->taxDocument)) {
            // a original pode ter sido criada com o CPF/CNPJ só no model (billing_details
            // do PaymentMethod), sem tax id no customer da Stripe — recupera de lá
            $customer->taxDocument = $this->pixBillingTaxId($original);
        }

        $duplicated = new Invoice();
        $duplicated->customer = $customer;
        $duplicated->amount = $parsedOriginal->amount;
        $duplicated->items = $parsedOriginal->items;
        $duplicated->availablePaymentMethods = [PaymentMethod::PIX];
        $duplicated->expiresAt = $expiresAt;
        // preserva o metadata da original (inclusive chaves custom do consumidor);
        // as gatewayOptions do chamador vêm por último e podem sobrescrever
        $originalMetadata = !empty($original->metadata) ? $original->metadata->toArray() : [];
        if (!empty($originalMetadata)) {
            $duplicated->gatewayOptions['metadata'] = $originalMetadata;
        }
        if (!empty($gatewayOptions)) {
            $duplicated->gatewayOptions = array_merge($duplicated->gatewayOptions, $gatewayOptions);
        }
        $duplicated = $this->createPixInvoice($duplicated);

        try {
            $this->cancelInvoice($parsedOriginal);
        } catch (MultiPaymentException $e) {
            // a duplicata já existe — propaga o id dela para o consumidor não a perder
            throw new GatewayException(
                "Invoice duplicated as [{$duplicated->id}] but the original [{$invoice->id}] could not be canceled: "
                . $e->getMessage(),
                null,
                $e,
                $e->httpStatus
            );
        }

        return $duplicated;
    }

    /**
     * @inheritDoc
     * @throws ModelAttributeValidationException
     */
    public function cancelInvoice(Invoice $invoice): Invoice
    {
        if (empty($invoice->id)) {
            throw ModelAttributeValidationException::required('Invoice', 'id');
        }

        // só estados não-terminais são canceláveis; PaymentIntent pago recusa o cancel
        // com payment_intent_unexpected_state (vira GatewayException)
        $stripePaymentIntent = $this->stripeRequest(function () use ($invoice) {
            return $this->client->paymentIntents->cancel(
                $invoice->id,
                ['expand' => self::PAYMENT_INTENT_EXPAND]
            );
        });

        return $this->parseInvoice($stripePaymentIntent, $invoice);
    }

    /**
     * @inheritDoc
     * @throws ModelAttributeValidationException|UnsupportedOperationException
     */
    public function createCreditCard(CreditCard $creditCard): CreditCard
    {
        if (empty($creditCard->customer) || empty($creditCard->customer->id)) {
            throw ModelAttributeValidationException::required('CreditCard', 'customer');
        }
        if (empty($creditCard->token)) {
            // token-only: dados crus exigiriam a liberação de raw card data APIs pela
            // Stripe e escopo PCI SAQ D; o cartão é tokenizado client-side
            $this->assertSupports(
                Capability::RAW_CARD_DATA,
                'Tokenize o cartão no navegador com Stripe.js e informe o id resultante em CreditCard::$token.'
            );
        }

        $stripePaymentMethod = $this->stripeRequest(function () use ($creditCard) {
            $paymentMethodId = $this->resolvePaymentMethodId($creditCard->token);

            $stripePaymentMethod = $this->client->paymentMethods->attach(
                $paymentMethodId,
                ['customer' => $creditCard->customer->id]
            );

            // o PaymentMethod da Stripe não tem campo de descrição — vai para metadata
            if (!empty($creditCard->description)) {
                $stripePaymentMethod = $this->client->paymentMethods->update(
                    $stripePaymentMethod->id,
                    ['metadata' => ['description' => $creditCard->description]]
                );
            }

            if (!empty($creditCard->default)) {
                $this->client->customers->update($creditCard->customer->id, [
                    'invoice_settings' => ['default_payment_method' => $stripePaymentMethod->id],
                ]);
            }

            return $stripePaymentMethod;
        });

        return $this->parseStripeCard($stripePaymentMethod, $creditCard);
    }

    /**
     * @inheritDoc
     */
    public function getCreditCard(CreditCard $creditCard): CreditCard
    {
        $stripePaymentMethod = $this->stripeRequest(function () use ($creditCard) {
            $stripePaymentMethod = $this->client->paymentMethods->retrieve($creditCard->id);
            $this->assertCardBelongsToCustomer($stripePaymentMethod, $creditCard);

            return $stripePaymentMethod;
        });

        return $this->parseStripeCard($stripePaymentMethod, $creditCard);
    }

    /**
     * @inheritDoc
     */
    public function deleteCreditCard(CreditCard $creditCard): void
    {
        $this->stripeRequest(function () use ($creditCard) {
            $stripePaymentMethod = $this->client->paymentMethods->retrieve($creditCard->id);
            $this->assertCardBelongsToCustomer($stripePaymentMethod, $creditCard);

            return $this->client->paymentMethods->detach($creditCard->id);
        });
    }

    /**
     * Resolve o token do consumidor para um id de PaymentMethod: tokens legados da Stripe
     * (tok_...) não são utilizáveis diretamente e viram PaymentMethod antes.
     *
     * @param  string  $token
     * @return string
     * @throws ApiErrorException
     */
    private function resolvePaymentMethodId(string $token): string
    {
        if (str_starts_with($token, 'tok_')) {
            return $this->client->paymentMethods->create([
                'type' => 'card',
                'card' => ['token' => $token],
            ])->id;
        }

        return $token;
    }

    /**
     * Espelha a semântica da Iugu (cartão buscado/excluído via customer): quando o model
     * informa o customer, a posse do PaymentMethod é validada antes da operação.
     *
     * @param  \Stripe\PaymentMethod  $stripePaymentMethod
     * @param  \Potelo\MultiPayment\Models\CreditCard  $creditCard
     * @return void
     * @throws GatewayException
     */
    private function assertCardBelongsToCustomer(StripePaymentMethod $stripePaymentMethod, CreditCard $creditCard): void
    {
        $customerId = $creditCard->customer->id ?? null;
        if (!empty($customerId) && $stripePaymentMethod->customer !== $customerId) {
            throw new GatewayException(
                "Credit card [{$stripePaymentMethod->id}] does not belong to customer [{$customerId}]"
            );
        }
    }

    /**
     * Converte o PaymentMethod de cartão da Stripe em um CreditCard do MultiPayment.
     *
     * @param  \Stripe\PaymentMethod  $stripePaymentMethod
     * @param  \Potelo\MultiPayment\Models\CreditCard|null  $creditCard
     * @return \Potelo\MultiPayment\Models\CreditCard
     */
    private function parseStripeCard(StripePaymentMethod $stripePaymentMethod, ?CreditCard $creditCard = null): CreditCard
    {
        if (is_null($creditCard)) {
            $creditCard = new CreditCard();
        }

        $card = isset($stripePaymentMethod->card) ? $stripePaymentMethod->card : null;
        $creditCard->id = $stripePaymentMethod->id;
        $creditCard->brand = $card->brand ?? null;
        $creditCard->lastDigits = $card->last4 ?? null;
        $creditCard->month = isset($card->exp_month)
            ? str_pad((string) $card->exp_month, 2, '0', STR_PAD_LEFT)
            : null;
        $creditCard->year = isset($card->exp_year) ? (string) $card->exp_year : null;

        $metadata = !empty($stripePaymentMethod->metadata) ? $stripePaymentMethod->metadata->toArray() : [];
        $creditCard->description = $metadata['description'] ?? $creditCard->description;

        if (!empty($stripePaymentMethod->billing_details?->name)) {
            $names = explode(' ', $stripePaymentMethod->billing_details->name);
            $creditCard->firstName = $names[array_key_first($names)] ?? null;
            $creditCard->lastName = $names[array_key_last($names)] ?? null;
        }

        $creditCard->gateway = 'stripe';
        $creditCard->original = $stripePaymentMethod;
        $creditCard->createdAt = Carbon::createFromTimestamp($stripePaymentMethod->created);

        return $creditCard;
    }

    /**
     * @inheritDoc
     */
    public function rescheduleAutomaticPixPayment(Invoice $invoice): Invoice
    {
        throw UnsupportedOperationException::forGateway($this, Capability::AUTOMATIC_PIX);
    }

    /**
     * @inheritDoc
     */
    public function cancelAutomaticPixScheduledPayment(AutomaticPixCharge $charge): AutomaticPixCancellation
    {
        throw UnsupportedOperationException::forGateway($this, Capability::AUTOMATIC_PIX);
    }

    /**
     * @inheritDoc
     */
    public function cancelAutomaticPixRecurrence(AutomaticPix $automaticPix): AutomaticPixCancellation
    {
        throw UnsupportedOperationException::forGateway($this, Capability::AUTOMATIC_PIX);
    }

    /**
     * @inheritDoc
     */
    public function getAutomaticPixCancellation(AutomaticPixCancellation $cancellation): AutomaticPixCancellation
    {
        throw UnsupportedOperationException::forGateway($this, Capability::AUTOMATIC_PIX);
    }

    /**
     * @inheritDoc
     */
    public function listAutomaticPixCancellations(AutomaticPix $automaticPix, int $page = 1, int $limit = 100): array
    {
        throw UnsupportedOperationException::forGateway($this, Capability::AUTOMATIC_PIX);
    }

    /**
     * @inheritDoc
     */
    public function __toString()
    {
        return 'stripe';
    }
}
