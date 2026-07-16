<?php

use App\AccessControl\Infrastructure\Http\CheckInController;
use App\Engagement\Infrastructure\Http\DashboardController;
use Illuminate\Support\Facades\Route;

// --- AccessControl: ruta crítica (síncrona, prioridad absoluta) ---
Route::post('/check-in', CheckInController::class);

// --- Engagement: lectura del dashboard (lee el read model desnormalizado) ---
Route::get('/dashboard/{userId}', DashboardController::class);
