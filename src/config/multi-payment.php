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
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Deduplicação de entregas de webhook (WebhookDeduplicator), sobre a IdempotencyStore,
    | e a rota pronta do pacote: verifica a autenticidade, descarta replay e despacha os
    | eventos do Laravel. A rota nasce desligada; quem prefere rota própria usa
    | MultiPayment::webhooks()->handle($request) com o mesmo pipeline.
    |
    */
    'webhooks' => [
        // prazo, em segundos, em que uma entrega com o mesmo id conta como replay
        'dedup_ttl' => env('MULTIPAYMENT_WEBHOOK_DEDUP_TTL', 259200),
        'route' => [
            // liga o registro da rota pelo service provider
            'enabled' => env('MULTIPAYMENT_WEBHOOK_ROUTE_ENABLED', false),
            // caminho da rota; o parâmetro {gateway} escolhe o driver (sem ele, vale o default)
            'path' => '/multipayment/webhooks/{gateway}',
            // middleware aplicado à rota; ela nasce fora de qualquer grupo (webhook não tem
            // sessão nem CSRF), acrescente aqui o que a aplicação precisar
            'middleware' => [],
        ],
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
            // token configurado no registro do webhook na Iugu, conferido com o header authorization
            'webhook_token' => env('IUGU_WEBHOOK_TOKEN'),
        ],
        'stripe' => [
            'api_key' => env('STRIPE_APIKEY'),
            'customer_column' => 'stripe_id',
            'class' => \Potelo\MultiPayment\Gateways\StripeGateway::class,
            // nome exibido no aplicativo do banco do pagador no mandato de Pix Automático
            'pix_mandate_reference' => env('STRIPE_PIX_MANDATE_REFERENCE'),
            // secret do endpoint de webhook (whsec_...), usado na verificação do Stripe-Signature
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            // tolerância, em segundos, entre o timestamp assinado da entrega e o relógio da aplicação
            'webhook_tolerance' => env('STRIPE_WEBHOOK_TOLERANCE', 300),
        ],
    ],
];