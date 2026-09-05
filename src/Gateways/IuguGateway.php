<?php

namespace Potelo\MultiPayment\Gateways;

use Iugu;
use APIResource;
use Carbon\Carbon;
use Iugu_APIRequest;
use IuguObjectNotFound;
use Potelo\MultiPayment\Models\Pix;
use Illuminate\Support\Facades\Config;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Refund;
use Potelo\MultiPayment\Models\Address;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\BankSlip;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\InvoiceItem;
use Potelo\MultiPayment\Models\PaymentError;
use Potelo\MultiPayment\Models\AutomaticPix;
use Potelo\MultiPayment\Models\AutomaticPixCharge;
use Potelo\MultiPayment\Models\Plan;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Models\SubscriptionItem;
use Potelo\MultiPayment\Models\SubscriptionDiscount;
use Potelo\MultiPayment\Models\SubscriptionPlanChange;
use Potelo\MultiPayment\Models\AutomaticPixCancellation;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Enums\InvoiceOriginType;
use Potelo\MultiPayment\Enums\RefundStatus;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Enums\ProrationBehavior;
use Potelo\MultiPayment\Enums\SubscriptionStatus;
use Potelo\MultiPayment\Enums\PlanInterval;
use Potelo\MultiPayment\Enums\DeclineCode;
use Potelo\MultiPayment\Helpers\LogHelper;
use Potelo\MultiPayment\Capabilities\CapabilityRestriction;
use Potelo\MultiPayment\Helpers\ConfigurationHelper;
use Potelo\MultiPayment\Contracts\PlanContract;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Contracts\IdempotencyStore;
use Potelo\MultiPayment\Contracts\SubscriptionContract;
use Potelo\MultiPayment\Contracts\SubscriptionSyncContract;
use Potelo\MultiPayment\Gateways\Concerns\ChecksCapabilities;
use Potelo\MultiPayment\Gateways\Concerns\ResolvesIdempotencyKey;
use Potelo\MultiPayment\Gateways\Iugu\DeclineCodes as IuguDeclineCodes;
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

class IuguGateway implements GatewayContract, SubscriptionContract, PlanContract, SubscriptionSyncContract
{
    use ChecksCapabilities;
    use ResolvesIdempotencyKey;

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
     * Prefixo reservado em `custom_variables` da assinatura para o estado que a lib grava
     * (cancelamento e validade de desconto); `metadata` com uma chave assim é recusado.
     */
    private const RESERVED_VARIABLE_PREFIX = 'mp_';

    /** Variável que marca o cancelamento agendado para o fim do período (`1` quando há). */
    private const CANCEL_AT_PERIOD_END_VARIABLE = 'mp_cancel_at_period_end';

    /** Variável com a data (`Y-m-d`) em que a assinatura agendada deve ser suspensa. */
    private const CANCEL_SCHEDULED_FOR_VARIABLE = 'mp_cancel_scheduled_for';

    /** Prefixo da variável de validade de um desconto: `mp_discount_<subitem_id>_until`. */
    private const DISCOUNT_UNTIL_PREFIX = 'mp_discount_';

    /** Sufixo da variável de validade de um desconto: `mp_discount_<subitem_id>_until`. */
    private const DISCOUNT_UNTIL_SUFFIX = '_until';

    /**
     * Variável de `custom_variables` da assinatura em que a lib grava a data do cancelamento.
     * A Iugu só suspende, então é essa marca que distingue `CANCELED` de `SUSPENDED`. O
     * prefixo `mp_` é reservado à lib.
     */
    private const CANCELED_AT_VARIABLE = 'mp_canceled_at';

    /** Faixa de `interval` aceita pela Iugu na criação de plano. */
    private const PLAN_INTERVAL_MIN = 1;
    private const PLAN_INTERVAL_MAX = 599;

    /** Prazo, em dias após o pagamento, em que a Iugu ainda aceita estorno pela API. */
    private const REFUND_WINDOW_DAYS = 90;

    /**
     * Máximo de parcelas declarado em `restriction(INSTALLMENTS)` quando a configuração
     * `multi-payment.gateways.iugu.max_installments` não informa o da conta; é o teto da Iugu.
     */
    private const DEFAULT_MAX_INSTALLMENTS = 12;

    /** Prefixo das chaves deste driver na `IdempotencyStore`. */
    private const IDEMPOTENCY_STORE_PREFIX = 'iugu:';

    private Iugu_APIRequest $apiRequest;

    private ?IdempotencyStore $idempotencyStore;

    /**
     * Configura a chave de API da Iugu e o requester HTTP. Sem requester, usa o compartilhado
     * do SDK (`APIResource::API()`); sem store, a `IdempotencyStore` registrada no container é
     * resolvida na primeira operação que precisar dela.
     *
     * @param  Iugu_APIRequest|null  $apiRequest
     * @param  IdempotencyStore|null  $idempotencyStore
     */
    public function __construct(?Iugu_APIRequest $apiRequest = null, ?IdempotencyStore $idempotencyStore = null)
    {
        Iugu::setApiKey(Config::get('multi-payment.gateways.iugu.api_key'));
        $this->apiRequest = $apiRequest ?? APIResource::API();
        $this->idempotencyStore = $idempotencyStore;
    }

    /**
     * @inheritDoc
     */
    public function capabilities(): array
    {
        return [
            Capability::CREDIT_CARD,
            Capability::PIX,
            Capability::BANK_SLIP,
            Capability::AUTOMATIC_PIX,
            Capability::MULTIPLE_PAYMENT_METHODS,
            Capability::RAW_CARD_DATA,
            Capability::INSTALLMENTS,
            Capability::PARTIAL_REFUND_CARD,
            Capability::INVOICE_DUPLICATION,
            Capability::INVOICE_CANCELLATION,
            Capability::IDEMPOTENCY,
            Capability::SUBSCRIPTIONS,
            Capability::PLANS,
        ];
    }

    /**
     * @inheritDoc
     */
    public function notYetImplemented(): array
    {
        return [
            Capability::DELAYED_CAPTURE,
            Capability::SUBSCRIPTION_CREDITS,
            Capability::WEBHOOKS,
        ];
    }

    /**
     * @inheritDoc
     *
     * A Iugu não tem cupom com prazo nem cancelamento ao fim do ciclo; a lib emula os dois com
     * estado em `custom_variables` da assinatura, aplicado pelo comando
     * `multipayment:sync-subscriptions` agendado pela aplicação.
     */
    public function emulated(): array
    {
        return [
            Capability::COUPONS,
            Capability::CANCEL_AT_PERIOD_END,
        ];
    }

    /**
     * @inheritDoc
     *
     * `INSTALLMENTS`: o número de parcelas vai em `gatewayOptions['months']`, até o máximo da
     * conta (`multi-payment.gateways.iugu.max_installments`, 12 por padrão), e a lib não lê as
     * parcelas da fatura paga. `AUTOMATIC_PIX`: a recorrência nasce na fatura e a aplicação é
     * o motor de recorrência; a assinatura não aceita o método.
     */
    public function restrictions(): array
    {
        // variável de ambiente vazia chega como string vazia; vale o padrão da Iugu
        $maxInstallments = (int) Config::get('multi-payment.gateways.iugu.max_installments')
            ?: self::DEFAULT_MAX_INSTALLMENTS;

        return [
            Capability::INSTALLMENTS->value => new CapabilityRestriction(
                description: "O número de parcelas vai em gatewayOptions['months'], até {$maxInstallments}"
                    . ' (máximo da conta, configurável em multi-payment.gateways.iugu.max_installments);'
                    . ' a lib não lê as parcelas da fatura paga.',
                maxInstallments: $maxInstallments,
            ),
            Capability::AUTOMATIC_PIX->value => new CapabilityRestriction(
                description: 'A recorrência nasce na fatura (Invoice com automaticPix e método pix) e'
                    . ' a aplicação é o motor de recorrência; a assinatura não aceita paymentMethod'
                    . ' automatic_pix.',
            ),
        ];
    }

    /**
     * @inheritDoc
     *
     * Os métodos da fatura vêm de `Invoice::resolvedPaymentMethods()` (`payable_with`); com
     * cartão entre eles e `creditCard` preenchido, a fatura é cobrada por `POST /charge`.
     * `dueDate` vai em `due_date` (sem ele, o dia de `pixExpiresAt`, ou hoje) e `pixExpiresAt`
     * em `pix_qr_code_expires_at`. A chave de idempotência vai no cabeçalho `Idempotency-Key` de `POST /invoices` ou de `POST /charge`;
     * o cartão salvo antes da cobrança usa a chave derivada `{chave}:card` pela
     * `IdempotencyStore`. Na reutilização da chave, a Iugu responde 409 com o id da fatura
     * original, que o driver lê e devolve.
     *
     * @throws ModelAttributeValidationException|ChargingException|UnsupportedOperationException
     */
    public function createInvoice(Invoice $invoice, ?string $idempotencyKey = null): Invoice
    {
        $this->assertSupportsAll($invoice->requiredCapabilities());
        // a API da Iugu não tem parâmetro de moeda e cobra sempre em BRL
        if (!empty($invoice->currency) && strcasecmp($invoice->currency, 'BRL') !== 0) {
            throw ModelAttributeValidationException::invalid(
                'Invoice',
                'currency',
                "the iugu gateway only charges in BRL, [{$invoice->currency}] given"
            );
        }
        $idempotencyKey = $this->idempotencyKeyFor($idempotencyKey, $invoice);

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
        $dueDate = $invoice->dueDate ?? $invoice->pixExpiresAt ?? Carbon::now();
        $iuguInvoiceData['due_date'] = $dueDate->format('Y-m-d');
        $iuguInvoiceData['expires_in'] = 0;
        if (!empty($invoice->pixExpiresAt)) {
            $iuguInvoiceData['pix_qr_code_expires_at'] = $invoice->pixExpiresAt->toIso8601String();
        }

        if (!empty($invoice->customer->address)) {
            $iuguInvoiceData['payer']['address'] = $invoice->customer->address->toArray();
            if (empty($invoice->customer->address->number)) {
                $iuguInvoiceData['payer']['address']['number'] = 'S/N';
            }
        }

        $payableWith = $invoice->resolvedPaymentMethods();

        if (!empty($payableWith)) {
            $iuguInvoiceData['payable_with'] = self::paymentMethodsToIuguPayableWith($payableWith);
        }

        if (!empty($invoice->automaticPix)) {
            $iuguInvoiceData['automatic_pix'] = $this->automaticPixToIuguData($invoice->automaticPix);
        }

        if (!empty($invoice->automaticPixCharge?->description)) {
            $iuguInvoiceData = array_merge(
                $iuguInvoiceData,
                $this->automaticPixChargeToIuguData($invoice->automaticPixCharge)
            );
        }

        foreach (self::withoutIdempotencyKey($invoice->gatewayOptions) as $option => $value) {
            $iuguInvoiceData[$option] = $value;
        }

        if (in_array(PaymentMethod::CREDIT_CARD, $payableWith, true) && !empty($invoice->creditCard)) {
            if (empty($invoice->creditCard->id)) {
                if (empty($invoice->creditCard->customer)) {
                    $invoice->creditCard->customer = $invoice->customer;
                }
                $invoice->creditCard = $this->createCreditCard(
                    $invoice->creditCard,
                    self::derivedIdempotencyKey($idempotencyKey, 'card')
                );
            }
            $iuguInvoiceData['customer_payment_method_id'] = $invoice->creditCard->id;
            $iuguInvoice = $this->chargeIuguInvoice($iuguInvoiceData, $idempotencyKey);
        } else {
            $iuguInvoice = $this->iuguIdempotentRequest(
                'POST',
                Iugu::getBaseURI() . '/invoices',
                $iuguInvoiceData,
                'creating invoice',
                $idempotencyKey,
                true,
                fn (string $originalId) => $this->fetchIuguInvoice($originalId, 'getting invoice')
            );
        }

        return $this->parseInvoice($iuguInvoice, $invoice);
    }

    /**
     * Lê uma fatura da Iugu pelo id.
     *
     * @param  string  $id
     * @param  string  $operation  descrição da operação, em inglês, para a mensagem
     * @return object|array
     * @throws MultiPaymentException
     */
    private function fetchIuguInvoice(string $id, string $operation): object|array
    {
        return $this->iuguRequest('GET', Iugu::getBaseURI() . '/invoices/' . rawurlencode($id), [], $operation);
    }

    /**
     * Tokeniza os dados crus do cartão na Iugu (`POST /payment_token`, deduplicado pela
     * `IdempotencyStore` quando há chave) e devolve o token gerado.
     *
     * @param  CreditCard  $creditCard
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     * @return string
     * @throws GatewayException|GatewayNotAvailableException|AuthenticationException
     */
    private function createIuguPaymentToken(CreditCard $creditCard, ?string $idempotencyKey): string
    {
        $iuguToken = $this->iuguIdempotentRequest(
            'POST',
            Iugu::getBaseURI() . '/payment_token',
            [
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
            ],
            'creating payment token',
            $idempotencyKey
        );

        if (empty($iuguToken->id)) {
            throw $this->iuguResponseException('Error creating payment token', null);
        }

        return $iuguToken->id;
    }

    /**
     * Traduz uma exceção capturada numa chamada à Iugu para a hierarquia do pacote, anexando a
     * original como `previous` e o status HTTP quando o SDK o informa.
     *
     * O SDK lança `IuguRequestException` com o status HTTP em `getCode()` quando a resposta não é
     * JSON (páginas de erro 5xx de proxy, corpo vazio de timeout com código 0) e
     * `IuguObjectNotFound` para 404; `IuguAuthenticationException` só quando a chave não foi
     * configurada. Erro com corpo JSON passa por `iuguResponseException()`.
     *
     * Regras: 401 e 403 viram `AuthenticationException`; 5xx e falha de rede viram
     * `GatewayNotAvailableException`; 404 vira `NotFoundException`; os demais status passam por
     * `classifyIuguFailure()` (400 e 422 `ValidationException`, 409
     * `IdempotencyConflictException`, 429 `RateLimitException`, o restante `GatewayException`),
     * sempre com o status em `httpStatus`. Exceção do próprio pacote passa intacta.
     *
     * @param  \Throwable  $e
     * @param  string  $operation  descrição da operação, em inglês, para a mensagem
     * @return MultiPaymentException
     */
    private function translateIuguException(\Throwable $e, string $operation): MultiPaymentException
    {
        if ($e instanceof MultiPaymentException) {
            return $e;
        }

        if ($e instanceof \IuguAuthenticationException) {
            return AuthenticationException::invalidCredentials('iugu', $e->getMessage(), $e);
        }

        if ($e instanceof IuguObjectNotFound) {
            // o SDK lança essa classe para 404 e fetchAPI() a relança sem o código HTTP
            return new NotFoundException("Error {$operation}: {$e->getMessage()}", null, $e, 404);
        }

        if ($e instanceof \IuguRequestException) {
            // corpo vazio e código 0: o cURL não obteve resposta (falha de conexão ou timeout)
            if ($e->getCode() <= 0 && trim($e->getMessage()) === '') {
                return new GatewayNotAvailableException(
                    "Error {$operation}: no response from the gateway (network failure or timeout)",
                    $e
                );
            }

            // fetchAPI() do SDK também lança esta classe, sem código, para resposta com `error`
            return $this->classifyIuguFailure(
                "Error {$operation}: {$e->getMessage()}",
                $e->getMessage(),
                null,
                $e,
                $e->getCode() > 0 ? (int) $e->getCode() : null
            );
        }

        return new GatewayException("Error {$operation}: {$e->getMessage()}", null, $e);
    }

    /**
     * Traduz um corpo de erro devolvido pela Iugu numa resposta HTTP válida (o SDK não lança
     * nesse caso) para a hierarquia do pacote, usando o status HTTP da última resposta.
     *
     * @param  string  $message
     * @param  mixed  $errors  corpo de `errors` da resposta
     * @return MultiPaymentException
     */
    private function iuguResponseException(string $message, $errors): MultiPaymentException
    {
        $detail = is_string($errors) ? $errors : json_encode($errors);

        return $this->classifyIuguFailure($message, (string) $detail, $errors, null, $this->lastIuguHttpStatus());
    }

