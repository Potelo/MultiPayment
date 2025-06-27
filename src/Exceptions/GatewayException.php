<?php

namespace Potelo\MultiPayment\Exceptions;

class GatewayException extends MultiPaymentException
{
    /** @var mixed */
    private $errors;

    /**
     * GatewayException constructor.
     *
     * @param  string  $message
     * @param $errors
     */
    public function __construct(string $message = "", $errors = null)
    {
        $this->errors = $errors;
        parent::__construct($message . ' - ' . $this->parseErrorsToString($errors));
    }

    /**
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Method not found in gateway.
     *
     * @param  string  $gatewayClass
     * @param  string  $method
     *
     * @return GatewayException
     */
    public static function methodNotFound(string $gatewayClass, string $method): GatewayException
    {
        return new static("Gateway [{$gatewayClass}] does not have method [$method]");
    }

    /**
     * Converte uma variável de erros (string, objeto ou array) em uma única string formatada.
     * Lida com arrays de erros aninhados.
     *
     * @param mixed $errors Os erros a serem processados.
     * @return string A string de erros formatada.
     */
    public function parseErrorsToString($errors): string
    {
        if (is_string($errors)) {
            return $errors;
        }

        if (is_object($errors)) {
            $errors = (array) $errors;
        }

        if (!is_array($errors)) {
            return ''; // Retorna string vazia se não for um tipo suportado
        }

        $errorMessages = [];
        $this->flattenErrors($errors, $errorMessages); // Chama a função auxiliar

        return implode(', ', $errorMessages);
    }

    /**
     * Função auxiliar recursiva para achatar o array de erros, preservando as chaves.
     *
     * @param array $array O array de erros a ser percorrido.
     * @param array &$messages O array de mensagens de erro formatadas (passado por referência).
     * @param string $prefix O prefixo da chave para o nível atual de recursão.
     */
    private function flattenErrors(array $array, array &$messages, string $prefix = ''): void
    {
        foreach ($array as $key => $value) {
            // Constrói a chave completa para o item atual
            $newKey = $prefix ? "{$prefix}.{$key}" : $key;

            if (is_array($value) && !empty($value)) {
                // Se o valor for um array não vazio, continua a recursão
                $this->flattenErrors($value, $messages, $newKey);
            } elseif (!is_array($value) && !empty($value)) {
                // Se for um valor final (não-array e não vazio), formata a mensagem
                $messages[] = "{$newKey}: {$value}";
            }
            // Ignora valores que são arrays vazios ou strings vazias
        }
    }
}
