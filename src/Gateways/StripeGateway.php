<?php

namespace Potelo\MultiPayment\Gateways;

use Carbon\Carbon;
use Stripe\StripeClient;
use Stripe\Customer as StripeCustomer;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\AuthenticationException;
use Illuminate\Support\Facades\Config;
use Potelo\MultiPayment\Models\Invoice;
use Potelo\MultiPayment\Models\Address;
use Potelo\MultiPayment\Models\Customer;
use Potelo\MultiPayment\Models\CreditCard;
use Potelo\MultiPayment\Models\AutomaticPix;
use Potelo\MultiPayment\Models\AutomaticPixCharge;
use Potelo\MultiPayment\Models\AutomaticPixCancellation;
use Potelo\MultiPayment\Contracts\GatewayContract;
use Potelo\MultiPayment\Exceptions\GatewayException;
use Potelo\MultiPayment\Exceptions\MultiPaymentException;
use Potelo\MultiPayment\Exceptions\GatewayNotAvailableException;
use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

class StripeGateway implements GatewayContract
{
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
     * Garante o expand de tax_ids no payload sem descartar um expand vindo de
     * gatewayAdicionalOptions — sem esse expand a Stripe não devolve os tax ids
     * e o parse/sync de taxDocument corromperia silenciosamente.
     *
     * @param  array  $stripeCustomerData
     * @return array
     */
    private function withTaxIdsExpanded(array $stripeCustomerData): array
    {
        $stripeCustomerData['expand'] = array_values(array_unique(array_merge(
            $stripeCustomerData['expand'] ?? [],
            ['tax_ids']
        )));

        return $stripeCustomerData;
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

        if (!empty($customer->gatewayAdicionalOptions)) {
            foreach ($customer->gatewayAdicionalOptions as $option => $value) {
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
     * @throws GatewayException|GatewayNotAvailableException
     */
    private function stripeRequest(callable $request)
    {
        try {
            return $request();
        } catch (AuthenticationException | ApiConnectionException $e) {
            throw new GatewayNotAvailableException($e->getMessage());
        } catch (ApiErrorException $e) {
            $error = $e->getError();
            throw new GatewayException($e->getMessage(), array_filter([
                'type' => $error?->type,
                'code' => $error?->code,
                'param' => $error?->param,
            ]));
        } catch (MultiPaymentException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new GatewayException($e->getMessage());
        }
    }

    /**
     * Exceção padrão para operações do contrato ainda não implementadas neste gateway —
     * mais clara que o methodNotFound do despacho por convenção, que sugeriria erro de digitação.
     *
     * @param  string  $operation
     * @return GatewayException
     */
    private function operationNotImplemented(string $operation): GatewayException
    {
        return new GatewayException("Operation [{$operation}] is not yet implemented by the stripe gateway");
    }

    /**
     * @inheritDoc
     */
    public function createInvoice(Invoice $invoice): Invoice
    {
        throw $this->operationNotImplemented('createInvoice');
    }

    /**
     * @inheritDoc
     */
    public function getInvoice(Invoice $invoice): Invoice
    {
        throw $this->operationNotImplemented('getInvoice');
    }

    /**
     * @inheritDoc
     */
    public function refundInvoice(Invoice $invoice): Invoice
    {
        throw $this->operationNotImplemented('refundInvoice');
    }

    /**
     * @inheritDoc
     */
    public function chargeInvoiceWithCreditCard(Invoice $invoice): Invoice
    {
        throw $this->operationNotImplemented('chargeInvoiceWithCreditCard');
    }

    /**
     * @inheritDoc
     */
    public function duplicateInvoice(Invoice $invoice, Carbon $expiresAt, array $gatewayOptions = []): Invoice
    {
        throw $this->operationNotImplemented('duplicateInvoice');
    }

    /**
     * @inheritDoc
     */
    public function cancelInvoice(Invoice $invoice): Invoice
    {
        throw $this->operationNotImplemented('cancelInvoice');
    }

    /**
     * @inheritDoc
     */
    public function createCreditCard(CreditCard $creditCard): CreditCard
    {
        throw $this->operationNotImplemented('createCreditCard');
    }

    /**
     * @inheritDoc
     */
    public function getCreditCard(CreditCard $creditCard): CreditCard
    {
        throw $this->operationNotImplemented('getCreditCard');
    }

    /**
     * @inheritDoc
     */
    public function deleteCreditCard(CreditCard $creditCard): void
    {
        throw $this->operationNotImplemented('deleteCreditCard');
    }

    /**
     * @inheritDoc
     */
    public function rescheduleAutomaticPixPayment(Invoice $invoice): Invoice
    {
        throw $this->operationNotImplemented('rescheduleAutomaticPixPayment');
    }

    /**
     * @inheritDoc
     */
    public function cancelAutomaticPixScheduledPayment(AutomaticPixCharge $charge): AutomaticPixCancellation
    {
        throw $this->operationNotImplemented('cancelAutomaticPixScheduledPayment');
    }

    /**
     * @inheritDoc
     */
    public function cancelAutomaticPixRecurrence(AutomaticPix $automaticPix): AutomaticPixCancellation
    {
        throw $this->operationNotImplemented('cancelAutomaticPixRecurrence');
    }

    /**
     * @inheritDoc
     */
    public function getAutomaticPixCancellation(AutomaticPixCancellation $cancellation): AutomaticPixCancellation
    {
        throw $this->operationNotImplemented('getAutomaticPixCancellation');
    }

    /**
     * @inheritDoc
     */
    public function listAutomaticPixCancellations(AutomaticPix $automaticPix, int $page = 1, int $limit = 100): array
    {
        throw $this->operationNotImplemented('listAutomaticPixCancellations');
    }

    /**
     * @inheritDoc
     */
    public function __toString()
    {
        return 'stripe';
    }
}
