<?php

namespace Potelo\MultiPayment\Gateways;

use Carbon\Carbon;
use Stripe\StripeClient;
use Stripe\Invoice as StripeInvoice;
use Stripe\Customer as StripeCustomer;
use Stripe\PaymentIntent as StripePaymentIntent;
use Stripe\PaymentMethod as StripePaymentMethod;
use Stripe\SetupIntent as StripeSetupIntent;
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
use Potelo\MultiPayment\Models\Model;
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
use Potelo\MultiPayment\Enums\InvoiceOriginType;
use Potelo\MultiPayment\Enums\RefundStatus;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Enums\DeclineCode;
use Potelo\MultiPayment\Helpers\LogHelper;
use Potelo\MultiPayment\Capabilities\CapabilityRestriction;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Gateways\Concerns\ChecksCapabilities;
use Potelo\MultiPayment\Gateways\Concerns\ResolvesIdempotencyKey;
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
    use ResolvesIdempotencyKey;

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
     * Expand na leitura de um Invoice da Stripe: `payments` só vem expandido, e é nele que está
     * o PaymentIntent da fatura (`payments.data[].payment.payment_intent`). O `expand` da Stripe
     * para em quatro níveis, então o `latest_charge` desse PaymentIntent é lido num GET à parte.
     */
    private const INVOICE_EXPAND = ['payments.data.payment.payment_intent'];

    /**
     * Expand de toda leitura ou criação de SetupIntent: o PaymentMethod expandido traz a
     * bandeira e os últimos dígitos do cartão e diz se a Stripe já o anexou ao cliente.
     */
    private const SETUP_INTENT_EXPAND = ['payment_method'];

    /** Chaves de `metadata` do SetupIntent que guardam o que aplicar ao cartão quando o setup conclui. */
    private const SETUP_METADATA_DESCRIPTION = 'description';
    private const SETUP_METADATA_DEFAULT = 'set_as_default';

    /** Prefixo do id de um objeto Invoice da Stripe; o de PaymentIntent é `pi_`. */
    private const STRIPE_INVOICE_ID_PREFIX = 'in_';

    /** Tipo de InvoicePayment cujo pagamento é um PaymentIntent. */
    private const INVOICE_PAYMENT_TYPE_PAYMENT_INTENT = 'payment_intent';

    /** Tipo de InvoicePayment registrado quando a fatura é paga fora da Stripe (`paid_out_of_band`). */
    private const INVOICE_PAYMENT_TYPE_PAYMENT_RECORD = 'payment_record';

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
            Capability::CARD_SETUP_AUTHENTICATION,
            Capability::PARTIAL_REFUND_CARD,
            Capability::PARTIAL_REFUND_PIX,
            Capability::INVOICE_DUPLICATION,
            Capability::INVOICE_CANCELLATION,
            Capability::IDEMPOTENCY,
            Capability::IDEMPOTENCY_ALL_ENDPOINTS,
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
     *
     * `CREDIT_CARD`: a conta brasileira só aceita crédito Visa e Mastercard, e outra bandeira
     * é recusada na cobrança com `DeclineCode::BRAND_NOT_SUPPORTED`. `INVOICE_DUPLICATION`:
     * só fatura Pix pendente de venda avulsa. `INVOICE_CANCELLATION`: a fatura de assinatura
     * (`in_`) só é anulada depois de finalizada pela Stripe; rascunho é recusado.
     */
    public function restrictions(): array
    {
        return [
            Capability::CREDIT_CARD->value => new CapabilityRestriction(
                description: 'Na conta brasileira só cartão de crédito Visa e Mastercard; outra bandeira é'
                    . ' recusada na cobrança com DeclineCode::BRAND_NOT_SUPPORTED.',
                allowedBrands: ['visa', 'mastercard'],
            ),
            Capability::INVOICE_DUPLICATION->value => new CapabilityRestriction(
                description: 'Só fatura Pix pendente de venda avulsa (PaymentIntent); cartão, outro estado'
                    . ' ou fatura de assinatura são recusados.',
                allowedPaymentMethods: [PaymentMethod::PIX],
            ),
            Capability::INVOICE_CANCELLATION->value => new CapabilityRestriction(
                description: 'A fatura de assinatura (objeto Invoice) só é anulada depois de finalizada'
                    . ' pela Stripe; rascunho é recusado.',
            ),
        ];
    }

    /**
     * @inheritDoc
     *
     * A chave de idempotência vai no cabeçalho `Idempotency-Key` da criação.
     */
    public function createCustomer(Customer $customer, ?string $idempotencyKey = null): Customer
    {
        $idempotencyKey = $this->idempotencyKeyFor($idempotencyKey, $customer);
        $stripeCustomerData = $this->customerToStripeData($customer);

        if (!empty($customer->taxDocument)) {
            $stripeCustomerData['tax_id_data'] = [[
                'type' => $this->taxDocumentType($customer->taxDocument),
                'value' => $customer->taxDocument,
            ]];
        }

        $stripeCustomer = $this->stripeRequest(function () use ($stripeCustomerData, $idempotencyKey) {
            return $this->client->customers->create(
                $this->withTaxIdsExpanded($stripeCustomerData),
                self::stripeOptions($idempotencyKey)
            );
        });

        return $this->parseCustomer($stripeCustomer, $customer);
    }

    /**
     * @inheritDoc
     *
     * A chave de idempotência vai no cabeçalho `Idempotency-Key` do update; a criação de um tax
     * id novo, quando o documento mudou, usa a chave derivada `{chave}:tax_id`.
     *
     * @throws ModelAttributeValidationException
     */
    public function updateCustomer(Customer $customer, ?string $idempotencyKey = null): Customer
    {
        if (empty($customer->id)) {
            throw ModelAttributeValidationException::required('Customer', 'id');
        }
        $idempotencyKey = $this->idempotencyKeyFor($idempotencyKey, $customer);

        $stripeCustomerData = $this->customerToStripeData($customer);

        $stripeCustomer = $this->stripeRequest(function () use ($customer, $stripeCustomerData, $idempotencyKey) {
            $stripeCustomer = $this->client->customers->update(
                $customer->id,
                $this->withTaxIdsExpanded($stripeCustomerData),
                self::stripeOptions($idempotencyKey)
            );

            if ($this->syncCustomerTaxDocument(
                $stripeCustomer,
                $customer->taxDocument,
                self::derivedIdempotencyKey($idempotencyKey, 'tax_id')
            )) {
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
    public function setCustomerDefaultCard(Customer $customer, string $cardId, ?string $idempotencyKey = null): Customer
    {
        $customer->defaultCard = new CreditCard();
        $customer->defaultCard->id = $cardId;

        return $this->updateCustomer($customer, $idempotencyKey);
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

        foreach (self::withoutIdempotencyKey($customer->gatewayOptions) as $option => $value) {
            $stripeCustomerData[$option] = $value;
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
     * @param  string|null  $idempotencyKey  chave da criação do tax id novo
     * @return bool  true se algum tax id foi criado/excluído (o customer precisa de refetch)
     * @throws ApiErrorException
     */
    private function syncCustomerTaxDocument(
        StripeCustomer $stripeCustomer,
        ?string $taxDocument,
        ?string $idempotencyKey = null
    ): bool {
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
            ], self::stripeOptions($idempotencyKey));
        }
        foreach ($staleTaxIds as $staleTaxIdId) {
            try {
                $this->client->customers->deleteTaxId($stripeCustomer->id, $staleTaxIdId);
            } catch (InvalidRequestException $e) {
                // já excluído por uma tentativa anterior (a Stripe repete o update com o
                // tax_ids antigo quando a chave é a mesma): o objetivo já foi atingido
                if (($e->getError()?->code ?? null) !== 'resource_missing') {
                    throw $e;
                }
            }
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
     * Traduz uma recusa de cartão do stripe-php para `ChargingException` a partir do erro da
     * exceção, com ela em `previous` e o status HTTP da resposta.
     *
     * @param  CardException  $e
     * @return ChargingException
     */
    private function cardDeclined(CardException $e): ChargingException
    {
        return $this->declinedFromStripeError($e->getError(), $e->getMessage(), $e, $e->getHttpStatus());
    }

    /**
     * Monta a `ChargingException` de uma recusa de cartão a partir do objeto de erro da Stripe
     * (o de uma `CardException` ou o `last_setup_error` de um SetupIntent): o `decline_code`
     * (ou, na falta dele, o `code`) vira `DeclineCode`, o `advice_code` decide `retryable`
     * quando presente, e o erro bruto vai em `chargeResponse`. Código fora da tabela vira
     * `DeclineCode::UNKNOWN`, com o original preservado em `gatewayCode` e registro em nível
     * `info`.
     *
     * @param  object|null  $error  `\Stripe\ErrorObject` ou `\Stripe\StripeObject` com `code`, `decline_code` e `advice_code`
     * @param  string  $message
     * @param  \Throwable|null  $previous
     * @param  int|null  $httpStatus
     * @return ChargingException
     */
    private function declinedFromStripeError(?object $error, string $message, ?\Throwable $previous, ?int $httpStatus): ChargingException
    {
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
            $message,
            $previous,
            $httpStatus,
            StripeDeclineCodes::retryableFromAdvice($error?->advice_code ?? null)
        );
        // array em vez do ErrorObject, para o formato ser o mesmo em qualquer operação
        $exception->chargeResponse = is_object($error) && method_exists($error, 'toArray') ? $error->toArray() : $error;
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
     *
     * A chave de idempotência vai no cabeçalho `Idempotency-Key` da criação do PaymentIntent;
     * o cartão salvo antes da cobrança usa a chave derivada `{chave}:card`.
     *
     * @throws ChargingException|ModelAttributeValidationException|UnsupportedOperationException
     */
    public function createInvoice(Invoice $invoice, ?string $idempotencyKey = null): Invoice
    {
        $this->assertSupportsAll($invoice->requiredCapabilities());
        $idempotencyKey = $this->idempotencyKeyFor($idempotencyKey, $invoice);

        $paymentMethod = $this->invoicePaymentMethod($invoice);

        return match ($paymentMethod) {
            PaymentMethod::CREDIT_CARD => $this->createCreditCardInvoice($invoice, $idempotencyKey),
            PaymentMethod::PIX => $this->createPixInvoice($invoice, $idempotencyKey),
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
        $methods = $invoice->resolvedPaymentMethods();

        if (count($methods) > 1) {
            throw UnsupportedOperationException::forGateway(
                $this,
                Capability::MULTIPLE_PAYMENT_METHODS,
                'Informe exatamente um método em availablePaymentMethods.'
            );
        }

        if (!empty($methods)) {
            return reset($methods);
        }

        throw ModelAttributeValidationException::required('Invoice', 'paymentMethod or availablePaymentMethods');
    }

    /**
     * Cria e confirma um PaymentIntent de cartão (síncrono: succeeded ou recusa na hora). Um
     * cartão informado por token é salvo antes por `createCreditCard()`; se o emissor exigir
     * autenticação do pagador para salvá-lo, a cobrança fora de sessão não tem como atendê-la
     * e a fatura não é criada: `ChargingException` com `DeclineCode::AUTHENTICATION_REQUIRED`
     * e o SetupIntent em `chargeResponse`.
     *
     * @param  \Potelo\MultiPayment\Models\Invoice  $invoice
     * @param  string|null  $idempotencyKey
     * @return \Potelo\MultiPayment\Models\Invoice
     * @throws ChargingException|GatewayException|ModelAttributeValidationException
     */
    private function createCreditCardInvoice(Invoice $invoice, ?string $idempotencyKey): Invoice
    {
        if (empty($invoice->creditCard)) {
            throw ModelAttributeValidationException::required('Invoice', 'creditCard');
        }

        if (empty($invoice->creditCard->id)) {
            if (empty($invoice->creditCard->customer)) {
                $invoice->creditCard->customer = $invoice->customer;
            }
            // a Stripe valida o cartão já no setup; a recusa nesse ponto é ChargingException
            $invoice->creditCard = $this->createCreditCard(
                $invoice->creditCard,
                self::derivedIdempotencyKey($idempotencyKey, 'card')
            );
            if ($invoice->creditCard->requiresAction) {
                $exception = ChargingException::declined(
                    'stripe',
                    DeclineCode::AUTHENTICATION_REQUIRED,
                    'authentication_required',
                    'O emissor exige autenticação do pagador para este cartão; salve-o com createCreditCard(),'
                    . ' conclua a autenticação com confirmCreditCardSetup() e cobre pelo id do cartão salvo.'
                );
                $exception->chargeResponse = $invoice->creditCard->original?->toArray();

                throw $exception;
            }
        }

        $stripePaymentIntentData = $this->invoiceToStripeData($invoice);
        $stripePaymentIntentData['payment_method_types'] = ['card'];
        $stripePaymentIntentData['payment_method'] = $invoice->creditCard->id;
        $stripePaymentIntentData['confirm'] = true;
        $stripePaymentIntentData['off_session'] = true;
        $stripePaymentIntentData = $this->mergeGatewayOptions($stripePaymentIntentData, $invoice);

        $stripePaymentIntent = $this->stripeRequest(function () use ($stripePaymentIntentData, $idempotencyKey) {
            return $this->client->paymentIntents->create(
                $this->withExpand($stripePaymentIntentData, self::PAYMENT_INTENT_EXPAND),
                self::stripeOptions($idempotencyKey)
            );
        });

        return $this->parseInvoice($stripePaymentIntent, $invoice);
    }

    /**
     * Cria e confirma um PaymentIntent de pix 100% server-side. A fatura volta pendente
     * com o QR code em next_action; o pagamento é assíncrono (acompanhar via getInvoice).
     *
     * @param  \Potelo\MultiPayment\Models\Invoice  $invoice
     * @param  string|null  $idempotencyKey
     * @return \Potelo\MultiPayment\Models\Invoice
     * @throws GatewayException|ModelAttributeValidationException
     */
    private function createPixInvoice(Invoice $invoice, ?string $idempotencyKey): Invoice
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
        $pixExpiresAt = $this->pixExpiresAt($invoice);
        if (!empty($pixExpiresAt)) {
            $stripePaymentIntentData['payment_method_options']['pix']['expires_at'] = $pixExpiresAt->getTimestamp();
        }
        $stripePaymentIntentData = $this->mergeGatewayOptions($stripePaymentIntentData, $invoice);

        $stripePaymentIntent = $this->stripeRequest(function () use ($stripePaymentIntentData, $idempotencyKey) {
            return $this->client->paymentIntents->create(
                $this->withExpand($stripePaymentIntentData, self::PAYMENT_INTENT_EXPAND),
                self::stripeOptions($idempotencyKey)
            );
        });

        return $this->parseInvoice($stripePaymentIntent, $invoice);
    }

    /**
     * Instante em que o QR Code do Pix expira: `pixExpiresAt` ou, na falta dele, o fim do dia
     * de `dueDate` (o vencimento vale o dia inteiro, como na Iugu). Nulo quando a fatura não
     * informa nenhum dos dois (a Stripe usa o padrão de 4 horas). A janela aceita pela Stripe
     * (mais de 10 segundos e menos de 14 dias no futuro) é validada antes da requisição.
     *
     * @param  \Potelo\MultiPayment\Models\Invoice  $invoice
     * @return Carbon|null
     * @throws ModelAttributeValidationException
     */
    private function pixExpiresAt(Invoice $invoice): ?Carbon
    {
        $attribute = !empty($invoice->pixExpiresAt) ? 'pixExpiresAt' : 'dueDate';
        $expiresAt = $invoice->pixExpiresAt ?? $invoice->dueDate?->copy()->endOfDay();
        if (empty($expiresAt)) {
            return null;
        }

        if ($expiresAt->lessThan(Carbon::now()->addSeconds(10))
            || $expiresAt->greaterThan(Carbon::now()->addDays(14))) {
            throw ModelAttributeValidationException::invalid(
                'Invoice',
                $attribute,
                "{$attribute} must be more than 10 seconds and less than 14 days in the future for pix invoices on the stripe gateway"
                . ($attribute === 'dueDate' ? ' (the QR Code expires at the end of the due date)' : '')
            );
        }

        return $expiresAt;
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
     * Opções de requisição do stripe-php com a chave de idempotência, que o SDK envia no
     * cabeçalho `Idempotency-Key`. Sem chave, nenhuma opção.
     *
     * @param  string|null  $idempotencyKey
     * @return array
     */
    private static function stripeOptions(?string $idempotencyKey): array
    {
        return is_null($idempotencyKey) ? [] : ['idempotency_key' => $idempotencyKey];
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
     * sobrescrever qualquer chave montada pelo gateway (válvula de escape do pacote). A chave
     * antiga de idempotência fica de fora do payload.
     *
     * @param  array  $stripeData
     * @param  Model  $model
     * @return array
     */
    private function mergeGatewayOptions(array $stripeData, Model $model): array
    {
        foreach (self::withoutIdempotencyKey($model->gatewayOptions) as $option => $value) {
            $stripeData[$option] = $value;
        }

        return $stripeData;
    }

    /**
     * @inheritDoc
     *
     * Aceita o id de um PaymentIntent (`pi_`, cobrança avulsa) ou de um Invoice da Stripe
     * (`in_`, fatura de assinatura): o prefixo decide qual objeto é lido e
     * `Invoice::$originType` diz qual voltou. A leitura de um Invoice custa um GET a mais
     * quando o PaymentIntent dele já teve tentativa de pagamento, porque o charge fica fora do
     * limite de níveis do `expand`.
     */
    public function getInvoice(Invoice $invoice): Invoice
    {
        if (self::isStripeInvoiceId($invoice->id)) {
            return $this->parseInvoice($this->retrieveStripeInvoice($invoice->id), $invoice);
        }

        $stripePaymentIntent = $this->stripeRequest(function () use ($invoice) {
            return $this->client->paymentIntents->retrieve(
                $invoice->id,
                ['expand' => self::PAYMENT_INTENT_EXPAND]
            );
        });

        return $this->parseInvoice($stripePaymentIntent, $invoice);
    }

    /**
     * Diz se o id é de um objeto Invoice da Stripe (prefixo `in_`).
     *
     * @param  string|null  $id
     * @return bool
     */
    private static function isStripeInvoiceId(?string $id): bool
    {
        return is_string($id) && str_starts_with($id, self::STRIPE_INVOICE_ID_PREFIX);
    }

    /**
     * Lê um objeto Invoice da Stripe com os pagamentos e o PaymentIntent deles expandidos.
     *
     * @param  string  $id
     * @return StripeInvoice
     * @throws GatewayException|GatewayNotAvailableException
     */
    private function retrieveStripeInvoice(string $id): StripeInvoice
    {
        return $this->stripeRequest(function () use ($id) {
            return $this->client->invoices->retrieve($id, ['expand' => self::INVOICE_EXPAND]);
        });
    }

    /**
     * Lança `UnsupportedOperationException` (`SUBSCRIPTIONS`, `not_implemented`) quando a fatura
     * é um Invoice da Stripe (id `in_`): a lib ainda não implementa escrita sobre a fatura de
     * assinatura.
     *
     * @param  Invoice  $invoice
     * @param  string  $operation  nome da operação, para a mensagem
     * @return void
     * @throws UnsupportedOperationException
     */
    private function assertPaymentIntentOrigin(Invoice $invoice, string $operation): void
    {
        if (self::isStripeInvoiceId($invoice->id)) {
            throw UnsupportedOperationException::forGateway(
                $this,
                Capability::SUBSCRIPTIONS,
                "A operação {$operation} sobre a fatura de assinatura [{$invoice->id}] (objeto Invoice da Stripe)"
                . ' ainda não está implementada nesta lib; a leitura por getInvoice() está disponível.'
            );
        }
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
     * A chave de idempotência vai no cabeçalho `Idempotency-Key` da criação do refund; as
     * leituras do PaymentIntent não a usam. Com chave, uma guarda de estado (fatura já
     * estornada, valor acima do restante) não recusa de imediato: a mesma chave pode ser a de um
     * estorno já feito, então o driver envia o refund e deixa a Stripe repetir a resposta
     * original; se ela recusar, a recusa da guarda é a que sobe.
     *
     * O valor vem de `$amount`; sem ele, do caminho antigo de escrever `refundedAmount` antes
     * de estornar (`Invoice::resolveRefundAmount()`); sem os dois, estorna o restante. No
     * estorno por valor a fatura é relida quando o model não traz o valor pago ou quando o
     * acumulado estornado que ele traz não é confiável (escrito pelo caminho antigo, ou ausente
     * numa fatura fora de `PAID`).
     *
     * @throws ModelAttributeValidationException|RefundNotSupportedException
     */
    public function refundInvoice(Invoice $invoice, ?int $amount = null, ?string $idempotencyKey = null): Refund
    {
        if (empty($invoice->id)) {
            throw ModelAttributeValidationException::required('Invoice', 'id');
        }
        $this->assertPaymentIntentOrigin($invoice, 'refundInvoice');
        $requestedAmount = $invoice->resolveRefundAmount($amount);
        $idempotencyKey = $this->idempotencyKeyFor($idempotencyKey, $invoice);

        $current = $invoice;
        if (
            empty($invoice->paymentMethod)
            || empty($invoice->status)
            || (!is_null($requestedAmount) && !self::hasReliableRefundableAmount($invoice))
        ) {
            $current = $this->getInvoice(clone $invoice);
        }

        $stripeRefundData = ['payment_intent' => $invoice->id];
        if (!is_null($requestedAmount)) {
            $stripeRefundData['amount'] = $requestedAmount;
        }
        $stripeRefundData = $this->mergeGatewayOptions($stripeRefundData, $invoice);

        try {
            $this->assertInvoiceIsRefundable($current, $requestedAmount);
            $stripeRefund = $this->stripeRequest(function () use ($stripeRefundData, $idempotencyKey) {
                return $this->client->refunds->create($stripeRefundData, self::stripeOptions($idempotencyKey));
            });
        } catch (RefundNotSupportedException $refusal) {
            $stripeRefund = $this->replayStripeRefund($refusal, $stripeRefundData, $idempotencyKey);
        }

        // o refund não devolve o PaymentIntent: refetch para reparse com o charge atualizado
        $invoice = $this->getInvoice($invoice);

        $refund = $this->parseRefund($stripeRefund, $invoice->id);
        $refund->invoice = $invoice;

        return $refund;
    }

    /**
     * Tenta repetir, pela chave de idempotência, um estorno que a guarda de estado recusou:
     * a Stripe devolve o refund original quando a chave é a dele. Sem chave, ou quando a recusa
     * não é de estado (boleto), ou quando a Stripe também recusa, sobe a recusa da guarda.
     *
     * @param  RefundNotSupportedException  $refusal
     * @param  array  $stripeRefundData
     * @param  string|null  $idempotencyKey
     * @return object  o objeto Refund devolvido pela Stripe
     * @throws RefundNotSupportedException
     */
    private function replayStripeRefund(RefundNotSupportedException $refusal, array $stripeRefundData, ?string $idempotencyKey): object
    {
        $stateReasons = [
            RefundNotSupportedException::REASON_ALREADY_REFUNDED,
            RefundNotSupportedException::REASON_AMOUNT_EXCEEDS_REFUNDABLE,
        ];
        if (is_null($idempotencyKey) || !in_array($refusal->reason, $stateReasons, true)) {
            throw $refusal;
        }

        try {
            return $this->client->refunds->create($stripeRefundData, self::stripeOptions($idempotencyKey));
        } catch (\Exception $e) {
            throw $refusal;
        }
    }

    /**
     * @inheritDoc
     *
     * Na Stripe o restante é `amount_captured` menos `amount_refunded` do charge
     * (`Invoice::$paidAmount` menos `Invoice::$refundedAmount`, porque o valor pago vem bruto); a
     * fatura é lida quando o model não traz o valor pago ou o acumulado estornado confiável. A
     * fatura de assinatura (`in_`) é recusada como em `refundInvoice()`, antes da leitura.
     *
     * @throws UnsupportedOperationException
     */
    public function refundableAmount(Invoice $invoice): int
    {
        if (empty($invoice->id)) {
            throw ModelAttributeValidationException::required('Invoice', 'id');
        }
        $this->assertPaymentIntentOrigin($invoice, 'refundableAmount');

        $current = self::hasReliableRefundableAmount($invoice) ? $invoice : $this->getInvoice(clone $invoice);

        return self::stripeRefundableAmount($current);
    }

    /**
     * Restante estornável de uma fatura já lida: `paidAmount` menos `refundedAmount`; zero
     * quando nada foi pago.
     *
     * @param  Invoice  $invoice
     * @return int
     */
    private static function stripeRefundableAmount(Invoice $invoice): int
    {
        return max(0, (int) ($invoice->paidAmount ?? 0) - (int) ($invoice->refundedAmount ?? 0));
    }

    /**
     * Diz se o model traz o que basta para calcular o restante estornável sem reler a fatura:
     * valor pago presente e `refundedAmount` sendo o acumulado do gateway, ou seja, sem valor
     * pedido pelo caminho antigo e preenchido sempre que a fatura está fora de `PAID`.
     *
     * @param  Invoice  $invoice
     * @return bool
     */
    private static function hasReliableRefundableAmount(Invoice $invoice): bool
    {
        if (is_null($invoice->paidAmount) || !is_null($invoice->requestedRefundAmount())) {
            return false;
        }

        return !is_null($invoice->refundedAmount) || $invoice->status === InvoiceStatus::PAID;
    }

    /**
     * Lança antes da rede quando a Stripe certamente recusaria o estorno: boleto não tem estorno
     * pela API, fatura em `refunded` é terminal e o valor pedido não pode passar do que resta
     * (`amount_captured` menos `amount_refunded` do charge).
     *
     * @param  Invoice  $invoice  fatura com `paidAmount` e `refundedAmount` confiáveis
     * @param  int|null  $requestedAmount  valor pedido em centavos; nulo é estorno do restante
     * @return void
     * @throws RefundNotSupportedException
     */
    private function assertInvoiceIsRefundable(Invoice $invoice, ?int $requestedAmount): void
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

        $refundable = self::stripeRefundableAmount($invoice);
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
     *
     * A chave de idempotência vai no cabeçalho `Idempotency-Key` do confirm; o update que
     * antecede o confirm usa `{chave}:update` e a conversão de token legado em PaymentMethod
     * usa `{chave}:payment_method`.
     *
     * @throws ChargingException|ModelAttributeValidationException
     */
    public function chargeInvoiceWithCreditCard(Invoice $invoice, ?string $idempotencyKey = null): Invoice
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
        $this->assertPaymentIntentOrigin($invoice, 'chargeInvoiceWithCreditCard');
        $idempotencyKey = $this->idempotencyKeyFor($idempotencyKey, $invoice);

        // id = PaymentMethod salvo no customer; token = PaymentMethod criado client-side
        $paymentMethodId = !empty($invoice->creditCard->id)
            ? $invoice->creditCard->id
            : $invoice->creditCard->token;

        $stripePaymentIntent = $this->stripeRequest(function () use ($invoice, $paymentMethodId, $idempotencyKey) {
            $paymentMethodId = $this->resolvePaymentMethodId(
                $paymentMethodId,
                self::derivedIdempotencyKey($idempotencyKey, 'payment_method')
            );
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
                throw UnsupportedOperationException::restricted(
                    (string) $this,
                    Capability::CREDIT_CARD,
                    "Credit card [{$paymentMethodId}] does not belong to customer [{$paymentIntentCustomer}];"
                    . ' the Stripe PaymentMethod is bound to one customer and cannot pay another customer\'s invoice.'
                );
            }

            // o customer vai sempre que o cartão tem um (igual ao do PaymentIntent, ou o
            // PaymentIntent ainda sem cliente): o payload fica o mesmo num retry com a mesma chave
            $updateParams = ['payment_method_types' => ['card']];
            if (!empty($stripePaymentMethod->customer)) {
                $updateParams['customer'] = $stripePaymentMethod->customer;
            }
            $this->client->paymentIntents->update(
                $invoice->id,
                $updateParams,
                self::stripeOptions(self::derivedIdempotencyKey($idempotencyKey, 'update'))
            );

            return $this->client->paymentIntents->confirm($invoice->id, [
                'payment_method' => $paymentMethodId,
                'off_session' => true,
                'expand' => self::PAYMENT_INTENT_EXPAND,
            ], self::stripeOptions($idempotencyKey));
        });

        return $this->parseInvoice($stripePaymentIntent, $invoice);
    }

    /**
     * Converte o objeto de origem da Stripe em uma Invoice do MultiPayment: PaymentIntent
     * (cobrança avulsa, origem `PAYMENT_INTENT`) ou Invoice da Stripe (fatura de assinatura,
     * origem `INVOICE`). `Invoice::$originType` diz qual foi e `original` guarda o objeto.
     *
     * @param  \Stripe\PaymentIntent|\Stripe\Invoice  $stripeObject
     * @param  \Potelo\MultiPayment\Models\Invoice|null  $invoice
     * @return \Potelo\MultiPayment\Models\Invoice
     * @throws GatewayException|GatewayNotAvailableException
     */
    private function parseInvoice(StripePaymentIntent|StripeInvoice $stripeObject, ?Invoice $invoice = null): Invoice
    {
        return $stripeObject instanceof StripeInvoice
            ? $this->parseFromStripeInvoice($stripeObject, $invoice)
            : $this->parseFromPaymentIntent($stripeObject, $invoice);
    }

    /**
     * Converte o PaymentIntent da Stripe em uma Invoice do MultiPayment (origem
     * `PAYMENT_INTENT`). Os line items vêm de `metadata`, onde `invoiceToStripeData()` os
     * serializou; `url` é a página de instruções do Pix, nula em cartão.
     *
     * @param  \Stripe\PaymentIntent  $stripePaymentIntent
     * @param  \Potelo\MultiPayment\Models\Invoice|null  $invoice
     * @return \Potelo\MultiPayment\Models\Invoice
     * @throws GatewayException|GatewayNotAvailableException
     */
    private function parseFromPaymentIntent(StripePaymentIntent $stripePaymentIntent, ?Invoice $invoice = null): Invoice
    {
        $invoice = $invoice ?? new Invoice();

        // sem expand o latest_charge vem só como id; um charge failed (ex.: pix expirado)
        // não pode alimentar paidAmount/refundedAmount
        $stripeCharge = is_object($stripePaymentIntent->latest_charge) ? $stripePaymentIntent->latest_charge : null;
        $paidCharge = ($stripeCharge && $stripeCharge->status === 'succeeded') ? $stripeCharge : null;

        $invoice->id = $stripePaymentIntent->id;
        $invoice->gateway = 'stripe';
        $invoice->originType = InvoiceOriginType::PAYMENT_INTENT;
        $invoice->status = $this->deriveStatus(null, $stripePaymentIntent, $paidCharge);
        $invoice->amount = $stripePaymentIntent->amount;
        $invoice->paidAmount = $paidCharge?->amount_captured;
        $invoice->setRefundedAmountFromGateway($paidCharge?->amount_refunded);
        $invoice->refunds = $this->parseRefunds($paidCharge, $stripePaymentIntent->id);
        $invoice->paidAt = $paidCharge ? Carbon::createFromTimestamp($paidCharge->created) : null;
        $invoice->fee = self::chargeFee($paidCharge);
        $invoice->createdAt = Carbon::createFromTimestamp($stripePaymentIntent->created);
        $invoice->original = $stripePaymentIntent;

        $this->parseInvoiceCustomer($invoice, $stripePaymentIntent->customer ?? null);
        $this->parsePaymentMethod($invoice, $stripePaymentIntent, $stripeCharge);

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

        $this->parseCardDetails($invoice, $stripeCharge);

        // sem next_action de pix não há QR utilizável: a página de instruções some junto,
        // inclusive num model reutilizado (ex.: fatura pix expirada re-cobrada com cartão)
        $invoice->url = $this->parsePixDisplay($invoice, $stripePaymentIntent);

        return $invoice;
    }

    /**
     * Converte um objeto Invoice da Stripe (fatura de assinatura) em uma Invoice do
     * MultiPayment (origem `INVOICE`). O PaymentIntent da fatura vem de `payments`
     * (`invoicePaymentIntent()`), e o charge dele alimenta valores, estornos e contestação como
     * na cobrança avulsa. Os line items vêm de `lines.data` (a primeira página, de até dez
     * itens); `url` é a página hospedada da fatura; `dueDate` é o `due_date`, quando a fatura
     * tem um, e `pixExpiresAt` a expiração do QR Code do Pix.
     *
     * @param  \Stripe\Invoice  $stripeInvoice
     * @param  \Potelo\MultiPayment\Models\Invoice|null  $invoice
     * @return \Potelo\MultiPayment\Models\Invoice
     * @throws GatewayException|GatewayNotAvailableException
     */
    private function parseFromStripeInvoice(StripeInvoice $stripeInvoice, ?Invoice $invoice = null): Invoice
    {
        $invoice = $invoice ?? new Invoice();

        $stripePaymentIntent = $this->invoicePaymentIntent($stripeInvoice);
        $stripeCharge = is_object($stripePaymentIntent?->latest_charge) ? $stripePaymentIntent->latest_charge : null;
        $paidCharge = ($stripeCharge && $stripeCharge->status === 'succeeded') ? $stripeCharge : null;

        $invoice->id = $stripeInvoice->id;
        $invoice->gateway = 'stripe';
        $invoice->originType = InvoiceOriginType::INVOICE;
        $invoice->status = $this->deriveStatus($stripeInvoice, $stripePaymentIntent, $paidCharge);
        $invoice->amount = $stripeInvoice->total;
        // sem charge pago, o valor recebido é o que o Invoice registra (pagamento externo,
        // fatura parcialmente paga ou quitada sem cobrança); fatura em aberto sem nada pago fica nula
        $amountPaid = $stripeInvoice->amount_paid ?? 0;
        $invoice->paidAmount = $paidCharge?->amount_captured
            ?? ($stripeInvoice->status === 'paid' || $amountPaid > 0 ? $amountPaid : null);
        $invoice->setRefundedAmountFromGateway($paidCharge?->amount_refunded);
        $invoice->refunds = $this->parseRefunds($paidCharge, $stripeInvoice->id);
        $paidAt = $stripeInvoice->status_transitions->paid_at ?? $paidCharge?->created;
        $invoice->paidAt = !empty($paidAt) ? Carbon::createFromTimestamp($paidAt) : null;
        $invoice->fee = self::chargeFee($paidCharge);
        $invoice->createdAt = Carbon::createFromTimestamp($stripeInvoice->created);
        $invoice->dueDate = !empty($stripeInvoice->due_date)
            ? Carbon::createFromTimestamp($stripeInvoice->due_date)
            : null;
        $invoice->url = $stripeInvoice->hosted_invoice_url ?? null;
        $invoice->original = $stripeInvoice;

        $this->parseInvoiceCustomer($invoice, $stripeInvoice->customer ?? null);
        // paga fora da Stripe, o método oferecido pelo PaymentIntent cancelado não diz como
        // o dinheiro entrou
        if ($invoice->status !== InvoiceStatus::EXTERNALLY_PAID) {
            $this->parsePaymentMethod($invoice, $stripePaymentIntent, $stripeCharge);
        }

        $items = [];
        foreach ($stripeInvoice->lines->data ?? [] as $line) {
            $invoiceItem = new InvoiceItem();
            $invoiceItem->description = $line->description ?? null;
            $invoiceItem->quantity = isset($line->quantity) ? (int) $line->quantity : 1;
            $invoiceItem->price = self::lineItemUnitAmount($line, $invoiceItem->quantity);
            $items[] = $invoiceItem;
        }
        if (!empty($items)) {
            $invoice->items = $items;
        }

        $this->parseCardDetails($invoice, $stripeCharge);
        $this->parsePixDisplay($invoice, $stripePaymentIntent);

        return $invoice;
    }

    /**
     * Escolhe o PaymentIntent da fatura entre os pagamentos do Invoice (`payments.data`): o
     * que está pago, senão o pagamento padrão (`is_default`), senão o primeiro do tipo
     * PaymentIntent. Devolve nulo quando a fatura não tem PaymentIntent (rascunho, quitada sem
     * cobrança, paga fora da Stripe). Um PaymentIntent que já tem charge, ou que veio só como
     * id, é relido com o expand de PaymentIntent, para o charge trazer valores, estornos e a
     * flag de contestação.
     *
     * @param  \Stripe\Invoice  $stripeInvoice
     * @return \Stripe\PaymentIntent|null
     * @throws GatewayException|GatewayNotAvailableException
     */
    private function invoicePaymentIntent(StripeInvoice $stripeInvoice): ?StripePaymentIntent
    {
        $candidates = [];
        foreach ($stripeInvoice->payments->data ?? [] as $invoicePayment) {
            if (($invoicePayment->payment->type ?? null) === self::INVOICE_PAYMENT_TYPE_PAYMENT_INTENT) {
                $candidates[] = $invoicePayment;
            }
        }

        $chosen = null;
        foreach ($candidates as $candidate) {
            if (($candidate->status ?? null) === 'paid') {
                $chosen = $candidate;
                break;
            }
        }
        if (is_null($chosen)) {
            foreach ($candidates as $candidate) {
                if (!empty($candidate->is_default)) {
                    $chosen = $candidate;
                    break;
                }
            }
        }
        $chosen = $chosen ?? ($candidates[0] ?? null);
        if (is_null($chosen)) {
            return null;
        }

        $stripePaymentIntent = $chosen->payment->payment_intent ?? null;
        if ($stripePaymentIntent instanceof StripePaymentIntent && empty($stripePaymentIntent->latest_charge)) {
            return $stripePaymentIntent;
        }

        $id = is_object($stripePaymentIntent) ? ($stripePaymentIntent->id ?? '') : (string) $stripePaymentIntent;
        if ($id === '') {
            return null;
        }

        return $this->stripeRequest(function () use ($id) {
            return $this->client->paymentIntents->retrieve($id, ['expand' => self::PAYMENT_INTENT_EXPAND]);
        });
    }

    /**
     * Tipo do primeiro pagamento do Invoice com status `paid` (`payment_intent`, `charge` ou
     * `payment_record`), ou nulo quando nenhum está pago.
     *
     * @param  \Stripe\Invoice  $stripeInvoice
     * @return string|null
     */
    private static function paidInvoicePaymentType(StripeInvoice $stripeInvoice): ?string
    {
        foreach ($stripeInvoice->payments->data ?? [] as $invoicePayment) {
            if (($invoicePayment->status ?? null) === 'paid') {
                return $invoicePayment->payment->type ?? null;
            }
        }

        return null;
    }

    /**
     * Valor unitário de um line item do Invoice: `pricing.unit_amount_decimal` quando existe,
     * senão o `amount` da linha dividido pela quantidade.
     *
     * @param  object  $line
     * @param  int  $quantity
     * @return int|null
     */
    private static function lineItemUnitAmount(object $line, int $quantity): ?int
    {
        $unitAmount = $line->pricing->unit_amount_decimal ?? null;
        if (is_numeric($unitAmount)) {
            return (int) round((float) $unitAmount);
        }

        if (!isset($line->amount)) {
            return null;
        }

        return $quantity > 1 ? intdiv((int) $line->amount, $quantity) : (int) $line->amount;
    }

    /**
     * Taxa da Stripe no charge pago, lida da balance transaction expandida. A balance
     * transaction do cartão é assíncrona: pode vir nula logo após o confirm e preenchida numa
     * leitura posterior.
     *
     * @param  object|null  $paidCharge
     * @return int|null
     */
    private static function chargeFee(?object $paidCharge): ?int
    {
        $balanceTransaction = $paidCharge?->balance_transaction;

        return is_object($balanceTransaction) ? $balanceTransaction->fee : null;
    }

    /**
     * Preenche o id do cliente da fatura a partir do `customer` do objeto da Stripe (id ou
     * objeto expandido); sem cliente no objeto, o model fica como estava.
     *
     * @param  Invoice  $invoice
     * @param  object|string|null  $stripeCustomer
     * @return void
     */
    private function parseInvoiceCustomer(Invoice $invoice, object|string|null $stripeCustomer): void
    {
        if (empty($stripeCustomer)) {
            return;
        }
        if (empty($invoice->customer)) {
            $invoice->customer = new Customer();
        }
        $invoice->customer->id = is_object($stripeCustomer) ? $stripeCustomer->id : $stripeCustomer;
    }

    /**
     * Preenche `paymentMethod` e `availablePaymentMethods` a partir do tipo do charge
     * (`payment_method_details.type`) ou, sem charge, do único tipo aceito pelo PaymentIntent.
     *
     * @param  Invoice  $invoice
     * @param  \Stripe\PaymentIntent|null  $stripePaymentIntent
     * @param  object|null  $stripeCharge
     * @return void
     */
    private function parsePaymentMethod(Invoice $invoice, ?StripePaymentIntent $stripePaymentIntent, ?object $stripeCharge): void
    {
        $detailsType = $stripeCharge?->payment_method_details?->type;
        if (!empty($detailsType)) {
            $invoice->paymentMethod = self::PAYMENT_METHOD_TYPES[$detailsType] ?? null;
        } elseif (count($stripePaymentIntent?->payment_method_types ?? []) === 1) {
            $invoice->paymentMethod = self::PAYMENT_METHOD_TYPES[$stripePaymentIntent->payment_method_types[0]] ?? null;
        }
        if (!empty($invoice->paymentMethod)) {
            $invoice->availablePaymentMethods = [$invoice->paymentMethod];
        }
    }

    /**
     * Preenche bandeira e últimos dígitos do cartão a partir de `payment_method_details.card`
     * do charge, quando existe.
     *
     * @param  Invoice  $invoice
     * @param  object|null  $stripeCharge
     * @return void
     */
    private function parseCardDetails(Invoice $invoice, ?object $stripeCharge): void
    {
        // `?->` não basta: em cobrança pix o payment_method_details existe e apenas não tem
        // a chave `card`, e o StripeObject loga "Undefined property" via Stripe::getLogger()
        // ao ler propriedade ausente. isset() passa pelo __isset e não polui o log.
        $paymentMethodDetails = $stripeCharge?->payment_method_details;
        $cardDetails = isset($paymentMethodDetails->card) ? $paymentMethodDetails->card : null;
        if (empty($cardDetails)) {
            return;
        }
        if (empty($invoice->creditCard)) {
            $invoice->creditCard = new CreditCard();
        }
        $invoice->creditCard->brand = $cardDetails->brand ?? null;
        $invoice->creditCard->lastDigits = $cardDetails->last4 ?? null;
        $invoice->creditCard->gateway = 'stripe';
    }

    /**
     * Preenche `pix` (QR Code) e `pixExpiresAt` a partir de `next_action.pix_display_qr_code` do
     * PaymentIntent e devolve a página hospedada de instruções. Sem QR Code, limpa `pix` e
     * devolve nulo.
     *
     * @param  Invoice  $invoice
     * @param  \Stripe\PaymentIntent|null  $stripePaymentIntent
     * @return string|null
     */
    private function parsePixDisplay(Invoice $invoice, ?StripePaymentIntent $stripePaymentIntent): ?string
    {
        // isset() passa pelo __isset: um next_action de outro tipo (3DS) não tem a chave e o
        // StripeObject registraria "Undefined property" no log ao lê-la
        $nextAction = $stripePaymentIntent?->next_action;
        $qrCode = isset($nextAction->pix_display_qr_code) ? $nextAction->pix_display_qr_code : null;
        if (empty($qrCode)) {
            $invoice->pix = null;

            return null;
        }

        if (empty($invoice->pix)) {
            $invoice->pix = new Pix();
        }
        $invoice->pix->qrCodeText = $qrCode->data ?? null;
        $invoice->pix->qrCodeImageUrl = $qrCode->image_url_png ?? null;
        $invoice->pixExpiresAt = !empty($qrCode->expires_at)
            ? Carbon::createFromTimestamp($qrCode->expires_at)
            : $invoice->pixExpiresAt;

        return $qrCode->hosted_instructions_url ?? null;
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
     * Deriva o `InvoiceStatus` do trio Invoice da Stripe, PaymentIntent e charge pago; os dois
     * `parse*` de fatura obtêm o status por aqui.
     *
     * Sem Invoice (origem `PAYMENT_INTENT`) o status vem do PaymentIntent e o charge refina
     * estorno e contestação: estorno não muda o status do PaymentIntent na Stripe;
     * `requires_capture` lê como `AUTHORIZED`, `processing` como `PROCESSING` e status fora do
     * mapa devolve `UNKNOWN` com aviso no log.
     *
     * Com Invoice (origem `INVOICE`) o status do Invoice manda no ciclo de vida e PaymentIntent
     * e charge só refinam o detalhe de pagamento: `draft` é `PENDING`; `open` é `PENDING`,
     * `AUTHORIZED`, `PROCESSING` ou `PARTIALLY_PAID` conforme o PaymentIntent e o
     * `amount_paid`; `paid` é `PAID`, estornada ou contestada conforme o charge,
     * `EXTERNALLY_PAID` quando o pagamento foi registrado fora da Stripe e `PAID` quando não
     * houve cobrança (`amount_due` zero); `void` é `CANCELED` e `uncollectible` é `EXPIRED`.
     * Combinação fora dessa tabela devolve `UNKNOWN` com aviso no log contendo os três status
     * e o id da fatura.
     *
     * Contestação, quando existe, vence estorno e status de pagamento nas duas origens.
     *
     * @param  \Stripe\Invoice|null  $stripeInvoice
     * @param  \Stripe\PaymentIntent|null  $stripePaymentIntent
     * @param  object|null  $paidCharge  charge em `succeeded`, expandido
     * @return InvoiceStatus
     * @throws GatewayException|GatewayNotAvailableException
     */
    private function deriveStatus(?StripeInvoice $stripeInvoice, ?StripePaymentIntent $stripePaymentIntent, ?object $paidCharge): InvoiceStatus
    {
        $disputeStatus = $paidCharge ? $this->disputeStatus($paidCharge) : null;

        if (is_null($stripeInvoice)) {
            return self::paymentIntentStatus($stripePaymentIntent, $paidCharge, $disputeStatus);
        }

        return match ($stripeInvoice->status) {
            'draft' => InvoiceStatus::PENDING,
            'open' => $this->openInvoiceStatus($stripeInvoice, $stripePaymentIntent),
            'paid' => $this->paidInvoiceStatus($stripeInvoice, $stripePaymentIntent, $paidCharge, $disputeStatus),
            'void' => InvoiceStatus::CANCELED,
            'uncollectible' => InvoiceStatus::EXPIRED,
            default => self::unknownInvoiceStatus($stripeInvoice, $stripePaymentIntent),
        };
    }

    /**
     * Status de um PaymentIntent sem Invoice (origem `PAYMENT_INTENT`).
     *
     * @param  \Stripe\PaymentIntent|null  $stripePaymentIntent
     * @param  object|null  $paidCharge
     * @param  InvoiceStatus|null  $disputeStatus
     * @return InvoiceStatus
     */
    private static function paymentIntentStatus(?StripePaymentIntent $stripePaymentIntent, ?object $paidCharge, ?InvoiceStatus $disputeStatus): InvoiceStatus
    {
        if ($disputeStatus !== null) {
            return $disputeStatus;
        }

        if ($paidCharge && $paidCharge->amount_refunded > 0) {
            return $paidCharge->refunded
                ? InvoiceStatus::REFUNDED
                : InvoiceStatus::PARTIALLY_REFUNDED;
        }

        return match ($stripePaymentIntent?->status) {
            'succeeded' => InvoiceStatus::PAID,
            'canceled' => InvoiceStatus::CANCELED,
            'requires_capture' => InvoiceStatus::AUTHORIZED,
            'processing' => InvoiceStatus::PROCESSING,
            // pix expirado volta a requires_payment_method (não vira canceled) e segue
            // re-cobrável; reportar PENDING preserva essa funcionalidade
            'requires_action', 'requires_confirmation', 'requires_payment_method' => InvoiceStatus::PENDING,
            default => InvoiceStatus::unknown((string) $stripePaymentIntent?->status, 'stripe'),
        };
    }

    /**
     * Status de um Invoice da Stripe em `open`: parcialmente paga quando `amount_paid` está
     * entre zero e o `total`; senão o PaymentIntent decide (ausente ou aguardando o cliente é
     * `PENDING`, `requires_capture` é `AUTHORIZED`, `processing` é `PROCESSING`). PaymentIntent
     * em `succeeded` ou `canceled` numa fatura ainda aberta é transição ou pagamento fora do
     * padrão e fica em `UNKNOWN`.
     *
     * @param  \Stripe\Invoice  $stripeInvoice
     * @param  \Stripe\PaymentIntent|null  $stripePaymentIntent
     * @return InvoiceStatus
     */
    private function openInvoiceStatus(StripeInvoice $stripeInvoice, ?StripePaymentIntent $stripePaymentIntent): InvoiceStatus
    {
        $amountPaid = $stripeInvoice->amount_paid ?? 0;
        if ($amountPaid > 0 && $amountPaid < ($stripeInvoice->total ?? 0)) {
            return InvoiceStatus::PARTIALLY_PAID;
        }

        return match ($stripePaymentIntent?->status) {
            null, 'requires_payment_method', 'requires_action', 'requires_confirmation' => InvoiceStatus::PENDING,
            'requires_capture' => InvoiceStatus::AUTHORIZED,
            'processing' => InvoiceStatus::PROCESSING,
            default => self::unknownInvoiceStatus($stripeInvoice, $stripePaymentIntent),
        };
    }

    /**
     * Status de um Invoice da Stripe em `paid`. Com o PaymentIntent em `succeeded`, o charge
     * refina: contestação, estorno parcial ou total, senão `PAID`. Sem ele, o pagamento
     * registrado fora da Stripe (`amount_paid_off_stripe`, ou um InvoicePayment pago do tipo
     * `payment_record`) lê como `EXTERNALLY_PAID`; um InvoicePayment pago do tipo `charge` lê
     * como `PAID`; e a fatura sem PaymentIntent com `amount_due` zero (avaliação gratuita,
     * saldo de crédito, valor abaixo do mínimo) lê como `PAID`. O restante é `UNKNOWN`.
     *
     * @param  \Stripe\Invoice  $stripeInvoice
     * @param  \Stripe\PaymentIntent|null  $stripePaymentIntent
     * @param  object|null  $paidCharge
     * @param  InvoiceStatus|null  $disputeStatus
     * @return InvoiceStatus
     */
    private function paidInvoiceStatus(
        StripeInvoice $stripeInvoice,
        ?StripePaymentIntent $stripePaymentIntent,
        ?object $paidCharge,
        ?InvoiceStatus $disputeStatus
    ): InvoiceStatus {
        if ($stripePaymentIntent?->status === 'succeeded') {
            return self::paymentIntentStatus($stripePaymentIntent, $paidCharge, $disputeStatus);
        }

        if (($stripeInvoice->amount_paid_off_stripe ?? 0) > 0) {
            return InvoiceStatus::EXTERNALLY_PAID;
        }

        $paidPaymentType = self::paidInvoicePaymentType($stripeInvoice);
        if ($paidPaymentType === self::INVOICE_PAYMENT_TYPE_PAYMENT_RECORD) {
            return InvoiceStatus::EXTERNALLY_PAID;
        }
        if ($paidPaymentType === 'charge') {
            return InvoiceStatus::PAID;
        }

        if (is_null($stripePaymentIntent) && (int) ($stripeInvoice->amount_due ?? 0) === 0) {
            return InvoiceStatus::PAID;
        }

        return self::unknownInvoiceStatus($stripeInvoice, $stripePaymentIntent);
    }

    /**
     * Devolve `UNKNOWN` e registra um aviso no log com o id da fatura e os status do Invoice,
     * do PaymentIntent e do charge.
     *
     * @param  \Stripe\Invoice  $stripeInvoice
     * @param  \Stripe\PaymentIntent|null  $stripePaymentIntent
     * @return InvoiceStatus
     */
    private static function unknownInvoiceStatus(StripeInvoice $stripeInvoice, ?StripePaymentIntent $stripePaymentIntent): InvoiceStatus
    {
        $stripeCharge = is_object($stripePaymentIntent?->latest_charge) ? $stripePaymentIntent->latest_charge : null;
        $context = [
            'gateway' => 'stripe',
            'invoice_id' => $stripeInvoice->id,
            'invoice_status' => $stripeInvoice->status,
            'payment_intent_status' => $stripePaymentIntent?->status,
            'charge_status' => $stripeCharge?->status,
        ];

        LogHelper::warning(
            "Combinação de status sem tradução na fatura [{$stripeInvoice->id}] do gateway [stripe]"
            . " (invoice [{$stripeInvoice->status}], payment_intent [" . ($stripePaymentIntent?->status ?? 'ausente')
            . '], charge [' . ($stripeCharge?->status ?? 'ausente') . ']), lida como unknown',
            $context
        );

        return InvoiceStatus::UNKNOWN;
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
     * Restrito a faturas pix pendentes (cartão é síncrono, não há o que duplicar). A chave de
     * idempotência vai na criação da nova fatura; o cancelamento da original usa
     * `{chave}:cancel_original`. Com chave, uma original já cancelada é aceita, porque pode ser
     * o resultado de uma tentativa anterior com a mesma chave, que a Stripe repete. Fatura de
     * origem `INVOICE` (id `in_`) é recusada antes da rede: a próxima fatura da assinatura é
     * gerada pela Stripe.
     *
     * @throws ModelAttributeValidationException|UnsupportedOperationException
     */
    public function duplicateInvoice(
        Invoice $invoice,
        Carbon $expiresAt,
        array $gatewayOptions = [],
        ?string $idempotencyKey = null
    ): Invoice {
        if (empty($invoice->id)) {
            throw ModelAttributeValidationException::required('Invoice', 'id');
        }
        if (self::isStripeInvoiceId($invoice->id)) {
            throw UnsupportedOperationException::restricted(
                (string) $this,
                Capability::INVOICE_DUPLICATION,
                "No Stripe a fatura de assinatura [{$invoice->id}] (objeto Invoice) não pode ser duplicada:"
                . ' a próxima fatura é gerada pela Stripe, e um Pix expirado se resolve com nova tentativa'
                . ' de pagamento da mesma fatura.'
            );
        }
        $idempotencyKey = $this->idempotencyKeyFor($idempotencyKey, $invoice, $gatewayOptions);
        $gatewayOptions = self::withoutIdempotencyKey($gatewayOptions);

        $original = $this->stripeRequest(function () use ($invoice) {
            return $this->client->paymentIntents->retrieve(
                $invoice->id,
                ['expand' => array_merge(self::PAYMENT_INTENT_EXPAND, ['payment_method'])]
            );
        });
        $parsedOriginal = $this->parseInvoice($original, new Invoice());

        // com chave, a original cancelada pode ser obra de uma tentativa anterior com a mesma
        // chave: a Stripe repete a criação da duplicata e o cancelamento
        $replayable = !is_null($idempotencyKey) && $parsedOriginal->status === InvoiceStatus::CANCELED;
        if ($parsedOriginal->status !== InvoiceStatus::PENDING && !$replayable) {
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
            throw UnsupportedOperationException::restricted(
                (string) $this,
                Capability::INVOICE_DUPLICATION,
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
        $duplicated->pixExpiresAt = $expiresAt;
        // preserva o metadata da original (inclusive chaves custom do consumidor);
        // as gatewayOptions do chamador vêm por último e podem sobrescrever
        $originalMetadata = !empty($original->metadata) ? $original->metadata->toArray() : [];
        if (!empty($originalMetadata)) {
            $duplicated->gatewayOptions['metadata'] = $originalMetadata;
        }
        if (!empty($gatewayOptions)) {
            $duplicated->gatewayOptions = array_merge($duplicated->gatewayOptions, $gatewayOptions);
        }
        $duplicated = $this->createPixInvoice($duplicated, $idempotencyKey);

        try {
            $this->cancelInvoice($parsedOriginal, self::derivedIdempotencyKey($idempotencyKey, 'cancel_original'));
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
     *
     * Na origem `PAYMENT_INTENT` cancela o PaymentIntent; na origem `INVOICE` (id `in_`) anula o
     * Invoice da Stripe (`void`), e a Stripe cancela sozinha o PaymentIntent padrão dele. A
     * chave de idempotência vai no cabeçalho `Idempotency-Key` do cancelamento.
     *
     * @throws ModelAttributeValidationException
     */
    public function cancelInvoice(Invoice $invoice, ?string $idempotencyKey = null): Invoice
    {
        if (empty($invoice->id)) {
            throw ModelAttributeValidationException::required('Invoice', 'id');
        }
        $idempotencyKey = $this->idempotencyKeyFor($idempotencyKey, $invoice);

        if (self::isStripeInvoiceId($invoice->id)) {
            return $this->voidStripeInvoice($invoice, $idempotencyKey);
        }

        // só estados não-terminais são canceláveis; PaymentIntent pago recusa o cancel
        // com payment_intent_unexpected_state (vira GatewayException)
        $stripePaymentIntent = $this->stripeRequest(function () use ($invoice, $idempotencyKey) {
            return $this->client->paymentIntents->cancel(
                $invoice->id,
                ['expand' => self::PAYMENT_INTENT_EXPAND],
                self::stripeOptions($idempotencyKey)
            );
        });

        return $this->parseInvoice($stripePaymentIntent, $invoice);
    }

    /**
     * Anula um Invoice da Stripe. A fatura é lida antes: `draft` lança
     * `UnsupportedOperationException::restricted()` (`INVOICE_CANCELLATION`) orientando a
     * esperar a finalização; nos demais estados a Stripe decide, e `paid` ou `void` recusam
     * com `ValidationException`, como o PaymentIntent já pago ou cancelado.
     *
     * @param  Invoice  $invoice
     * @param  string|null  $idempotencyKey
     * @return Invoice
     * @throws GatewayException|GatewayNotAvailableException|UnsupportedOperationException
     */
    private function voidStripeInvoice(Invoice $invoice, ?string $idempotencyKey): Invoice
    {
        $current = $this->retrieveStripeInvoice($invoice->id);
        if ($current->status === 'draft') {
            throw UnsupportedOperationException::restricted(
                (string) $this,
                Capability::INVOICE_CANCELLATION,
                "A fatura [{$invoice->id}] ainda é um rascunho na Stripe e não pode ser cancelada;"
                . ' aguarde a finalização dela pela Stripe.'
            );
        }

        $stripeInvoice = $this->stripeRequest(function () use ($invoice, $idempotencyKey) {
            return $this->client->invoices->voidInvoice(
                $invoice->id,
                ['expand' => self::INVOICE_EXPAND],
                self::stripeOptions($idempotencyKey)
            );
        });

        return $this->parseInvoice($stripeInvoice, $invoice);
    }

    /**
     * @inheritDoc
     *
     * O cartão é salvo por um SetupIntent criado e confirmado na mesma requisição
     * (`usage: off_session`), que autentica o portador com o emissor quando ele exige. Em
     * `succeeded` a Stripe anexa o PaymentMethod ao cliente e o cartão volta cobrável, com
     * `id`. Em `requires_action` nada é anexado: o cartão volta com `requiresAction`
     * verdadeiro, `setupId`, `clientSecret` (para `stripe.confirmCardSetup()` no navegador) e
     * `actionUrl` quando `gatewayOptions['return_url']` foi informado (página hospedada de
     * 3DS), com `id` nulo até `confirmCreditCardSetup()`. Recusa no setup é
     * `ChargingException`. A descrição e a marcação de padrão vão em `metadata` do SetupIntent
     * (mesclado ao `metadata` de `gatewayOptions`, quando há) e são aplicadas quando o setup
     * conclui, nesta chamada ou na confirmação.
     *
     * A chave de idempotência vai no cabeçalho `Idempotency-Key` do SetupIntent; as requisições
     * secundárias usam chaves derivadas: `{chave}:payment_method` na conversão de token legado,
     * `{chave}:attach` no anexo (só quando a Stripe devolve o PaymentMethod sem cliente),
     * `{chave}:metadata` na descrição e `{chave}:default` ao marcar como padrão.
     *
     * @throws ChargingException|ModelAttributeValidationException|UnsupportedOperationException
     */
    public function createCreditCard(CreditCard $creditCard, ?string $idempotencyKey = null): CreditCard
    {
        if (empty($creditCard->customer) || empty($creditCard->customer->id)) {
            throw ModelAttributeValidationException::required('CreditCard', 'customer');
        }
        $idempotencyKey = $this->idempotencyKeyFor($idempotencyKey, $creditCard);
        if (empty($creditCard->token)) {
            // token-only: dados crus exigiriam a liberação de raw card data APIs pela
            // Stripe e escopo PCI SAQ D; o cartão é tokenizado client-side
            $this->assertSupports(
                Capability::RAW_CARD_DATA,
                'Tokenize o cartão no navegador com Stripe.js e informe o id resultante em CreditCard::$token.'
            );
        }

        $stripeSetupIntent = $this->stripeRequest(function () use ($creditCard, $idempotencyKey) {
            $paymentMethodId = $this->resolvePaymentMethodId(
                $creditCard->token,
                self::derivedIdempotencyKey($idempotencyKey, 'payment_method')
            );

            $stripeSetupIntentData = [
                'customer' => $creditCard->customer->id,
                'payment_method' => $paymentMethodId,
                'payment_method_types' => ['card'],
                'usage' => 'off_session',
                'confirm' => true,
            ];
            $stripeSetupIntentData = $this->mergeGatewayOptions($stripeSetupIntentData, $creditCard);
            // o metadata do consumidor (gatewayOptions) convive com as chaves do setup
            $metadata = array_merge($stripeSetupIntentData['metadata'] ?? [], self::cardSetupMetadata($creditCard));
            if (!empty($metadata)) {
                $stripeSetupIntentData['metadata'] = $metadata;
            }

            return $this->client->setupIntents->create(
                $this->withExpand($stripeSetupIntentData, self::SETUP_INTENT_EXPAND),
                self::stripeOptions($idempotencyKey)
            );
        });

        return $this->finishCardSetup($stripeSetupIntent, $creditCard, $idempotencyKey);
    }

    /**
     * @inheritDoc
     *
     * Lê o SetupIntent e aplica o desfecho: `succeeded` anexa o cartão ao cliente quando a
     * Stripe ainda não o fez, aplica a descrição e a marcação de padrão guardadas em `metadata`
     * do setup e devolve o cartão cobrável; `requires_action` devolve o cartão ainda com
     * `requiresAction` (o pagador não concluiu a autenticação); os demais estados lançam
     * `ChargingException` com o `last_setup_error` quando há um. Um SetupIntent sem cliente
     * (criado fora de `createCreditCard()`) é recusado com `ModelAttributeValidationException`
     * antes de qualquer escrita. A chave de idempotência vai nas escritas secundárias,
     * derivada: `{chave}:attach`, `{chave}:metadata` e `{chave}:default`.
     *
     * @throws ModelAttributeValidationException
     */
    public function confirmCreditCardSetup(string $setupId, ?string $idempotencyKey = null): CreditCard
    {
        $stripeSetupIntent = $this->stripeRequest(function () use ($setupId) {
            return $this->client->setupIntents->retrieve($setupId, ['expand' => self::SETUP_INTENT_EXPAND]);
        });

        $customerId = is_object($stripeSetupIntent->customer)
            ? $stripeSetupIntent->customer->id
            : $stripeSetupIntent->customer;
        if (empty($customerId)) {
            throw ModelAttributeValidationException::invalid(
                'CreditCard',
                'setupId',
                "SetupIntent [{$setupId}] has no customer; only a setup created by createCreditCard() can be confirmed as a saved card"
            );
        }

        $creditCard = new CreditCard();
        $creditCard->customer = new Customer();
        $creditCard->customer->id = $customerId;

        return $this->finishCardSetup($stripeSetupIntent, $creditCard, $idempotencyKey);
    }

    /**
     * `metadata` do SetupIntent com o que aplicar ao cartão quando o setup conclui: a
     * descrição (o PaymentMethod da Stripe não tem campo de descrição) e a marcação de padrão.
     * Vazio quando o model não informa nenhum dos dois.
     *
     * @param  \Potelo\MultiPayment\Models\CreditCard  $creditCard
     * @return array<string, string>
     */
    private static function cardSetupMetadata(CreditCard $creditCard): array
    {
        $metadata = [];
        if (!empty($creditCard->description)) {
            $metadata[self::SETUP_METADATA_DESCRIPTION] = $creditCard->description;
        }
        if (!empty($creditCard->default)) {
            $metadata[self::SETUP_METADATA_DEFAULT] = '1';
        }

        return $metadata;
    }

    /**
     * Converte o SetupIntent, com `payment_method` expandido, no `CreditCard` que a operação
     * devolve. `succeeded`: anexa o PaymentMethod ao cliente quando ele voltou sem cliente (a
     * Stripe anexa ao confirmar um SetupIntent com cliente, e este anexo só cobre a resposta
     * em que isso não aconteceu), grava a descrição em `metadata` do PaymentMethod e o marca
     * como padrão do cliente (`default` verdadeiro no model), conforme `metadata` do setup.
     * `requires_action`: cartão com
     * `requiresAction`, `setupId`, `clientSecret`, `actionUrl` (quando `next_action` é
     * `redirect_to_url`), os dados do cartão para exibição e `id` nulo. Os demais estados
     * (`requires_payment_method` depois de uma autenticação que falhou, `canceled`,
     * `processing`, `requires_confirmation`) lançam `ChargingException`.
     *
     * @param  \Stripe\SetupIntent  $stripeSetupIntent
     * @param  \Potelo\MultiPayment\Models\CreditCard  $creditCard  model a preencher, com `customer->id`
     * @param  string|null  $idempotencyKey
     * @return \Potelo\MultiPayment\Models\CreditCard
     * @throws ChargingException|GatewayException|GatewayNotAvailableException
     */
    private function finishCardSetup(StripeSetupIntent $stripeSetupIntent, CreditCard $creditCard, ?string $idempotencyKey): CreditCard
    {
        $stripePaymentMethod = is_object($stripeSetupIntent->payment_method) ? $stripeSetupIntent->payment_method : null;
        $metadata = !empty($stripeSetupIntent->metadata) ? $stripeSetupIntent->metadata->toArray() : [];

        if ($stripeSetupIntent->status === StripeSetupIntent::STATUS_SUCCEEDED) {
            $stripePaymentMethod = $this->stripeRequest(function () use ($stripeSetupIntent, $stripePaymentMethod, $metadata, $creditCard, $idempotencyKey) {
                $paymentMethodId = $stripePaymentMethod?->id ?? $stripeSetupIntent->payment_method;
                if (empty($stripePaymentMethod?->customer)) {
                    $stripePaymentMethod = $this->client->paymentMethods->attach(
                        $paymentMethodId,
                        ['customer' => $creditCard->customer->id],
                        self::stripeOptions(self::derivedIdempotencyKey($idempotencyKey, 'attach'))
                    );
                }

                if (!empty($metadata[self::SETUP_METADATA_DESCRIPTION])) {
                    $stripePaymentMethod = $this->client->paymentMethods->update(
                        $paymentMethodId,
                        ['metadata' => [self::SETUP_METADATA_DESCRIPTION => $metadata[self::SETUP_METADATA_DESCRIPTION]]],
                        self::stripeOptions(self::derivedIdempotencyKey($idempotencyKey, 'metadata'))
                    );
                }

                if (!empty($metadata[self::SETUP_METADATA_DEFAULT])) {
                    $this->client->customers->update($creditCard->customer->id, [
                        'invoice_settings' => ['default_payment_method' => $paymentMethodId],
                    ], self::stripeOptions(self::derivedIdempotencyKey($idempotencyKey, 'default')));
                }

                return $stripePaymentMethod;
            });

            $creditCard = $this->parseStripeCard($stripePaymentMethod, $creditCard);
            if (!empty($metadata[self::SETUP_METADATA_DEFAULT])) {
                $creditCard->default = true;
            }
            $creditCard->setupId = $stripeSetupIntent->id;
            $creditCard->requiresAction = false;
            $creditCard->actionUrl = null;
            $creditCard->clientSecret = null;

            return $creditCard;
        }

        if ($stripeSetupIntent->status === StripeSetupIntent::STATUS_REQUIRES_ACTION) {
            $this->fillCardFields($creditCard, $stripePaymentMethod?->card ?? null);
            $creditCard->id = null;
            $creditCard->description = $metadata[self::SETUP_METADATA_DESCRIPTION] ?? $creditCard->description;
            $creditCard->requiresAction = true;
            $creditCard->setupId = $stripeSetupIntent->id;
            $creditCard->clientSecret = $stripeSetupIntent->client_secret;
            $creditCard->actionUrl = $stripeSetupIntent->next_action->redirect_to_url->url ?? null;
            $creditCard->gateway = 'stripe';
            $creditCard->original = $stripeSetupIntent;
            $creditCard->createdAt = Carbon::createFromTimestamp($stripeSetupIntent->created);

            return $creditCard;
        }

        throw $this->cardSetupFailed($stripeSetupIntent);
    }

    /**
     * `ChargingException` de um SetupIntent que não chegou a `succeeded` nem parou em
     * `requires_action`: com `last_setup_error`, a recusa segue a tradução normal do código
     * (`setup_intent_authentication_failure` vira `DeclineCode::AUTHENTICATION_REQUIRED`); sem
     * ele (setup cancelado, por exemplo), `DeclineCode::UNKNOWN` com `cancellation_reason` ou
     * o status em `gatewayCode`. O SetupIntent vai em `chargeResponse` nos dois casos.
     *
     * @param  \Stripe\SetupIntent  $stripeSetupIntent
     * @return ChargingException
     */
    private function cardSetupFailed(StripeSetupIntent $stripeSetupIntent): ChargingException
    {
        $error = $stripeSetupIntent->last_setup_error ?? null;
        if (is_object($error)) {
            $exception = $this->declinedFromStripeError(
                $error,
                (string) ($error->message ?? "SetupIntent {$stripeSetupIntent->id} em {$stripeSetupIntent->status}"),
                null,
                null
            );
        } else {
            $exception = ChargingException::declined(
                'stripe',
                DeclineCode::UNKNOWN,
                $stripeSetupIntent->cancellation_reason ?? $stripeSetupIntent->status,
                "SetupIntent {$stripeSetupIntent->id} em {$stripeSetupIntent->status}; o cartão não foi salvo."
            );
        }
        $exception->chargeResponse = $stripeSetupIntent->toArray();

        return $exception;
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
     *
     * A chave de idempotência vai no cabeçalho `Idempotency-Key` do detach. Com chave, um cartão
     * já sem cliente passa pela checagem de posse, porque pode ter sido desvinculado por uma
     * tentativa anterior com a mesma chave.
     */
    public function deleteCreditCard(CreditCard $creditCard, ?string $idempotencyKey = null): void
    {
        $idempotencyKey = $this->idempotencyKeyFor($idempotencyKey, $creditCard);

        $this->stripeRequest(function () use ($creditCard, $idempotencyKey) {
            $stripePaymentMethod = $this->client->paymentMethods->retrieve($creditCard->id);
            // cartão já sem cliente com chave informada: pode ter sido desvinculado por uma
            // tentativa anterior com a mesma chave, que a Stripe repete
            if (!empty($stripePaymentMethod->customer) || is_null($idempotencyKey)) {
                $this->assertCardBelongsToCustomer($stripePaymentMethod, $creditCard);
            }

            return $this->client->paymentMethods->detach($creditCard->id, null, self::stripeOptions($idempotencyKey));
        });
    }

    /**
     * Resolve o token do consumidor para um id de PaymentMethod: tokens legados da Stripe
     * (tok_...) não são utilizáveis diretamente e viram PaymentMethod antes.
     *
     * @param  string  $token
     * @param  string|null  $idempotencyKey  chave da criação do PaymentMethod a partir do token
     * @return string
     * @throws ApiErrorException
     */
    private function resolvePaymentMethodId(string $token, ?string $idempotencyKey = null): string
    {
        if (str_starts_with($token, 'tok_')) {
            return $this->client->paymentMethods->create([
                'type' => 'card',
                'card' => ['token' => $token],
            ], self::stripeOptions($idempotencyKey))->id;
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
     * @throws UnsupportedOperationException
     */
    private function assertCardBelongsToCustomer(StripePaymentMethod $stripePaymentMethod, CreditCard $creditCard): void
    {
        $customerId = $creditCard->customer->id ?? null;
        if (!empty($customerId) && $stripePaymentMethod->customer !== $customerId) {
            throw UnsupportedOperationException::restricted(
                (string) $this,
                Capability::CREDIT_CARD,
                "Credit card [{$stripePaymentMethod->id}] does not belong to customer [{$customerId}];"
                . ' the Stripe PaymentMethod is bound to one customer.'
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

        $creditCard->id = $stripePaymentMethod->id;
        $this->fillCardFields($creditCard, isset($stripePaymentMethod->card) ? $stripePaymentMethod->card : null);

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
     * Preenche bandeira, últimos dígitos e validade a partir do objeto `card` de um
     * PaymentMethod da Stripe; nulos quando o objeto não veio.
     *
     * @param  \Potelo\MultiPayment\Models\CreditCard  $creditCard
     * @param  object|null  $card
     * @return void
     */
    private function fillCardFields(CreditCard $creditCard, ?object $card): void
    {
        $creditCard->brand = $card->brand ?? null;
        $creditCard->lastDigits = $card->last4 ?? null;
        $creditCard->month = isset($card->exp_month)
            ? str_pad((string) $card->exp_month, 2, '0', STR_PAD_LEFT)
            : null;
        $creditCard->year = isset($card->exp_year) ? (string) $card->exp_year : null;
    }

    /**
     * @inheritDoc
     */
    public function rescheduleAutomaticPixPayment(Invoice $invoice, ?string $idempotencyKey = null): Invoice
    {
        throw UnsupportedOperationException::forGateway($this, Capability::AUTOMATIC_PIX);
    }

    /**
     * @inheritDoc
     */
    public function cancelAutomaticPixScheduledPayment(
        AutomaticPixCharge $charge,
        ?string $idempotencyKey = null
    ): AutomaticPixCancellation {
        throw UnsupportedOperationException::forGateway($this, Capability::AUTOMATIC_PIX);
    }

    /**
     * @inheritDoc
     */
    public function cancelAutomaticPixRecurrence(
        AutomaticPix $automaticPix,
        ?string $idempotencyKey = null
    ): AutomaticPixCancellation {
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
