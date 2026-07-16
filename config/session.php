<?php

return [
    // API sin estado: sesión en memoria (no se usa realmente)
    'driver' => env('SESSION_DRIVER', 'array'),
    'lifetime' => 120,
    'expire_on_close' => false,
    'encrypt' => false,
    'files' => storage_path('framework/sessions'),
    'cookie' => 'gym_session',
    'path' => '/',
    'http_only' => true,
    'same_site' => 'lax',
];
