<?php

declare(strict_types=1);

namespace App\AccessControl\Domain;

use App\Shared\Domain\DomainEvent;
use App\Shared\Domain\Uuid;

/**
 * Aggregate Root del contexto AccessControl: un registro de acceso físico.
 *
 * Es una entidad de dominio PURA: no extiende Eloquent ni conoce la base de
 * datos. Al registrarse, "graba" internamente un evento CheckedIn que la capa
 * de aplicación recogerá para publicarlo.
 */
final class CheckIn
{
    /** @var DomainEvent[] */
    private array $recordedEvents = [];

    private function __construct(
        public readonly string $id,
        public readonly string $userId,
        public readonly string $branchId,
        public readonly string $checkedInAt,
        public readonly ?string $idempotencyKey,
    ) {
    }

    /**
     * Fábrica: crea el acceso y registra el evento de dominio.
     *
     * $idempotencyKey es opcional: si el cliente (torno) lo envía, un reintento
     * con la misma clave no debe generar un segundo acceso físico (ver
     * RegisterCheckInHandler, que resuelve la deduplicación antes de llegar aquí).
     */
    public static function register(string $userId, string $branchId, ?string $idempotencyKey = null): self
    {
        $checkIn = new self(
            id: Uuid::generate(),
            userId: $userId,
            branchId: $branchId,
            checkedInAt: (new \DateTimeImmutable())->format(DATE_ATOM),
            idempotencyKey: $idempotencyKey,
        );

        $checkIn->recordedEvents[] = new CheckedIn(
            accessLogId: $checkIn->id,
            userId: $userId,
            branchId: $branchId,
            checkedInAt: $checkIn->checkedInAt,
        );

        return $checkIn;
    }

    /** Reconstrucción desde persistencia (sin regenerar eventos). */
    public static function fromState(
        string $id,
        string $userId,
        string $branchId,
        string $checkedInAt,
        ?string $idempotencyKey = null,
    ): self {
        return new self($id, $userId, $branchId, $checkedInAt, $idempotencyKey);
    }

    /** Devuelve y vacía los eventos pendientes de publicar. */
    public function pullDomainEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }
}