    /**
     * Escolhe a exceção do pacote pelo status HTTP da falha: 401 e 403 `AuthenticationException`,
     * 5xx `GatewayNotAvailableException`, 400 e 422 `ValidationException` (com os erros por
     * campo, nos dois formatos que a Iugu usa: objeto por campo ou string), 404
     * `NotFoundException`, 409 `IdempotencyConflictException`, 429 `RateLimitException` (com
     * `retryAfter` lido do cabeçalho `Retry-After` quando a Iugu o envia) e o restante
     * `GatewayException`.
     *
     * @param  string  $message
     * @param  string  $detail  texto da resposta, para a mensagem de autenticação
     * @param  mixed  $errors
     * @param  \Throwable|null  $previous
     * @param  int|null  $httpStatus  nulo quando o SDK não informou o status
     * @return MultiPaymentException
     */
    private function classifyIuguFailure(
        string $message,
        string $detail,
        $errors,
        ?\Throwable $previous,
        ?int $httpStatus
    ): MultiPaymentException {
        if (in_array($httpStatus, [401, 403], true)) {
            return AuthenticationException::invalidCredentials('iugu', $detail, $previous, $httpStatus);
        }

        if ($httpStatus >= 500) {
            return new GatewayNotAvailableException($message, $previous, $httpStatus);
        }

        return match ($httpStatus) {
            400, 422 => ValidationException::withFieldErrors(
                $message,
                ValidationException::normalizeFieldErrors($errors ?? $detail),
                $errors,
                $previous,
                $httpStatus
            ),
            404 => new NotFoundException($message, $errors, $previous, $httpStatus),
            409 => IdempotencyConflictException::withResourceId(
                $message,
                $errors,
                $previous,
                $httpStatus,
                self::iuguConflictResourceId($errors ?? $detail)
            ),
            429 => RateLimitException::withRetryAfter($message, $errors, $previous, $httpStatus, $this->lastIuguRetryAfter()),
            default => new GatewayException($message, $errors, $previous, $httpStatus),
        };
    }

    /**
     * Status HTTP da última resposta recebida pelo requester deste driver (JSON ou não), que o
     * SDK grava em `Iugu_APIRequest::$lastResponseCode`. Nulo antes da primeira requisição e
     * quando não houve resposta.
     *
     * @return int|null
     */
    private function lastIuguHttpStatus(): ?int
    {
        $code = $this->apiRequest->lastResponseCode;

        return is_int($code) && $code > 0 ? $code : null;
    }

