<?php

return [
    'name' => env('APP_NAME', 'GymMonolith'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),

    'timezone' => 'UTC',
    'locale' => 'es',
    'fallback_locale' => 'en',
    'faker_locale' => 'es_ES',

    // Clave para el cifrado (se genera con `php artisan key:generate`)
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),

    'maintenance' => [
        'driver' => 'file',
    ],
];
