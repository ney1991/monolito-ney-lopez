<?php

declare(strict_types=1);

namespace App\AccessControl\Infrastructure\Persistence;

use App\AccessControl\Domain\AccessLogRepository;
use App\AccessControl\Domain\CheckIn;
use App\AccessControl\Domain\DuplicateIdempotencyKey;
use Illuminate\Database\QueryException;

/**
 * Adaptador: implementa el puerto AccessLogRepository con Eloquent.
 * Traduce la entidad de dominio CheckIn ↔ modelo de persistencia.
 */
final class EloquentAccessLogRepository implements AccessLogRepository
{
    /** Código SQLSTATE de PostgreSQL para "unique_violation". */
    private const UNIQUE_VIOLATION = '23505';

    public function save(CheckIn $checkIn): void
    {
        try {
            AccessLogModel::create([
                'id' => $checkIn->id,
                'user_id' => $checkIn->userId,
                'branch_id' => $checkIn->branchId,
                'checked_in_at' => $checkIn->checkedInAt,
                'idempotency_key' => $checkIn->idempotencyKey,
            ]);
        } catch (QueryException $e) {
            // Bajo concurrencia, dos requests con la misma Idempotency-Key pueden
            // llegar aquí casi al mismo tiempo. La restricción UNIQUE de la base
            // de datos es el árbitro final: si perdimos la carrera, lo traducimos
            // a una excepción de dominio en vez de dejar escapar el detalle SQL.
            if ($e->getCode() === self::UNIQUE_VIOLATION && $checkIn->idempotencyKey !== null) {
                throw DuplicateIdempotencyKey::forKey($checkIn->idempotencyKey);
            }

            throw $e;
        }
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?CheckIn
    {
        $row = AccessLogModel::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($row === null) {
            return null;
        }

        return CheckIn::fromState(
            id: $row->id,
            userId: $row->user_id,
            branchId: $row->branch_id,
            checkedInAt: (string) $row->checked_in_at,
            idempotencyKey: $row->idempotency_key,
        );
    }
}
