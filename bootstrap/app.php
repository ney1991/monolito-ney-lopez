<?php

use App\Engagement\Infrastructure\Console\ConsumeEngagementCommand;
use App\Shared\Infrastructure\Outbox\OutboxRelayCommand;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // Rutas HTTP de la API (prefijo /api)
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withCommands([
        // Comandos de los procesos asíncronos (worker + relay del outbox)
        ConsumeEngagementCommand::class,
        OutboxRelayCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        // Sin middleware adicional: solo exponemos una API mínima
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
