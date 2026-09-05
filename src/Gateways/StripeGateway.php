<?php

namespace Potelo\MultiPayment\Gateways;

use Carbon\Carbon;
use Stripe\StripeClient;
use Stripe\Price as StripePrice;
use Stripe\Invoice as StripeInvoice;
use Stripe\Customer as StripeCustomer;
use Stripe\PaymentIntent as StripePaymentIntent;
use Stripe\PaymentMethod as StripePaymentMethod;
use Stripe\SetupIntent as StripeSetupIntent;
use Stripe\Subscription as StripeSubscription;
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
use Potelo\MultiPayment\Models\Plan;
use Potelo\MultiPayment\Models\Model;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Refund;
use Potelo\MultiPayment\Models\Address;
use Potelo\MultiPayment\Models\BankSlip;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\InvoiceItem;
use Potelo\MultiPayment\Models\PaymentError;
use Potelo\MultiPayment\Models\AutomaticPix;
use Potelo\MultiPayment\Models\Subscription;
use Potelo\MultiPayment\Models\SubscriptionItem;
use Potelo\MultiPayment\Models\SubscriptionDiscount;
use Potelo\MultiPayment\Models\AutomaticPixCharge;
use Potelo\MultiPayment\Models\SubscriptionPlanChange;
use Potelo\MultiPayment\Models\AutomaticPixCancellation;
use Potelo\MultiPayment\Enums\Capability;
use Potelo\MultiPayment\Enums\PlanInterval;
use Potelo\MultiPayment\Enums\InvoiceStatus;
use Potelo\MultiPayment\Enums\InvoiceOriginType;
use Potelo\MultiPayment\Enums\RefundStatus;
use Potelo\MultiPayment\Enums\PaymentMethod;
use Potelo\MultiPayment\Enums\DeclineCode;
use Potelo\MultiPayment\Enums\ProrationBehavior;
use Potelo\MultiPayment\Helpers\LogHelper;
use Potelo\MultiPayment\Capabilities\CapabilityRestriction;
use Potelo\MultiPayment\Models\WebhookEvent;
use Potelo\MultiPayment\Enums\WebhookEventType;
use Potelo\MultiPayment\Contracts\PlanContract;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Contracts\WebhookContract;
use Potelo\MultiPayment\Contracts\SubscriptionContract;
use Potelo\MultiPayment\Gateways\Concerns\ChecksCapabilities;
use Potelo\MultiPayment\Gateways\Concerns\ResolvesIdempotencyKey;
use Potelo\MultiPayment\Gateways\Stripe\DeclineCodes as StripeDeclineCodes;
use Potelo\MultiPayment\Gateways\Stripe\ProrationBehaviors;
use Potelo\MultiPayment\Gateways\Stripe\SubscriptionStatuses;
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
use Potelo\MultiPayment\Exceptions\WebhookSignatureException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