    /**
     * Segundos do cabeçalho `Retry-After` da última resposta, lidos de
     * `Iugu_APIRequest::$lastResponseHeaders`. Nulo quando ausente ou quando não é um inteiro.
     *
     * @return int|null
     */
    private function lastIuguRetryAfter(): ?int
    {
        $value = $this->apiRequest->lastResponseHeaders['retry-after'] ?? null;
        $value = is_array($value) ? reset($value) : $value;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Id do recurso original numa resposta 409 de chave de idempotência reutilizada. A Iugu
     * responde "Essa chave de idempotência já esta em uso: idempotency_key: ..., resource_id: X",
     * com o id da fatura em fatura e cobrança e `processing` em cliente e assinatura, que aqui
     * vira nulo.
     *
     * @param  mixed  $errors  corpo de `errors` ou texto da resposta
     * @return string|null
     */
    private static function iuguConflictResourceId(mixed $errors): ?string
    {
        $text = is_string($errors) ? $errors : json_encode($errors, JSON_UNESCAPED_UNICODE);
        if (!is_string($text) || !preg_match('/resource_id:\s*([A-Za-z0-9_-]+)/', $text, $match)) {
            return null;
        }

        return strtolower($match[1]) === 'processing' ? null : $match[1];
    }

    /**
     * @inheritDoc
     *
     * A chave de idempotência vai no cabeçalho `Idempotency-Key` de `POST /customers`. Na
     * reutilização da chave a Iugu responde 409 sem o id do cliente original
     * (`resource_id: processing`), que chega como `IdempotencyConflictException`.
     */
    public function createCustomer(Customer $customer, ?string $idempotencyKey = null): Customer
    {
        $iuguCustomer = $this->iuguIdempotentRequest(
            'POST',
            Iugu::getBaseURI() . '/customers',
            $this->customerToIuguData($customer),
            'creating customer',
            $this->idempotencyKeyFor($idempotencyKey, $customer),
            true
        );

        $customer->id = $iuguCustomer->id ?? null;
        $customer->gateway = 'iugu';
        $customer->createdAt = !empty($iuguCustomer->created_at) ? new Carbon($iuguCustomer->created_at) : null;
        $customer->original = $iuguCustomer;

        return $customer;
    }

    /**
     * Converte o status da fatura Iugu no status genérico. Cada status lê como o caso
     * homônimo, com duas exceções: `draft` lê como `PENDING` e `in_analysis` (primeira etapa
     * da cobrança em duas etapas) como `AUTHORIZED`. Status fora do mapa devolve `UNKNOWN` e
     * registra um aviso no log.
     *
     * @param  string|null  $iuguStatus
     *
     * @return InvoiceStatus
     */
    private static function iuguStatusToMultiPayment(?string $iuguStatus): InvoiceStatus
    {
        return match ($iuguStatus) {
            self::STATUS_PENDING, self::STATUS_DRAFT => InvoiceStatus::PENDING,
            self::STATUS_IN_ANALYSIS, self::STATUS_AUTHORIZED => InvoiceStatus::AUTHORIZED,
            self::STATUS_PAID => InvoiceStatus::PAID,
            self::STATUS_PARTIALLY_PAID => InvoiceStatus::PARTIALLY_PAID,
            self::STATUS_EXTERNALLY_PAID => InvoiceStatus::EXTERNALLY_PAID,
            self::STATUS_PARTIALLY_REFUNDED => InvoiceStatus::PARTIALLY_REFUNDED,
            self::STATUS_REFUNDED => InvoiceStatus::REFUNDED,
            self::STATUS_IN_PROTEST => InvoiceStatus::DISPUTED,
            self::STATUS_CHARGEBACK => InvoiceStatus::CHARGEBACK,
            self::STATUS_CANCELED => InvoiceStatus::CANCELED,
            self::STATUS_EXPIRED => InvoiceStatus::EXPIRED,
            default => InvoiceStatus::unknown((string) $iuguStatus, 'iugu'),
        };
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
     * @inheritDoc
     *
     * Salva o cartão no cliente (`POST /customers/{id}/payment_methods`), tokenizando antes os
     * dados crus quando não há token. A Iugu não aceita `Idempotency-Key` nesses endpoints, então
     * a chave passa pela `IdempotencyStore`: a informada no `POST` do cartão e `{chave}:token`
     * na tokenização.
     *
     * @throws GatewayException|ModelAttributeValidationException
     * @throws GatewayNotAvailableException
     */
    public function createCreditCard(CreditCard $creditCard, ?string $idempotencyKey = null): CreditCard
    {
        if (empty($creditCard->customer) || empty($creditCard->customer->id)) {
            throw ModelAttributeValidationException::required('CreditCard', 'customer');
        }
        $idempotencyKey = $this->idempotencyKeyFor($idempotencyKey, $creditCard);
        if (empty($creditCard->token)) {
            $creditCard->token = $this->createIuguPaymentToken(
                $creditCard,
                self::derivedIdempotencyKey($idempotencyKey, 'token')
            );
        }

        $options = [
            'token' => $creditCard->token,
            'description' => $creditCard->description ?? 'CREDIT CARD',
        ];

        if (!empty($creditCard->default)) {
            $options['set_as_default'] = $creditCard->default;
        }

        $iuguCreditCard = $this->iuguIdempotentRequest(
            'POST',
            Iugu::getBaseURI() . '/customers/' . rawurlencode($creditCard->customer->id) . '/payment_methods',
            $options,
            'creating credit card',
            $idempotencyKey
        );

        return $this->parseIuguCard($iuguCreditCard, $creditCard);
    }

    /**
     * @inheritDoc
     *
     * Na Iugu o cartão salvo por `createCreditCard()` já volta cobrável (o Zero Auth só
     * confere a validade do cartão, com uma autorização de valor zero, sem autenticar o
     * portador), então `CARD_SETUP_AUTHENTICATION` fica fora das listas do driver e este
     * método lança sempre `UnsupportedOperationException` com `reason` `gateway_limitation`.
     */
    public function confirmCreditCardSetup(string $setupId, ?string $idempotencyKey = null): CreditCard
    {
        throw UnsupportedOperationException::forGateway(
            $this,
            Capability::CARD_SETUP_AUTHENTICATION,
            'Na Iugu o cartão salvo por createCreditCard() já é cobrável; não há setup a confirmar.'
        );
    }

    /**
     * @inheritDoc
     */
    public function getInvoice(Invoice $invoice): Invoice
    {
        return $this->parseInvoice($this->fetchIuguInvoice((string) $invoice->id, 'getting invoice'), $invoice);
    }

    /**
     * Convert the iugu payment method to the MultiPayment payment method
     *
     * @param  mixed  $iuguPaymentMethod
     *
     * @return PaymentMethod|null
     */
    private function iuguToMultiPaymentPaymentMethod($iuguPaymentMethod): ?PaymentMethod
    {
        if (empty($iuguPaymentMethod) || !is_string($iuguPaymentMethod)) {
            return null;
        }

        // o nome da Iugu carrega o genérico como sufixo (`iugu_credit_card`, `iugu_pix`)
        foreach ([PaymentMethod::PIX, PaymentMethod::BANK_SLIP, PaymentMethod::CREDIT_CARD] as $paymentMethod) {
            if (str_contains($iuguPaymentMethod, $paymentMethod->value)) {
                return $paymentMethod;
            }
        }

        return null;
    }

    /**
     * Converte a lista genérica de métodos de pagamento nos valores de `payable_with` da Iugu.
     *
     * @param  PaymentMethod[]  $paymentMethods
     *
     * @return string[]
     */
    private static function paymentMethodsToIuguPayableWith(array $paymentMethods): array
    {
        return array_values(array_map(
            static fn (PaymentMethod $paymentMethod) => $paymentMethod->value,
            $paymentMethods
        ));
    }

    /**
     * @inheritDoc
     *
     * As guardas de estorno precisam do método de pagamento, do status, da data de pagamento e,
     * no estorno por valor, do valor pago. Um model que traz só o `id` custa um GET a mais para
     * ler a fatura antes do estorno; um model lido do gateway e já pago não paga esse GET. A
     * leitura prévia acontece numa cópia: o model do chamador só é alterado se o estorno
     * acontecer. A Iugu não devolve um objeto de estorno, então o `Refund` é montado pela lib:
     * sem id, com o valor pedido (ou, no estorno integral, o `paid_cents` anterior ao estorno,
     * que a Iugu devolve líquido do já estornado) e status `SUCCEEDED`, porque a Iugu só
     * responde 200 com o estorno feito.
     *
     * A Iugu não aceita `Idempotency-Key` no estorno, então com chave a operação inteira
     * (leitura prévia, guardas e `POST /refund`) passa pela `IdempotencyStore`: a chamada
     * seguinte com a mesma chave devolve o `Refund` da primeira sem reler a fatura, que a essa
     * altura já estaria estornada e faria a guarda recusar o retry.
     *
     * O valor vem de `$amount`; sem ele, do caminho antigo de escrever `refundedAmount` antes
     * de estornar (`Invoice::resolveRefundAmount()`); sem os dois, estorna o restante.
     *
     * @throws ModelAttributeValidationException|RefundNotSupportedException
     */
    public function refundInvoice(Invoice $invoice, ?int $amount = null, ?string $idempotencyKey = null): Refund
    {
        if (empty($invoice->id)) {
            throw ModelAttributeValidationException::required('Invoice', 'id');
        }
        $requestedAmount = $invoice->resolveRefundAmount($amount);
        $idempotencyKey = $this->idempotencyKeyFor($idempotencyKey, $invoice);

        if (is_null($idempotencyKey)) {
            return $this->performIuguRefund($invoice, $requestedAmount);
        }

        return $this->rememberIuguOperation(
            $idempotencyKey,
            'POST ' . Iugu::getBaseURI() . '/invoices/' . rawurlencode($invoice->id) . '/refund',
            fn () => $this->performIuguRefund($invoice, $requestedAmount)
        );
    }

    /**
     * @inheritDoc
     *
     * Na Iugu o restante é `paid_cents`, que a API devolve líquido do que já foi estornado
     * (`Invoice::$paidAmount`); fatura paga com boleto devolve zero, porque `refundInvoice()`
     * a recusa (`REFUND_BANK_SLIP` é limitação do gateway). A fatura é lida quando o model não
     * traz o valor pago ou o método de pagamento.
     */
    public function refundableAmount(Invoice $invoice): int
    {
        if (empty($invoice->id)) {
            throw ModelAttributeValidationException::required('Invoice', 'id');
        }

        $current = is_null($invoice->paidAmount) || is_null($invoice->paymentMethod)
            ? $this->getInvoice(clone $invoice)
            : $invoice;

        return self::iuguRefundableAmount($current);
    }

    /**
     * Restante estornável de uma fatura já lida: `paid_cents`, líquido do estornado; zero
     * quando nada foi pago e para fatura paga com boleto, que o estorno recusa.
     *
     * @param  Invoice  $invoice
     * @return int
     */
    private static function iuguRefundableAmount(Invoice $invoice): int
    {
        if ($invoice->paymentMethod === PaymentMethod::BANK_SLIP) {
            return 0;
        }

        return max(0, (int) ($invoice->paidAmount ?? 0));
    }

    /**
     * Lê a fatura quando o model não traz o que as guardas precisam, aplica as guardas e faz o
     * `POST /refund`, devolvendo o `Refund` montado pela lib.
     *
     * @param  Invoice  $invoice
     * @param  int|null  $requestedAmount  valor pedido em centavos; nulo é estorno do restante
     * @return Refund
     * @throws RefundNotSupportedException
     */
    private function performIuguRefund(Invoice $invoice, ?int $requestedAmount): Refund
    {
        $current = $invoice;
        if (
            empty($invoice->paymentMethod)
            || empty($invoice->status)
            || is_null($invoice->paidAt)
            || (!is_null($requestedAmount) && is_null($invoice->paidAmount))
        ) {
            $current = $this->getInvoice(clone $invoice);
        }

        $this->assertInvoiceIsRefundable($current, $requestedAmount);

        $data = [];
        // valor igual ao pago é estorno integral e vai sem partial_value_refund_cents; assim o
        // Pix, que só aceita integral, não é recusado por um "parcial" do valor cheio
        if (!is_null($requestedAmount) && $requestedAmount !== $current->paidAmount) {
            $data['partial_value_refund_cents'] = $requestedAmount;
        }
        // o estorno integral devolve o paid_cents anterior, que parseInvoice() vai sobrescrever
        $refundableBefore = $current->paidAmount;

        $url = Iugu::getBaseURI() . '/invoices/' . rawurlencode($invoice->id) . '/refund';
        $iuguInvoice = $this->iuguRequest('POST', $url, $data, 'refunding invoice');

        $invoice = $this->parseInvoice($iuguInvoice, $invoice);

        $refund = new Refund();
        $refund->invoiceId = $invoice->id;
        $refund->amount = $requestedAmount ?? $refundableBefore ?? $invoice->refundedAmount;
        $refund->status = RefundStatus::SUCCEEDED;
        $refund->createdAt = Carbon::now();
        $refund->gateway = 'iugu';
        $refund->invoice = $invoice;

        return $refund;
    }

    /**
     * Lança antes da rede quando a Iugu certamente recusaria o estorno: boleto não tem estorno
     * pela API, fatura em `refunded` é terminal, o valor pedido não pode passar do que resta
     * (`paid_cents`, que a Iugu já devolve líquido do que foi estornado), Pix só estorna o valor
     * integral e o prazo de estorno termina no fim do 90º dia após o pagamento.
     *
     * @param  Invoice  $invoice
     * @param  int|null  $requestedAmount  valor pedido em centavos; nulo é estorno integral
     * @return void
     * @throws RefundNotSupportedException
     */
    private function assertInvoiceIsRefundable(Invoice $invoice, ?int $requestedAmount): void
    {
        if ($invoice->paymentMethod === PaymentMethod::BANK_SLIP) {
            throw RefundNotSupportedException::boletoNoRefund('iugu');
        }

        if ($invoice->status === InvoiceStatus::REFUNDED) {
            throw RefundNotSupportedException::alreadyRefunded('iugu', $invoice->paymentMethod?->value);
        }

        if (!is_null($requestedAmount) && !is_null($invoice->paidAmount) && $requestedAmount > self::iuguRefundableAmount($invoice)) {
            throw RefundNotSupportedException::amountExceedsRefundable(
                'iugu',
                $invoice->paymentMethod?->value,
                $requestedAmount,
                self::iuguRefundableAmount($invoice)
            );
        }

        if (
            $invoice->paymentMethod === PaymentMethod::PIX
            && !is_null($requestedAmount)
            && $requestedAmount !== $invoice->paidAmount
        ) {
            throw RefundNotSupportedException::pixPartialNotSupported('iugu', $requestedAmount, $invoice->paidAmount);
        }

        // a Iugu conta o prazo em dias; até o fim do 90º dia a chamada segue e a API decide
        if (
            !is_null($invoice->paidAt)
            && $invoice->paidAt->copy()->addDays(self::REFUND_WINDOW_DAYS)->endOfDay()->isPast()
        ) {
            throw RefundNotSupportedException::refundWindowExpired(
                'iugu',
                $invoice->paymentMethod?->value,
                $invoice->paidAt,
                self::REFUND_WINDOW_DAYS
            );
        }
    }

    /**
     * @inheritDoc
     *
     * A chave de idempotência passa pela `IdempotencyStore` (a Iugu não aceita o cabeçalho
     * neste endpoint).
     */
    public function cancelInvoice(Invoice $invoice, ?string $idempotencyKey = null): Invoice
    {
        $url = Iugu::getBaseURI() . '/invoices/' . rawurlencode((string) $invoice->id) . '/cancel';

        $response = $this->iuguIdempotentRequest(
            'PUT',
            $url,
            [],
            'cancelling invoice',
            $this->idempotencyKeyFor($idempotencyKey, $invoice)
        );

        return $this->parseInvoice($response, $invoice);
    }

    /**
     * @inheritDoc
     *
     * A chave de idempotência passa pela `IdempotencyStore` (a Iugu não aceita o cabeçalho
     * neste endpoint).
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
        $idempotencyKey = $this->idempotencyKeyFor($idempotencyKey, $invoice, $gatewayOptions);

        $params = array_merge(self::withoutIdempotencyKey($gatewayOptions), [
            'due_date' => $expiresAt->format('Y-m-d'),
        ]);

        $iuguInvoice = $this->iuguIdempotentRequest(
            'POST',
            Iugu::getBaseURI() . '/invoices/' . rawurlencode($invoice->id) . '/duplicate',
            $params,
            'duplicating invoice',
            $idempotencyKey
        );

        return $this->parseInvoice($iuguInvoice);
    }

    /**
     * @inheritDoc
     *
     * A chave de idempotência passa pela `IdempotencyStore`.
     */
    public function rescheduleAutomaticPixPayment(Invoice $invoice, ?string $idempotencyKey = null): Invoice
    {
        if (empty($invoice->id)) {
            throw ModelAttributeValidationException::required('Invoice', 'id');
        }

        $url = Iugu::getBaseURI() . '/invoices/' . rawurlencode($invoice->id)
            . '/reschedule_automatic_pix_payment';
        $response = $this->iuguIdempotentRequest(
            'POST',
            $url,
            [],
            'rescheduling automatic pix payment',
            $this->idempotencyKeyFor($idempotencyKey, $invoice)
        );

        if (!empty($response->id) && !empty($response->status) && isset($response->total_cents)) {
            return $this->parseInvoice($response, $invoice);
        }

        $invoice->gateway = 'iugu';
        $invoice->originType = InvoiceOriginType::INVOICE;
        $invoice->original = $response;

        return $invoice;
    }

    /**
     * @inheritDoc
     *
     * A chave de idempotência passa pela `IdempotencyStore`.
     */
    public function cancelAutomaticPixRecurrence(
        AutomaticPix $automaticPix,
        ?string $idempotencyKey = null
    ): AutomaticPixCancellation {
        if (empty($automaticPix->id)) {
            throw ModelAttributeValidationException::required('AutomaticPix', 'id');
        }

        $url = Iugu::getBaseURI() . '/automatic_pix/receiver_recurrences/'
            . rawurlencode($automaticPix->id) . '/cancel';
        $response = $this->iuguIdempotentRequest(
            'PUT',
            $url,
            [],
            'cancelling automatic pix recurrence',
            $this->idempotencyKeyFor($idempotencyKey, $automaticPix)
        );

        $cancellation = $this->parseAutomaticPixCancellation($response);
        $cancellation->recurrenceId = $automaticPix->id;
        $cancellation->status ??= AutomaticPixCancellation::STATUS_REQUESTED;

        return $cancellation;
    }

    /**
     * @inheritDoc
     *
     * A chave de idempotência passa pela `IdempotencyStore`.
     */
    public function cancelAutomaticPixScheduledPayment(
        AutomaticPixCharge $charge,
        ?string $idempotencyKey = null
    ): AutomaticPixCancellation {
        if (empty($charge->id)) {
            throw ModelAttributeValidationException::required('AutomaticPixCharge', 'id');
        }
        if (empty($charge->endToEndId)) {
            throw ModelAttributeValidationException::required('AutomaticPixCharge', 'endToEndId');
        }

        $query = http_build_query([
            'receiver_recurrence_payment_id' => $charge->id,
            'end_to_end_id' => $charge->endToEndId,
        ], '', '&', PHP_QUERY_RFC3986);
        $url = Iugu::getBaseURI() . '/automatic_pix/receiver_recurrence_payments/cancel?' . $query;
        $response = $this->iuguIdempotentRequest(
            'POST',
            $url,
            [],
            'cancelling automatic pix scheduled payment',
            $this->idempotencyKeyFor($idempotencyKey, $charge)
        );

        $cancellation = $this->parseAutomaticPixCancellation($response);
        $cancellation->paymentId ??= $charge->id;
        $cancellation->endToEndId ??= $charge->endToEndId;
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
        $response = $this->iuguRequest('GET', $url, [], 'getting automatic pix cancellation');

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
            throw ModelAttributeValidationException::invalid('AutomaticPix', 'page', 'Automatic Pix cancellation page must be at least 1');
        }
        if ($limit < 1 || $limit > 100) {
            throw ModelAttributeValidationException::invalid('AutomaticPix', 'limit', 'Automatic Pix cancellation limit must be between 1 and 100');
        }

        $query = http_build_query(['limit' => $limit, 'page' => $page], '', '&', PHP_QUERY_RFC3986);
        $url = Iugu::getBaseURI() . '/automatic_pix/receiver_recurrences/'
            . rawurlencode($automaticPix->id) . '/cancellations?' . $query;
        $response = $this->iuguRequest('GET', $url, [], 'listing automatic pix cancellations');

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

        if (!empty($automaticPix->id)) {
            return ['receiver_recurrence_id' => $automaticPix->id];
        }

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
     * Convert the gateway-neutral charge model into Iugu invoice fields.
     */
    private function automaticPixChargeToIuguData(AutomaticPixCharge $charge): array
    {
        return array_filter([
            'pix_remittance_info' => $charge->description,
        ], static fn ($value) => !is_null($value));
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
     * Convert Iugu scheduled charge fields into the gateway-neutral model.
     */
    private function parseAutomaticPixCharge(
        $data,
        ?AutomaticPixCharge $charge = null,
        ?string $recurrenceId = null,
        ?string $description = null
    ): AutomaticPixCharge {
        $data = (object) $data;
        $charge ??= new AutomaticPixCharge();
        $charge->id = $data->receiver_recurrence_payment_id ?? $data->id ?? $charge->id;
        $charge->recurrenceId = $recurrenceId ?? $charge->recurrenceId;
        $charge->endToEndId = $data->receiver_recurrence_payment_end_to_end_id
            ?? $data->end_to_end_id
            ?? $charge->endToEndId;
        $charge->description = $description ?? $data->description ?? $charge->description;
        $charge->amount = $data->amount ?? $charge->amount;
        $charge->scheduledAt = !empty($data->scheduled_payment_at)
            ? new Carbon($data->scheduled_payment_at)
            : $charge->scheduledAt;
        $charge->status = $data->status ?? $charge->status;
        $charge->gateway = 'iugu';
        $charge->original = $data;

        return $charge;
    }

    /**
     * Executa uma requisição à Iugu pelo requester do driver e traduz a falha (exceção do SDK,
     * corpo com `errors` ou `success` falso) para a hierarquia do pacote.
     *
     * @param  string  $method
     * @param  string  $url
     * @param  array  $data
     * @param  string  $operation  descrição da operação, em inglês, para a mensagem
     * @param  array  $headers  cabeçalhos extras, no formato 'Nome: valor'
     * @return object|array
     * @throws MultiPaymentException
     */
    private function iuguRequest(
        string $method,
        string $url,
        array $data,
        string $operation,
        array $headers = []
    ): object|array {
        try {
            $response = $this->apiRequest->request($method, $url, $data, $headers);
        } catch (\Exception $e) {
            throw $this->translateIuguException($e, $operation);
        }

        $responseObject = is_array($response) ? (object) $response : $response;
        if (
            !empty($responseObject->errors)
            || (isset($responseObject->success) && $responseObject->success !== true)
        ) {
            throw $this->iuguResponseException("Error {$operation}", (array) ($responseObject->errors ?? []));
        }

        return $response;
    }

    /**
     * Executa uma requisição de escrita com chave de idempotência. Nos endpoints em que a Iugu
     * aceita o cabeçalho (`$nativeSupport`: criar fatura, cliente, assinatura e cobrança direta)
     * a chave vai em `Idempotency-Key`; nos demais, a requisição passa pela `IdempotencyStore`,
     * que devolve a resposta guardada nas chamadas seguintes com a mesma chave. Sem chave, é
     * uma requisição comum.
     *
     * Na reutilização de uma chave, a Iugu responde 409 com o id do recurso original em
     * `resource_id` (fatura e cobrança); com `$fetchOriginal`, o driver lê esse recurso e o
     * devolve no lugar da exceção, para a segunda chamada ter o mesmo resultado da primeira.
     *
     * @param  string  $method
     * @param  string  $url
     * @param  array  $data
     * @param  string  $operation  descrição da operação, em inglês, para a mensagem
     * @param  string|null  $idempotencyKey
     * @param  bool  $nativeSupport  a Iugu aceita `Idempotency-Key` neste endpoint
     * @param  \Closure|null  $fetchOriginal  recebe o `resource_id` do 409 e devolve o recurso original
     * @return object|array
     * @throws MultiPaymentException
     */
    private function iuguIdempotentRequest(
        string $method,
        string $url,
        array $data,
        string $operation,
        ?string $idempotencyKey,
        bool $nativeSupport = false,
        ?\Closure $fetchOriginal = null
    ): object|array {
        if (is_null($idempotencyKey)) {
            return $this->iuguRequest($method, $url, $data, $operation);
        }

        if ($nativeSupport) {
            try {
                return $this->iuguRequest($method, $url, $data, $operation, ['Idempotency-Key: ' . $idempotencyKey]);
            } catch (IdempotencyConflictException $e) {
                if (is_null($fetchOriginal) || is_null($e->resourceId)) {
                    throw $e;
                }

                return $fetchOriginal($e->resourceId);
            }
        }

        return $this->rememberIuguOperation(
            $idempotencyKey,
            $method . ' ' . $url,
            fn () => $this->iuguRequest($method, $url, $data, $operation)
        );
    }

    /**
     * Executa a operação uma única vez por chave pela `IdempotencyStore` e devolve o resultado
     * guardado nas chamadas seguintes. O resultado é guardado junto com a assinatura da
     * operação (método e url, ou nome da operação); a mesma chave reaparecendo em outra
     * operação lança `IdempotencyConflictException` em vez de devolver o resultado errado.
     *
     * @param  string  $idempotencyKey
     * @param  string  $fingerprint  identifica a operação que a chave cobre
     * @param  \Closure  $operation
     * @return mixed
     * @throws IdempotencyConflictException
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     */
    private function rememberIuguOperation(string $idempotencyKey, string $fingerprint, \Closure $operation): mixed
    {
        $stored = $this->idempotencyStore()->remember(
            self::IDEMPOTENCY_STORE_PREFIX . $idempotencyKey,
            fn () => ['fingerprint' => $fingerprint, 'result' => $operation()],
            ConfigurationHelper::idempotencyTtl()
        );

        if (!is_array($stored) || ($stored['fingerprint'] ?? null) !== $fingerprint) {
            throw IdempotencyConflictException::reusedOnAnotherOperation($idempotencyKey);
        }

        return $stored['result'];
    }

    /**
     * `IdempotencyStore` deste driver: a injetada no construtor ou a registrada no container,
     * resolvida na primeira vez que uma operação precisa dela.
     *
     * @return IdempotencyStore
     * @throws \Potelo\MultiPayment\Exceptions\ConfigurationException
     */
    private function idempotencyStore(): IdempotencyStore
    {
        return $this->idempotencyStore ??= ConfigurationHelper::resolveIdempotencyStore();
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
     * Monta a lista de estornos da fatura a partir de `refunded_cents`. A Iugu não lista os
     * estornos nem os identifica, então a lista tem no máximo um `Refund`, sem id, com o
     * acumulado estornado; sem estorno a lista é vazia.
     *
     * @param  Invoice  $invoice  fatura já parseada, com `id`, `refundedAmount` e `paidAt`
     * @return Refund[]
     */
    private function parseRefunds(Invoice $invoice): array
    {
        if (empty($invoice->refundedAmount)) {
            return [];
        }

        $refund = new Refund();
        $refund->invoiceId = $invoice->id;
        $refund->amount = $invoice->refundedAmount;
        $refund->status = RefundStatus::SUCCEEDED;
        $refund->gateway = 'iugu';

        return [$refund];
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

        // leituras com `??`: a resposta é stdClass do request cru, que avisa em campo ausente
        $iuguInvoice = (object) $iuguInvoice;
        $invoice->id = $iuguInvoice->id ?? null;
        $invoice->gateway = 'iugu';
        $invoice->originType = InvoiceOriginType::INVOICE;
        $invoice->status = self::iuguStatusToMultiPayment($iuguInvoice->status ?? null);
        $invoice->amount = $iuguInvoice->total_cents ?? null;
        $invoice->paidAt = !empty($iuguInvoice->paid_at) ? new Carbon($iuguInvoice->paid_at) : null;
        $invoice->url = $iuguInvoice->secure_url ?? null;
        $invoice->fee = $iuguInvoice->taxes_paid_cents ?? null;
        $invoice->original = $iuguInvoice;
        $invoice->createdAt = !empty($iuguInvoice->created_at_iso) ? new Carbon($iuguInvoice->created_at_iso) : null;
        $invoice->paidAmount = $iuguInvoice->paid_cents ?? null;
        $invoice->setRefundedAmountFromGateway($iuguInvoice->refunded_cents ?? null);
        $invoice->refunds = $this->parseRefunds($invoice);
        // a Iugu só opera BRL
        $invoice->currency = $iuguInvoice->currency ?? 'BRL';
        // a Iugu não documenta o campo do LR na fatura; o driver aceita os formatos dos
        // outros canais (`LR`, `lr` e o trecho `LR: xx` das mensagens)
        $invoice->lastPaymentError = self::parseIuguPaymentError(IuguDeclineCodes::extractLr($iuguInvoice));
        $invoice->dueDate = !empty($iuguInvoice->due_date) ? new Carbon($iuguInvoice->due_date) : null;
        // a Iugu não documenta a expiração do QR Code na fatura; quando vier, ela vale, senão
        // fica o que o model já tinha
        $invoice->pixExpiresAt = !empty($iuguInvoice->pix_qr_code_expires_at)
            ? new Carbon($iuguInvoice->pix_qr_code_expires_at)
            : $invoice->pixExpiresAt;

        // a resposta manda: o método pedido na escrita só fica enquanto a Iugu não informa o
        // método com que a fatura foi paga
        $invoice->paymentMethod = $this->iuguToMultiPaymentPaymentMethod($iuguInvoice->payment_method ?? null)
            ?? $invoice->paymentMethod;

        if (!empty($iuguInvoice->payable_with)) {
            $invoice->availablePaymentMethods = $this->iuguPayableWithToPaymentMethods($iuguInvoice->payable_with);
        }

        if (empty($invoice->customer)) {
            $invoice->customer = new Customer();
        }

        $invoice->customer->id = $iuguInvoice->customer_id ?? null;
        $invoice->customer->name = $iuguInvoice->customer_name ?? null;
        $invoice->customer->email = $iuguInvoice->email ?? null;
        $invoice->customer->phoneNumber = $iuguInvoice->payer_phone ?? null;
        $invoice->customer->phoneArea = $iuguInvoice->payer_phone_prefix ?? null;

        $invoice->items = [];

        foreach ((array) ($iuguInvoice->items ?? []) as $itemIugu) {
            $invoiceItem = new InvoiceItem();
            $itemIugu = (object) $itemIugu;
            $invoiceItem->description = $itemIugu->description ?? null;
            $invoiceItem->price = $itemIugu->price_cents ?? null;
            $invoiceItem->quantity = $itemIugu->quantity ?? null;
            $invoice->items[] = $invoiceItem;
        }

        if (!empty($iuguInvoice->payer_address_zip_code)) {
            if (empty($invoice->customer->address)) {
                $invoice->customer->address = new Address();
            }
            $invoice->customer->address->zipCode = $iuguInvoice->payer_address_zip_code;
            $invoice->customer->address->street = $iuguInvoice->payer_address_street ?? null;
            $invoice->customer->address->number = $iuguInvoice->payer_address_number ?? null;
            $invoice->customer->address->district = $iuguInvoice->payer_address_district ?? null;
            $invoice->customer->address->city = $iuguInvoice->payer_address_city ?? null;
            $invoice->customer->address->state = $iuguInvoice->payer_address_state ?? null;
            $invoice->customer->address->complement = $iuguInvoice->payer_address_complement ?? null;
            $invoice->customer->address->country = $iuguInvoice->payer_address_country ?? null;
        }

        if (!empty($iuguInvoice->bank_slip)) {
            if (empty($invoice->bankSlip)) {
                $invoice->bankSlip = new BankSlip();
            }
            $bankSlip = (object) $iuguInvoice->bank_slip;
            $invoice->bankSlip->url = ($iuguInvoice->secure_url ?? '') . '.pdf';
            $invoice->bankSlip->number = $bankSlip->digitable_line ?? null;
            $invoice->bankSlip->barcodeData = $bankSlip->barcode_data ?? null;
            $invoice->bankSlip->barcodeImage = $bankSlip->barcode ?? null;
        }

        if (!empty($iuguInvoice->pix)) {
            if (empty($invoice->pix)) {
                $invoice->pix = new Pix();
            }
            $pix = (object) $iuguInvoice->pix;
            $invoice->pix->qrCodeImageUrl = $pix->qrcode ?? null;
            $invoice->pix->qrCodeText = $pix->qrcode_text ?? null;
        }

        if (!empty($iuguInvoice->automatic_pix)) {
            $invoice->automaticPix = $this->parseAutomaticPix(
                $iuguInvoice->automatic_pix,
                $invoice->automaticPix
            );

            $automaticPix = (object) $iuguInvoice->automatic_pix;
            if (!empty($automaticPix->recurrence_receiver_payment)) {
                $invoice->automaticPixCharge = $this->parseAutomaticPixCharge(
                    $automaticPix->recurrence_receiver_payment,
                    $invoice->automaticPixCharge,
                    $invoice->automaticPix->id,
                    $iuguInvoice->pix_remittance_info ?? null
                );
            }
        }

        if (!empty($iuguInvoice->credit_card_transaction)) {
            if (empty($invoice->creditCard)) {
                $invoice->creditCard = new CreditCard();
            }
            $transaction = (object) $iuguInvoice->credit_card_transaction;
            $invoice->creditCard->brand = $iuguInvoice->credit_card_brand ?? null;
            $invoice->creditCard->lastDigits = $iuguInvoice->credit_card_last_4 ?? $transaction->last4 ?? null;

            $holderName = null;
            foreach ((array) ($iuguInvoice->variables ?? []) as $iuguInvoiceVariable) {
                $iuguInvoiceVariable = (object) $iuguInvoiceVariable;
                $variableName = $iuguInvoiceVariable->variable ?? null;
                if ($variableName == 'payment_data.holder_name') {
                    $holderName = $iuguInvoiceVariable->value ?? null;
                } else if (empty($invoice->creditCard->lastDigits) && $variableName == 'payment_data.display_number') {
                    $invoice->creditCard->lastDigits = substr((string) ($iuguInvoiceVariable->value ?? ''), -4);
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

        $iuguInvoiceData = [];
        $iuguInvoiceData['invoice_id'] = $invoice->id;

        if (!empty($invoice->creditCard->id)) {
            $iuguInvoiceData['customer_payment_method_id'] = $invoice->creditCard->id;
        } else {
            $iuguInvoiceData['token'] = $invoice->creditCard->token;
        }

        $iuguInvoice = $this->chargeIuguInvoice($iuguInvoiceData, $this->idempotencyKeyFor($idempotencyKey, $invoice));

        return $this->parseInvoice($iuguInvoice, $invoice);
    }

    /**
     * Cobra pela cobrança direta da Iugu (`POST /charge`, com a chave de idempotência no
     * cabeçalho `Idempotency-Key`) e lê a fatura cobrada, que a resposta só identifica pelo id.
     * Recusa de cartão (`success` falso) vira `ChargingException`; chave reutilizada (409 com
     * `resource_id`) devolve a fatura da primeira cobrança.
     *
     * @param  array  $iuguInvoiceData
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     * @return object
     * @throws \Potelo\MultiPayment\Exceptions\ChargingException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayException
     * @throws \Potelo\MultiPayment\Exceptions\GatewayNotAvailableException
     * @throws \Potelo\MultiPayment\Exceptions\AuthenticationException
     */
    private function chargeIuguInvoice(array $iuguInvoiceData, ?string $idempotencyKey): object
    {
        $headers = is_null($idempotencyKey) ? [] : ['Idempotency-Key: ' . $idempotencyKey];

        try {
            $iuguCharge = $this->apiRequest->request('POST', Iugu::getBaseURI() . '/charge', $iuguInvoiceData, $headers);
        } catch (\Exception $e) {
            throw $this->translateIuguException($e, 'charging invoice');
        }

        $iuguCharge = is_array($iuguCharge) ? (object) $iuguCharge : $iuguCharge;
        if (!empty($iuguCharge->errors)) {
            $exception = $this->iuguResponseException('Error charging invoice', $iuguCharge->errors);
            // chave reutilizada: a Iugu aponta a fatura da primeira cobrança, que é o resultado
            if ($exception instanceof IdempotencyConflictException && !is_null($exception->resourceId)) {
                return $this->fetchIuguInvoice($exception->resourceId, 'getting charged invoice');
            }

            throw $exception;
        }
        if (empty($iuguCharge->success)) {
            throw $this->cardDeclined($iuguCharge);
        }

        // a cobrança devolve só o id; a leitura da fatura é outra requisição e falha como tal
        $invoiceId = $iuguCharge->invoice_id ?? null;
        if (empty($invoiceId)) {
            throw $this->iuguResponseException('Error getting charged invoice: the charge response has no invoice_id', null);
        }

        return $this->fetchIuguInvoice((string) $invoiceId, 'getting charged invoice');
    }

    /**
     * Traduz uma cobrança recusada (`success` falso em `POST /v1/charge`) para
     * `ChargingException`, com o LR lido da resposta e traduzido para `DeclineCode`. LR fora da
     * tabela vira `DeclineCode::UNKNOWN`, com o código preservado em `gatewayCode` e registro em
     * nível `info`; resposta sem LR também vira `UNKNOWN`, sem registro.
     *
     * @param  object  $iuguCharge  resposta da cobrança
     * @return ChargingException
     */
    private function cardDeclined(object $iuguCharge): ChargingException
    {
        $lr = IuguDeclineCodes::extractLr($iuguCharge);
        $declineCode = self::declineCodeFromLr($lr);

        $detail = $iuguCharge->info_message ?? $iuguCharge->message ?? '';
        $exception = ChargingException::declined(
            'iugu',
            $declineCode,
            $lr,
            is_string($detail) ? $detail : '',
            null,
            $this->lastIuguHttpStatus()
        );
        $exception->chargeResponse = $iuguCharge;

        return $exception;
    }

    /**
     * Traduz o código LR para `DeclineCode`, no vocabulário do pacote. LR fora da tabela
     * devolve `UNKNOWN` com registro em nível `info`; sem LR, `UNKNOWN` sem registro.
     *
     * @param  string|null  $lr
     * @return DeclineCode
     */
    private static function declineCodeFromLr(?string $lr): DeclineCode
    {
        $declineCode = IuguDeclineCodes::toDeclineCode($lr);
        if (!is_null($declineCode)) {
            return $declineCode;
        }

        if (!is_null($lr)) {
            LogHelper::info('Código LR da Iugu sem tradução para DeclineCode', ['gateway' => 'iugu', 'lr' => $lr]);
        }

        return DeclineCode::UNKNOWN;
    }

    /**
     * Converte o código LR que a fatura lida expuser num `PaymentError`, com o mesmo
     * mapeamento de `DeclineCode` da recusa síncrona; nulo quando a fatura não traz LR. A Iugu
     * não informa mensagem nem instante da tentativa recusada na fatura, então `message`,
     * `occurredAt` e `retryable` ficam nulos.
     *
     * @param  string|null  $lr
     * @return PaymentError|null
     */
    private static function parseIuguPaymentError(?string $lr): ?PaymentError
    {
        if (is_null($lr)) {
            return null;
        }

        $error = new PaymentError();
        $error->declineCode = self::declineCodeFromLr($lr);
        $error->gatewayCode = $lr;
        $error->gateway = 'iugu';

        return $error;
    }

    /**
     * @inheritDoc
     */
    public function getCustomer(Customer $customer): Customer
    {
        $iuguCustomer = $this->iuguRequest(
            'GET',
            Iugu::getBaseURI() . '/customers/' . rawurlencode((string) $customer->id),
            [],
            'getting customer'
        );

        return $this->parseCustomer($iuguCustomer, $customer);
    }

    /**
     * @inheritDoc
     *
     * A chave de idempotência passa pela `IdempotencyStore` (a Iugu não aceita o cabeçalho
     * neste endpoint).
     */
    public function updateCustomer(Customer $customer, ?string $idempotencyKey = null): Customer
    {
        if (empty($customer->id)) {
            throw ModelAttributeValidationException::required('Customer', 'id');
        }

        $iuguCustomer = $this->iuguIdempotentRequest(
            'PUT',
            Iugu::getBaseURI() . '/customers/' . rawurlencode($customer->id),
            $this->customerToIuguData($customer),
            'updating customer',
            $this->idempotencyKeyFor($idempotencyKey, $customer)
        );

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
        $iuguCustomer = (object) $iuguCustomer;

        $valuesInsideCustomVariables = ['birth_date' => null, 'country' => null];

        if (!empty($iuguCustomer->custom_variables)) {
            foreach ($iuguCustomer->custom_variables as $variable) {
                if (in_array($variable->name, array_keys($valuesInsideCustomVariables))) {
                    $valuesInsideCustomVariables[$variable->name] = $variable->value;
                }
            }
        }

        // leituras com `??`: a resposta pode ser stdClass (request cru), que avisa em campo ausente
        $customer->id = $iuguCustomer->id ?? null;
        $customer->name = $iuguCustomer->name ?? null;
        $customer->email = $iuguCustomer->email ?? null;
        $customer->taxDocument = $iuguCustomer->cpf_cnpj ?? null;
        $customer->phoneNumber = $iuguCustomer->phone ?? null;
        $customer->phoneArea = $iuguCustomer->phone_prefix ?? null;
        $customer->birthDate = !empty($valuesInsideCustomVariables['birth_date'])
            ? Carbon::createFromFormat('Y-m-d', $valuesInsideCustomVariables['birth_date'])
            : null;
        $customer->gateway = 'iugu';
        // o recurso de cliente da Iugu devolve `created_at` (a fatura é que tem `created_at_iso`)
        $createdAt = $iuguCustomer->created_at ?? $iuguCustomer->created_at_iso ?? null;
        $customer->createdAt = !empty($createdAt) ? new Carbon($createdAt) : null;
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

        foreach (self::withoutIdempotencyKey($customer->gatewayOptions) as $option => $value) {
            $iuguCustomerData[$option] = $value;
        }

        if (!empty($customer->defaultCard) && !empty($customer->defaultCard->id)) {
            $iuguCustomerData['default_payment_method_id'] = $customer->defaultCard->id;
        }

        return $iuguCustomerData;
    }

    /**
     * @inheritDoc
     */
    public function setCustomerDefaultCard(Customer $customer, string $cardId, ?string $idempotencyKey = null): Customer
    {
        $customer->defaultCard = new CreditCard();
        $customer->defaultCard->id = $cardId;

        return $this->updateCustomer($customer, $idempotencyKey);
    }

    /**
     * @inheritDoc
     *
     * A chave de idempotência passa pela `IdempotencyStore`.
     */
    public function deleteCreditCard(CreditCard $creditCard, ?string $idempotencyKey = null): void
    {
        if (empty($creditCard->id)) {
            throw ModelAttributeValidationException::required('CreditCard', 'id');
        }
        if (empty($creditCard->customer) || empty($creditCard->customer->id)) {
            throw ModelAttributeValidationException::required('CreditCard', 'customer');
        }

        $this->iuguIdempotentRequest(
            'DELETE',
            Iugu::getBaseURI() . '/customers/' . rawurlencode($creditCard->customer->id)
                . '/payment_methods/' . rawurlencode($creditCard->id),
            [],
            'deleting credit card',
            $this->idempotencyKeyFor($idempotencyKey, $creditCard)
        );
    }

    /**
     * @inheritDoc
     */
    public function getCreditCard(CreditCard $creditCard): CreditCard
    {
        if (empty($creditCard->customer) || empty($creditCard->customer->id)) {
            throw ModelAttributeValidationException::required('CreditCard', 'customer');
        }

        $iuguCreditCard = $this->iuguRequest(
            'GET',
            Iugu::getBaseURI() . '/customers/' . rawurlencode($creditCard->customer->id)
                . '/payment_methods/' . rawurlencode((string) $creditCard->id),
            [],
            'getting credit card'
        );

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
        $iuguCreditCard = (object) $iuguCreditCard;

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
        $displayNumber = $iuguCreditCard->data->display_number ?? null;
        $creditCard->lastDigits = $iuguCreditCard->data->last_digits
            ?? (is_string($displayNumber) ? substr($displayNumber, -4) : null);
        $creditCard->gateway = 'iugu';
        $creditCard->original = $iuguCreditCard;
        $creditCard->createdAt = !empty($iuguCreditCard->created_at_iso) ? new Carbon($iuguCreditCard->created_at_iso) : null;
        return $creditCard;
    }

    /**
     * @inheritDoc
     *
     * `payable_with` vem de `availablePaymentMethods` ou, com ela vazia, de `paymentMethod`
     * (cartão quando só `creditCard` foi informado); o cartão informado vira o padrão do
     * cliente antes da criação, porque a assinatura da Iugu cobra o cartão padrão. `trialDays`
     * vira `trialEndsAt` contado de hoje, e um trial (`trialEndsAt`) vai como `expires_at` com
     * `only_charge_on_due_date`, para o primeiro ciclo só ser cobrado no fim do teste;
     * `nextBillingAt` sozinho vai só como `expires_at`. Desconto com validade (`validUntil`,
     * ou `cycles` acima de 1, convertido pela duração do plano) grava
     * `mp_discount_<subitem_id>_until` em `custom_variables` numa segunda requisição (`PUT`,
     * chave derivada `{chave}:discounts`); o comando `multipayment:sync-subscriptions` remove
     * o subitem quando a data passa. A chave de idempotência vai no cabeçalho
     * `Idempotency-Key` de `POST /subscriptions`. Na reutilização da chave a Iugu responde 409
     * sem o id da assinatura original (`resource_id: processing`), que chega como
     * `IdempotencyConflictException`.
     */
    public function createSubscription(Subscription $subscription, ?string $idempotencyKey = null): Subscription
    {
        $idempotencyKey = $this->idempotencyKeyFor($idempotencyKey, $subscription);

        $requestedDiscounts = $subscription->discounts ?? [];
        $data = array_merge(
            $this->subscriptionToIuguData($subscription),
            self::withoutIdempotencyKey($subscription->gatewayOptions)
        );

        $this->applySubscriptionCreditCard($subscription, $idempotencyKey);

        $response = $this->iuguIdempotentRequest(
            'POST',
            Iugu::getBaseURI() . '/subscriptions',
            $data,
            'creating subscription',
            $idempotencyKey,
            true
        );

        $parsed = $this->parseIuguSubscription($response, $subscription);

        return empty($requestedDiscounts)
            ? $parsed
            : $this->applyIuguDiscountValidities($requestedDiscounts, $parsed, true, $idempotencyKey);
    }

    /**
     * Torna o cartão de `Subscription::$creditCard` o cartão padrão do cliente, que é o que a
     * Iugu cobra numa assinatura paga com cartão. Cartão sem `id` (token ou dados crus) é salvo
     * no cliente já como padrão (`{chave}:card`); cartão com `id` é marcado como padrão por um
     * `PUT` no cliente (`{chave}:default`). Sem cartão no model, nada é feito.
     *
     * @param  Subscription  $subscription
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     * @return void
     * @throws GatewayException|GatewayNotAvailableException|ModelAttributeValidationException
     */
    private function applySubscriptionCreditCard(Subscription $subscription, ?string $idempotencyKey): void
    {
        $creditCard = $subscription->creditCard;
        if (empty($creditCard)) {
            return;
        }
        if (empty($subscription->customer) || empty($subscription->customer->id)) {
            throw ModelAttributeValidationException::required('Subscription', 'customer');
        }

        if (empty($creditCard->id)) {
            if (empty($creditCard->customer)) {
                $creditCard->customer = $subscription->customer;
            }
            $creditCard->default = true;
            $subscription->creditCard = $this->createCreditCard(
                $creditCard,
                self::derivedIdempotencyKey($idempotencyKey, 'card')
            );

            return;
        }

        $this->iuguIdempotentRequest(
            'PUT',
            Iugu::getBaseURI() . '/customers/' . rawurlencode($subscription->customer->id),
            ['default_payment_method_id' => $creditCard->id],
            'setting the subscription card as the customer default',
            self::derivedIdempotencyKey($idempotencyKey, 'default')
        );
        $creditCard->default = true;
    }

    /**
     * @inheritDoc
     */
    public function getSubscription(Subscription $subscription): Subscription
    {
        if (empty($subscription->id)) {
            throw ModelAttributeValidationException::required('Subscription', 'id');
        }

        $response = $this->iuguRequest(
            'GET',
            $this->subscriptionUrl($subscription->id),
            [],
            'getting subscription'
        );

        return $this->parseIuguSubscription($response, $subscription);
    }

    /**
     * @inheritDoc
     *
     * A chave de idempotência passa pela `IdempotencyStore`: a informada no `PUT` da
     * atualização, `{chave}:remove` na remoção de subitens que a antecede, `{chave}:card` ou
     * `{chave}:default` no cartão que passa a ser o padrão do cliente e `{chave}:discounts`
     * na escrita da validade dos descontos (`mp_discount_<subitem_id>_until`), que também
     * remove a variável de desconto que saiu da lista.
     */
    public function updateSubscription(Subscription $subscription, ?string $idempotencyKey = null): Subscription
    {
        if (empty($subscription->id)) {
            throw ModelAttributeValidationException::required('Subscription', 'id');
        }
        $idempotencyKey = $this->idempotencyKeyFor($idempotencyKey, $subscription);

        $requestedDiscounts = $subscription->discounts;
        $data = array_merge(
            $this->subscriptionToIuguData($subscription, false),
            self::withoutIdempotencyKey($subscription->gatewayOptions)
        );
        $subitems = $data['subitems'] ?? null;
        unset($data['subitems']);

        $this->applySubscriptionCreditCard($subscription, $idempotencyKey);

        if (!is_null($subitems)) {
            // a Iugu recusa remover e adicionar subitens na mesma requisição, então a remoção
            // vai sozinha e antes; entre as duas a assinatura fica sem os itens removidos
            $toDestroy = $this->iuguSubitemsToDestroy(
                $subscription->id,
                $subitems,
                !is_null($subscription->items),
                !is_null($subscription->discounts)
            );

            if (!empty($toDestroy)) {
                $this->iuguIdempotentRequest(
                    'PUT',
                    $this->subscriptionUrl($subscription->id),
                    ['subitems' => $toDestroy],
                    'removing subscription items',
                    self::derivedIdempotencyKey($idempotencyKey, 'remove')
                );
            }

            if (!empty($subitems)) {
                $data['subitems'] = $subitems;
            }
        }

        $response = $this->iuguIdempotentRequest(
            'PUT',
            $this->subscriptionUrl($subscription->id),
            $data,
            'updating subscription',
            $idempotencyKey
        );

        $parsed = $this->parseIuguSubscription($response, $subscription);

        return is_null($requestedDiscounts)
            ? $parsed
            : $this->applyIuguDiscountValidities($requestedDiscounts, $parsed, false, $idempotencyKey);
    }

    /**
     * @inheritDoc
     *
     * A chave de idempotência passa pela `IdempotencyStore`.
     */
    public function suspendSubscription(Subscription $subscription, ?string $idempotencyKey = null): Subscription
    {
        $response = $this->iuguSubscriptionAction(
            $subscription,
            'suspend',
            'suspending subscription',
            $this->idempotencyKeyFor($idempotencyKey, $subscription)
        );

        return $this->parseIuguSubscription($response, $subscription);
    }

    /**
     * @inheritDoc
     *
     * Reativa também uma assinatura cancelada por `cancelSubscription()`, imediato ou
     * agendado: a marca de cancelamento (`mp_canceled_at`) e o agendamento
     * (`mp_cancel_at_period_end`, `mp_cancel_scheduled_for`) são removidos de
     * `custom_variables` numa segunda requisição (`PUT` com `_destroy`, chave derivada
     * `{chave}:uncancel`), para a assinatura voltar a ler como `ACTIVE` sem cancelamento
     * pendente. A chave de idempotência passa pela `IdempotencyStore`.
     */
    public function resumeSubscription(Subscription $subscription, ?string $idempotencyKey = null): Subscription
    {
        $idempotencyKey = $this->idempotencyKeyFor($idempotencyKey, $subscription);

        $response = $this->iuguSubscriptionAction($subscription, 'activate', 'resuming subscription', $idempotencyKey);
        $resumed = $this->parseIuguSubscription($response, $subscription);

        if (is_null($resumed->canceledAt) && !$resumed->cancelAtPeriodEnd) {
            return $resumed;
        }

        $response = $this->iuguIdempotentRequest(
            'PUT',
            $this->subscriptionUrl($subscription->id),
            ['custom_variables' => [
                ['name' => self::CANCELED_AT_VARIABLE, '_destroy' => true],
                ['name' => self::CANCEL_AT_PERIOD_END_VARIABLE, '_destroy' => true],
                ['name' => self::CANCEL_SCHEDULED_FOR_VARIABLE, '_destroy' => true],
            ]],
            'clearing the subscription cancellation',
            self::derivedIdempotencyKey($idempotencyKey, 'uncancel')
        );

        return $this->parseIuguSubscription($response, $resumed);
    }

    /**
     * @inheritDoc
     *
     * Na Iugu o cancelamento é uma suspensão com marca: a assinatura é suspensa e recebe a
     * data do cancelamento em `custom_variables` (`mp_canceled_at`), numa segunda requisição
     * (`PUT`, chave derivada `{chave}:cancel`); é essa marca que faz a leitura devolver
     * `CANCELED`. Assinatura que já tem a marca é só suspensa de novo, e a data original fica.
     * Com `$atPeriodEnd`, a assinatura não é suspensa: um único `PUT` grava
     * `mp_cancel_at_period_end` e `mp_cancel_scheduled_for` (a data da próxima cobrança), ela
     * segue ativa com `cancelAtPeriodEnd` verdadeiro, e o comando
     * `multipayment:sync-subscriptions` a suspende quando a data chega, gravando
     * `mp_canceled_at`. `resumeSubscription()` desfaz as duas formas. A chave de idempotência
     * passa pela `IdempotencyStore`.
     */
    public function cancelSubscription(
        Subscription $subscription,
        bool $atPeriodEnd = false,
        ?string $idempotencyKey = null
    ): Subscription {
        $idempotencyKey = $this->idempotencyKeyFor($idempotencyKey, $subscription);

        if ($atPeriodEnd) {
            return $this->scheduleIuguCancellation($subscription, $idempotencyKey);
        }

        $response = $this->iuguSubscriptionAction($subscription, 'suspend', 'suspending subscription', $idempotencyKey);
        $suspended = $this->parseIuguSubscription($response, $subscription);

        if (!is_null($suspended->canceledAt)) {
            return $suspended;
        }

        $response = $this->iuguIdempotentRequest(
            'PUT',
            $this->subscriptionUrl($subscription->id),
            ['custom_variables' => [[
                'name' => self::CANCELED_AT_VARIABLE,
                'value' => Carbon::now()->toIso8601String(),
            ]]],
            'marking the subscription as canceled',
            self::derivedIdempotencyKey($idempotencyKey, 'cancel')
        );

        return $this->parseIuguSubscription($response, $suspended);
    }

    /**
     * Agenda o cancelamento para o fim do período corrente: grava em `custom_variables` a
     * intenção (`mp_cancel_at_period_end`) e a data programada (`mp_cancel_scheduled_for`, a
     * data da próxima cobrança), sem suspender. A data vem de `nextBillingAt` do model ou de
     * uma leitura da assinatura; sem data de cobrança não há fim de período e a operação é
     * recusada. Chamada repetida atualiza a data programada para a próxima cobrança atual.
     *
     * @param  Subscription  $subscription
     * @param  string|null  $idempotencyKey  chave já resolvida; passa pela `IdempotencyStore`
     *
     * @return Subscription
     * @throws GatewayException|GatewayNotAvailableException|ModelAttributeValidationException
     */
    private function scheduleIuguCancellation(Subscription $subscription, ?string $idempotencyKey): Subscription
    {
        if (empty($subscription->id)) {
            throw ModelAttributeValidationException::required('Subscription', 'id');
        }

        $scheduledFor = $subscription->nextBillingAt;
        if (empty($scheduledFor)) {
            $current = $this->iuguRequest(
                'GET',
                $this->subscriptionUrl($subscription->id),
                [],
                'getting subscription'
            );
            $expiresAt = ((object) $current)->expires_at ?? null;
            $scheduledFor = empty($expiresAt) ? null : new Carbon($expiresAt);
        }

        if (empty($scheduledFor)) {
            throw ModelAttributeValidationException::invalid(
                'Subscription',
                'nextBillingAt',
                'the subscription has no billing date, so there is no period end to schedule'
                . ' the cancellation at; cancel it immediately instead.'
            );
        }

        $response = $this->iuguIdempotentRequest(
            'PUT',
            $this->subscriptionUrl($subscription->id),
            ['custom_variables' => [
                ['name' => self::CANCEL_AT_PERIOD_END_VARIABLE, 'value' => '1'],
                ['name' => self::CANCEL_SCHEDULED_FOR_VARIABLE, 'value' => $scheduledFor->format('Y-m-d')],
            ]],
            'scheduling the subscription cancellation',
            $idempotencyKey
        );

        return $this->parseIuguSubscription($response, $subscription);
    }

    /**
     * @inheritDoc
     *
     * Percorre todas as assinaturas da conta em páginas de 100. Assinatura suspensa é pulada:
     * ela não gera fatura, e a que o comando suspendeu na rodada anterior já está aplicada. O
     * desconto vencido e a variável dele saem num único `PUT`; a variável de desconto sem
     * subitem correspondente (sobra de uma escrita interrompida) também é removida. O
     * cancelamento agendado que chegou à data grava a marca `mp_canceled_at` e suspende
     * (`POST /suspend`), nessa ordem, e o agendamento fica gravado.
     */
    public function syncSubscriptions(bool $dryRun = false): array
    {
        $actions = [];
        $limit = 100;
        $start = 0;

        do {
            $query = http_build_query(['limit' => $limit, 'start' => $start], '', '&', PHP_QUERY_RFC3986);
            $response = $this->iuguRequest(
                'GET',
                Iugu::getBaseURI() . '/subscriptions?' . $query,
                [],
                'listing subscriptions'
            );
            $items = (array) (is_array($response) ? $response : ($response->items ?? []));

            foreach ($items as $item) {
                $item = (object) $item;
                // uma assinatura com problema não derruba a varredura das demais; a rodada
                // seguinte tenta de novo
                try {
                    array_push($actions, ...$this->syncIuguSubscription($item, $dryRun));
                } catch (MultiPaymentException $e) {
                    LogHelper::warning(
                        'Sincronização da assinatura [' . ($item->id ?? '?') . '] da Iugu falhou: '
                        . $e->getMessage(),
                        ['subscription' => $item->id ?? null, 'gateway' => 'iugu']
                    );
                }
            }

            $start += $limit;
        } while (count($items) === $limit);

        return $actions;
    }

    /**
     * Aplica numa assinatura o que a emulação deixou agendado e devolve as ações, no formato
     * de `SubscriptionSyncContract::syncSubscriptions()`. Com `$dryRun`, só as devolve.
     *
     * @param  object  $iuguSubscription
     * @param  bool  $dryRun
     *
     * @return array<int, array{subscription: string, action: string, detail: string}>
     * @throws GatewayException|GatewayNotAvailableException
     */
    private function syncIuguSubscription(object $iuguSubscription, bool $dryRun): array
    {
        if (!empty($iuguSubscription->suspended) || empty($iuguSubscription->id)) {
            return [];
        }
        $id = (string) $iuguSubscription->id;

        $discountSubitemIds = [];
        foreach ((array) ($iuguSubscription->subitems ?? []) as $subitem) {
            $subitem = (object) $subitem;
            if (($subitem->price_cents ?? 0) < 0 && !empty($subitem->id)) {
                $discountSubitemIds[] = (string) $subitem->id;
            }
        }

        $actions = [];
        $subitemDestroys = [];
        $variableDestroys = [];
        foreach (array_keys($this->iuguCustomVariables($iuguSubscription)) as $name) {
            $subitemId = self::discountSubitemIdFromVariable((string) $name);
            if (is_null($subitemId)) {
                continue;
            }

            if (!in_array($subitemId, $discountSubitemIds, true)) {
                $variableDestroys[] = ['name' => (string) $name, '_destroy' => true];
                $actions[] = [
                    'subscription' => $id,
                    'action' => 'remove_orphan_discount_variable',
                    'detail' => "variável {$name} sem subitem de desconto correspondente removida",
                ];
                continue;
            }

            $until = $this->iuguDiscountUntil($iuguSubscription, $subitemId);
            if (is_null($until) || !$until->copy()->endOfDay()->isPast()) {
                continue;
            }

            $subitemDestroys[] = ['id' => $subitemId, '_destroy' => true];
            $variableDestroys[] = ['name' => (string) $name, '_destroy' => true];
            $actions[] = [
                'subscription' => $id,
                'action' => 'remove_discount',
                'detail' => "desconto {$subitemId} vencido em {$until->format('Y-m-d')} removido",
            ];
        }

        $cancelDue = $this->iuguScheduledCancellationDue($iuguSubscription);
        if (!is_null($cancelDue)) {
            $actions[] = [
                'subscription' => $id,
                'action' => 'cancel',
                'detail' => "cancelamento agendado para {$cancelDue->format('Y-m-d')} aplicado:"
                    . ' assinatura suspensa e marcada como cancelada',
            ];
        }

        if ($dryRun || $actions === []) {
            return $actions;
        }

        if ($variableDestroys !== []) {
            $data = ['custom_variables' => $variableDestroys];
            if ($subitemDestroys !== []) {
                $data['subitems'] = $subitemDestroys;
            }
            $this->iuguRequest(
                'PUT',
                $this->subscriptionUrl($id),
                $data,
                'removing expired subscription discounts'
            );
        }

        if (!is_null($cancelDue)) {
            // a marca vai antes da suspensão: uma falha entre as duas deixa a assinatura
            // ativa com a marca, que a rodada seguinte reprocessa (suspensa sem a marca
            // seria pulada e leria SUSPENDED em vez de CANCELED para sempre)
            $this->iuguRequest(
                'PUT',
                $this->subscriptionUrl($id),
                ['custom_variables' => [[
                    'name' => self::CANCELED_AT_VARIABLE,
                    'value' => Carbon::now()->toIso8601String(),
                ]]],
                'marking the subscription as canceled'
            );
            $this->iuguRequest('POST', $this->subscriptionUrl($id) . '/suspend', [], 'suspending subscription');
        }

        return $actions;
    }

    /**
     * Data do cancelamento agendado que já chegou (`mp_cancel_at_period_end` com
     * `mp_cancel_scheduled_for` de hoje ou anterior); nulo quando não há agendamento, a data
     * ainda não chegou ou a data gravada não é legível (com aviso no log).
     *
     * @param  object  $iuguSubscription
     *
     * @return Carbon|null
     */
    private function iuguScheduledCancellationDue(object $iuguSubscription): ?Carbon
    {
        $flag = $this->iuguCustomVariable($iuguSubscription, self::CANCEL_AT_PERIOD_END_VARIABLE);
        if (is_null($flag) || $flag === '0') {
            return null;
        }

        $scheduled = $this->iuguCustomVariable($iuguSubscription, self::CANCEL_SCHEDULED_FOR_VARIABLE);
        if (is_null($scheduled)) {
            return null;
        }

        try {
            $date = new Carbon($scheduled);
        } catch (\Throwable) {
            LogHelper::warning(
                'Data de cancelamento agendado [' . self::CANCEL_SCHEDULED_FOR_VARIABLE
                . "] ilegível [{$scheduled}] na assinatura [" . ($iuguSubscription->id ?? '?')
                . '] da Iugu, tratada como ausente',
                ['subscription' => $iuguSubscription->id ?? null, 'value' => $scheduled, 'gateway' => 'iugu']
            );

            return null;
        }

        return $date->copy()->startOfDay()->lte(Carbon::now()) ? $date : null;
    }

    /**
     * Faz o `POST` de uma ação da assinatura (`suspend`, `activate`) e devolve a resposta crua.
     *
     * @param  Subscription  $subscription
     * @param  string  $action
     * @param  string  $operation
     * @param  string|null  $idempotencyKey  chave já resolvida; passa pela `IdempotencyStore`
     *
     * @return object|array
     * @throws ModelAttributeValidationException
     */
    private function iuguSubscriptionAction(
        Subscription $subscription,
        string $action,
        string $operation,
        ?string $idempotencyKey
    ): object|array {
        if (empty($subscription->id)) {
            throw ModelAttributeValidationException::required('Subscription', 'id');
        }

        return $this->iuguIdempotentRequest(
            'POST',
            $this->subscriptionUrl($subscription->id) . '/' . $action,
            [],
            $operation,
            $idempotencyKey
        );
    }

    /**
     * @inheritDoc
     *
     * `CHARGE_DIFFERENCE` vai para `POST change_plan`, que gera a fatura da troca na hora;
     * `NONE` vai num `PUT` com `skip_charge`; `CREDIT` é recusado antes da rede
     * (`PLAN_CHANGE_PRORATION` é limitação da Iugu, que não gera crédito ao trocar de plano).
     * A chave de idempotência passa pela `IdempotencyStore` na requisição que aplica a troca
     * (`POST change_plan` ou `PUT`); a releitura da assinatura que segue a troca com cobrança
     * não a usa.
     */
    public function changeSubscriptionPlan(
        Subscription $subscription,
        string $planId,
        ProrationBehavior|bool $proration = ProrationBehavior::CHARGE_DIFFERENCE,
        ?string $idempotencyKey = null,
        ?bool $charge = null
    ): Subscription {
        if (empty($subscription->id)) {
            throw ModelAttributeValidationException::required('Subscription', 'id');
        }

        $proration = ProrationBehavior::resolve($charge ?? $proration);
        if (!is_null($proration->requiredCapability())) {
            $this->assertSupports(
                $proration->requiredCapability(),
                'A Iugu não gera crédito do período não usado ao trocar de plano; use'
                . ' ProrationBehavior::CHARGE_DIFFERENCE ou ProrationBehavior::NONE.'
            );
        }
        $idempotencyKey = $this->idempotencyKeyFor($idempotencyKey, $subscription);

        if ($proration === ProrationBehavior::CHARGE_DIFFERENCE) {
            $this->iuguIdempotentRequest(
                'POST',
                $this->subscriptionUrl($subscription->id) . '/change_plan/' . rawurlencode($planId),
                [],
                'changing subscription plan',
                $idempotencyKey
            );

            $subscription->planId = $planId;

            return $this->getSubscription($subscription);
        }

        $data = ['plan_identifier' => $planId, 'skip_charge' => true];

        if (!empty($subscription->nextBillingAt)) {
            $data['expires_at'] = $subscription->nextBillingAt->format('Y-m-d');
        }

        $response = $this->iuguIdempotentRequest(
            'PUT',
            $this->subscriptionUrl($subscription->id),
            $data,
            'changing subscription plan',
            $idempotencyKey
        );

        return $this->parseIuguSubscription($response, $subscription);
    }

    /**
     * @inheritDoc
     *
     * A Iugu tem um único endpoint de simulação (`change_plan_simulation`), o do fluxo de
     * `ProrationBehavior::CHARGE_DIFFERENCE`: a prévia com `NONE` devolve a mesma simulação, e
     * `CREDIT` é recusado antes da rede, como em `changeSubscriptionPlan()`
     * (`PLAN_CHANGE_PRORATION` é limitação da Iugu).
     * `appliesImmediately` depende de como a assinatura é paga: verdadeiro quando o único
     * método é cartão (a Iugu cobra o cartão padrão na hora); falso quando há boleto ou Pix,
     * porque a Iugu só efetiva a troca depois do pagamento da fatura gerada. Quando o model não
     * traz `paymentMethod` nem `availablePaymentMethods`, o driver lê a assinatura antes da
     * simulação (num model à parte, sem tocar no do chamador), o que custa uma requisição a
     * mais; `creditCard` sozinho não conta, porque é atributo de escrita.
     */
    public function previewSubscriptionPlanChange(
        Subscription $subscription,
        string $planId,
        ProrationBehavior $proration = ProrationBehavior::CHARGE_DIFFERENCE
    ): SubscriptionPlanChange {
        if (empty($subscription->id)) {
            throw ModelAttributeValidationException::required('Subscription', 'id');
        }

        if (!is_null($proration->requiredCapability())) {
            $this->assertSupports(
                $proration->requiredCapability(),
                'A Iugu não gera crédito do período não usado ao trocar de plano; use'
                . ' ProrationBehavior::CHARGE_DIFFERENCE ou ProrationBehavior::NONE.'
            );
        }

        $source = $subscription;
        if (empty($subscription->availablePaymentMethods) && is_null($subscription->paymentMethod)) {
            $source = new Subscription();
            $source->id = $subscription->id;
            $source = $this->getSubscription($source);
        }
        $paymentMethods = $source->resolvedPaymentMethods();

        $response = $this->iuguRequest(
            'GET',
            $this->subscriptionUrl($subscription->id)
                . '/change_plan_simulation/' . rawurlencode($planId),
            [],
            'simulating subscription plan change'
        );

        $planChange = $this->parseIuguPlanChange($response);
        $planChange->appliesImmediately = $paymentMethods === [PaymentMethod::CREDIT_CARD];

        return $planChange;
    }

    /**
     * @inheritDoc
     */
    public function listSubscriptions(Customer $customer, int $page = 1, int $limit = 100): array
    {
        if (empty($customer->id)) {
            throw ModelAttributeValidationException::required('Customer', 'id');
        }

        if ($page < 1) {
            throw ModelAttributeValidationException::invalid('Subscription', 'page', 'Subscription page must be at least 1');
        }

        if ($limit < 1 || $limit > 100) {
            throw ModelAttributeValidationException::invalid('Subscription', 'limit', 'Subscription limit must be between 1 and 100');
        }

        $query = http_build_query([
            'customer_id' => $customer->id,
            'limit' => $limit,
            'start' => ($page - 1) * $limit,
        ], '', '&', PHP_QUERY_RFC3986);

        $response = $this->iuguRequest(
            'GET',
            Iugu::getBaseURI() . '/subscriptions?' . $query,
            [],
            'listing subscriptions'
        );

        $items = is_array($response) ? $response : ($response->items ?? []);

        return array_map(fn($item) => $this->parseIuguSubscription($item), $items);
    }

    /**
     * @inheritDoc
     *
     * A chave de idempotência passa pela `IdempotencyStore` (a Iugu não aceita o cabeçalho
     * neste endpoint).
     */
    public function createPlan(Plan $plan, ?string $idempotencyKey = null): Plan
    {
        $response = $this->iuguIdempotentRequest(
            'POST',
            Iugu::getBaseURI() . '/plans',
            array_merge($this->planToIuguData($plan), self::withoutIdempotencyKey($plan->gatewayOptions)),
            'creating plan',
            $this->idempotencyKeyFor($idempotencyKey, $plan)
        );

        return $this->parseIuguPlan($response, $plan);
    }

    /**
     * @inheritDoc
     */
    public function getPlan(Plan $plan): Plan
    {
        if (!empty($plan->id)) {
            $url = Iugu::getBaseURI() . '/plans/' . rawurlencode($plan->id);
        } elseif (!empty($plan->identifier)) {
            $url = Iugu::getBaseURI() . '/plans/identifier/' . rawurlencode($plan->identifier);
        } else {
            throw ModelAttributeValidationException::required('Plan', 'id or identifier');
        }

        return $this->parseIuguPlan($this->iuguRequest('GET', $url, [], 'getting plan'), $plan);
    }

    /**
     * @inheritDoc
     */
    public function listPlans(int $page = 1, int $limit = 100): array
    {
        if ($page < 1) {
            throw ModelAttributeValidationException::invalid('Plan', 'page', 'Plan page must be at least 1');
        }

        if ($limit < 1 || $limit > 100) {
            throw ModelAttributeValidationException::invalid('Plan', 'limit', 'Plan limit must be between 1 and 100');
        }

        $query = http_build_query([
            'limit' => $limit,
            'start' => ($page - 1) * $limit,
        ], '', '&', PHP_QUERY_RFC3986);

        $response = $this->iuguRequest(
            'GET',
            Iugu::getBaseURI() . '/plans?' . $query,
            [],
            'listing plans'
        );

        $items = is_array($response) ? $response : ($response->items ?? []);

        return array_map(fn($item) => $this->parseIuguPlan($item), $items);
    }

    /**
     * Sempre lança: a Iugu não tem desativação de plano.
     *
     * @param  Plan  $plan
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     *
     * @return Plan
     * @throws UnsupportedOperationException
     */
    public function deactivatePlan(Plan $plan, ?string $idempotencyKey = null): Plan
    {
        throw UnsupportedOperationException::forGateway(
            $this,
            Capability::PLAN_DEACTIVATION,
            'Planos da Iugu não têm flag de ativo; deixe de referenciar o plano ao criar assinaturas.'
        );
    }

    /**
     * Monta o payload de assinatura da Iugu a partir do model.
     *
     * Itens e descontos viram uma única lista de `subitems`: desconto é subitem de `price_cents`
     * negativo. Com $creating falso, só os atributos preenchidos entram no payload.
     *
     * @param  Subscription  $subscription
     * @param  bool  $creating
     *
     * @return array
     * @throws GatewayException|ModelAttributeValidationException|UnsupportedOperationException
     */
    private function subscriptionToIuguData(Subscription $subscription, bool $creating = true): array
    {
        $data = [];

        if ($creating) {
            if (empty($subscription->customer) || empty($subscription->customer->id)) {
                throw ModelAttributeValidationException::required('Subscription', 'customer');
            }

            $data['customer_id'] = $subscription->customer->id;
            $data['plan_identifier'] = $subscription->planId;
        }

        // o fim do trial em dias é calculado agora, no momento da requisição, e substitui os
        // dias no model, para o próximo save() não os recontar
        if (empty($subscription->trialEndsAt) && !empty($subscription->trialDays)) {
            $subscription->trialEndsAt = Carbon::now()->addDays($subscription->trialDays);
            $subscription->trialDays = null;
        }
        $trialEndsAt = $subscription->trialEndsAt;

        if (
            !empty($subscription->nextBillingAt)
            && !empty($trialEndsAt)
            && !$subscription->nextBillingAt->isSameDay($trialEndsAt)
        ) {
            throw ModelAttributeValidationException::invalid(
                'Subscription',
                'nextBillingAt',
                'Iugu stores the trial end and the next billing date in the same field, so '
                . 'nextBillingAt and trialEndsAt (or trialDays) cannot hold different dates.'
            );
        }

        $expiresAt = $subscription->nextBillingAt ?? $trialEndsAt;

        if (!empty($expiresAt) && ($creating || !$this->isOriginalExpiresAt($subscription, $expiresAt))) {
            $data['expires_at'] = $expiresAt->format('Y-m-d');
        }

        // por padrão a Iugu cobra o primeiro ciclo na criação, mesmo com `expires_at` no
        // futuro; num trial a primeira cobrança só pode acontecer no fim dele
        if ($creating && !empty($trialEndsAt)) {
            $data['only_charge_on_due_date'] = true;
        }

        // lista vazia: a Iugu usa os métodos habilitados na conta
        $payableWith = $subscription->resolvedPaymentMethods();
        // a mesma recusa do guard de capabilities do model, para a chamada direta ao driver:
        // a assinatura com o método exige que o gateway agende as cobranças
        if (in_array(PaymentMethod::AUTOMATIC_PIX, $payableWith, true)) {
            throw UnsupportedOperationException::forGateway(
                $this,
                Capability::MANAGES_RECURRENCE,
                'Na Iugu a recorrência de Pix Automático nasce na fatura (Invoice com automaticPix'
                . ' e método pix); a assinatura não aceita paymentMethod automatic_pix.'
            );
        }
        if (
            !empty($payableWith)
            && ($creating || !$this->isOriginalPayableWith($subscription, $payableWith))
        ) {
            $data['payable_with'] = self::paymentMethodsToIuguPayableWith($payableWith);
        }

        if (!empty($subscription->metadata)) {
            foreach (array_keys($subscription->metadata) as $name) {
                if (str_starts_with((string) $name, self::RESERVED_VARIABLE_PREFIX)) {
                    throw ModelAttributeValidationException::invalid(
                        'Subscription',
                        'metadata',
                        'metadata keys with the mp_ prefix are reserved for the library state'
                        . ' in the Iugu custom_variables.'
                    );
                }
            }

            $data['custom_variables'] = array_map(
                fn($name, $value) => ['name' => $name, 'value' => $value],
                array_keys($subscription->metadata),
                array_values($subscription->metadata)
            );
        }

        if (!is_null($subscription->items) || !is_null($subscription->discounts)) {
            $data['subitems'] = array_merge(
                array_map(
                    fn(SubscriptionItem $item) => $this->subscriptionItemToIuguData($item),
                    $subscription->items ?? []
                ),
                array_map(
                    fn(SubscriptionDiscount $discount) => $this->subscriptionDiscountToIuguData($discount),
                    $subscription->discounts ?? []
                )
            );
        }

        return $data;
    }

    /**
     * Diz se a data informada é a mesma que veio do gateway na leitura.
     *
     * @param  Subscription  $subscription
     * @param  Carbon  $expiresAt
     *
     * @return bool
     */
    private function isOriginalExpiresAt(Subscription $subscription, Carbon $expiresAt): bool
    {
        $original = $subscription->original->expires_at ?? null;

        return !empty($original)
            && (new Carbon($original))->format('Y-m-d') === $expiresAt->format('Y-m-d');
    }

    /**
     * Diz se os métodos de pagamento informados são os mesmos que vieram do gateway na leitura.
     *
     * A comparação é feita depois da expansão, para que `all` não seja reenviado como a lista
     * dos três métodos.
     *
     * @param  Subscription  $subscription
     * @param  PaymentMethod[]  $payableWith
     *
     * @return bool
     */
    private function isOriginalPayableWith(Subscription $subscription, array $payableWith): bool
    {
        $original = $subscription->original->payable_with ?? null;

        if (empty($original)) {
            return false;
        }

        return $this->iuguPayableWithToPaymentMethods($original) === array_values($payableWith);
    }

    /**
     * Monta um subitem da Iugu a partir de um item de assinatura.
     *
     * @param  SubscriptionItem  $item
     *
     * @return array
     */
    private function subscriptionItemToIuguData(SubscriptionItem $item): array
    {
        if (is_null($item->amount)) {
            throw ModelAttributeValidationException::required('SubscriptionItem', 'amount');
        }

        $data = [
            'description' => $item->description,
            'price_cents' => $item->amount,
            'quantity' => $item->quantity ?? 1,
            // o encoder do SDK transforma false em string vazia, então vai como inteiro
            'recurrent' => (int) $item->recurring,
        ];

        if (!empty($item->id)) {
            $data['id'] = $item->id;
        }

        return $data;
    }

    /**
     * Monta um subitem da Iugu a partir de um desconto de assinatura.
     *
     * Desconto percentual não tem equivalente na Iugu. `cycles` 1 vira um subitem sem
     * recorrência (vale só para a próxima fatura); os demais casos vão como subitem
     * recorrente, e a validade (`validUntil`, ou a calculada de `cycles`) é gravada em
     * `custom_variables` depois da criação, para o comando de sincronização remover o subitem
     * vencido.
     *
     * @param  SubscriptionDiscount  $discount
     *
     * @return array
     * @throws UnsupportedOperationException|ModelAttributeValidationException
     */
    private function subscriptionDiscountToIuguData(SubscriptionDiscount $discount): array
    {
        if (!is_null($discount->percentOff)) {
            throw UnsupportedOperationException::forGateway(
                $this,
                Capability::PERCENT_DISCOUNT,
                'A Iugu não tem desconto percentual em assinatura; use amountOff.'
            );
        }

        if (is_null($discount->amountOff)) {
            throw ModelAttributeValidationException::required('SubscriptionDiscount', 'amountOff');
        }

        $data = [
            'description' => $discount->description,
            'price_cents' => -abs($discount->amountOff),
            'quantity' => 1,
            'recurrent' => (int) ($discount->cycles !== 1),
        ];

        if (!empty($discount->id)) {
            $data['id'] = $discount->id;
        }

        return $data;
    }

    /**
     * Lista os subitens a destruir: os que a assinatura tem no gateway e que não aparecem, por
     * id, na lista desejada.
     *
     * Só entram os subitens do tipo que está sendo substituído — item quando $replacingItems, e
     * desconto, que na Iugu é subitem de `price_cents` negativo, quando $replacingDiscounts.
     * Faz um GET na assinatura para descobrir o estado atual.
     *
     * @param  string  $subscriptionId
     * @param  array  $desiredSubitems
     * @param  bool  $replacingItems
     * @param  bool  $replacingDiscounts
     *
     * @return array
     * @throws GatewayException|GatewayNotAvailableException
     */
    private function iuguSubitemsToDestroy(
        string $subscriptionId,
        array $desiredSubitems,
        bool $replacingItems,
        bool $replacingDiscounts
    ): array {
        $current = $this->iuguRequest(
            'GET',
            $this->subscriptionUrl($subscriptionId),
            [],
            'getting subscription items'
        );

        $keptIds = array_map('strval', array_filter(array_column($desiredSubitems, 'id')));

        $toDestroy = [];
        foreach ((array) ($current->subitems ?? []) as $subitem) {
            $subitem = (object) $subitem;
            $id = $subitem->id ?? null;

            if (empty($id) || in_array((string) $id, $keptIds, true)) {
                continue;
            }

            $replacing = ($subitem->price_cents ?? 0) < 0 ? $replacingDiscounts : $replacingItems;

            if ($replacing) {
                $toDestroy[] = ['id' => $id, '_destroy' => true];
            }
        }

        return $toDestroy;
    }

    /**
     * Converte a assinatura da Iugu numa assinatura do MultiPayment.
     *
     * Subitem de `price_cents` negativo vira desconto, não item.
     *
     * @param  mixed  $iuguSubscription
     * @param  Subscription|null  $subscription
     *
     * @return Subscription
     */
    private function parseIuguSubscription($iuguSubscription, ?Subscription $subscription = null): Subscription
    {
        $iuguSubscription = (object) $iuguSubscription;
        $subscription = $subscription ?? new Subscription();

        $subscription->id = $iuguSubscription->id ?? $subscription->id;
        if (isset($iuguSubscription->recent_invoices)) {
            $subscription->latestInvoice = $this->parseIuguRecentInvoice($iuguSubscription);
        }
        $subscription->status = $this->iuguToMultiPaymentSubscriptionStatus($iuguSubscription)
            ?? $subscription->status;
        $subscription->planId = $iuguSubscription->plan_identifier ?? $subscription->planId;
        $subscription->amount = $iuguSubscription->price_cents ?? $subscription->amount;
        // a Iugu só opera BRL
        $subscription->currency = $iuguSubscription->currency ?? 'BRL';

        if (!empty($iuguSubscription->customer_id)) {
            // cliente de outro id não é o mesmo cliente: manter os atributos antigos produziria
            // um Customer com id de um e documento de outro
            if (
                is_null($subscription->customer)
                || $subscription->customer->id !== $iuguSubscription->customer_id
            ) {
                $subscription->customer = new Customer();
            }

            $subscription->customer->id = $iuguSubscription->customer_id;
            $subscription->customer->name = $iuguSubscription->customer_name
                ?? $subscription->customer->name;
            $subscription->customer->email = $iuguSubscription->customer_email
                ?? $subscription->customer->email;
        }

        if (!empty($iuguSubscription->expires_at)) {
            $subscription->nextBillingAt = new Carbon($iuguSubscription->expires_at);

            if (!empty($iuguSubscription->in_trial)) {
                $subscription->trialEndsAt = $subscription->nextBillingAt->copy();
            }
        }

        if (!empty($iuguSubscription->created_at)) {
            $subscription->createdAt = new Carbon($iuguSubscription->created_at);
        }

        if (isset($iuguSubscription->subitems)) {
            $subscription->items = [];
            $subscription->discounts = [];

            foreach ((array) $iuguSubscription->subitems as $iuguSubitem) {
                $iuguSubitem = (object) $iuguSubitem;

                if (($iuguSubitem->price_cents ?? 0) < 0) {
                    $discount = $this->parseIuguSubscriptionDiscount($iuguSubitem);
                    $discount->validUntil = $this->iuguDiscountUntil($iuguSubscription, $discount->id);
                    $subscription->discounts[] = $discount;
                } else {
                    $subscription->items[] = $this->parseIuguSubscriptionItem($iuguSubitem);
                }
            }
        }

        if (!empty($iuguSubscription->payable_with)) {
            $subscription->availablePaymentMethods = $this->iuguPayableWithToPaymentMethods(
                $iuguSubscription->payable_with
            );
            // a Iugu não diz com qual método a assinatura é cobrada: só há um quando ela
            // aceita um único método
            $subscription->paymentMethod = count($subscription->availablePaymentMethods) === 1
                ? $subscription->availablePaymentMethods[0]
                : null;
        }

        // lista vazia também conta: é o que a Iugu devolve depois de remover a última variável;
        // as variáveis mp_ são estado da lib e viram os campos tipados, fora de metadata
        if (isset($iuguSubscription->custom_variables)) {
            $subscription->metadata = array_filter(
                $this->iuguCustomVariables($iuguSubscription),
                static fn ($name) => !str_starts_with((string) $name, self::RESERVED_VARIABLE_PREFIX),
                ARRAY_FILTER_USE_KEY
            );
            $subscription->canceledAt = $this->iuguCanceledAt($iuguSubscription);
            // `0` conta como sem agendamento, a mesma leitura do comando de sincronização
            $flag = $this->iuguCustomVariable($iuguSubscription, self::CANCEL_AT_PERIOD_END_VARIABLE);
            $subscription->cancelAtPeriodEnd = !is_null($flag) && $flag !== '0';
        }

        $subscription->gateway = 'iugu';
        $subscription->original = $iuguSubscription;

        return $subscription;
    }

    /**
     * Lê `custom_variables` da assinatura como um mapa nome para valor.
     *
     * @param  object  $iuguSubscription
     *
     * @return array<string, mixed>
     */
    private function iuguCustomVariables(object $iuguSubscription): array
    {
        $variables = [];

        foreach ((array) ($iuguSubscription->custom_variables ?? []) as $variable) {
            $variable = (object) $variable;
            if (isset($variable->name)) {
                $variables[$variable->name] = $variable->value ?? null;
            }
        }

        return $variables;
    }

    /**
     * Data do cancelamento gravada pela lib em `custom_variables` (`mp_canceled_at`). Nulo
     * quando a marca não existe ou não é uma data legível; neste último caso registra um aviso
     * no log e a marca é tratada como ausente.
     *
     * @param  object  $iuguSubscription
     *
     * @return Carbon|null
     */
    private function iuguCanceledAt(object $iuguSubscription): ?Carbon
    {
        $value = $this->iuguCustomVariable($iuguSubscription, self::CANCELED_AT_VARIABLE);

        if (is_null($value)) {
            return null;
        }

        try {
            return new Carbon($value);
        } catch (\Throwable) {
            LogHelper::warning(
                'Marca de cancelamento [' . self::CANCELED_AT_VARIABLE . "] ilegível [{$value}] na assinatura ["
                . ($iuguSubscription->id ?? '?') . '] da Iugu, tratada como ausente',
                ['subscription' => $iuguSubscription->id ?? null, 'value' => $value, 'gateway' => 'iugu']
            );

            return null;
        }
    }

    /**
     * Valor de uma variável de `custom_variables` da assinatura; nulo quando ausente ou vazia.
     *
     * @param  object  $iuguSubscription
     * @param  string  $name
     *
     * @return string|null
     */
    private function iuguCustomVariable(object $iuguSubscription, string $name): ?string
    {
        $value = $this->iuguCustomVariables($iuguSubscription)[$name] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * Nome da variável de validade de um subitem de desconto (`mp_discount_<subitem_id>_until`).
     *
     * @param  string  $subitemId
     *
     * @return string
     */
    private static function discountUntilVariable(string $subitemId): string
    {
        return self::DISCOUNT_UNTIL_PREFIX . $subitemId . self::DISCOUNT_UNTIL_SUFFIX;
    }

    /**
     * Id do subitem de desconto de uma variável `mp_discount_<subitem_id>_until`; nulo quando
     * o nome não segue o padrão.
     *
     * @param  string  $name
     *
     * @return string|null
     */
    private static function discountSubitemIdFromVariable(string $name): ?string
    {
        if (
            !str_starts_with($name, self::DISCOUNT_UNTIL_PREFIX)
            || !str_ends_with($name, self::DISCOUNT_UNTIL_SUFFIX)
        ) {
            return null;
        }

        $id = substr(
            $name,
            strlen(self::DISCOUNT_UNTIL_PREFIX),
            -strlen(self::DISCOUNT_UNTIL_SUFFIX)
        );

        return $id === '' ? null : $id;
    }

    /**
     * Validade gravada pela lib para um subitem de desconto
     * (`mp_discount_<subitem_id>_until`). Nulo quando a variável não existe ou não é uma data
     * legível; neste último caso registra um aviso no log e a variável é tratada como ausente.
     *
     * @param  object  $iuguSubscription
     * @param  string|null  $subitemId
     *
     * @return Carbon|null
     */
    private function iuguDiscountUntil(object $iuguSubscription, ?string $subitemId): ?Carbon
    {
        if (empty($subitemId)) {
            return null;
        }

        $name = self::discountUntilVariable($subitemId);
        $value = $this->iuguCustomVariable($iuguSubscription, $name);
        if (is_null($value)) {
            return null;
        }

        try {
            return new Carbon($value);
        } catch (\Throwable) {
            LogHelper::warning(
                "Validade de desconto [{$name}] ilegível [{$value}] na assinatura ["
                . ($iuguSubscription->id ?? '?') . '] da Iugu, tratada como ausente',
                ['subscription' => $iuguSubscription->id ?? null, 'value' => $value, 'gateway' => 'iugu']
            );

            return null;
        }
    }

    /**
     * Grava em `custom_variables` a validade dos descontos que acabaram de ser escritos
     * (`mp_discount_<subitem_id>_until`) e remove a variável de desconto que saiu da lista,
     * num único `PUT` (chave derivada `{chave}:discounts`). Sem mudança a fazer, nenhuma
     * requisição sai. O model devolvido traz `validUntil` aplicado em cada desconto.
     *
     * @param  SubscriptionDiscount[]  $requestedDiscounts  descontos como o chamador os informou
     * @param  Subscription  $parsed  model já preenchido com a resposta da escrita
     * @param  bool  $creating
     * @param  string|null  $idempotencyKey  chave de idempotência da operação; nula não deduplica
     *
     * @return Subscription
     * @throws GatewayException|GatewayNotAvailableException
     */
    private function applyIuguDiscountValidities(
        array $requestedDiscounts,
        Subscription $parsed,
        bool $creating,
        ?string $idempotencyKey
    ): Subscription {
        $changes = $this->iuguDiscountVariableChanges($requestedDiscounts, $parsed, $creating);
        if ($changes === []) {
            return $parsed;
        }

        $response = $this->iuguIdempotentRequest(
            'PUT',
            $this->subscriptionUrl($parsed->id),
            ['custom_variables' => $changes],
            'writing the subscription discount validity',
            self::derivedIdempotencyKey($idempotencyKey, 'discounts')
        );

        return $this->parseIuguSubscription($response, $parsed);
    }

    /**
     * Mudanças de `custom_variables` que deixam as validades de desconto iguais às pedidas:
     * uma escrita por desconto com validade nova ou diferente da gravada, e um `_destroy` por
     * variável de desconto que não corresponde mais a um desconto com validade. O desconto
     * pedido é casado com o subitem da resposta pelo `id`; os sem `id` casam pela descrição e
     * pelo valor e, sem par assim, na ordem em que foram enviados.
     *
     * @param  SubscriptionDiscount[]  $requestedDiscounts
     * @param  Subscription  $parsed
     * @param  bool  $creating
     *
     * @return array
     * @throws GatewayException|GatewayNotAvailableException
     */
    private function iuguDiscountVariableChanges(array $requestedDiscounts, Subscription $parsed, bool $creating): array
    {
        $existing = $this->iuguCustomVariables((object) ($parsed->original ?? new \stdClass()));

        $requestedIds = array_map('strval', array_filter(array_column($requestedDiscounts, 'id')));
        $byId = [];
        $unmatched = [];
        foreach ($parsed->discounts ?? [] as $parsedDiscount) {
            if (!empty($parsedDiscount->id) && in_array((string) $parsedDiscount->id, $requestedIds, true)) {
                $byId[(string) $parsedDiscount->id] = $parsedDiscount;
            } else {
                $unmatched[] = $parsedDiscount;
            }
        }

        $planCycle = null;
        $planCycleResolver = function () use (&$planCycle, $parsed): array {
            return $planCycle ??= $this->iuguPlanCycle($parsed->planId);
        };

        $desired = [];
        foreach ($requestedDiscounts as $requested) {
            if (!$requested instanceof SubscriptionDiscount) {
                continue;
            }

            $target = !empty($requested->id)
                ? ($byId[(string) $requested->id] ?? null)
                : $this->shiftMatchingDiscount($unmatched, $requested);
            if (is_null($target) || empty($target->id)) {
                continue;
            }

            $until = $this->iuguDiscountValidUntil($requested, $parsed, $creating, $planCycleResolver);
            if (is_null($until)) {
                continue;
            }

            $desired[self::discountUntilVariable((string) $target->id)] = $until->format('Y-m-d');
            $target->validUntil = $until;
        }

        $changes = [];
        foreach ($desired as $name => $value) {
            if (($existing[$name] ?? null) !== $value) {
                $changes[] = ['name' => $name, 'value' => $value];
            }
        }
        foreach (array_keys($existing) as $name) {
            if (
                !is_null(self::discountSubitemIdFromVariable((string) $name))
                && !array_key_exists($name, $desired)
            ) {
                $changes[] = ['name' => (string) $name, '_destroy' => true];
            }
        }

        return $changes;
    }

    /**
     * Tira da lista o primeiro desconto da resposta com a mesma descrição e o mesmo valor do
     * pedido; sem um par assim, o primeiro da lista. A ordem dos subitens na resposta da Iugu
     * não é garantida, então a igualdade de conteúdo vem antes da posição.
     *
     * @param  SubscriptionDiscount[]  $unmatched  descontos da resposta ainda sem par; o escolhido sai da lista
     * @param  SubscriptionDiscount  $requested
     *
     * @return SubscriptionDiscount|null
     */
    private function shiftMatchingDiscount(array &$unmatched, SubscriptionDiscount $requested): ?SubscriptionDiscount
    {
        foreach ($unmatched as $index => $candidate) {
            if (
                $candidate->description === $requested->description
                && $candidate->amountOff === abs((int) $requested->amountOff)
            ) {
                unset($unmatched[$index]);
                $unmatched = array_values($unmatched);

                return $candidate;
            }
        }

        return array_shift($unmatched);
    }

    /**
     * Data até a qual um desconto pedido vale: `validUntil` quando informado; com `cycles`
     * acima de 1, a data da fatura de número `cycles`, com um intervalo do plano entre
     * faturas. A contagem parte da próxima cobrança lida da resposta: numa criação sem trial
     * a primeira fatura já foi cobrada e a segunda sai na próxima cobrança, então faltam
     * `cycles - 2` intervalos; com trial, e no update, a próxima cobrança é a primeira fatura
     * coberta e faltam `cycles - 1`. Nulo quando o desconto não tem prazo (`cycles` 1 vale só
     * para a próxima fatura e é o próprio subitem sem recorrência).
     *
     * @param  SubscriptionDiscount  $discount
     * @param  Subscription  $parsed
     * @param  bool  $creating
     * @param  callable  $planCycle  devolve `[PlanInterval, int]` do plano, lido sob demanda
     *
     * @return Carbon|null
     */
    private function iuguDiscountValidUntil(
        SubscriptionDiscount $discount,
        Subscription $parsed,
        bool $creating,
        callable $planCycle
    ): ?Carbon {
        if (!empty($discount->validUntil)) {
            return $discount->validUntil->copy();
        }

        if (is_null($discount->cycles) || $discount->cycles <= 1) {
            return null;
        }

        [$interval, $intervalCount] = $planCycle();

        if ($creating && !empty($parsed->trialEndsAt)) {
            $base = $parsed->trialEndsAt;
            $remaining = $discount->cycles - 1;
        } elseif ($creating && !empty($parsed->nextBillingAt)) {
            $base = $parsed->nextBillingAt;
            $remaining = $discount->cycles - 2;
        } elseif (!$creating && !empty($parsed->nextBillingAt)) {
            $base = $parsed->nextBillingAt;
            $remaining = $discount->cycles - 1;
        } else {
            $base = Carbon::now();
            $remaining = $discount->cycles - 1;
        }

        return self::addPlanCycles($base, $interval, $intervalCount, max(0, $remaining));
    }

    /**
     * Intervalo de cobrança do plano, para converter `cycles` em data. Sem plano legível
     * (identificador vazio, plano removido ou sem intervalo), assume mensal.
     *
     * @param  string|null  $planId
     *
     * @return array{0: PlanInterval, 1: int}
     * @throws GatewayException|GatewayNotAvailableException
     */
    private function iuguPlanCycle(?string $planId): array
    {
        if (empty($planId)) {
            return [PlanInterval::MONTH, 1];
        }

        $plan = new Plan();
        $plan->identifier = $planId;

        try {
            $plan = $this->getPlan($plan);
        } catch (NotFoundException) {
            return [PlanInterval::MONTH, 1];
        }

        return [$plan->interval ?? PlanInterval::MONTH, $plan->intervalCount ?? 1];
    }

    /**
     * Soma ciclos do plano a uma data.
     *
     * @param  Carbon  $date
     * @param  PlanInterval  $interval
     * @param  int  $intervalCount
     * @param  int  $cycles
     *
     * @return Carbon
     */
    private static function addPlanCycles(Carbon $date, PlanInterval $interval, int $intervalCount, int $cycles): Carbon
    {
        $units = $intervalCount * $cycles;

        return match ($interval) {
            PlanInterval::DAY => $date->copy()->addDays($units),
            PlanInterval::WEEK => $date->copy()->addWeeks($units),
            PlanInterval::MONTH => $date->copy()->addMonths($units),
            PlanInterval::YEAR => $date->copy()->addYears($units),
        };
    }

    /**
     * Converte um subitem de valor não negativo da Iugu num item de assinatura.
     *
     * @param  object  $iuguSubitem
     *
     * @return SubscriptionItem
     */
    private function parseIuguSubscriptionItem(object $iuguSubitem): SubscriptionItem
    {
        $item = new SubscriptionItem();
        $item->id = $iuguSubitem->id ?? null;
        $item->description = $iuguSubitem->description ?? null;
        $item->amount = $iuguSubitem->price_cents ?? null;
        $item->quantity = $iuguSubitem->quantity ?? null;
        $item->recurring = (bool) ($iuguSubitem->recurrent ?? false);

        return $item;
    }

    /**
     * Converte um subitem de valor negativo da Iugu num desconto de assinatura.
     *
     * @param  object  $iuguSubitem
     *
     * @return SubscriptionDiscount
     */
    private function parseIuguSubscriptionDiscount(object $iuguSubitem): SubscriptionDiscount
    {
        $discount = new SubscriptionDiscount();
        $discount->id = $iuguSubitem->id ?? null;
        $discount->description = $iuguSubitem->description ?? null;
        $discount->amountOff = abs($iuguSubitem->price_cents) * (int) ($iuguSubitem->quantity ?? 1);
        $discount->cycles = empty($iuguSubitem->recurrent) ? 1 : null;

        return $discount;
    }

    /**
     * Converte as flags de estado da assinatura da Iugu no status do MultiPayment.
     *
     * A Iugu descreve a assinatura por flags, sem campo de status; a regra, na ordem:
     * `suspended` com a marca `mp_canceled_at` legível em `custom_variables` é `CANCELED`;
     * `suspended` sem a marca é `SUSPENDED`; `in_trial` é `TRIALING`; `expires_at` no passado com alguma
     * fatura de `recent_invoices` em aberto é `PAST_DUE` (a Iugu não tem inadimplência: a
     * assinatura segue `active` com a data vencida); `active` é `ACTIVE`; sem `active`,
     * `expires_at` no passado é `EXPIRED` (o ciclo terminou sem renovação e sem fatura a
     * receber) e `expires_at` futuro ou ausente é `PENDING` (criada e ainda não ativada).
     * Resposta sem a flag `active` devolve nulo e o status anterior do model é mantido.
     *
     * @param  object  $iuguSubscription
     *
     * @return SubscriptionStatus|null
     */
    private function iuguToMultiPaymentSubscriptionStatus(object $iuguSubscription): ?SubscriptionStatus
    {
        if (!empty($iuguSubscription->suspended)) {
            return is_null($this->iuguCanceledAt($iuguSubscription))
                ? SubscriptionStatus::SUSPENDED
                : SubscriptionStatus::CANCELED;
        }

        if (!empty($iuguSubscription->in_trial)) {
            return SubscriptionStatus::TRIALING;
        }

        if ($this->iuguSubscriptionIsPastDue($iuguSubscription)) {
            return SubscriptionStatus::PAST_DUE;
        }

        if (!isset($iuguSubscription->active)) {
            return null;
        }

        if ($iuguSubscription->active) {
            return SubscriptionStatus::ACTIVE;
        }

        return $this->iuguSubscriptionExpiresAtHasPassed($iuguSubscription)
            ? SubscriptionStatus::EXPIRED
            : SubscriptionStatus::PENDING;
    }

    /**
     * Diz se a data da próxima cobrança da assinatura já passou (fim do dia de `expires_at`).
     *
     * @param  object  $iuguSubscription
     *
     * @return bool
     */
    private function iuguSubscriptionExpiresAtHasPassed(object $iuguSubscription): bool
    {
        return !empty($iuguSubscription->expires_at)
            && (new Carbon($iuguSubscription->expires_at))->endOfDay()->isPast();
    }

    /**
     * Diz se o resumo de fatura ainda tem valor a receber: `pending`, `partially_paid` e
     * `expired` (na Iugu a fatura vencida segue devida até ser paga ou cancelada).
     *
     * @param  object  $iuguInvoice
     *
     * @return bool
     */
    private function iuguInvoiceIsOpen(object $iuguInvoice): bool
    {
        return in_array(
            $iuguInvoice->status ?? null,
            [self::STATUS_PENDING, self::STATUS_EXPIRED, self::STATUS_PARTIALLY_PAID],
            true
        );
    }

    /**
     * Diz se a assinatura está com cobrança vencida: data da próxima cobrança no passado e
     * alguma fatura ainda em aberto.
     *
     * Olha todas as faturas, e não só a escolhida como `latestInvoice`: uma fatura cancelada de
     * vencimento posterior esconderia uma pendente anterior.
     *
     * @param  object  $iuguSubscription
     *
     * @return bool
     */
    private function iuguSubscriptionIsPastDue(object $iuguSubscription): bool
    {
        if (!$this->iuguSubscriptionExpiresAtHasPassed($iuguSubscription)) {
            return false;
        }

        foreach ($this->iuguRecentInvoices($iuguSubscription) as $entrada) {
            if ($this->iuguInvoiceIsOpen($entrada)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normaliza `recent_invoices` numa lista de objetos.
     *
     * @param  object  $iuguSubscription
     *
     * @return object[]
     */
    private function iuguRecentInvoices(object $iuguSubscription): array
    {
        $recent = $iuguSubscription->recent_invoices ?? null;

        if (empty($recent) || !is_array($recent)) {
            return [];
        }

        return array_map(fn($entrada) => (object) $entrada, array_values($recent));
    }

    /**
     * Escolhe a entrada mais recente de `recent_invoices`.
     *
     * Vence a de maior `due_date`. Entrada sem `due_date` perde para qualquer uma com data, e
     * empate é resolvido pelo menor id, para que a escolha não dependa da ordem da resposta.
     *
     * Entrada sem `id` é ignorada, porque não daria para buscar a fatura depois.
     *
     * @param  array  $recent
     *
     * @return object|null
     */
    private function latestIuguRecentInvoice(array $recent): ?object
    {
        $escolhida = null;

        foreach ($recent as $entrada) {
            if (empty($entrada->id)) {
                continue;
            }

            if (is_null($escolhida)) {
                $escolhida = $entrada;
                continue;
            }

            $data = $entrada->due_date ?? null;
            $atual = $escolhida->due_date ?? null;

            if ($data === $atual) {
                if ((string) $entrada->id < (string) $escolhida->id) {
                    $escolhida = $entrada;
                }

                continue;
            }

            if (is_null($atual) || (!is_null($data) && $data > $atual)) {
                $escolhida = $entrada;
            }
        }

        return $escolhida;
    }

    /**
     * Converte a entrada mais recente de `recent_invoices` numa fatura do MultiPayment.
     *
     * A Iugu devolve essas faturas resumidas, sem itens nem valores em centavos, então só os
     * campos presentes são preenchidos; `original` guarda o resumo cru.
     *
     * @param  object  $iuguSubscription
     *
     * @return Invoice|null
     */
    private function parseIuguRecentInvoice(object $iuguSubscription): ?Invoice
    {
        $iuguInvoice = $this->latestIuguRecentInvoice(
            $this->iuguRecentInvoices($iuguSubscription)
        );

        if (is_null($iuguInvoice)) {
            return null;
        }

        $invoice = new Invoice();
        $invoice->id = $iuguInvoice->id;
        $invoice->status = isset($iuguInvoice->status)
            ? self::iuguStatusToMultiPayment($iuguInvoice->status)
            : null;

        $invoice->dueDate = !empty($iuguInvoice->due_date)
            ? new Carbon($iuguInvoice->due_date)
            : null;
        $invoice->url = $iuguInvoice->secure_url ?? null;
        $invoice->gateway = 'iugu';
        $invoice->originType = InvoiceOriginType::INVOICE;
        $invoice->original = $iuguInvoice;

        return $invoice;
    }

    /**
     * Converte a simulação de troca de plano da Iugu no model do MultiPayment.
     *
     * @param  mixed  $response
     *
     * @return SubscriptionPlanChange
     */
    private function parseIuguPlanChange($response): SubscriptionPlanChange
    {
        $response = (object) $response;
        $planChange = new SubscriptionPlanChange();

        // cost e total_cents podem vir formatados ("R$ 300,00"), então só numérico é aceito
        foreach (['cost', 'price_cents', 'total_cents', 'cost_cents'] as $field) {
            if (isset($response->{$field}) && is_numeric($response->{$field})) {
                $planChange->amount = (int) $response->{$field};
                break;
            }
        }

        foreach (['subitems', 'items'] as $field) {
            if (!empty($response->{$field}) && is_array($response->{$field})) {
                $planChange->items = array_map(function ($line) {
                    $line = (object) $line;
                    $item = new InvoiceItem();
                    $item->description = $line->description ?? null;
                    $item->price = $line->price_cents ?? null;
                    $item->quantity = $line->quantity ?? null;

                    return $item;
                }, $response->{$field});
                break;
            }
        }

        if (empty($planChange->items) && !is_null($planChange->amount)) {
            $planChange->items = $this->synthesizeIuguPlanChangeItems($planChange->amount, $response);
        }

        if (!empty($response->expires_at)) {
            $planChange->effectiveAt = new Carbon($response->expires_at);
        }

        $planChange->gateway = 'iugu';
        $planChange->original = $response;

        return $planChange;
    }

    /**
     * Monta as linhas da simulação de troca de plano a partir dos totais, porque a Iugu não
     * devolve linhas em `change_plan_simulation`: uma de cobrança do plano novo e, quando
     * `discount` é maior que zero, uma negativa de crédito do plano antigo. `cost` é lido como
     * o valor líquido da troca, então a linha do plano novo é `cost` mais `discount` e a soma
     * das linhas é igual a `cost`.
     *
     * @param  int  $amount  valor líquido da troca (`cost`)
     * @param  object  $response
     *
     * @return InvoiceItem[]
     */
    private function synthesizeIuguPlanChangeItems(int $amount, object $response): array
    {
        $discount = isset($response->discount) && is_numeric($response->discount)
            ? (int) $response->discount
            : 0;
        $newPlan = isset($response->new_plan) && is_scalar($response->new_plan) ? (string) $response->new_plan : null;
        $oldPlan = isset($response->old_plan) && is_scalar($response->old_plan) ? (string) $response->old_plan : null;

        $charge = new InvoiceItem();
        $charge->description = is_null($newPlan) ? 'Plano novo' : "Plano {$newPlan}";
        $charge->price = $amount + max($discount, 0);
        $charge->quantity = 1;

        $items = [$charge];

        if ($discount > 0) {
            $credit = new InvoiceItem();
            $credit->description = is_null($oldPlan) ? 'Crédito do plano anterior' : "Crédito do plano {$oldPlan}";
            $credit->price = -$discount;
            $credit->quantity = 1;
            $items[] = $credit;
        }

        return $items;
    }

    /**
     * Monta o payload de plano da Iugu a partir do model.
     *
     * @param  Plan  $plan
     *
     * @return array
     * @throws GatewayException
     */
    private function planToIuguData(Plan $plan): array
    {
        $data = array_merge(
            [
                'name' => $plan->name,
                'identifier' => $plan->identifier ?? $plan->name,
            ],
            $this->intervalToIuguData($plan->interval, $plan->intervalCount ?? 1),
            ['value_cents' => $plan->amount]
        );

        if (!empty($plan->currency)) {
            $data['currency'] = $plan->currency;
        }

        return $data;
    }

    /**
     * Converte o intervalo genérico no par `interval` e `interval_type` da Iugu.
     *
     * A Iugu só tem `weeks` e `months`, então o intervalo anual é enviado como múltiplo de 12
     * meses; o diário, o intervalo ausente e a contagem fora da faixa da Iugu lançam
     * `ModelAttributeValidationException` antes da requisição. A leitura inversa fica em
     * `iuguIntervalToMultiPayment()`.
     *
     * @param  PlanInterval|null  $interval
     * @param  int  $intervalCount
     *
     * @return array{interval: int, interval_type: string}
     * @throws ModelAttributeValidationException
     */
    private function intervalToIuguData(?PlanInterval $interval, int $intervalCount): array
    {
        $data = match ($interval) {
            PlanInterval::WEEK => ['interval' => $intervalCount, 'interval_type' => 'weeks'],
            PlanInterval::MONTH => ['interval' => $intervalCount, 'interval_type' => 'months'],
            PlanInterval::YEAR => ['interval' => 12 * $intervalCount, 'interval_type' => 'months'],
            default => throw ModelAttributeValidationException::invalid(
                'Plan',
                'interval',
                'Iugu driver does not support the `' . ($interval?->value ?? 'null') . '` plan interval; '
                . 'use week, month or year (sent as 12 months).'
            ),
        };

        // a Iugu aceita interval de 1 a 599; a tradução de ano pode estourar o teto
        if ($data['interval'] < self::PLAN_INTERVAL_MIN || $data['interval'] > self::PLAN_INTERVAL_MAX) {
            throw ModelAttributeValidationException::invalid(
                'Plan',
                'intervalCount',
                "Iugu accepts a plan interval from " . self::PLAN_INTERVAL_MIN . ' to '
                . self::PLAN_INTERVAL_MAX . " {$data['interval_type']}, {$data['interval']} given."
            );
        }

        return $data;
    }

    /**
     * Converte o par `interval_type` e `interval` da Iugu no intervalo genérico e na contagem.
     *
     * Heurística de leitura: como `year` vai para a Iugu como múltiplo de 12 meses, todo plano
     * em `months` cujo `interval` é múltiplo de 12 volta como `year` com `intervalCount` igual
     * a `interval / 12`. Um plano criado direto na Iugu com 24 meses é lido como 2 anos; quem
     * precisar do valor cru lê `original`.
     *
     * @param  string|null  $intervalType
     * @param  int|null  $interval
     *
     * @return array{0: PlanInterval|null, 1: int|null}
     */
    private function iuguIntervalToMultiPayment(?string $intervalType, ?int $interval): array
    {
        if ($intervalType === 'months' && $interval > 0 && $interval % 12 === 0) {
            return [PlanInterval::YEAR, intdiv($interval, 12)];
        }

        $genericInterval = match ($intervalType) {
            'weeks' => PlanInterval::WEEK,
            'months' => PlanInterval::MONTH,
            default => null,
        };

        return [$genericInterval, $interval];
    }

    /**
     * Converte o plano da Iugu num plano do MultiPayment.
     *
     * @param  mixed  $iuguPlan
     * @param  Plan|null  $plan
     *
     * @return Plan
     */
    private function parseIuguPlan($iuguPlan, ?Plan $plan = null): Plan
    {
        $iuguPlan = (object) $iuguPlan;
        $plan = $plan ?? new Plan();

        $plan->id = $iuguPlan->id ?? $plan->id;
        $plan->identifier = $iuguPlan->identifier ?? $plan->identifier;
        $plan->name = $iuguPlan->name ?? $plan->name;
        [$interval, $intervalCount] = $this->iuguIntervalToMultiPayment(
            $iuguPlan->interval_type ?? null,
            isset($iuguPlan->interval) ? (int) $iuguPlan->interval : null
        );
        $plan->interval = $interval ?? $plan->interval;
        $plan->intervalCount = $intervalCount ?? $plan->intervalCount;

        // o create recebe value_cents, mas a resposta traz os valores em prices[], um por moeda
        if (isset($iuguPlan->value_cents)) {
            $plan->amount = $iuguPlan->value_cents;
        } elseif (!empty($iuguPlan->prices)) {
            $price = (object) ((array) $iuguPlan->prices)[0];
            $plan->amount = $price->value_cents ?? $plan->amount;
            $plan->currency = $price->currency ?? $plan->currency;
        }

        $plan->gateway = 'iugu';
        $plan->original = $iuguPlan;

        return $plan;
    }

    /**
     * Converte o `payable_with` da Iugu na lista de métodos de pagamento do MultiPayment.
     *
     * O valor `all` expande para os três métodos selecionáveis; valor desconhecido é ignorado.
     *
     * @param  mixed  $payableWith
     *
     * @return PaymentMethod[]
     */
    private function iuguPayableWithToPaymentMethods($payableWith): array
    {
        $methods = [];
        foreach ((array) $payableWith as $iuguMethod) {
            if ($iuguMethod === 'all') {
                return PaymentMethod::selectable();
            }

            $method = $this->iuguToMultiPaymentPaymentMethod($iuguMethod);

            if (!is_null($method)) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /**
     * Monta a url de uma assinatura na Iugu.
     *
     * @param  string  $id
     *
     * @return string
     */
    private function subscriptionUrl(string $id): string
    {
        return Iugu::getBaseURI() . '/subscriptions/' . rawurlencode($id);
    }
}
