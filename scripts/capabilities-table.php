<?php

/**
 * Imprime a matriz de capabilities por gateway em Markdown, a partir das declarações dos
 * drivers registrados em `src/config/multi-payment.php`. Uso: `composer capabilities:table`.
 */

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Potelo\MultiPayment\Helpers\CapabilitiesTable;

require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../src/config/multi-payment.php';

// os drivers leem a credencial da config no construtor; a tabela não faz requisição alguma
foreach ($config['gateways'] as $name => $gateway) {
    $config['gateways'][$name]['api_key'] = $gateway['api_key'] ?? 'chave-nao-usada';
}

$app = new Container();
$app->instance('config', new Repository(['multi-payment' => $config]));
Facade::setFacadeApplication($app);

$gateways = [];
foreach ($config['gateways'] as $name => $gateway) {
    $gateways[$name] = new $gateway['class']();
}

echo CapabilitiesTable::markdown($gateways);
