<?php

namespace Potelo\MultiPayment\Enums;

/**
 * Recurso que um gateway pode oferecer e a lib pode ter implementado. Cada driver declara, por
 * `DeclaresCapabilities`, o que suporta e o que o gateway oferece mas a lib ainda não construiu;
 * o que não aparece em nenhuma das duas listas é limitação do gateway.
 */
enum Capability: string
{
    /** Fatura paga com cartão de crédito. */
    case CREDIT_CARD = 'credit_card';

    /** Fatura paga com Pix avulso, com QR Code de pagamento único. */
    case PIX = 'pix';

    /** Fatura paga com boleto bancário. */
    case BANK_SLIP = 'bank_slip';

    /** Recorrência de Pix Automático criada junto com a fatura, com reagendamento e cancelamento pela lib. */
    case AUTOMATIC_PIX = 'automatic_pix';

    /** Fatura aberta a mais de um método de pagamento, escolhido pelo pagador na hora de pagar. */
    case MULTIPLE_PAYMENT_METHODS = 'multiple_payment_methods';

    /**
     * Cartão informado com número e CVV pela API; sem ela, o cartão é tokenizado no navegador
     * e só o token chega à lib.
     */
    case RAW_CARD_DATA = 'raw_card_data';

    /** Parcelamento da cobrança no cartão de crédito. */
    case INSTALLMENTS = 'installments';

    /** Cobrança em duas etapas no cartão: reserva do valor agora e captura depois. */
    case DELAYED_CAPTURE = 'delayed_capture';

    /** Estorno de parte do valor numa fatura paga com cartão. */
    case PARTIAL_REFUND_CARD = 'partial_refund_card';

    /** Estorno de parte do valor numa fatura paga com Pix. */
    case PARTIAL_REFUND_PIX = 'partial_refund_pix';

    /** Estorno pela API de uma fatura paga com boleto. */
    case REFUND_BANK_SLIP = 'refund_bank_slip';

    /** Segunda via de uma fatura pendente com nova data de vencimento (`duplicateInvoice`). */
    case INVOICE_DUPLICATION = 'invoice_duplication';

    /**
     * Chave de idempotência (`idempotencyKey`) honrada em toda operação de escrita, pelo
     * gateway ou pela deduplicação da lib (`IdempotencyStore`).
     */
    case IDEMPOTENCY = 'idempotency';

    /**
     * Chave de idempotência honrada pelo próprio gateway em toda operação de escrita, sem
     * depender da deduplicação da lib.
     */
    case IDEMPOTENCY_ALL_ENDPOINTS = 'idempotency_all_endpoints';

    /**
     * Assinatura recorrente: criar, buscar, atualizar, suspender, retomar, cancelar, trocar de
     * plano e listar.
     */
    case SUBSCRIPTIONS = 'subscriptions';

    /** Plano de assinatura: criar, buscar e listar. */
    case PLANS = 'plans';

    /** Desativar um plano sem apagá-lo (`deactivatePlan`). */
    case PLAN_DEACTIVATION = 'plan_deactivation';

    /** Cancelar a assinatura só no fim do período já pago (`cancel(atPeriodEnd: true)`). */
    case CANCEL_AT_PERIOD_END = 'cancel_at_period_end';

    /** Cupom de primeira classe na assinatura: desconto percentual e desconto limitado a vários ciclos. */
    case NATIVE_COUPONS = 'native_coupons';

    /** Crédito proporcional do período não usado, calculado pelo gateway, ao trocar de plano (`changePlan()` com `ProrationBehavior::CREDIT`). */
    case PLAN_CHANGE_PRORATION = 'plan_change_proration';

    /** Assinatura com saldo de créditos consumíveis, abatidos a cada uso. */
    case SUBSCRIPTION_CREDITS = 'subscription_credits';

    /**
     * O gateway agenda as cobranças do Pix Automático por conta própria; sem ela, a aplicação é
     * o motor de recorrência e chama as operações de `AutomaticPixContract` na periodicidade
     * certa.
     */
    case MANAGES_RECURRENCE = 'manages_recurrence';

    /**
     * Capability que uma fatura precisa para ser paga com o método informado.
     *
     * @param  PaymentMethod  $paymentMethod
     * @return static
     */
    public static function forPaymentMethod(PaymentMethod $paymentMethod): static
    {
        return match ($paymentMethod) {
            PaymentMethod::CREDIT_CARD => self::CREDIT_CARD,
            PaymentMethod::PIX => self::PIX,
            PaymentMethod::BANK_SLIP => self::BANK_SLIP,
            PaymentMethod::AUTOMATIC_PIX => self::AUTOMATIC_PIX,
        };
    }

    /**
     * Descrição do que a capability significa para quem consome, lida do docblock do caso;
     * um docblock de várias linhas vira uma linha só. Vazia quando o interpretador não guarda
     * comentários.
     *
     * @return string
     */
    public function description(): string
    {
        $docComment = (new \ReflectionEnumBackedCase(self::class, $this->name))->getDocComment();
        if ($docComment === false) {
            return '';
        }

        $body = preg_replace('#^/\*\*|\*/$#', '', trim($docComment));
        $lines = array_map(
            static fn (string $line) => trim(preg_replace('#^\s*\*\s?#', '', trim($line))),
            preg_split('/\R/', $body)
        );

        return trim(implode(' ', array_filter($lines, static fn (string $line) => $line !== '')));
    }
}
