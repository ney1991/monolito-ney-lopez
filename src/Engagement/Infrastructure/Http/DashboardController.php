<?php

declare(strict_types=1);

namespace App\Engagement\Infrastructure\Http;

use App\Engagement\Domain\Phrase\DashboardProjectionRepository;
use Illuminate\Http\JsonResponse;

/**
 * Endpoint de lectura del dashboard (lado Query de CQRS).
 *
 * Lee directamente el modelo desnormalizado. No arma la respuesta con JOINs
 * entre el log de accesos y las frases: eso ya lo hizo el proyector al escribir.
 */
final class DashboardController
{
    public function __invoke(string $userId, DashboardProjectionRepository $dashboard): JsonResponse
    {
        return response()->json([
            'user_id' => $userId,
            'history' => $dashboard->forUser($userId),
        ]);
    }
}
