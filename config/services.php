<?php

return [
    // Configuración del proveedor externo de frases (API pública de terceros).
    // El dominio NO conoce esta URL; solo la usa el adaptador de infraestructura (ACL).
    'quotes' => [
        'url' => env('QUOTES_API_URL', 'https://dummyjson.com/quotes/random'),
        'timeout' => (float) env('QUOTES_API_TIMEOUT', 3.0), // segundos
    ],
];
