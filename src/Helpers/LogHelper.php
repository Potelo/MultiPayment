<?php

namespace Potelo\MultiPayment\Helpers;

use Illuminate\Support\Facades\Facade;
use Illuminate\Contracts\Container\Container;

/**
 * Escreve no log da aplicação. Usa o logger registrado como `log` no container do Laravel
 * quando ele existe; sem container ou sem logger (pacote fora do Laravel, teste unitário sem
 * `log`), escreve pelo `error_log()` do PHP.
 */
final class LogHelper
{
    /**
     * Registra um aviso.
     *
     * @param  string  $message
     * @param  array  $context
     * @return void
     */
    public static function warning(string $message, array $context = []): void
    {
        $app = Facade::getFacadeApplication();

        if ($app instanceof Container && $app->bound('log')) {
            $app->make('log')->warning($message, $context);

            return;
        }

        error_log(trim($message . ' ' . json_encode($context)));
    }
}
