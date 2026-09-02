<?php

namespace Potelo\MultiPayment\Capabilities;

use Potelo\MultiPayment\Enums\PaymentMethod;

/**
 * Restrição que um gateway impõe a uma capability que ele oferece e a lib implementa: a
 * célula da tabela é "sim", mas só numa parte dos casos. `description` sempre diz qual; os
 * demais campos existem quando a restrição é enumerável (métodos de pagamento aceitos,
 * bandeiras aceitas, máximo de parcelas). Consultada por `restriction(Capability)` no driver e
 * na fachada; a operação fora da restrição lança `UnsupportedOperationException::restricted()`.
 */
final class CapabilityRestriction
{
    /**
     * @param  string  $description  a restrição, em uma frase, no vocabulário da lib
     * @param  PaymentMethod[]|null  $allowedPaymentMethods  métodos em que a capability vale; nulo quando não é por método
     * @param  string[]|null  $allowedBrands  bandeiras de cartão aceitas, em minúsculas (`visa`, `mastercard`); nulo quando não é por bandeira
     * @param  int|null  $maxInstallments  máximo de parcelas aceito; nulo quando não é sobre parcelas
     */
    public function __construct(
        public readonly string $description,
        public readonly ?array $allowedPaymentMethods = null,
        public readonly ?array $allowedBrands = null,
        public readonly ?int $maxInstallments = null,
    ) {
    }

    /**
     * Diz se o método de pagamento está dentro da restrição. Verdadeiro quando a restrição
     * não é por método.
     *
     * @param  PaymentMethod  $paymentMethod
     * @return bool
     */
    public function allowsPaymentMethod(PaymentMethod $paymentMethod): bool
    {
        return is_null($this->allowedPaymentMethods) || in_array($paymentMethod, $this->allowedPaymentMethods, true);
    }

    /**
     * Diz se a bandeira está dentro da restrição, sem diferenciar maiúsculas. Verdadeiro
     * quando a restrição não é por bandeira.
     *
     * @param  string  $brand
     * @return bool
     */
    public function allowsBrand(string $brand): bool
    {
        return is_null($this->allowedBrands)
            || in_array(strtolower($brand), array_map('strtolower', $this->allowedBrands), true);
    }
}
