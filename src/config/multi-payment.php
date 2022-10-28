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
    'default' => env('MULTIPAYMENT_DEFAULT', 'iugu'),

    /*
    |--------------------------------------------------------------------------
    | MultiPayment environment
    |--------------------------------------------------------------------------
    |
    |  If will be use gateway's sandbox or production environment.
    |
    */
    'environment' => env('APP_ENV', 'production'),

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
            'id' => env('IUGU_ID'),
            'api_key' => env('IUGU_APIKEY'),
            'customer_column' => 'iugu_id',
            'class' => \Potelo\MultiPayment\Gateways\IuguGateway::class,
        ],
        'moip' => [
            'api_token' => env('MOIP_APITOKEN'),
            'api_key' => env('MOIP_APIKEY'),
            'customer_column' => 'moip_id',
            'class' => \Potelo\MultiPayment\Gateways\MoipGateway::class,
        ],
    ],
];