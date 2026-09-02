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
    | fill() estrito
    |--------------------------------------------------------------------------
    |
    | Com true (padrão), Model::fill() lança ModelAttributeValidationException para chave
    | que não corresponde a nenhuma propriedade do model (chaves com prefixo gateway_ e o
    | conteúdo de gateway_options ficam livres). Com false, a chave desconhecida é ignorada
    | em silêncio, como nas versões anteriores; use só durante a migração.
    |
    */
    'strict_fill' => env('MULTIPAYMENT_STRICT_FILL', true),

    /*
    |--------------------------------------------------------------------------
    | Idempotência
    |--------------------------------------------------------------------------
    |
    | Deduplicação feita pela lib (IdempotencyStore) nas operações de escrita em que o
    | gateway não aceita o cabeçalho Idempotency-Key. A store padrão usa o cache do Laravel;
    | para trocar, faça bind de Potelo\MultiPayment\Contracts\IdempotencyStore no container.
    |
    */
    'idempotency' => [
        // prazo, em segundos, em que a mesma chave devolve o resultado guardado
        'ttl' => env('MULTIPAYMENT_IDEMPOTENCY_TTL', 86400),
        // store de cache do Laravel usada pela CacheIdempotencyStore; nulo usa a padrão da aplicação
        'cache_store' => env('MULTIPAYMENT_IDEMPOTENCY_CACHE_STORE'),
        // prefixo das chaves no cache
        'prefix' => 'multi-payment:idempotency:',
    ],

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
            // máximo de parcelas habilitado na conta; a lib o publica em restriction(INSTALLMENTS)
            'max_installments' => env('IUGU_MAX_INSTALLMENTS', 12),
        ],
        'stripe' => [
            'api_key' => env('STRIPE_APIKEY'),
            'customer_column' => 'stripe_id',
            'class' => \Potelo\MultiPayment\Gateways\StripeGateway::class,
        ],
    ],
];