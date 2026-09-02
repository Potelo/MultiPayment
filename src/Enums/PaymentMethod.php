<?php

namespace Potelo\MultiPayment\Enums;

use Potelo\MultiPayment\Exceptions\ModelAttributeValidationException;

/**
 * Método de pagamento genérico. O nome específico de cada gateway (`iugu_credit_card`,
 * `card`...) é traduzido dentro do driver.
 */
enum PaymentMethod: string
{
    /** Cartão de crédito. */
    case CREDIT_CARD = 'credit_card';

    /** Boleto bancário. */
    case BANK_SLIP = 'bank_slip';

    /** Pix avulso, com QR Code de pagamento único. */
    case PIX = 'pix';

    /**
     * Pix Automático, recorrência autorizada pelo pagador. Nenhum driver o emite ainda em
     * `Invoice::$paymentMethod`: a fatura com Pix Automático é criada com `PIX` em
     * `availablePaymentMethods` e `automaticPix` preenchido.
     */
    case AUTOMATIC_PIX = 'automatic_pix';

    /**
     * Métodos que uma fatura ou assinatura aceita em `availablePaymentMethods`.
     *
     * @return static[]
     */
    public static function selectable(): array
    {
        return [self::CREDIT_CARD, self::BANK_SLIP, self::PIX];
    }

    /**
     * Converte uma lista de métodos (casos do enum ou strings) numa lista de casos de
     * `selectable()`, lançando `ModelAttributeValidationException` para valor fora dela.
     *
     * @param  mixed  $methods
     * @param  string  $model  nome do model, para a mensagem da exceção
     * @return static[]
     * @throws ModelAttributeValidationException
     */
    public static function normalizeSelectable(mixed $methods, string $model): array
    {
        if (!is_array($methods)) {
            throw ModelAttributeValidationException::invalid(
                $model,
                'availablePaymentMethods',
                'availablePaymentMethods must be an array of payment methods'
            );
        }

        $selectable = self::selectable();
        $accepted = implode(', ', array_column($selectable, 'value'));

        return array_map(static function ($method) use ($selectable, $accepted, $model) {
            $case = $method instanceof self ? $method : (is_string($method) ? self::tryFrom($method) : null);

            if (is_null($case) || !in_array($case, $selectable, true)) {
                throw ModelAttributeValidationException::invalid(
                    $model,
                    'availablePaymentMethods',
                    "availablePaymentMethods must be one of: {$accepted}"
                );
            }

            return $case;
        }, $methods);
    }
}
