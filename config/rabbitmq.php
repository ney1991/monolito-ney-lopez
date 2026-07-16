<?php

return [
    'host' => env('RABBITMQ_HOST', 'rabbitmq'),
    'port' => (int) env('RABBITMQ_PORT', 5672),
    'user' => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
    'vhost' => env('RABBITMQ_VHOST', '/'),

    // --- Topología de eventos de dominio ---
    // Exchange principal (tipo topic): enruta por routing key.
    'exchange' => env('RABBITMQ_EXCHANGE', 'domain_events'),

    // Dead Letter Exchange: recibe mensajes que agotaron reintentos.
    'dlx' => env('RABBITMQ_DLX', 'domain_events.dlx'),

    // Cola del consumidor de Engagement y su routing key.
    'queues' => [
        'assign_phrase' => [
            'name' => 'engagement.assign_phrase',
            'routing_key' => 'access.checked_in',
            'dlq' => 'engagement.assign_phrase.dlq',
        ],
    ],

    // Nº máximo de reintentos antes de mandar el mensaje a la DLQ.
    'max_retries' => (int) env('RABBITMQ_MAX_RETRIES', 3),
];
