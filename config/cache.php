<?php

return [
    // Cache simple en base de datos/archivo; no es crítico para esta prueba
    'default' => env('CACHE_STORE', 'file'),

    'stores' => [
        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
        ],
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],
    ],

    'prefix' => env('CACHE_PREFIX', 'gym_cache'),
];