class StripeGateway implements GatewayContract, SubscriptionContract, PlanContract, WebhookContract
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

    /** Prefixo do id de um Price da Stripe, que é o id do plano neste driver. */
    private const STRIPE_PRICE_ID_PREFIX = 'price_';

    /**
     * Expand de toda leitura ou escrita de Subscription: o PaymentMethod padrão diz com que
     * método a assinatura é cobrada, e o Product de cada item dá a descrição dos itens.
     */
    private const SUBSCRIPTION_EXPAND = ['default_payment_method', 'discounts.source.coupon', 'items.data.price.product'];

    /** Expand na leitura ou criação de um Price: o Product dá nome e identificador ao plano. */
    private const PRICE_EXPAND = ['product'];

    /** Valor mínimo de um boleto na Stripe, em centavos (R$ 5,00). */
    private const BOLETO_MIN_AMOUNT = 500;

    /** Valor máximo de um boleto na Stripe, em centavos (R$ 49.999,99). */
    private const BOLETO_MAX_AMOUNT = 4999999;

    /** Prazo máximo de vencimento de um boleto na Stripe, em dias corridos a partir de hoje. */
    private const BOLETO_MAX_EXPIRES_AFTER_DAYS = 60;

    /**
     * Prazo de pagamento (`days_until_due`) da fatura de assinatura cobrada por boleto,
     * alinhado ao vencimento padrão do voucher na Stripe (3 dias). Sobrescritível por
     * `gatewayOptions['days_until_due']`.
     */
    private const BOLETO_DAYS_UNTIL_DUE = 3;

    /**
     * Prazo, em dias, entre o início do ciclo de cobrança e o débito de um mandato de Pix
     * Automático: a Stripe notifica o pagador no início do ciclo e debita três dias depois. O
     * mesmo prazo é o mínimo entre hoje e o `start_date` do mandato.
     */
    private const PIX_MANDATE_DEBIT_OFFSET_DAYS = 3;

    /**
     * Agenda do mandato de Pix Automático (`payment_schedule`) por intervalo de plano, no
     * formato `interval:interval_count`. Intervalo sem agenda correspondente é recusado antes
     * da requisição. Os valores seguem as periodicidades do Pix Automático.
     */
    private const PIX_MANDATE_SCHEDULES = [
        'week:1' => AutomaticPix::FREQUENCY_WEEKLY,
        'month:1' => AutomaticPix::FREQUENCY_MONTHLY,
        'month:3' => AutomaticPix::FREQUENCY_QUARTERLY,
        'month:6' => AutomaticPix::FREQUENCY_SEMIANNUAL,
        'month:12' => AutomaticPix::FREQUENCY_ANNUAL,
        'year:1' => AutomaticPix::FREQUENCY_ANNUAL,
    ];

    /** Status do Mandate da Stripe que encerra a recorrência. */
    private const MANDATE_STATUS_INACTIVE = 'inactive';

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

    /** Cabeçalho de assinatura das entregas de webhook da Stripe. */
    private const WEBHOOK_SIGNATURE_HEADER = 'Stripe-Signature';

    /** Tolerância padrão, em segundos, entre o timestamp assinado da entrega e o relógio da aplicação. */
    private const WEBHOOK_DEFAULT_TOLERANCE_SECONDS = 300;

    /**
     * Eventos da Stripe com tradução direta para o tipo comum. `customer.subscription.updated`
     * e `invoice.paid` têm regras próprias em `webhookEventType()`; evento fora do mapa vira
     * `UNKNOWN`.
     */
    private const WEBHOOK_EVENT_TYPES = [
        'customer.subscription.created' => WebhookEventType::SUBSCRIPTION_CREATED,
        'customer.subscription.deleted' => WebhookEventType::SUBSCRIPTION_CANCELED,
        'invoice.created' => WebhookEventType::INVOICE_CREATED,
        'invoice.payment_failed' => WebhookEventType::INVOICE_PAYMENT_FAILED,
        'invoice.voided' => WebhookEventType::INVOICE_CANCELED,
        'charge.refunded' => WebhookEventType::REFUND_CREATED,
        'charge.dispute.created' => WebhookEventType::DISPUTE_OPENED,
        'charge.dispute.closed' => WebhookEventType::DISPUTE_CLOSED,
        'payment_method.updated' => WebhookEventType::PAYMENT_METHOD_UPDATED,
        'setup_intent.succeeded' => WebhookEventType::PAYMENT_METHOD_UPDATED,
        'mandate.updated' => WebhookEventType::PIX_MANDATE_CHANGED,
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
            Capability::BANK_SLIP,
            Capability::CARD_SETUP_AUTHENTICATION,
            Capability::PARTIAL_REFUND_CARD,
            Capability::PARTIAL_REFUND_PIX,
            Capability::INVOICE_DUPLICATION,
            Capability::INVOICE_CANCELLATION,
            Capability::IDEMPOTENCY,
            Capability::IDEMPOTENCY_ALL_ENDPOINTS,
            Capability::SUBSCRIPTIONS,
            Capability::PLANS,
            Capability::PLAN_DEACTIVATION,
            Capability::CANCEL_AT_PERIOD_END,
            Capability::COUPONS,
            Capability::PERCENT_DISCOUNT,
            Capability::PLAN_CHANGE_PRORATION,
            Capability::MANAGES_RECURRENCE,
            Capability::AUTOMATIC_PIX,
            Capability::GATEWAY_DUNNING,
            Capability::WEBHOOKS,
        ];
    }

    /**
     * @inheritDoc
     */
    public function notYetImplemented(): array
    {
        return [
            Capability::MULTIPLE_PAYMENT_METHODS,
            Capability::DELAYED_CAPTURE,
        ];
    }

    /**
     * @inheritDoc
     *
     * `CREDIT_CARD`: a conta brasileira só aceita crédito Visa e Mastercard, e outra bandeira
     * é recusada na cobrança com `DeclineCode::BRAND_NOT_SUPPORTED`. `BANK_SLIP`: valor entre
     * R$ 5,00 e R$ 49.999,99 e vencimento em até 60 dias, validados antes da requisição.
     * `INVOICE_DUPLICATION`: só fatura Pix pendente de venda avulsa. `INVOICE_CANCELLATION`: a
     * fatura de assinatura (`in_`) só é anulada depois de finalizada pela Stripe (rascunho é
     * recusado), e o boleto pendente só depois de o voucher vencer.
     * `SUBSCRIPTIONS`: a Stripe só aceita `nextBillingAt` na criação da assinatura; na troca de
     * plano e na atualização a data da próxima cobrança segue o ciclo. `COUPONS`: o cupom da
     * Stripe dura meses inteiros (`duration_in_months`), então `cycles` maior que 1 exige plano
     * com intervalo mensal ou anual, e `validUntil` vira meses inteiros contados da aplicação,
     * arredondados para cima. `AUTOMATIC_PIX`: a recorrência é o mandato da assinatura,
     * agendado pelo gateway; fatura avulsa com `automaticPix` e as operações de agendamento da
     * lib não se aplicam.
     */
    public function restrictions(): array
    {
        return [
            Capability::COUPONS->value => new CapabilityRestriction(
                description: 'O cupom da Stripe dura meses inteiros (duration_in_months): cycles maior que 1'
                    . ' exige plano com intervalo mensal ou anual, e validUntil vira meses inteiros contados'
                    . ' da aplicação, arredondados para cima.',
            ),
            Capability::CREDIT_CARD->value => new CapabilityRestriction(
                description: 'Na conta brasileira só cartão de crédito Visa e Mastercard; outra bandeira é'
                    . ' recusada na cobrança com DeclineCode::BRAND_NOT_SUPPORTED.',
                allowedBrands: ['visa', 'mastercard'],
            ),
            Capability::BANK_SLIP->value => new CapabilityRestriction(
                description: 'A Stripe aceita boleto de R$ 5,00 a R$ 49.999,99, com vencimento de hoje a'
                    . ' 60 dias; fora dessas janelas a criação é recusada antes da requisição.',
            ),
            Capability::INVOICE_DUPLICATION->value => new CapabilityRestriction(
                description: 'Só fatura Pix pendente de venda avulsa (PaymentIntent); cartão, boleto,'
                    . ' outro estado ou fatura de assinatura são recusados.',
                allowedPaymentMethods: [PaymentMethod::PIX],
            ),
            Capability::INVOICE_CANCELLATION->value => new CapabilityRestriction(
                description: 'A fatura de assinatura (objeto Invoice) só é anulada depois de finalizada'
                    . ' pela Stripe (rascunho é recusado), e o boleto pendente só depois de o voucher'
                    . ' vencer.',
            ),
            Capability::SUBSCRIPTIONS->value => new CapabilityRestriction(
                description: 'nextBillingAt vale só na criação da assinatura; na troca de plano e na'
                    . ' atualização a Stripe não aceita uma data arbitrária de próxima cobrança.',
            ),
            Capability::AUTOMATIC_PIX->value => new CapabilityRestriction(
                description: 'A recorrência é o mandato de uma assinatura (paymentMethod automatic_pix'
                    . ' na criação) e o gateway agenda as cobranças; fatura avulsa com automaticPix não'
                    . ' é aceita, e as operações de agendamento e de cancelamento de cobrança da lib'
                    . ' respondem managed_by_gateway.',
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

        $declineCode = self::declineCodeFromGatewayCode($gatewayCode);

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
     * Traduz o código de recusa da Stripe para `DeclineCode`, no vocabulário do pacote. Código
     * fora da tabela devolve `UNKNOWN` com registro em nível `info`; sem código, `UNKNOWN` sem
     * registro.
     *
     * @param  string|null  $gatewayCode  `decline_code` ou, na falta dele, `code` do erro
     * @return DeclineCode
     */
    private static function declineCodeFromGatewayCode(?string $gatewayCode): DeclineCode
    {
        $declineCode = StripeDeclineCodes::toDeclineCode($gatewayCode);
        if (!is_null($declineCode)) {
            return $declineCode;
        }

        if (!empty($gatewayCode)) {
            LogHelper::info('Código de recusa da Stripe sem tradução para DeclineCode', ['gateway' => 'stripe', 'code' => $gatewayCode]);
        }

        return DeclineCode::UNKNOWN;
    }

    /**
     * Converte o erro de pagamento da Stripe (`last_payment_error` do PaymentIntent ou
     * `last_finalization_error` do Invoice) num `PaymentError` para a leitura da fatura, com o
     * mesmo mapeamento de `DeclineCode` da recusa síncrona. `occurredAt` vem do `created` do
     * charge recusado, quando o erro aponta para ele; `retryable` vem do `advice_code`, quando
     * a Stripe o envia. Nulo quando não há erro.
     *
     * @param  object|null  $stripeError  `\Stripe\StripeObject` com `code`, `decline_code`, `advice_code`, `message` e `charge`
     * @param  object|null  $stripeCharge  o `latest_charge` expandido, para datar a tentativa recusada
     * @return PaymentError|null
     */
    private function parseStripePaymentError(?object $stripeError, ?object $stripeCharge): ?PaymentError
    {
        if (empty($stripeError)) {
            return null;
        }

        $code = $stripeError->code ?? null;
        $stripeDeclineCode = $stripeError->decline_code ?? null;
        $gatewayCode = $stripeDeclineCode ?: $code;

        $error = new PaymentError();
        $error->declineCode = self::declineCodeFromGatewayCode($gatewayCode);
        $error->gatewayCode = $gatewayCode;
        $error->message = $stripeError->message ?? null;
        $error->retryable = StripeDeclineCodes::retryableFromAdvice($stripeError->advice_code ?? null);

        $failedChargeId = $stripeError->charge ?? null;
        if (!empty($failedChargeId) && ($stripeCharge->id ?? null) === $failedChargeId && !empty($stripeCharge->created)) {
            $error->occurredAt = Carbon::createFromTimestamp($stripeCharge->created);
        }

        $error->gateway = 'stripe';
        $error->original = $stripeError;

        return $error;
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
        // sem a recusa, a fatura seria criada em silêncio sem a recorrência pedida
        if (!empty($invoice->automaticPix) || !empty($invoice->automaticPixCharge)) {
            throw UnsupportedOperationException::notImplemented(
                (string) $this,
                Capability::AUTOMATIC_PIX,
                'Nesse gateway a recorrência de Pix Automático vive na assinatura: crie uma'
                . ' Subscription com paymentMethod automatic_pix. A fatura avulsa com automaticPix'
                . ' ainda não é suportada pela lib.'
            );
        }
        $idempotencyKey = $this->idempotencyKeyFor($idempotencyKey, $invoice);

        $paymentMethod = $this->invoicePaymentMethod($invoice);

        return match ($paymentMethod) {
            PaymentMethod::CREDIT_CARD => $this->createCreditCardInvoice($invoice, $idempotencyKey),
            PaymentMethod::PIX => $this->createPixInvoice($invoice, $idempotencyKey),
            PaymentMethod::BANK_SLIP => $this->createBankSlipInvoice($invoice, $idempotencyKey),
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
     * Cria e confirma um PaymentIntent de boleto 100% server-side. A fatura volta pendente com
     * o voucher em `next_action.boleto_display_details`: `Invoice::$url` é a página hospedada,
     * `bankSlip->number` é a linha digitável e `bankSlip->url` é o PDF. O pagamento é
     * assíncrono (a compensação leva até um dia útil; acompanhar via `getInvoice()`).
     *
     * A Stripe exige CPF/CNPJ (`boleto.tax_id`), nome, e-mail e endereço completo do pagador
     * (`billing_details`), valor entre R$ 5,00 e R$ 49.999,99 e vencimento
     * (`expires_after_days`, derivado de `dueDate`) de hoje a 60 dias; tudo é validado antes
     * da requisição. Sem `dueDate`, vale o prazo padrão da conta na Stripe (3 dias).
     *
     * @param  \Potelo\MultiPayment\Models\Invoice  $invoice
     * @param  string|null  $idempotencyKey
     * @return \Potelo\MultiPayment\Models\Invoice
     * @throws GatewayException|ModelAttributeValidationException
     */
    private function createBankSlipInvoice(Invoice $invoice, ?string $idempotencyKey): Invoice
    {
        // a sandbox aceita boleto sem os dados do pagador, mas a produção exige documento,
        // nome, e-mail e endereço completo; falhar cedo evita um erro obscuro da API
        if (empty($invoice->customer) || empty($invoice->customer->taxDocument)) {
            throw ModelAttributeValidationException::required('Customer', 'taxDocument');
        }
        foreach (['name', 'email'] as $attribute) {
            if (empty($invoice->customer->{$attribute})) {
                throw ModelAttributeValidationException::required('Customer', $attribute);
            }
        }
        $address = $invoice->customer->address;
        if (empty($address) || empty($address->street) || empty($address->city)
            || empty($address->state) || empty($address->zipCode)) {
            throw ModelAttributeValidationException::required('Customer', 'address (street, city, state and zipCode)');
        }

        $stripePaymentIntentData = $this->invoiceToStripeData($invoice);
        $amount = $stripePaymentIntentData['amount'];
        if ($amount < self::BOLETO_MIN_AMOUNT || $amount > self::BOLETO_MAX_AMOUNT) {
            throw ModelAttributeValidationException::invalid(
                'Invoice',
                'amount',
                'amount must be between ' . self::BOLETO_MIN_AMOUNT . ' and ' . self::BOLETO_MAX_AMOUNT
                . ' cents for bank slip invoices on the stripe gateway'
            );
        }

        $stripePaymentIntentData['payment_method_types'] = ['boleto'];
        $stripePaymentIntentData['payment_method_data'] = [
            'type' => 'boleto',
            'boleto' => ['tax_id' => $invoice->customer->taxDocument],
            'billing_details' => array_filter([
                'name' => $invoice->customer->name,
                'email' => $invoice->customer->email,
                'address' => array_filter([
                    'line1' => trim(($address->street ?? '') . ', ' . ($address->number ?: 'S/N'), ', '),
                    'line2' => $address->complement,
                    'city' => $address->city,
                    'state' => $address->state,
                    'postal_code' => $address->zipCode,
                    // a Stripe exige o código ISO de duas letras, e o boleto é só do Brasil
                    'country' => 'BR',
                ], static fn ($value) => !is_null($value) && $value !== ''),
            ]),
        ];
        $stripePaymentIntentData['confirm'] = true;
        $expiresAfterDays = $this->boletoExpiresAfterDays($invoice);
        if (!is_null($expiresAfterDays)) {
            $stripePaymentIntentData['payment_method_options']['boleto']['expires_after_days'] = $expiresAfterDays;
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
     * Dias corridos até o vencimento do boleto (`expires_after_days`), derivados de `dueDate`:
     * zero vence hoje às 23h59 (fuso de São Paulo) e o teto da Stripe é 60. Nulo quando a
     * fatura não informa `dueDate` (vale o prazo padrão da conta). Vencimento no passado ou
     * além do teto é recusado antes da requisição. A contagem parte da data corrente em São
     * Paulo, o fuso em que a Stripe vira o dia do boleto, e trata `dueDate` como a data de
     * calendário que o consumidor informou, qualquer que seja o fuso dela.
     *
     * @param  \Potelo\MultiPayment\Models\Invoice  $invoice
     * @return int|null
     * @throws ModelAttributeValidationException
     */
    private function boletoExpiresAfterDays(Invoice $invoice): ?int
    {
        if (empty($invoice->dueDate)) {
            return null;
        }

        $days = Carbon::now('America/Sao_Paulo')->startOfDay()->diffInDays(
            Carbon::parse($invoice->dueDate->format('Y-m-d'), 'America/Sao_Paulo'),
            false
        );
        if ($days < 0 || $days > self::BOLETO_MAX_EXPIRES_AFTER_DAYS) {
            throw ModelAttributeValidationException::invalid(
                'Invoice',
                'dueDate',
                'dueDate must be between today and ' . self::BOLETO_MAX_EXPIRES_AFTER_DAYS
                . ' days in the future for bank slip invoices on the stripe gateway'
            );
        }

        return (int) $days;
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
            'currency' => strtolower($invoice->currency ?? 'BRL'),
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
            // reason fixado em not_implemented: SUBSCRIPTIONS está em capabilities(), mas a
            // escrita sobre a fatura de assinatura ainda não foi construída nesta lib
            throw new UnsupportedOperationException(
                "A operação {$operation} sobre a fatura de assinatura [{$invoice->id}] (objeto Invoice da Stripe)"
                . ' ainda não está implementada nesta lib; a leitura por getInvoice() está disponível.',
                (string) $this,
                Capability::SUBSCRIPTIONS,
                UnsupportedOperationException::REASON_NOT_IMPLEMENTED
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
     * (`Invoice::$paidAmount` menos `Invoice::$refundedAmount`, porque o valor pago vem bruto);
     * fatura paga com boleto devolve zero, porque `refundInvoice()` a recusa
     * (`REFUND_BANK_SLIP` é limitação do gateway). A fatura é lida quando o model não traz o
     * valor pago, o método de pagamento ou o acumulado estornado confiável. A fatura de
     * assinatura (`in_`) é recusada como em `refundInvoice()`, antes da leitura.
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
     * quando nada foi pago e para fatura paga com boleto, que o estorno recusa.
     *
     * @param  Invoice  $invoice
     * @return int
     */
    private static function stripeRefundableAmount(Invoice $invoice): int
    {
        if ($invoice->paymentMethod === PaymentMethod::BANK_SLIP) {
            return 0;
        }

        return max(0, (int) ($invoice->paidAmount ?? 0) - (int) ($invoice->refundedAmount ?? 0));
    }

    /**
     * Diz se o model traz o que basta para calcular o restante estornável sem reler a fatura:
     * valor pago e método de pagamento presentes e `refundedAmount` sendo o acumulado do
     * gateway, ou seja, sem valor pedido pelo caminho antigo e preenchido sempre que a fatura
     * está fora de `PAID`.
     *
     * @param  Invoice  $invoice
     * @return bool
     */
    private static function hasReliableRefundableAmount(Invoice $invoice): bool
    {
        if (
            is_null($invoice->paidAmount)
            || is_null($invoice->paymentMethod)
            || !is_null($invoice->requestedRefundAmount())
        ) {
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
        $invoice->currency = isset($stripePaymentIntent->currency)
            ? strtoupper($stripePaymentIntent->currency)
            : $invoice->currency;
        $invoice->lastPaymentError = $this->parseStripePaymentError(
            $stripePaymentIntent->last_payment_error ?? null,
            $stripeCharge
        );
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

        // sem next_action não há QR nem voucher utilizável: a página hospedada some junto,
        // inclusive num model reutilizado (ex.: fatura pix expirada re-cobrada com cartão);
        // os dois parses rodam sempre, para limpar o que sobrou do outro método
        $pixUrl = $this->parsePixDisplay($invoice, $stripePaymentIntent);
        $boletoUrl = $this->parseBoletoDisplay($invoice, $stripePaymentIntent);
        $invoice->url = $pixUrl ?? $boletoUrl;

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
        $invoice->currency = isset($stripeInvoice->currency)
            ? strtoupper($stripeInvoice->currency)
            : $invoice->currency;
        // paga fora da Stripe, o erro do PaymentIntent cancelado não descreve o pagamento
        // recebido, como em parsePaymentMethod()
        $invoice->lastPaymentError = $invoice->status !== InvoiceStatus::EXTERNALLY_PAID
            ? $this->parseStripePaymentError(
                ($stripeInvoice->last_finalization_error ?? null) ?? ($stripePaymentIntent->last_payment_error ?? null),
                $stripeCharge
            )
            : null;
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
        $this->parseBoletoDisplay($invoice, $stripePaymentIntent);

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
     * Preenche `bankSlip` (linha digitável em `number`, PDF em `url`) a partir de
     * `next_action.boleto_display_details` do PaymentIntent e devolve a página hospedada do
     * voucher; `dueDate`, quando vazio, recebe o instante em que o voucher vence. Sem voucher
     * (boleto pago, vencido ou outro método), limpa `bankSlip` e devolve nulo.
     *
     * @param  Invoice  $invoice
     * @param  \Stripe\PaymentIntent|null  $stripePaymentIntent
     * @return string|null
     */
    private function parseBoletoDisplay(Invoice $invoice, ?StripePaymentIntent $stripePaymentIntent): ?string
    {
        // isset() passa pelo __isset: um next_action de outro tipo não tem a chave e o
        // StripeObject registraria "Undefined property" no log ao lê-la
        $nextAction = $stripePaymentIntent?->next_action;
        $voucher = isset($nextAction->boleto_display_details) ? $nextAction->boleto_display_details : null;
        if (empty($voucher)) {
            $invoice->bankSlip = null;

            return null;
        }

        if (empty($invoice->bankSlip)) {
            $invoice->bankSlip = new BankSlip();
        }
        $invoice->bankSlip->number = $voucher->number ?? null;
        $invoice->bankSlip->url = $voucher->pdf ?? null;
        if (empty($invoice->dueDate) && !empty($voucher->expires_at)) {
            $invoice->dueDate = Carbon::createFromTimestamp($voucher->expires_at);
        }

        return $voucher->hosted_voucher_url ?? null;
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
     * Invoice da Stripe (`void`), e a Stripe cancela sozinha o PaymentIntent padrão dele. O
     * boleto com voucher em aberto não pode ser cancelado na Stripe: quando o model traz o
     * voucher (`bankSlip` numa fatura pendente), a recusa acontece antes da requisição; sem
     * ele, a recusa da Stripe chega como `ValidationException`. Depois que o voucher vence, a
     * fatura volta a ser cancelável. A chave de idempotência vai no cabeçalho
     * `Idempotency-Key` do cancelamento.
     *
     * @throws ModelAttributeValidationException|UnsupportedOperationException
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

        if ($invoice->paymentMethod === PaymentMethod::BANK_SLIP
            && $invoice->status === InvoiceStatus::PENDING
            && !empty($invoice->bankSlip)) {
            throw UnsupportedOperationException::restricted(
                (string) $this,
                Capability::INVOICE_CANCELLATION,
                "O boleto pendente [{$invoice->id}] não pode ser cancelado na Stripe enquanto o voucher"
                . ' não vence; aguarde o vencimento (a fatura volta a ser cancelável) ou o pagamento.'
            );
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
     *
     * O plano vira um par Product e Price recorrente: o Product guarda o nome e o
     * identificador (`metadata.identifier`), o Price guarda o valor e o intervalo, e o id do
     * Price é o id do plano. O identificador vai também em `lookup_key` do Price, que é como
     * `getPlan()` o encontra; identificador repetido é recusado pela Stripe. A chave de
     * idempotência vai no cabeçalho `Idempotency-Key` da criação do Price; o Product usa a
     * derivada `{chave}:product`.
     */
    public function createPlan(Plan $plan, ?string $idempotencyKey = null): Plan
    {
        if (is_null($plan->interval)) {
            throw ModelAttributeValidationException::required('Plan', 'interval');
        }
        $idempotencyKey = $this->idempotencyKeyFor($idempotencyKey, $plan);
        $identifier = $plan->identifier ?? $plan->name;

        $stripeProduct = $this->stripeRequest(function () use ($plan, $identifier, $idempotencyKey) {
            return $this->client->products->create([
                'name' => $plan->name,
                'metadata' => ['identifier' => $identifier],
            ], self::stripeOptions(self::derivedIdempotencyKey($idempotencyKey, 'product')));
        });

        $stripePriceData = [
            'product' => $stripeProduct->id,
            'unit_amount' => $plan->amount,
            'currency' => strtolower($plan->currency ?? 'brl'),
            'recurring' => [
                'interval' => $plan->interval->value,
                'interval_count' => $plan->intervalCount ?? 1,
            ],
            'lookup_key' => $identifier,
            'nickname' => $plan->name,
        ];
        $stripePriceData = $this->mergeGatewayOptions($stripePriceData, $plan);

        $stripePrice = $this->stripeRequest(function () use ($stripePriceData, $idempotencyKey) {
            return $this->client->prices->create(
                $this->withExpand($stripePriceData, self::PRICE_EXPAND),
                self::stripeOptions($idempotencyKey)
            );
        });

        return $this->parseStripePlan($stripePrice, $plan);
    }

    /**
     * @inheritDoc
     *
     * Busca pelo `id` (id de Price, prefixo `price_`) ou pelo `identifier` (`lookup_key` do
     * Price). Um `identifier` com o prefixo `price_` é tratado como id, o que poupa a segunda
     * busca de `MultiPayment::getPlan()`; identificador sem Price correspondente lança
     * `NotFoundException`.
     */
    public function getPlan(Plan $plan): Plan
    {
        if (!empty($plan->id)) {
            $priceId = $plan->id;
        } elseif (!empty($plan->identifier)) {
            if (!str_starts_with($plan->identifier, self::STRIPE_PRICE_ID_PREFIX)) {
                return $this->parseStripePlan($this->findStripePriceByLookupKey($plan->identifier), $plan);
            }
            $priceId = $plan->identifier;
        } else {
            throw ModelAttributeValidationException::required('Plan', 'id or identifier');
        }

        $stripePrice = $this->stripeRequest(function () use ($priceId) {
            return $this->client->prices->retrieve($priceId, ['expand' => self::PRICE_EXPAND]);
        });

        return $this->parseStripePlan($stripePrice, $plan);
    }

    /**
     * @inheritDoc
     *
     * Lista os Prices recorrentes, ativos e arquivados. A paginação da Stripe é por cursor,
     * então uma página além da primeira custa uma requisição por página anterior; página além
     * do fim devolve lista vazia.
     */
    public function listPlans(int $page = 1, int $limit = 100): array
    {
        if ($page < 1) {
            throw ModelAttributeValidationException::invalid('Plan', 'page', 'Plan page must be at least 1');
        }

        if ($limit < 1 || $limit > 100) {
            throw ModelAttributeValidationException::invalid('Plan', 'limit', 'Plan limit must be between 1 and 100');
        }

        $stripePrices = $this->stripeListPage(
            fn (array $params) => $this->client->prices->all($params),
            ['type' => 'recurring', 'limit' => $limit, 'expand' => ['data.product']],
            $page
        );

        return array_map(fn ($stripePrice) => $this->parseStripePlan($stripePrice), $stripePrices);
    }

    /**
     * @inheritDoc
     *
     * Arquiva o Price (`active` falso): as assinaturas existentes continuam cobrando e uma
     * assinatura nova com esse plano é recusada pela Stripe; o Product fica ativo. A chave de
     * idempotência vai no cabeçalho `Idempotency-Key` da atualização.
     */
    public function deactivatePlan(Plan $plan, ?string $idempotencyKey = null): Plan
    {
        $idempotencyKey = $this->idempotencyKeyFor($idempotencyKey, $plan);

        if (!empty($plan->id)) {
            $priceId = $plan->id;
        } elseif (!empty($plan->identifier)) {
            $priceId = $this->resolveStripePriceId($plan->identifier);
        } else {
            throw ModelAttributeValidationException::required('Plan', 'id or identifier');
        }

        $stripePrice = $this->stripeRequest(function () use ($priceId, $idempotencyKey) {
            return $this->client->prices->update(
                $priceId,
                ['active' => false, 'expand' => self::PRICE_EXPAND],
                self::stripeOptions($idempotencyKey)
            );
        });

        return $this->parseStripePlan($stripePrice, $plan);
    }

    /**
     * Converte um Price da Stripe (com o Product expandido, quando veio) num plano do
     * MultiPayment. O id do plano é o do Price; `identifier` vem de `lookup_key`, senão de
     * `metadata.identifier` do Product; a moeda volta em maiúsculas, como na leitura da Iugu.
     *
     * @param  \Stripe\Price  $stripePrice
     * @param  Plan|null  $plan
     * @return Plan
     */
    private function parseStripePlan(StripePrice $stripePrice, ?Plan $plan = null): Plan
    {
        $plan = $plan ?? new Plan();
        $stripeProduct = is_object($stripePrice->product ?? null) ? $stripePrice->product : null;

        $plan->id = $stripePrice->id;
        $plan->identifier = $stripePrice->lookup_key
            ?? $stripeProduct?->metadata['identifier']
            ?? $plan->identifier;
        $plan->name = $stripeProduct?->name ?? $stripePrice->nickname ?? $plan->name;
        $plan->amount = $stripePrice->unit_amount ?? $plan->amount;
        $plan->interval = PlanInterval::tryFrom($stripePrice->recurring?->interval ?? '') ?? $plan->interval;
        $plan->intervalCount = $stripePrice->recurring?->interval_count ?? $plan->intervalCount;
        $plan->currency = isset($stripePrice->currency) ? strtoupper($stripePrice->currency) : $plan->currency;
        $plan->active = $stripePrice->active ?? $plan->active;
        $plan->gateway = 'stripe';
        $plan->original = $stripePrice;

        return $plan;
    }

    /**
     * Busca o Price de um `lookup_key`, com o Product expandido. A Stripe responde 200 com a
     * lista vazia quando o identificador não existe; a lista vazia é traduzida em
     * `NotFoundException`, como um 404 seria.
     *
     * @param  string  $lookupKey
     * @return \Stripe\Price
     * @throws NotFoundException|GatewayException|GatewayNotAvailableException
     */
    private function findStripePriceByLookupKey(string $lookupKey): StripePrice
    {
        $stripePrices = $this->stripeRequest(function () use ($lookupKey) {
            return $this->client->prices->all([
                'lookup_keys' => [$lookupKey],
                'limit' => 1,
                'expand' => ['data.product'],
            ]);
        });

        $stripePrice = $stripePrices->data[0] ?? null;
        if (is_null($stripePrice)) {
            throw new NotFoundException("No plan found with identifier [{$lookupKey}] on stripe.");
        }

        return $stripePrice;
    }

    /**
     * Resolve o plano apontado pelo consumidor para um id de Price: um valor com o prefixo
     * `price_` já é o id; outro valor é procurado como `lookup_key`.
     *
     * @param  string  $planId
     * @return string
     * @throws NotFoundException|GatewayException|GatewayNotAvailableException
     */
    private function resolveStripePriceId(string $planId): string
    {
        if (str_starts_with($planId, self::STRIPE_PRICE_ID_PREFIX)) {
            return $planId;
        }

        return $this->findStripePriceByLookupKey($planId)->id;
    }

    /**
     * Uma página de uma lista da Stripe no modelo página e limite do pacote. A Stripe pagina
     * por cursor (`starting_after`), então as páginas anteriores à pedida são percorridas, uma
     * requisição por página. Devolve os objetos da página, vazia quando a lista acabou antes.
     *
     * @param  callable  $fetch  recebe os parâmetros da listagem e devolve a `\Stripe\Collection`
     * @param  array  $params
     * @param  int  $page
     * @return array
     */
    private function stripeListPage(callable $fetch, array $params, int $page): array
    {
        for ($current = 1; ; $current++) {
            $collection = $this->stripeRequest(fn () => $fetch($params));
            $data = $collection->data ?? [];
            if ($current === $page) {
                return $data;
            }
            if (empty($data) || empty($collection->has_more)) {
                return [];
            }
            $params['starting_after'] = end($data)->id;
        }
    }

    /**
     * @inheritDoc
     *
     * O plano (`planId`) é o `lookup_key` ou o id de um Price. Itens extras viram subscription
     * items com Price criado sob demanda no intervalo do plano (um Product e um Price novos
     * por item); item com `recurring` falso vai como item avulso da primeira fatura. O cartão
     * de `creditCard` vira o `default_payment_method` da assinatura, sem mudar o cartão
     * padrão do cliente; cartão sem `id` é salvo antes pelo fluxo de SetupIntent, e um
     * emissor que exija autenticação interrompe a criação com `ChargingException`
     * (`AUTHENTICATION_REQUIRED`). Com cartão ou sem método informado, a primeira fatura é
     * cobrada na criação e a recusa sobe como `ChargingException` (`payment_behavior`
     * `error_if_incomplete`); com Pix a assinatura nasce com a primeira fatura em aberto até o
     * pagamento (`default_incomplete`), lida em `latestInvoice`, com a página hospedada em
     * `url` para o pagador quitar. Com Pix Automático (`paymentMethod` `AUTOMATIC_PIX`) a
     * assinatura também nasce com a primeira fatura em aberto e registra o mandato em
     * `payment_method_options.pix.mandate_options`, derivado do plano e de
     * `Subscription::$automaticPix` (ver `pixMandateOptions()`); o pagador autoriza o mandato
     * ao pagar a primeira fatura e a Stripe agenda as cobranças seguintes
     * (`MANAGES_RECURRENCE`). Com boleto a assinatura nasce ativa em modo de fatura
     * enviada (`send_invoice`, com `days_until_due` de 3 dias, sobrescritível por
     * `gatewayOptions['days_until_due']`): a primeira fatura é finalizada na hora e volta em
     * `latestInvoice` aberta, com a página hospedada em `url`, onde o pagador gera o voucher;
     * as faturas dos ciclos seguintes seguem o mesmo prazo. Cada desconto de `discounts` vira
     * um Coupon criado na hora e aplicado à assinatura: `cycles` 1 é `duration` `once`, mais
     * de um ciclo ou `validUntil` viram `repeating` com `duration_in_months`, e desconto sem
     * prazo é `forever`. Os dias de `trialDays` vão como
     * `trial_period_days`, que não muda entre tentativas com a mesma chave de idempotência;
     * o model devolvido traz a data em `trialEndsAt` e `trialDays` zerado. `nextBillingAt`
     * vira `billing_cycle_anchor`.
     *
     * A chave de idempotência vai no cabeçalho `Idempotency-Key` da criação; as requisições
     * secundárias usam derivadas (`{chave}:card` no cartão salvo antes,
     * `{chave}:item{N}_product` no Product de cada item extra, `{chave}:discount{N}_coupon`
     * no Coupon de cada desconto, `{chave}:finalize` na finalização da fatura de boleto).
     *
     * @throws ChargingException|NotFoundException|UnsupportedOperationException
     */
    public function createSubscription(Subscription $subscription, ?string $idempotencyKey = null): Subscription
    {
        $this->assertSupportsAll($subscription->requiredCapabilities());
        if (empty($subscription->customer) || empty($subscription->customer->id)) {
            throw ModelAttributeValidationException::required('Subscription', 'customer');
        }
        if (empty($subscription->planId)) {
            throw ModelAttributeValidationException::required('Subscription', 'planId');
        }
        $idempotencyKey = $this->idempotencyKeyFor($idempotencyKey, $subscription);

        $paymentMethod = $this->subscriptionPaymentMethod($subscription);
        $priceId = $this->resolveStripePriceId($subscription->planId);

        $stripeSubscriptionData = [
            'customer' => $subscription->customer->id,
            'items' => [['price' => $priceId]],
        ];
        if ($paymentMethod === PaymentMethod::BANK_SLIP) {
            // boleto é assíncrono demais para a cobrança automática na criação (a janela de
            // 23 horas de `incomplete` venceria antes da compensação): a assinatura nasce
            // ativa e cada ciclo emite uma fatura em aberto com prazo de pagamento
            $stripeSubscriptionData['collection_method'] = 'send_invoice';
            $stripeSubscriptionData['days_until_due'] = self::BOLETO_DAYS_UNTIL_DUE;
        } else {
            $stripeSubscriptionData['collection_method'] = 'charge_automatically';
            // no Pix (avulso ou com mandato) o pagador precisa agir para a primeira fatura
            $stripeSubscriptionData['payment_behavior'] = in_array(
                $paymentMethod,
                [PaymentMethod::PIX, PaymentMethod::AUTOMATIC_PIX],
                true
            ) ? 'default_incomplete' : 'error_if_incomplete';
        }

        if ($paymentMethod === PaymentMethod::AUTOMATIC_PIX) {
            $stripeSubscriptionData['payment_settings'] = [
                'payment_method_types' => ['pix'],
                'payment_method_options' => [
                    'pix' => ['mandate_options' => $this->pixMandateOptions($subscription, $priceId)],
                ],
            ];
        } elseif (!is_null($paymentMethod)) {
            $stripeSubscriptionData['payment_settings'] = [
                'payment_method_types' => [self::paymentMethodToStripeType($paymentMethod)],
            ];
        }

        $defaultPaymentMethodId = $this->applyStripeSubscriptionCard($subscription, $idempotencyKey);
        if (!is_null($defaultPaymentMethodId)) {
            $stripeSubscriptionData['default_payment_method'] = $defaultPaymentMethodId;
        }

        $trialDays = null;
        if (empty($subscription->trialEndsAt) && !empty($subscription->trialDays)) {
            $trialDays = $subscription->trialDays;
            $stripeSubscriptionData['trial_period_days'] = $trialDays;
        } elseif (!empty($subscription->trialEndsAt)) {
            $stripeSubscriptionData['trial_end'] = $subscription->trialEndsAt->getTimestamp();
        }

        if (!empty($subscription->nextBillingAt)) {
            $stripeSubscriptionData['billing_cycle_anchor'] = $subscription->nextBillingAt->getTimestamp();
        }

        $itemsData = $this->subscriptionItemsData($subscription->items ?? [], $priceId, $idempotencyKey);
        $stripeSubscriptionData['items'] = array_merge($stripeSubscriptionData['items'], $itemsData['items']);
        if (!empty($itemsData['add_invoice_items'])) {
            $stripeSubscriptionData['add_invoice_items'] = $itemsData['add_invoice_items'];
        }

        if (!empty($subscription->discounts)) {
            $stripeSubscriptionData['discounts'] = $this->stripeSubscriptionDiscountsData(
                $subscription->discounts,
                fn () => $this->stripePriceRecurring($priceId),
                $idempotencyKey
            );
        }

        if (!empty($subscription->metadata)) {
            $stripeSubscriptionData['metadata'] = $subscription->metadata;
        }

        $stripeSubscriptionData = $this->mergeGatewayOptions($stripeSubscriptionData, $subscription);

        $stripeSubscription = $this->stripeRequest(function () use ($stripeSubscriptionData, $idempotencyKey) {
            return $this->client->subscriptions->create(
                $this->withExpand($stripeSubscriptionData, self::SUBSCRIPTION_EXPAND),
                self::stripeOptions($idempotencyKey)
            );
        });

        if ($paymentMethod === PaymentMethod::BANK_SLIP) {
            $this->finalizeFirstBoletoInvoice($stripeSubscription, self::derivedIdempotencyKey($idempotencyKey, 'finalize'));
        }

        if (!is_null($trialDays)) {
            $subscription->trialDays = null;
        }

        return $this->parseStripeSubscription($stripeSubscription, $subscription, true);
    }

    /**
     * Finaliza a primeira fatura de uma assinatura de boleto: no modo `send_invoice` ela nasce
     * rascunho, sem página hospedada, e a Stripe só a finalizaria sozinha cerca de uma hora
     * depois. Sem fatura na assinatura (trial), o método não faz nada. Num retry com a mesma
     * chave de idempotência a Stripe repete a finalização original.
     *
     * @param  \Stripe\Subscription  $stripeSubscription
     * @param  string|null  $idempotencyKey
     * @return void
     * @throws GatewayException|GatewayNotAvailableException
     */
    private function finalizeFirstBoletoInvoice(StripeSubscription $stripeSubscription, ?string $idempotencyKey): void
    {
        $latestInvoiceId = is_object($stripeSubscription->latest_invoice ?? null)
            ? $stripeSubscription->latest_invoice->id
            : ($stripeSubscription->latest_invoice ?? null);
        if (empty($latestInvoiceId)) {
            return;
        }

        $this->stripeRequest(function () use ($latestInvoiceId, $idempotencyKey) {
            return $this->client->invoices->finalizeInvoice(
                $latestInvoiceId,
                [],
                self::stripeOptions($idempotencyKey)
            );
        });
    }

    /**
     * @inheritDoc
     *
     * `latestInvoice` volta lido por inteiro (`parseFromStripeInvoice()`), o que custa a
     * leitura da fatura além da assinatura.
     */
    public function getSubscription(Subscription $subscription): Subscription
    {
        if (empty($subscription->id)) {
            throw ModelAttributeValidationException::required('Subscription', 'id');
        }

        $stripeSubscription = $this->stripeRequest(function () use ($subscription) {
            return $this->client->subscriptions->retrieve(
                $subscription->id,
                ['expand' => self::SUBSCRIPTION_EXPAND]
            );
        });

        return $this->parseStripeSubscription($stripeSubscription, $subscription, true);
    }

    /**
     * @inheritDoc
     *
     * Escreve o cartão (`default_payment_method`), o método de pagamento
     * (`payment_settings`; a troca para boleto muda a assinatura para o modo de fatura enviada
     * com prazo de 3 dias, e a troca de boleto para outro método a devolve à cobrança
     * automática), o trial (`trial_end`; os dias de `trialDays` viram a data agora),
     * `metadata` e os itens. `nextBillingAt` diferente do que veio do gateway é recusado: a
     * Stripe não aceita mudar a data da próxima cobrança fora do ciclo. Nos itens, item novo
     * cria Price sob demanda, item com `id` tem a quantidade atualizada e mantém o Price, e a
     * troca não gera pró-rata (`proration_behavior` `none`). Nos descontos, a lista informada
     * substitui a da assinatura: desconto com `id` mantém o Coupon, desconto novo cria um, e
     * lista vazia remove todos. A chave de idempotência vai no cabeçalho da atualização e,
     * derivada, nas requisições que a antecedem (`{chave}:card`, `{chave}:item{N}_product`,
     * `{chave}:discount{N}_coupon`).
     *
     * @throws ChargingException|UnsupportedOperationException
     */
    public function updateSubscription(Subscription $subscription, ?string $idempotencyKey = null): Subscription
    {
        if (empty($subscription->id)) {
            throw ModelAttributeValidationException::required('Subscription', 'id');
        }
        $idempotencyKey = $this->idempotencyKeyFor($idempotencyKey, $subscription);

        $data = [];

        // o método é validado antes de o cartão ser salvo, para a recusa (boleto, mais de um
        // método, cartão fora da lista) não deixar um cartão anexado ao cliente
        $paymentMethod = $this->subscriptionPaymentMethod($subscription);

        $defaultPaymentMethodId = $this->applyStripeSubscriptionCard($subscription, $idempotencyKey);
        if (!is_null($defaultPaymentMethodId)) {
            $data['default_payment_method'] = $defaultPaymentMethodId;
        }

        // o mandato de Pix Automático é registrado na criação: numa assinatura que já o tem,
        // o método lido do gateway não é uma troca (e sair dele exige encerrar o mandato);
        // sem ele, a troca para o método ainda não é suportada
        $originalHasPixMandate = is_object(
            $subscription->original->payment_settings->payment_method_options->pix->mandate_options ?? null
        );
        if ($paymentMethod === PaymentMethod::AUTOMATIC_PIX) {
            if (!$originalHasPixMandate) {
                throw UnsupportedOperationException::notImplemented(
                    (string) $this,
                    Capability::AUTOMATIC_PIX,
                    'A lib só registra o mandato de Pix Automático na criação da assinatura.'
                );
            }
        } elseif (!is_null($paymentMethod) && $originalHasPixMandate) {
            // sem a recusa, a comparação com os types originais (['pix']) engoliria a troca
            // em silêncio e o mandato continuaria valendo
            throw UnsupportedOperationException::notImplemented(
                (string) $this,
                Capability::AUTOMATIC_PIX,
                'A lib não implementa trocar o método de uma assinatura com mandato de Pix'
                . ' Automático; cancele a assinatura e crie outra com o método desejado.'
            );
        } elseif (!is_null($paymentMethod) && !$this->isOriginalStripePaymentMethod($subscription, $paymentMethod)) {
            $data['payment_settings'] = [
                'payment_method_types' => [self::paymentMethodToStripeType($paymentMethod)],
            ];
            // a troca de método muda também o modo de cobrança: boleto exige fatura enviada
            // com prazo (send_invoice), os demais cobram automaticamente. O modo vai sempre
            // que o método muda, sem depender do estado lido do gateway: num model fresco
            // (só o id) o driver não sabe o modo atual, e reescrever o mesmo modo na Stripe
            // não tem efeito
            if ($paymentMethod === PaymentMethod::BANK_SLIP) {
                $data['collection_method'] = 'send_invoice';
                $data['days_until_due'] = self::BOLETO_DAYS_UNTIL_DUE;
            } else {
                $data['collection_method'] = 'charge_automatically';
            }
        }

        // a atualização só aceita a data do fim do trial, então os dias viram a data agora
        if (empty($subscription->trialEndsAt) && !empty($subscription->trialDays)) {
            $subscription->trialEndsAt = Carbon::now()->addDays($subscription->trialDays);
            $subscription->trialDays = null;
        }
        if (!empty($subscription->trialEndsAt) && !$this->isOriginalStripeTrialEnd($subscription)) {
            $data['trial_end'] = $subscription->trialEndsAt->getTimestamp();
        }

        $this->assertNextBillingAtIsUnchanged($subscription);

        if (!is_null($subscription->items)) {
            $declared = $this->declarativeStripeItems($subscription, $idempotencyKey);
            if (!empty($declared['items'])) {
                $data['items'] = $declared['items'];
                $data['proration_behavior'] = 'none';
            }
            if (!empty($declared['add_invoice_items'])) {
                $data['add_invoice_items'] = $declared['add_invoice_items'];
            }
        }

        if (!is_null($subscription->discounts) && !$this->isOriginalStripeDiscounts($subscription)) {
            // lista vazia remove todos os descontos; a Stripe limpa o campo com string vazia
            $data['discounts'] = empty($subscription->discounts)
                ? ''
                : $this->stripeSubscriptionDiscountsData(
                    $subscription->discounts,
                    fn () => $this->stripePlanRecurringForUpdate($subscription),
                    $idempotencyKey
                );
        }

        if (!empty($subscription->metadata)) {
            $data['metadata'] = $subscription->metadata;
        }

        $data = $this->mergeGatewayOptions($data, $subscription);

        $stripeSubscription = $this->stripeRequest(function () use ($subscription, $data, $idempotencyKey) {
            return $this->client->subscriptions->update(
                $subscription->id,
                $this->withExpand($data, self::SUBSCRIPTION_EXPAND),
                self::stripeOptions($idempotencyKey)
            );
        });

        return $this->parseStripeSubscription($stripeSubscription, $subscription);
    }

    /**
     * @inheritDoc
     *
     * Pausa a cobrança (`pause_collection` com `behavior` `void`): a assinatura continua
     * existindo na Stripe e lê como `SUSPENDED` nesta lib, e as faturas dos ciclos pausados são
     * anuladas. A chave de idempotência vai no cabeçalho `Idempotency-Key` da atualização.
     */
    public function suspendSubscription(Subscription $subscription, ?string $idempotencyKey = null): Subscription
    {
        if (empty($subscription->id)) {
            throw ModelAttributeValidationException::required('Subscription', 'id');
        }
        $idempotencyKey = $this->idempotencyKeyFor($idempotencyKey, $subscription);

        $stripeSubscription = $this->stripeRequest(function () use ($subscription, $idempotencyKey) {
            return $this->client->subscriptions->update(
                $subscription->id,
                $this->withExpand(['pause_collection' => ['behavior' => 'void']], self::SUBSCRIPTION_EXPAND),
                self::stripeOptions($idempotencyKey)
            );
        });

        return $this->parseStripeSubscription($stripeSubscription, $subscription);
    }

    /**
     * @inheritDoc
     *
     * Desfaz a pausa (`pause_collection`) e o cancelamento agendado (`cancel_at_period_end`)
     * numa única atualização. Assinatura cancelada de vez (`CANCELED`) não volta na Stripe: a
     * atualização é recusada pelo gateway e chega como `ValidationException`. A chave de
     * idempotência vai no cabeçalho `Idempotency-Key` da atualização.
     */
    public function resumeSubscription(Subscription $subscription, ?string $idempotencyKey = null): Subscription
    {
        if (empty($subscription->id)) {
            throw ModelAttributeValidationException::required('Subscription', 'id');
        }
        $idempotencyKey = $this->idempotencyKeyFor($idempotencyKey, $subscription);

        $stripeSubscription = $this->stripeRequest(function () use ($subscription, $idempotencyKey) {
            return $this->client->subscriptions->update(
                $subscription->id,
                $this->withExpand([
                    'pause_collection' => '',
                    'cancel_at_period_end' => false,
                ], self::SUBSCRIPTION_EXPAND),
                self::stripeOptions($idempotencyKey)
            );
        });

        return $this->parseStripeSubscription($stripeSubscription, $subscription);
    }

    /**
     * @inheritDoc
     *
     * Sem `atPeriodEnd`, cancela na hora (a assinatura lê como `CANCELED` e não volta). Com
     * `atPeriodEnd`, grava `cancel_at_period_end`: a assinatura segue ativa até o fim do
     * período pago e o model volta com `cancelAtPeriodEnd` e `canceledAt` preenchidos;
     * `resumeSubscription()` desfaz. A chave de idempotência vai no cabeçalho das duas formas
     * (no cancelamento imediato, um `DELETE`, a Stripe a ignora).
     */
    public function cancelSubscription(
        Subscription $subscription,
        bool $atPeriodEnd = false,
        ?string $idempotencyKey = null
    ): Subscription {
        if (empty($subscription->id)) {
            throw ModelAttributeValidationException::required('Subscription', 'id');
        }
        $idempotencyKey = $this->idempotencyKeyFor($idempotencyKey, $subscription);

        $stripeSubscription = $this->stripeRequest(function () use ($subscription, $atPeriodEnd, $idempotencyKey) {
            if ($atPeriodEnd) {
                return $this->client->subscriptions->update(
                    $subscription->id,
                    $this->withExpand(['cancel_at_period_end' => true], self::SUBSCRIPTION_EXPAND),
                    self::stripeOptions($idempotencyKey)
                );
            }

            return $this->client->subscriptions->cancel(
                $subscription->id,
                ['expand' => self::SUBSCRIPTION_EXPAND],
                self::stripeOptions($idempotencyKey)
            );
        });

        return $this->parseStripeSubscription($stripeSubscription, $subscription);
    }

    /**
     * @inheritDoc
     *
     * A troca escreve o Price novo no item do plano. `CHARGE_DIFFERENCE` vai como
     * `always_invoice` (a pró-rata é faturada e cobrada na hora, e a fatura volta em
     * `latestInvoice`); `NONE` como `none`; `CREDIT` como `create_prorations` (crédito e
     * cobrança proporcionais ficam para a próxima fatura). `nextBillingAt` diferente do que
     * veio do gateway é recusado: a Stripe não aceita mudar a data da próxima cobrança na
     * troca. A chave de idempotência vai no cabeçalho da atualização; a leitura que acha o
     * item do plano não a usa.
     *
     * @throws NotFoundException|UnsupportedOperationException
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
            $this->assertSupports($proration->requiredCapability());
        }
        $this->assertNextBillingAtIsUnchanged($subscription);
        $idempotencyKey = $this->idempotencyKeyFor($idempotencyKey, $subscription);

        $priceId = $this->resolveStripePriceId($planId);
        $planItem = $this->currentStripePlanItem($subscription);

        $stripeSubscription = $this->stripeRequest(function () use ($subscription, $planItem, $priceId, $proration, $idempotencyKey) {
            return $this->client->subscriptions->update(
                $subscription->id,
                $this->withExpand([
                    'items' => [['id' => $planItem->id, 'price' => $priceId]],
                    'proration_behavior' => ProrationBehaviors::toStripe($proration),
                ], self::SUBSCRIPTION_EXPAND),
                self::stripeOptions($idempotencyKey)
            );
        });

        $subscription->planId = $planId;

        return $this->parseStripeSubscription(
            $stripeSubscription,
            $subscription,
            $proration === ProrationBehavior::CHARGE_DIFFERENCE
        );
    }

    /**
     * @inheritDoc
     *
     * Usa a prévia de fatura da Stripe (`invoices.create_preview`) com o item do plano
     * apontando o Price novo e o `proration_behavior` da política informada (`always_invoice`,
     * `none` ou `create_prorations`); as linhas voltam reais em `items`, com o crédito do
     * período não usado em `price` negativo. `effectiveAt` é o fim de período da linha mais
     * distante, quando acontece a próxima cobrança normal; `appliesImmediately` é verdadeiro,
     * porque a Stripe aplica o plano novo na hora, independente do pagamento. Além da prévia,
     * custa a leitura da assinatura (o item do plano) e, quando `planId` é um identificador, a
     * busca do Price.
     */
    public function previewSubscriptionPlanChange(
        Subscription $subscription,
        string $planId,
        ProrationBehavior $proration = ProrationBehavior::CHARGE_DIFFERENCE
    ): SubscriptionPlanChange {
        if (empty($subscription->id)) {
            throw ModelAttributeValidationException::required('Subscription', 'id');
        }

        $priceId = $this->resolveStripePriceId($planId);
        $planItem = $this->currentStripePlanItem($subscription);

        $preview = $this->stripeRequest(function () use ($subscription, $planItem, $priceId, $proration) {
            return $this->client->invoices->createPreview([
                'subscription' => $subscription->id,
                'subscription_details' => [
                    'items' => [['id' => $planItem->id, 'price' => $priceId]],
                    'proration_behavior' => ProrationBehaviors::toStripe($proration),
                ],
            ]);
        });

        $planChange = new SubscriptionPlanChange();
        $planChange->amount = $preview->total ?? null;
        $items = [];
        $effectiveAt = null;
        foreach ($preview->lines->data ?? [] as $line) {
            $invoiceItem = new InvoiceItem();
            $invoiceItem->description = $line->description ?? null;
            $invoiceItem->quantity = isset($line->quantity) ? (int) $line->quantity : 1;
            // usa o valor total da linha, que carrega o sinal: a linha de crédito vem negativa
            $amount = isset($line->amount) ? (int) $line->amount : null;
            $invoiceItem->price = $amount;
            if (!is_null($amount) && $invoiceItem->quantity > 1) {
                if ($amount % $invoiceItem->quantity === 0) {
                    $invoiceItem->price = intdiv($amount, $invoiceItem->quantity);
                } else {
                    // valor que não divide pela quantidade vira uma linha de valor total,
                    // para a soma dos itens continuar igual a amount
                    $invoiceItem->quantity = 1;
                }
            }
            $items[] = $invoiceItem;

            $periodEnd = $line->period->end ?? null;
            if (!empty($periodEnd) && (is_null($effectiveAt) || $periodEnd > $effectiveAt)) {
                $effectiveAt = $periodEnd;
            }
        }
        $planChange->items = $items;
        if (!is_null($effectiveAt)) {
            $planChange->effectiveAt = Carbon::createFromTimestamp($effectiveAt);
        }
        $planChange->appliesImmediately = true;
        $planChange->gateway = 'stripe';
        $planChange->original = $preview;

        return $planChange;
    }

    /**
     * @inheritDoc
     *
     * Traz assinaturas em qualquer status (`status` `all`), sem `latestInvoice` (use
     * `getSubscription()` para a fatura). A paginação da Stripe é por cursor, então uma
     * página além da primeira custa uma requisição por página anterior.
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

        $stripeSubscriptions = $this->stripeListPage(
            fn (array $params) => $this->client->subscriptions->all($params),
            [
                'customer' => $customer->id,
                'status' => 'all',
                'limit' => $limit,
                'expand' => ['data.default_payment_method', 'data.discounts.source.coupon'],
            ],
            $page
        );

        return array_map(
            fn ($stripeSubscription) => $this->parseStripeSubscription($stripeSubscription),
            $stripeSubscriptions
        );
    }

    /**
     * Resolve o único método de pagamento da assinatura, como `invoicePaymentMethod()` faz
     * para a fatura; nulo quando o model não aponta método (a Stripe cobra o método padrão do
     * cliente). Pix Automático é aceito (o mandato da assinatura); mais de um método é
     * recusado (`MULTIPLE_PAYMENT_METHODS`), e um método fora do mapa do driver é recusado
     * pela capability dele.
     *
     * @param  Subscription  $subscription
     * @return PaymentMethod|null
     * @throws ModelAttributeValidationException|UnsupportedOperationException
     */
    private function subscriptionPaymentMethod(Subscription $subscription): ?PaymentMethod
    {
        $methods = $subscription->resolvedPaymentMethods();

        if (count($methods) > 1) {
            throw UnsupportedOperationException::forGateway(
                $this,
                Capability::MULTIPLE_PAYMENT_METHODS,
                'Informe exatamente um método em availablePaymentMethods.'
            );
        }

        $method = empty($methods) ? null : reset($methods);
        if (
            !is_null($method)
            && $method !== PaymentMethod::AUTOMATIC_PIX
            && !in_array($method, self::PAYMENT_METHOD_TYPES, true)
        ) {
            throw UnsupportedOperationException::forGateway($this, Capability::forPaymentMethod($method));
        }

        return $method;
    }

    /**
     * Tipo de PaymentMethod da Stripe para um método do pacote (o inverso de
     * `PAYMENT_METHOD_TYPES`).
     *
     * @param  PaymentMethod  $paymentMethod
     * @return string
     */
    private static function paymentMethodToStripeType(PaymentMethod $paymentMethod): string
    {
        return (string) array_search($paymentMethod, self::PAYMENT_METHOD_TYPES, true);
    }

    /**
     * Payload de `discounts` da assinatura: desconto com `id` mantém o Coupon existente;
     * desconto novo cria um Coupon (`{chave}:discount{N}_coupon`) com a duração derivada de
     * `cycles` e `validUntil`.
     *
     * @param  SubscriptionDiscount[]  $discounts
     * @param  callable  $planRecurring  devolve `{interval, interval_count}` do plano, lido sob demanda
     * @param  string|null  $idempotencyKey
     * @return array
     * @throws ModelAttributeValidationException|UnsupportedOperationException
     */
    private function stripeSubscriptionDiscountsData(array $discounts, callable $planRecurring, ?string $idempotencyKey): array
    {
        $data = [];
        foreach (array_values($discounts) as $index => $discount) {
            if (!empty($discount->id)) {
                $data[] = ['coupon' => $discount->id];
                continue;
            }

            $couponData = $this->stripeCouponData($discount, $planRecurring);
            $stripeCoupon = $this->stripeRequest(function () use ($couponData, $index, $idempotencyKey) {
                return $this->client->coupons->create(
                    $couponData,
                    self::stripeOptions(self::derivedIdempotencyKey($idempotencyKey, "discount{$index}_coupon"))
                );
            });

            $data[] = ['coupon' => $stripeCoupon->id];
        }

        return $data;
    }

    /**
     * Payload de um Coupon a partir de um desconto de assinatura: `percent_off` ou
     * `amount_off` em `brl`, e a duração de `stripeCouponDuration()`.
     *
     * @param  SubscriptionDiscount  $discount
     * @param  callable  $planRecurring  devolve `{interval, interval_count}` do plano, lido sob demanda
     * @return array
     * @throws ModelAttributeValidationException|UnsupportedOperationException
     */
    private function stripeCouponData(SubscriptionDiscount $discount, callable $planRecurring): array
    {
        if (empty($discount->description)) {
            throw ModelAttributeValidationException::required('SubscriptionDiscount', 'description');
        }
        if (is_null($discount->percentOff) && is_null($discount->amountOff)) {
            throw ModelAttributeValidationException::required('SubscriptionDiscount', 'amountOff or percentOff');
        }

        $couponData = ['name' => $discount->description];
        if (!is_null($discount->percentOff)) {
            $couponData['percent_off'] = $discount->percentOff;
        } else {
            $couponData['amount_off'] = $discount->amountOff;
            $couponData['currency'] = 'brl';
        }

        return array_merge($couponData, $this->stripeCouponDuration($discount, $planRecurring));
    }

    /**
     * Duração do Coupon: `cycles` 1 é `once`; desconto sem prazo é `forever`; `validUntil` e
     * `cycles` acima de 1 viram `repeating` com `duration_in_months` (meses até `validUntil`,
     * arredondados para cima, ou os meses de `cycles` ciclos do plano). `validUntil` no
     * passado é recusado com `ModelAttributeValidationException`, e plano com intervalo
     * fora de mês e ano com `cycles` acima de 1 é recusado, porque a duração do Coupon só
     * conta em meses.
     *
     * @param  SubscriptionDiscount  $discount
     * @param  callable  $planRecurring  devolve `{interval, interval_count}` do plano, lido sob demanda
     * @return array
     * @throws ModelAttributeValidationException|UnsupportedOperationException
     */
    private function stripeCouponDuration(SubscriptionDiscount $discount, callable $planRecurring): array
    {
        if ($discount->cycles === 1) {
            return ['duration' => 'once'];
        }

        if (!empty($discount->validUntil)) {
            $now = Carbon::now();
            if ($discount->validUntil->lessThanOrEqualTo($now)) {
                throw ModelAttributeValidationException::invalid(
                    'SubscriptionDiscount',
                    'validUntil',
                    'validUntil must be a future date to create the coupon.'
                );
            }

            // diffInMonths trunca em algumas versões do Carbon; o mês parcial conta inteiro
            $months = (int) $now->diffInMonths($discount->validUntil);
            if ($now->copy()->addMonths($months)->lessThan($discount->validUntil)) {
                $months++;
            }

            return ['duration' => 'repeating', 'duration_in_months' => max(1, $months)];
        }

        if (is_null($discount->cycles)) {
            return ['duration' => 'forever'];
        }

        $recurring = $planRecurring();
        $monthsPerCycle = match ($recurring['interval'] ?? null) {
            'month' => (int) ($recurring['interval_count'] ?? 1),
            'year' => 12 * (int) ($recurring['interval_count'] ?? 1),
            default => throw UnsupportedOperationException::restricted(
                (string) $this,
                Capability::COUPONS,
                'O cupom da Stripe dura meses inteiros, então cycles acima de 1 exige plano com'
                . ' intervalo mensal ou anual; use validUntil.'
            ),
        };

        return ['duration' => 'repeating', 'duration_in_months' => $discount->cycles * $monthsPerCycle];
    }

    /**
     * Intervalo de cobrança do plano de uma assinatura existente, no formato de
     * `price_data.recurring`: lido de `original` quando a assinatura veio do gateway, senão do
     * item do plano na Stripe (uma leitura).
     *
     * @param  Subscription  $subscription
     * @return array{interval: string|null, interval_count: int}
     * @throws GatewayException|NotFoundException
     */
    private function stripePlanRecurringForUpdate(Subscription $subscription): array
    {
        $stripeItems = $subscription->original->items->data ?? null;
        $planItem = is_null($stripeItems)
            ? $this->currentStripePlanItem($subscription)
            : self::stripePlanItem((array) $stripeItems, $subscription->planId);
        $recurring = $planItem->price->recurring ?? null;

        return [
            'interval' => $recurring->interval ?? null,
            'interval_count' => (int) ($recurring->interval_count ?? 1),
        ];
    }

    /**
     * Diz se os descontos informados são os mesmos que vieram do gateway na leitura: todos com
     * `id` e na mesma ordem dos Coupons da assinatura.
     *
     * @param  Subscription  $subscription
     * @return bool
     */
    private function isOriginalStripeDiscounts(Subscription $subscription): bool
    {
        $original = $subscription->original->discounts ?? null;
        if (!is_array($original) && !$original instanceof \Traversable) {
            return false;
        }

        $originalCoupons = [];
        foreach ($original as $stripeDiscount) {
            $coupon = is_object($stripeDiscount) ? ($stripeDiscount->source->coupon ?? null) : null;
            $originalCoupons[] = is_object($coupon) ? ($coupon->id ?? null) : (is_string($coupon) ? $coupon : null);
        }

        $modelCoupons = array_map(
            static fn (SubscriptionDiscount $discount) => $discount->id,
            array_values($subscription->discounts)
        );

        return $modelCoupons === $originalCoupons && !in_array(null, $modelCoupons, true);
    }

    /**
     * Converte um discount da Stripe (com o Coupon expandido em `source.coupon`) num desconto
     * de assinatura. O `id` é o do Coupon; `cycles` volta 1 para `once` e nulo nos demais
     * casos, e a duração `repeating` traz o fim em `validUntil`.
     *
     * @param  object  $stripeDiscount
     * @return SubscriptionDiscount
     */
    private function parseStripeSubscriptionDiscount(object $stripeDiscount): SubscriptionDiscount
    {
        $coupon = is_object($stripeDiscount->source->coupon ?? null) ? $stripeDiscount->source->coupon : null;

        $discount = new SubscriptionDiscount();
        $discount->id = $coupon->id ?? null;
        $discount->description = $coupon->name ?? null;
        $discount->amountOff = isset($coupon->amount_off) ? (int) $coupon->amount_off : null;
        $discount->percentOff = isset($coupon->percent_off) ? (float) $coupon->percent_off : null;
        $discount->cycles = ($coupon->duration ?? null) === 'once' ? 1 : null;
        $discount->validUntil = !empty($stripeDiscount->end ?? null)
            ? Carbon::createFromTimestamp($stripeDiscount->end)
            : null;

        return $discount;
    }

    /**
     * Cartão que a assinatura cobra: devolve o id do PaymentMethod para
     * `default_payment_method`. Cartão sem `id` é salvo antes por `createCreditCard()` (chave
     * derivada `{chave}:card`); um emissor que exija autenticação do pagador interrompe a
     * operação com `ChargingException` (`AUTHENTICATION_REQUIRED`), com o SetupIntent em
     * `chargeResponse`; conclua com `confirmCreditCardSetup()` e use o id do cartão salvo. O
     * cartão padrão do cliente não muda (na Iugu muda, porque lá a assinatura não tem cartão
     * próprio). Sem cartão no model devolve nulo.
     *
     * @param  Subscription  $subscription
     * @param  string|null  $idempotencyKey
     * @return string|null
     * @throws ChargingException|ModelAttributeValidationException
     */
    private function applyStripeSubscriptionCard(Subscription $subscription, ?string $idempotencyKey): ?string
    {
        $creditCard = $subscription->creditCard;
        if (empty($creditCard)) {
            return null;
        }
        if (!empty($creditCard->id)) {
            return $creditCard->id;
        }

        if (empty($creditCard->customer)) {
            $creditCard->customer = $subscription->customer;
        }
        $subscription->creditCard = $this->createCreditCard(
            $creditCard,
            self::derivedIdempotencyKey($idempotencyKey, 'card')
        );
        if ($subscription->creditCard->requiresAction) {
            $exception = ChargingException::declined(
                'stripe',
                DeclineCode::AUTHENTICATION_REQUIRED,
                'authentication_required',
                'O emissor exige autenticação do pagador para este cartão; salve-o com createCreditCard(),'
                . ' conclua a autenticação com confirmCreditCardSetup() e use o id do cartão salvo na assinatura.'
            );
            $exception->chargeResponse = $subscription->creditCard->original?->toArray();

            throw $exception;
        }

        return $subscription->creditCard->id;
    }

    /**
     * Payload dos itens extras da assinatura: item com `recurring` verdadeiro vira um
     * subscription item com Price recorrente criado sob demanda no intervalo do plano; item
     * com `recurring` falso vira um item avulso da primeira fatura (`add_invoice_items`).
     * Cada item cria um Product no Stripe (`price_data` exige um Product existente), com a
     * chave derivada `{chave}:item{N}_product`.
     *
     * @param  SubscriptionItem[]  $items
     * @param  string  $planPriceId
     * @param  string|null  $idempotencyKey
     * @return array{items: array, add_invoice_items: array}
     * @throws ModelAttributeValidationException
     */
    private function subscriptionItemsData(array $items, string $planPriceId, ?string $idempotencyKey): array
    {
        $data = ['items' => [], 'add_invoice_items' => []];
        $recurring = null;

        foreach (array_values($items) as $index => $item) {
            if (empty($item->description)) {
                throw ModelAttributeValidationException::required('SubscriptionItem', 'description');
            }
            if (is_null($item->amount)) {
                throw ModelAttributeValidationException::required('SubscriptionItem', 'amount');
            }

            $stripeProduct = $this->stripeRequest(function () use ($item, $index, $idempotencyKey) {
                return $this->client->products->create(
                    ['name' => $item->description],
                    self::stripeOptions(self::derivedIdempotencyKey($idempotencyKey, "item{$index}_product"))
                );
            });

            $priceData = [
                'currency' => 'brl',
                'product' => $stripeProduct->id,
                'unit_amount' => $item->amount,
            ];

            if ($item->recurring) {
                // todo item recorrente precisa do mesmo intervalo de cobrança do plano
                $recurring = $recurring ?? $this->stripePriceRecurring($planPriceId);
                $priceData['recurring'] = $recurring;
                $data['items'][] = ['price_data' => $priceData, 'quantity' => $item->quantity ?? 1];
            } else {
                $data['add_invoice_items'][] = ['price_data' => $priceData, 'quantity' => $item->quantity ?? 1];
            }
        }

        return $data;
    }

    /**
     * Intervalo de cobrança de um Price, no formato de `price_data.recurring`.
     *
     * @param  string  $priceId
     * @return array{interval: string, interval_count: int}
     */
    private function stripePriceRecurring(string $priceId): array
    {
        $stripePrice = $this->stripeRequest(function () use ($priceId) {
            return $this->client->prices->retrieve($priceId);
        });

        return [
            'interval' => $stripePrice->recurring->interval ?? 'month',
            'interval_count' => $stripePrice->recurring->interval_count ?? 1,
        ];
    }

    /**
     * Monta o `mandate_options` do Pix Automático de uma assinatura, a partir do plano e de
     * `Subscription::$automaticPix`. A agenda (`payment_schedule`) vem da frequência informada
     * em `automaticPix` ou do intervalo do plano (`PIX_MANDATE_SCHEDULES`); intervalo sem
     * agenda é recusado antes da requisição. O valor é a soma do plano com os itens
     * recorrentes; com desconto na assinatura o débito varia entre ciclos e o valor vira um
     * teto (`amount_type` `maximum`). O `start_date` vem de `automaticPix->startsAt`, senão do
     * fim do trial ou de `nextBillingAt`, com o mínimo de três dias a partir de hoje (data
     * derivada anterior ao mínimo é elevada a ele; data informada anterior é recusada). O
     * `end_date` vem de `automaticPix->endsAt` e o `reference` (nome exibido no aplicativo do
     * banco) da configuração `multi-payment.gateways.stripe.pix_mandate_reference`.
     *
     * @param  Subscription  $subscription
     * @param  string  $priceId
     * @return array
     * @throws ModelAttributeValidationException|UnsupportedOperationException
     * @throws GatewayException|GatewayNotAvailableException
     */
    private function pixMandateOptions(Subscription $subscription, string $priceId): array
    {
        $automaticPix = $subscription->automaticPix;
        $stripePrice = $this->stripeRequest(function () use ($priceId) {
            return $this->client->prices->retrieve($priceId);
        });

        $schedule = $automaticPix?->frequency;
        if (is_null($schedule)) {
            $interval = ($stripePrice->recurring->interval ?? 'month')
                . ':' . ($stripePrice->recurring->interval_count ?? 1);
            $schedule = self::PIX_MANDATE_SCHEDULES[$interval] ?? null;
            if (is_null($schedule)) {
                throw UnsupportedOperationException::restricted(
                    (string) $this,
                    Capability::AUTOMATIC_PIX,
                    'O Pix Automático aceita agenda semanal, mensal, trimestral, semestral ou anual,'
                    . " e o intervalo do plano [{$interval}] não corresponde a nenhuma delas;"
                    . ' informe a frequência em automaticPix.'
                );
            }
        }

        if (is_null($stripePrice->unit_amount ?? null)) {
            throw UnsupportedOperationException::restricted(
                (string) $this,
                Capability::AUTOMATIC_PIX,
                'O mandato de Pix Automático precisa do valor por ciclo, e o Price do plano'
                . " [{$priceId}] não tem unit_amount fixo (preço por camadas ou por uso);"
                . ' use um plano de valor fixo.'
            );
        }
        $amount = (int) $stripePrice->unit_amount;
        foreach ($subscription->items ?? [] as $item) {
            if ($item instanceof SubscriptionItem && $item->recurring && !is_null($item->amount)) {
                $amount += (int) $item->amount * (int) ($item->quantity ?? 1);
            }
        }

        $minimumStart = Carbon::now()->addDays(self::PIX_MANDATE_DEBIT_OFFSET_DAYS)->startOfDay();
        // as datas derivadas usam o início do dia: dentro do mesmo dia, o retry com a mesma
        // chave de idempotência reproduz o payload
        $startsAt = $automaticPix?->startsAt
            ?? $subscription->trialEndsAt
            ?? (!empty($subscription->trialDays) ? Carbon::now()->addDays($subscription->trialDays)->startOfDay() : null)
            ?? $subscription->nextBillingAt;
        if (!is_null($automaticPix?->startsAt) && $automaticPix->startsAt->lt($minimumStart)) {
            throw ModelAttributeValidationException::invalid(
                'AutomaticPix',
                'startsAt',
                'startsAt must be at least ' . self::PIX_MANDATE_DEBIT_OFFSET_DAYS
                . ' days from now for automatic pix on the stripe gateway'
            );
        }
        if (is_null($startsAt) || $startsAt->lt($minimumStart)) {
            $startsAt = $minimumStart;
        }

        $mandateOptions = [
            'amount' => $amount,
            'amount_type' => empty($subscription->discounts) ? 'fixed' : 'maximum',
            'payment_schedule' => $schedule,
            'start_date' => $startsAt->getTimestamp(),
        ];

        $reference = Config::get('multi-payment.gateways.stripe.pix_mandate_reference');
        if (!empty($reference)) {
            $mandateOptions['reference'] = $reference;
        }
        if (!is_null($automaticPix?->endsAt)) {
            $mandateOptions['end_date'] = $automaticPix->endsAt->getTimestamp();
        }

        return $mandateOptions;
    }

    /**
     * Itens da atualização declarativa: os subscription items atuais fora da lista desejada
     * são removidos (o item do plano fica), item com `id` tem a quantidade atualizada e item
     * novo cria Price sob demanda no intervalo do plano; item com `recurring` falso vai como
     * item avulso da próxima fatura. Faz um GET na assinatura para conhecer o estado atual.
     *
     * @param  Subscription  $subscription
     * @param  string|null  $idempotencyKey
     * @return array{items: array, add_invoice_items: array}
     */
    private function declarativeStripeItems(Subscription $subscription, ?string $idempotencyKey): array
    {
        $current = $this->stripeRequest(function () use ($subscription) {
            return $this->client->subscriptions->retrieve($subscription->id);
        });
        $currentItems = $current->items->data ?? [];
        $planItem = self::stripePlanItem($currentItems, $subscription->planId);
        if (is_null($planItem) || empty($planItem->price->id ?? null)) {
            throw new NotFoundException("No plan item found on subscription [{$subscription->id}] on stripe.");
        }

        $keptIds = [];
        foreach ($subscription->items as $item) {
            if (!empty($item->id)) {
                $keptIds[] = (string) $item->id;
            }
        }

        $entries = [];
        foreach ($currentItems as $stripeItem) {
            $id = $stripeItem->id ?? null;
            if (empty($id) || $id === ($planItem->id ?? null) || in_array((string) $id, $keptIds, true)) {
                continue;
            }
            $entries[] = ['id' => $id, 'deleted' => true];
        }

        $newItems = [];
        foreach ($subscription->items as $item) {
            if (!empty($item->id)) {
                if (!is_null($item->quantity)) {
                    $entries[] = ['id' => $item->id, 'quantity' => $item->quantity];
                }
                continue;
            }
            $newItems[] = $item;
        }

        $created = $this->subscriptionItemsData($newItems, $planItem->price->id, $idempotencyKey);

        return [
            'items' => array_merge($entries, $created['items']),
            'add_invoice_items' => $created['add_invoice_items'],
        ];
    }

    /**
     * Item do plano da assinatura, lido do gateway.
     *
     * @param  Subscription  $subscription
     * @return object
     * @throws NotFoundException|GatewayException|GatewayNotAvailableException
     */
    private function currentStripePlanItem(Subscription $subscription): object
    {
        $current = $this->stripeRequest(function () use ($subscription) {
            return $this->client->subscriptions->retrieve($subscription->id);
        });

        $planItem = self::stripePlanItem($current->items->data ?? [], $subscription->planId);
        if (is_null($planItem) || empty($planItem->id)) {
            throw new NotFoundException("No plan item found on subscription [{$subscription->id}] on stripe.");
        }

        return $planItem;
    }

    /**
     * Item do plano entre os subscription items: o que aponta o Price cujo `lookup_key` ou id
     * é o `planId` conhecido. Sem correspondência, o único item cujo Price tem `lookup_key`
     * (os Prices criados sob demanda para itens extras não têm um); em último caso, o item
     * mais antigo, porque o do plano nasce com a assinatura e os extras entram depois.
     *
     * @param  array  $stripeItems
     * @param  string|null  $planId
     * @return object|null
     */
    private static function stripePlanItem(array $stripeItems, ?string $planId): ?object
    {
        if (!is_null($planId)) {
            foreach ($stripeItems as $stripeItem) {
                $price = $stripeItem->price ?? null;
                if (($price->lookup_key ?? null) === $planId || ($price->id ?? null) === $planId) {
                    return $stripeItem;
                }
            }
        }

        $withLookupKey = array_values(array_filter(
            $stripeItems,
            static fn ($stripeItem) => !empty($stripeItem->price->lookup_key ?? null)
        ));
        if (count($withLookupKey) === 1) {
            return $withLookupKey[0];
        }

        $oldest = null;
        foreach ($stripeItems as $stripeItem) {
            if (is_null($oldest) || ($stripeItem->created ?? PHP_INT_MAX) < ($oldest->created ?? PHP_INT_MAX)) {
                $oldest = $stripeItem;
            }
        }

        return $oldest;
    }

    /**
     * Recusa `nextBillingAt` diferente do que veio do gateway: fora da criação, a Stripe não
     * aceita uma data arbitrária de próxima cobrança (a restrição consultável de
     * `SUBSCRIPTIONS`). Um model lido do gateway, com a data que ele mesmo devolveu, passa.
     *
     * @param  Subscription  $subscription
     * @return void
     * @throws UnsupportedOperationException
     */
    private function assertNextBillingAtIsUnchanged(Subscription $subscription): void
    {
        if (empty($subscription->nextBillingAt)) {
            return;
        }

        $original = $subscription->original->items->data ?? [];
        $planItem = self::stripePlanItem(is_array($original) ? $original : [], $subscription->planId);
        $originalPeriodEnd = $planItem->current_period_end ?? null;
        // igualdade exata: a leitura preenche nextBillingAt com este mesmo timestamp, então
        // qualquer diferença é uma mudança pedida pelo consumidor
        if (!empty($originalPeriodEnd) && (int) $originalPeriodEnd === $subscription->nextBillingAt->getTimestamp()) {
            return;
        }

        throw UnsupportedOperationException::restricted(
            (string) $this,
            Capability::SUBSCRIPTIONS,
            'A Stripe não aceita definir a data da próxima cobrança de uma assinatura existente;'
            . ' nextBillingAt vale só na criação (billing_cycle_anchor).'
        );
    }

    /**
     * Diz se o método informado é o mesmo que veio do gateway na leitura
     * (`payment_settings.payment_method_types`).
     *
     * @param  Subscription  $subscription
     * @param  PaymentMethod  $paymentMethod
     * @return bool
     */
    private function isOriginalStripePaymentMethod(Subscription $subscription, PaymentMethod $paymentMethod): bool
    {
        $original = $subscription->original->payment_settings->payment_method_types ?? null;

        return is_array($original) && $original === [self::paymentMethodToStripeType($paymentMethod)];
    }

    /**
     * Diz se o fim do trial informado é o mesmo que veio do gateway na leitura (`trial_end`).
     *
     * @param  Subscription  $subscription
     * @return bool
     */
    private function isOriginalStripeTrialEnd(Subscription $subscription): bool
    {
        $original = $subscription->original->trial_end ?? null;

        return !empty($original) && (int) $original === $subscription->trialEndsAt->getTimestamp();
    }

    /**
     * Converte a Subscription da Stripe numa assinatura do MultiPayment.
     *
     * O item cujo Price é o plano (`stripePlanItem()`) dá o `planId` (`lookup_key`, senão o
     * id do Price) e a próxima cobrança (`current_period_end`); os demais itens viram
     * `items`, e `amount` é a soma dos itens por ciclo. Os discounts expandidos viram
     * `discounts`, com o id do Coupon. `paymentMethod` vem do PaymentMethod
     * padrão expandido, senão do único tipo em `payment_settings`; quando o padrão é um
     * cartão, `creditCard` recebe id, bandeira, últimos dígitos e validade
     * (`parseSubscriptionCardDetails()`). Com $withLatestInvoice, a
     * fatura mais recente é lida por inteiro (`parseFromStripeInvoice()`), uma leitura a
     * mais.
     *
     * @param  \Stripe\Subscription  $stripeSubscription
     * @param  Subscription|null  $subscription
     * @param  bool  $withLatestInvoice
     * @return Subscription
     * @throws GatewayException|GatewayNotAvailableException
     */
    private function parseStripeSubscription(
        StripeSubscription $stripeSubscription,
        ?Subscription $subscription = null,
        bool $withLatestInvoice = false
    ): Subscription {
        $subscription = $subscription ?? new Subscription();

        $subscription->id = $stripeSubscription->id ?? $subscription->id;
        $subscription->status = SubscriptionStatuses::toSubscriptionStatus($stripeSubscription);

        $stripeItems = $stripeSubscription->items->data ?? [];
        $planItem = self::stripePlanItem($stripeItems, $subscription->planId);
        $planPrice = $planItem->price ?? null;
        $subscription->planId = $planPrice->lookup_key ?? $planPrice->id ?? $subscription->planId;

        $amount = 0;
        $hasAmount = false;
        $items = [];
        foreach ($stripeItems as $stripeItem) {
            $unitAmount = $stripeItem->price->unit_amount ?? null;
            $quantity = (int) ($stripeItem->quantity ?? 1);
            if (!is_null($unitAmount)) {
                $amount += $unitAmount * $quantity;
                $hasAmount = true;
            }
            if (($stripeItem->id ?? null) === ($planItem->id ?? null)) {
                continue;
            }
            $items[] = $this->parseStripeSubscriptionItem($stripeItem);
        }
        if ($hasAmount) {
            $subscription->amount = $amount;
        }
        if (!empty($stripeItems)) {
            $subscription->items = $items;
        }

        $stripeDiscounts = $stripeSubscription->discounts ?? null;
        if (is_array($stripeDiscounts) || $stripeDiscounts instanceof \Traversable) {
            $discounts = [];
            $unexpanded = false;
            foreach ($stripeDiscounts as $stripeDiscount) {
                // sem expand o discount vem como id (string), que não tem o Coupon para ler
                if (is_object($stripeDiscount)) {
                    $discounts[] = $this->parseStripeSubscriptionDiscount($stripeDiscount);
                } else {
                    $unexpanded = true;
                }
            }
            // com um discount ilegível, a lista fica como está: gravar uma lista incompleta
            // faria um save() posterior remover da assinatura os descontos que existem
            if (!$unexpanded) {
                $subscription->discounts = $discounts;
            }
        }

        $customerId = is_object($stripeSubscription->customer ?? null)
            ? $stripeSubscription->customer->id
            : ($stripeSubscription->customer ?? null);
        if (!empty($customerId)) {
            // com um id diferente, manter os atributos antigos produziria um Customer com id
            // de um e documento de outro
            if (is_null($subscription->customer) || $subscription->customer->id !== $customerId) {
                $subscription->customer = new Customer();
            }
            $subscription->customer->id = $customerId;
        }

        if (!empty($planItem->current_period_end ?? null)) {
            $subscription->nextBillingAt = Carbon::createFromTimestamp($planItem->current_period_end);
        }
        if (!empty($stripeSubscription->trial_end ?? null)) {
            $subscription->trialEndsAt = Carbon::createFromTimestamp($stripeSubscription->trial_end);
        }
        $subscription->cancelAtPeriodEnd = (bool) ($stripeSubscription->cancel_at_period_end ?? false);
        $subscription->canceledAt = !empty($stripeSubscription->canceled_at ?? null)
            ? Carbon::createFromTimestamp($stripeSubscription->canceled_at)
            : null;
        if (!empty($stripeSubscription->created ?? null)) {
            $subscription->createdAt = Carbon::createFromTimestamp($stripeSubscription->created);
        }

        if (isset($stripeSubscription->metadata)) {
            $metadata = $stripeSubscription->metadata;
            $subscription->metadata = is_object($metadata) && method_exists($metadata, 'toArray')
                ? $metadata->toArray()
                : (array) $metadata;
        }

        $pixMandateOptions = $stripeSubscription->payment_settings->payment_method_options->pix->mandate_options ?? null;
        if (is_object($pixMandateOptions)) {
            // o mandato faz do Pix Automático o método da assinatura; a lista fica de fora
            // para um save() posterior não recusar o método como ausente dela, e um cartão
            // que sobrou no model sai, porque a assinatura com mandato não cobra cartão
            $subscription->creditCard = null;
            $subscription->paymentMethod = PaymentMethod::AUTOMATIC_PIX;
            $subscription->automaticPix = $this->parsePixMandateOptions(
                $pixMandateOptions,
                $subscription->automaticPix,
                $planItem->current_period_end ?? null
            );
        } else {
            $defaultPaymentMethod = $stripeSubscription->default_payment_method ?? null;
            $method = is_object($defaultPaymentMethod)
                ? (self::PAYMENT_METHOD_TYPES[$defaultPaymentMethod->type ?? ''] ?? null)
                : null;
            $this->parseSubscriptionCardDetails($subscription, $defaultPaymentMethod);
            $types = $stripeSubscription->payment_settings->payment_method_types ?? null;
            if (is_array($types)) {
                $methods = array_values(array_filter(array_map(
                    static fn ($type) => self::PAYMENT_METHOD_TYPES[$type] ?? null,
                    $types
                )));
                if (!empty($methods)) {
                    $subscription->availablePaymentMethods = $methods;
                    $method = $method ?? (count($methods) === 1 ? $methods[0] : null);
                }
            }
            if (!is_null($method)) {
                $subscription->paymentMethod = $method;
            }
        }

        if ($withLatestInvoice) {
            $latestInvoiceId = is_object($stripeSubscription->latest_invoice ?? null)
                ? $stripeSubscription->latest_invoice->id
                : ($stripeSubscription->latest_invoice ?? null);
            if (!empty($latestInvoiceId)) {
                $subscription->latestInvoice = $this->parseInvoice($this->retrieveStripeInvoice($latestInvoiceId));
            }
        }

        $subscription->currency = isset($stripeSubscription->currency)
            ? strtoupper($stripeSubscription->currency)
            : $subscription->currency;
        $subscription->gateway = 'stripe';
        $subscription->original = $stripeSubscription;

        return $subscription;
    }

    /**
     * Preenche `Subscription::$creditCard` com o cartão do PaymentMethod padrão expandido (id,
     * bandeira, últimos dígitos e validade). Um PaymentMethod padrão expandido de outro tipo
     * limpa o campo, para um cartão de leitura ou escrita anterior não sobreviver no model;
     * sem PaymentMethod padrão (nulo ou sem expand), o campo fica como está.
     *
     * @param  Subscription  $subscription
     * @param  object|string|null  $defaultPaymentMethod
     * @return void
     */
    private function parseSubscriptionCardDetails(Subscription $subscription, object|string|null $defaultPaymentMethod): void
    {
        // isset() passa pelo __isset: num PaymentMethod de outro tipo a chave `card` não
        // existe e o StripeObject registraria "Undefined property" no log ao lê-la
        $cardDetails = is_object($defaultPaymentMethod) && isset($defaultPaymentMethod->card)
            ? $defaultPaymentMethod->card
            : null;
        if (empty($cardDetails)) {
            if (is_object($defaultPaymentMethod)) {
                $subscription->creditCard = null;
            }

            return;
        }

        if (empty($subscription->creditCard)) {
            $subscription->creditCard = new CreditCard();
        }
        $subscription->creditCard->id = $defaultPaymentMethod->id ?? $subscription->creditCard->id;
        $subscription->creditCard->brand = $cardDetails->brand ?? null;
        $subscription->creditCard->lastDigits = $cardDetails->last4 ?? null;
        $subscription->creditCard->month = isset($cardDetails->exp_month)
            ? str_pad((string) $cardDetails->exp_month, 2, '0', STR_PAD_LEFT)
            : $subscription->creditCard->month;
        $subscription->creditCard->year = isset($cardDetails->exp_year)
            ? (string) $cardDetails->exp_year
            : $subscription->creditCard->year;
        $subscription->creditCard->gateway = 'stripe';
    }

    /**
     * Converte o `mandate_options` do Pix Automático de uma assinatura no model genérico. A
     * frequência é a agenda (`payment_schedule`), as datas vêm de `start_date` e `end_date` e
     * as duas datas derivadas seguem o ciclo: a notificação de pré-débito sai no início do
     * ciclo (`current_period_end` da leitura) e o débito acontece três dias depois. O id e o
     * status do mandato não vêm na assinatura; chegam pelo webhook `mandate.updated` ou pela
     * consulta de cancelamentos.
     *
     * @param  object  $mandateOptions
     * @param  AutomaticPix|null  $automaticPix
     * @param  int|null  $currentPeriodEnd
     * @return AutomaticPix
     */
    private function parsePixMandateOptions(
        object $mandateOptions,
        ?AutomaticPix $automaticPix,
        ?int $currentPeriodEnd
    ): AutomaticPix {
        $automaticPix ??= new AutomaticPix();

        $automaticPix->frequency = $mandateOptions->payment_schedule ?? $automaticPix->frequency;
        if (!empty($mandateOptions->start_date)) {
            $automaticPix->startsAt = Carbon::createFromTimestamp($mandateOptions->start_date);
        }
        if (!empty($mandateOptions->end_date)) {
            $automaticPix->endsAt = Carbon::createFromTimestamp($mandateOptions->end_date);
        }
        if (!empty($currentPeriodEnd)) {
            $automaticPix->preDebitNotificationAt = Carbon::createFromTimestamp($currentPeriodEnd);
            $automaticPix->nextDebitAt = Carbon::createFromTimestamp($currentPeriodEnd)
                ->addDays(self::PIX_MANDATE_DEBIT_OFFSET_DAYS);
        }
        $automaticPix->gateway = 'stripe';
        $automaticPix->original = $mandateOptions;

        return $automaticPix;
    }

    /**
     * Converte um subscription item da Stripe (fora o do plano) num item de assinatura. A
     * descrição vem do Product expandido, senão do apelido do Price.
     *
     * @param  object  $stripeItem
     * @return SubscriptionItem
     */
    private function parseStripeSubscriptionItem(object $stripeItem): SubscriptionItem
    {
        $price = $stripeItem->price ?? null;
        $product = is_object($price->product ?? null) ? $price->product : null;

        $item = new SubscriptionItem();
        $item->id = $stripeItem->id ?? null;
        $item->description = $product?->name ?? $price->nickname ?? null;
        $item->amount = $price->unit_amount ?? null;
        $item->quantity = isset($stripeItem->quantity) ? (int) $stripeItem->quantity : null;
        $item->recurring = true;

        return $item;
    }

    /**
     * @inheritDoc
     *
     * No Stripe a Stripe agenda e retenta cada débito do mandato; a operação lança
     * `UnsupportedOperationException` com `reason` `managed_by_gateway` sem nenhuma
     * requisição.
     */
    public function rescheduleAutomaticPixPayment(Invoice $invoice, ?string $idempotencyKey = null): Invoice
    {
        throw UnsupportedOperationException::managedByGateway(
            (string) $this,
            Capability::AUTOMATIC_PIX,
            'A Stripe agenda e retenta as cobranças do mandato; não há reagendamento pela lib.'
        );
    }

    /**
     * @inheritDoc
     *
     * No Stripe cada débito do mandato é conduzido pela Stripe; a operação lança
     * `UnsupportedOperationException` com `reason` `managed_by_gateway` sem nenhuma
     * requisição.
     */
    public function cancelAutomaticPixScheduledPayment(
        AutomaticPixCharge $charge,
        ?string $idempotencyKey = null
    ): AutomaticPixCancellation {
        throw UnsupportedOperationException::managedByGateway(
            (string) $this,
            Capability::AUTOMATIC_PIX,
            'A Stripe conduz cada débito do mandato; não há cancelamento de um agendamento pela lib.'
        );
    }

    /**
     * @inheritDoc
     *
     * No Stripe o mandato vive na assinatura e é encerrado com ela; a operação lança
     * `UnsupportedOperationException` com `reason` `managed_by_gateway` orientando o
     * cancelamento da assinatura.
     */
    public function cancelAutomaticPixRecurrence(
        AutomaticPix $automaticPix,
        ?string $idempotencyKey = null
    ): AutomaticPixCancellation {
        throw UnsupportedOperationException::managedByGateway(
            (string) $this,
            Capability::AUTOMATIC_PIX,
            'O mandato vive na assinatura: cancele a assinatura (cancelSubscription) e a Stripe o encerra.'
        );
    }

    /**
     * @inheritDoc
     *
     * No Stripe não existe um objeto de cancelamento: a consulta lê o Mandate
     * (`recurrenceId`, id `mandate_`) e responde pelo status dele. Mandato `inactive` devolve
     * o cancelamento como `completed`; mandato ainda ativo lança `NotFoundException`.
     */
    public function getAutomaticPixCancellation(AutomaticPixCancellation $cancellation): AutomaticPixCancellation
    {
        if (empty($cancellation->recurrenceId)) {
            throw ModelAttributeValidationException::required('AutomaticPixCancellation', 'recurrenceId');
        }

        $stripeMandate = $this->retrieveStripeMandate($cancellation->recurrenceId);
        if (($stripeMandate->status ?? null) !== self::MANDATE_STATUS_INACTIVE) {
            throw new NotFoundException(
                "The automatic pix recurrence [{$cancellation->recurrenceId}] has no cancellation on stripe:"
                . ' the mandate is still active.'
            );
        }

        return $this->parseMandateCancellation($stripeMandate, $cancellation);
    }

    /**
     * @inheritDoc
     *
     * No Stripe não existe um objeto de cancelamento: a consulta lê o Mandate (`id` do model,
     * `mandate_`) e devolve no máximo um item, `completed`, quando o mandato está `inactive`
     * (lista vazia com o mandato ativo ou fora da primeira página). A leitura também preenche
     * `mandateId` e `mandateStatus` no model informado.
     */
    public function listAutomaticPixCancellations(AutomaticPix $automaticPix, int $page = 1, int $limit = 100): array
    {
        if (empty($automaticPix->id)) {
            throw ModelAttributeValidationException::required('AutomaticPix', 'id');
        }

        $stripeMandate = $this->retrieveStripeMandate($automaticPix->id);
        $automaticPix->mandateId = $stripeMandate->id ?? $automaticPix->id;
        $automaticPix->mandateStatus = $stripeMandate->status ?? null;

        if ($page > 1 || ($stripeMandate->status ?? null) !== self::MANDATE_STATUS_INACTIVE) {
            return [];
        }

        return [$this->parseMandateCancellation($stripeMandate)];
    }

    /**
     * Lê um Mandate da Stripe pelo id.
     *
     * @param  string  $mandateId
     * @return \Stripe\Mandate
     * @throws GatewayException|GatewayNotAvailableException
     */
    private function retrieveStripeMandate(string $mandateId)
    {
        return $this->stripeRequest(function () use ($mandateId) {
            return $this->client->mandates->retrieve($mandateId);
        });
    }

    /**
     * Converte um Mandate `inactive` no cancelamento genérico: `completed`, com o id do
     * mandato como recorrência. A Stripe não informa a data do encerramento.
     *
     * @param  object  $stripeMandate
     * @param  AutomaticPixCancellation|null  $cancellation
     * @return AutomaticPixCancellation
     */
    private function parseMandateCancellation(
        object $stripeMandate,
        ?AutomaticPixCancellation $cancellation = null
    ): AutomaticPixCancellation {
        $cancellation ??= new AutomaticPixCancellation();

        $cancellation->id ??= $stripeMandate->id ?? null;
        $cancellation->recurrenceId ??= $stripeMandate->id ?? null;
        $cancellation->status = AutomaticPixCancellation::STATUS_COMPLETED;
        $cancellation->gateway = 'stripe';
        $cancellation->original = $stripeMandate;

        return $cancellation;
    }

    /**
     * @inheritDoc
     *
     * Verifica o `Stripe-Signature` (HMAC-SHA256 de `{timestamp}.{corpo}` com o secret de
     * `multi-payment.gateways.stripe.webhook_secret`, comparado em tempo constante) e recusa a
     * entrega cujo timestamp assinado está fora da tolerância
     * (`multi-payment.gateways.stripe.webhook_tolerance`, 300 segundos por padrão). O tipo
     * comum sai do nome do evento; `customer.subscription.updated` vira `SUBSCRIPTION_CANCELED`
     * quando a assinatura está cancelada ou com `cancel_at_period_end` e `SUBSCRIPTION_SUSPENDED`
     * quando `pause_collection` está preenchido, e `invoice.paid` de um ciclo de renovação
     * (`billing_reason` `subscription_cycle`) vira `SUBSCRIPTION_RENEWED`, com a fatura paga em
     * `invoice()`. Num evento cujo objeto traz `last_payment_error`, o `declineCode` é
     * preenchido do payload.
     */
    public function parseWebhook(string $rawBody, array $headers): WebhookEvent
    {
        $this->verifyWebhookSignature($rawBody, $headers);

        $payload = json_decode($rawBody, true);

        $event = new WebhookEvent();
        $event->gateway = 'stripe';

        if (!is_array($payload)) {
            LogHelper::warning('Corpo de webhook da Stripe com assinatura válida e JSON inválido', ['gateway' => 'stripe']);
            $event->type = WebhookEventType::UNKNOWN;
            $event->raw = $rawBody;

            return $event;
        }

        $stripeObject = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : [];

        $event->id = $payload['id'] ?? null;
        $event->type = self::webhookEventType($payload['type'] ?? '', $stripeObject);
        $event->occurredAt = !empty($payload['created']) ? Carbon::createFromTimestamp($payload['created']) : null;
        $event->resourceType = $stripeObject['object'] ?? null;
        $event->resourceId = $stripeObject['id'] ?? null;
        $event->raw = $payload;

        $this->fillWebhookResourceIds($event, $stripeObject);

        $error = $stripeObject['last_payment_error'] ?? null;
        $gatewayCode = is_array($error) ? (($error['decline_code'] ?? null) ?: ($error['code'] ?? null)) : null;
        if (!empty($gatewayCode)) {
            $event->declineCode = self::declineCodeFromGatewayCode($gatewayCode);
        }

        return $event;
    }

    /**
     * Verifica o cabeçalho `Stripe-Signature` da entrega sobre o corpo cru recebido.
     *
     * @param  string  $rawBody
     * @param  array  $headers
     * @return void
     * @throws WebhookSignatureException
     */
    private function verifyWebhookSignature(string $rawBody, array $headers): void
    {
        $secret = Config::get('multi-payment.gateways.stripe.webhook_secret');
        if (empty($secret)) {
            throw WebhookSignatureException::missingSecret('stripe', 'multi-payment.gateways.stripe.webhook_secret');
        }

        $header = self::webhookHeaderValue($headers, self::WEBHOOK_SIGNATURE_HEADER);
        if (is_null($header) || trim($header) === '') {
            throw WebhookSignatureException::missingHeader('stripe', self::WEBHOOK_SIGNATURE_HEADER);
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if (!is_numeric($timestamp) || empty($signatures)) {
            throw WebhookSignatureException::invalidSignature('stripe');
        }

        $tolerance = (int) (Config::get('multi-payment.gateways.stripe.webhook_tolerance')
            ?? self::WEBHOOK_DEFAULT_TOLERANCE_SECONDS);
        if ($tolerance > 0 && abs(Carbon::now()->getTimestamp() - (int) $timestamp) > $tolerance) {
            throw WebhookSignatureException::timestampOutOfTolerance('stripe', $tolerance);
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw WebhookSignatureException::invalidSignature('stripe');
    }

    /**
     * Valor de um cabeçalho da entrega, sem diferenciar maiúsculas no nome; um valor em lista
     * (como o Laravel entrega) devolve o primeiro item.
     *
     * @param  array  $headers
     * @param  string  $name
     * @return string|null
     */
    private static function webhookHeaderValue(array $headers, string $name): ?string
    {
        foreach ($headers as $headerName => $value) {
            if (strcasecmp((string) $headerName, $name) !== 0) {
                continue;
            }
            $value = is_array($value) ? reset($value) : $value;

            return is_string($value) ? $value : null;
        }

        return null;
    }

    /**
     * Traduz o nome do evento da Stripe para o tipo comum, aplicando as regras que dependem do
     * objeto: `customer.subscription.updated` com status `canceled` ou `cancel_at_period_end`
     * lê como cancelamento, e com `pause_collection` preenchido como suspensão; `invoice.paid`
     * com `billing_reason` `subscription_cycle` lê como renovação da assinatura.
     *
     * @param  string  $stripeType
     * @param  array  $stripeObject  o `data.object` do evento
     * @return WebhookEventType
     */
    private static function webhookEventType(string $stripeType, array $stripeObject): WebhookEventType
    {
        if ($stripeType === 'customer.subscription.updated') {
            if (($stripeObject['status'] ?? null) === 'canceled' || !empty($stripeObject['cancel_at_period_end'])) {
                return WebhookEventType::SUBSCRIPTION_CANCELED;
            }
            if (!empty($stripeObject['pause_collection'])) {
                return WebhookEventType::SUBSCRIPTION_SUSPENDED;
            }

            return WebhookEventType::SUBSCRIPTION_UPDATED;
        }

        if ($stripeType === 'invoice.paid') {
            return ($stripeObject['billing_reason'] ?? null) === 'subscription_cycle'
                ? WebhookEventType::SUBSCRIPTION_RENEWED
                : WebhookEventType::INVOICE_PAID;
        }

        return self::WEBHOOK_EVENT_TYPES[$stripeType] ?? WebhookEventType::UNKNOWN;
    }

    /**
     * Preenche `invoiceId`, `subscriptionId` e `disputeId` a partir do objeto do evento, para a
     * hidratação: Invoice aponta a própria fatura e a assinatura de origem, PaymentIntent é a
     * fatura de venda avulsa, charge e dispute apontam a fatura pelo PaymentIntent.
     *
     * @param  WebhookEvent  $event
     * @param  array  $stripeObject  o `data.object` do evento
     * @return void
     */
    private function fillWebhookResourceIds(WebhookEvent $event, array $stripeObject): void
    {
        $id = $stripeObject['id'] ?? null;

        switch ($stripeObject['object'] ?? null) {
            case 'invoice':
                $event->invoiceId = $id;
                // o caminho por parent existe nas versões de API recentes; endpoint de webhook
                // configurado numa versão anterior entrega o id no campo subscription da raiz
                $subscription = $stripeObject['parent']['subscription_details']['subscription']
                    ?? $stripeObject['subscription']
                    ?? null;
                $event->subscriptionId = is_string($subscription) ? $subscription : null;
                break;
            case 'subscription':
                $event->subscriptionId = $id;
                break;
            case 'payment_intent':
                $event->invoiceId = $id;
                break;
            case 'charge':
                $event->invoiceId = $stripeObject['payment_intent'] ?? null;
                break;
            case 'dispute':
                $event->invoiceId = $stripeObject['payment_intent'] ?? null;
                $event->disputeId = $id;
                break;
        }
    }

    /**
     * @inheritDoc
     */
    public function __toString()
    {
        return 'stripe';
    }
}
