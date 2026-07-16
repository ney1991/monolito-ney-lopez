<?php

declare(strict_types=1);

namespace App\Engagement\Domain\Phrase;

/**
 * Puerto del modelo de LECTURA (CQRS).
 *
 * El proyector escribe aquí una fila desnormalizada (acceso + frase ya
 * combinados) para que el dashboard lea O(1), sin JOINs en tiempo de ejecución.
 */
interface DashboardProjectionRepository
{
    public function project(MotivationalPhrase $phrase): void;

    /** Devuelve el historial ya desnormalizado de un usuario (para el endpoint). */
    public function forUser(string $userId): array;
}
