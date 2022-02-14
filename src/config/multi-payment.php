<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default gateway
    |--------------------------------------------------------------------------
    |
    |  Will be used in case none gateway is informed.
    |
    */
    'default' => !empty($_ENV['MULTIPAYMENT_DEFAULT']) ? $_ENV['MULTIPAYMENT_DEFAULT'] : 'iugu',

    /*
    |--------------------------------------------------------------------------
    | MultiPayment environment
    |--------------------------------------------------------------------------
    |
    |  If will be use gateway's sandbox or production environment.
    |
    */
    'environment' => !empty($_ENV['APP_ENV']) ? $_ENV['APP_ENV'] : 'production',

    /*
    |--------------------------------------------------------------------------
    | Available gateways
    |--------------------------------------------------------------------------
    |
    | Array with the implemented payment gateways,
    | containing the class that implemented the Gateway contract,
    | and the necessary information to connect with the gateway.
    |
    */
    'gateways' => [
        'iugu' => [
            'id' => !empty($_ENV['IUGU_ID']) ? $_ENV['IUGU_ID'] : null,
            'api_key' => !empty($_ENV['IUGU_APIKEY']) ? $_ENV['IUGU_APIKEY'] : null,
            'customer_column' => 'iugu_id',
            'class' => \Potelo\MultiPayment\Gateways\IuguGateway::class,
        ],
        'moip' => [
            'api_token' => !empty($_ENV['MOIP_APITOKEN']) ? $_ENV['MOIP_APITOKEN'] : null,
            'api_key' => !empty($_ENV['MOIP_APIKEY']) ? $_ENV['MOIP_APIKEY'] : null,
            'customer_column' => 'moip_id',
            'class' => \Potelo\MultiPayment\Gateways\MoipGateway::class,
        ],
    ],
];
