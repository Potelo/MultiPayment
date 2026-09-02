<?php

namespace Potelo\MultiPayment\Exceptions;

class GatewayException extends MultiPaymentException
{
    /** @var mixed */
    private $errors;

    /**
     * Cria a exceção com os erros devolvidos pelo gateway, a exceção original do SDK (quando
     * houver) e o status HTTP da resposta.
     *
     * @param  string  $message
     * @param  mixed  $errors  corpo de erro do gateway: string, objeto ou array
     * @param  \Throwable|null  $previous
     * @param  int|null  $httpStatus
     */
    public function __construct(string $message = "", $errors = null, ?\Throwable $previous = null, ?int $httpStatus = null)
    {
        $this->errors = $errors;
        $appends = $this->parseErrorsToString($errors);

        if (!empty($appends)) {
            $message .= ' - ' . $appends;
        }

        parent::__construct($message, $previous, $httpStatus);
    }

    /**
     * Retorna os erros do gateway normalizados para array — podem ter sido
     * informados como nulo, string, objeto ou array.
     *
     * @return array
     */
    public function getErrors(): array
    {
        if (is_null($this->errors)) {
            return [];
        }

        return is_array($this->errors) ? $this->errors : (array) $this->errors;
    }

    /**
     * Dispatch method missing in a gateway that declares the capability (driver error).
     *
     * @deprecated desde 2026-09-02, use `ConfigurationException::GatewayMethodNotFound()`: a falta
     *             do método é erro de configuração do driver, sem resposta do gateway.
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

            // Normaliza objetos (ex.: stdClass aninhado vindo da Iugu) para array
            // antes de prosseguir, evitando "Object of class stdClass could not be
            // converted to string" ao tentar interpolar o valor.
            if (is_object($value)) {
                $value = (array) $value;
            }

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
