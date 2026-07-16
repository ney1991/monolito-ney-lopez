<?php

declare(strict_types=1);

namespace App\Engagement\Infrastructure\Persistence;

use App\Engagement\Domain\Phrase\DashboardProjectionRepository;
use App\Engagement\Domain\Phrase\MotivationalPhrase;

/**
 * Adaptador del modelo de lectura (el "proyector").
 *
 * Usa upsert por access_log_id: si el mismo evento se procesara dos veces, la
 * fila no se duplica (idempotencia también en la proyección).
 */
final class EloquentDashboardProjectionRepository implements DashboardProjectionRepository
{
    public function project(MotivationalPhrase $phrase): void
    {
        DashboardReadModel::query()->updateOrCreate(
            ['access_log_id' => $phrase->accessLogId],
            [
                'user_id' => $phrase->userId,
                'checked_in_at' => $phrase->checkedInAt,
                'quote_text' => $phrase->quote->text,
                'quote_author' => $phrase->quote->author,
            ],
        );
    }

    public function forUser(string $userId): array
    {
        // SELECT directo sobre la tabla desnormalizada: lectura O(1) por fila,
        // sin JOINs en tiempo de ejecución.
        return DashboardReadModel::query()
            ->where('user_id', $userId)
            ->orderByDesc('checked_in_at')
            ->get(['access_log_id', 'checked_in_at', 'quote_text', 'quote_author'])
            ->map(fn ($row) => [
                'access_log_id' => $row->access_log_id,
                'checked_in_at' => $row->checked_in_at,
                'quote' => [
                    'text' => $row->quote_text,
                    'author' => $row->quote_author,
                ],
            ])
            ->all();
    }
}
