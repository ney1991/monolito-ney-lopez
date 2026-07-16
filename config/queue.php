<?php

return [
    // No usamos las colas nativas de Laravel: la mensajería es RabbitMQ vía
    // php-amqplib. Este archivo existe solo para satisfacer al framework.
    'default' => env('QUEUE_CONNECTION', 'sync'),

    'connections' => [
        'sync' => [
            'driver' => 'sync',
        ],
    ],

    'failed' => [
        'driver' => 'null',
    ],
];
