<?php

declare(strict_types=1);

namespace App\AccessControl\Application\RegisterCheckIn;

use App\AccessControl\Domain\AccessLogRepository;
use App\AccessControl\Domain\CheckIn;
use App\AccessControl\Domain\DuplicateIdempotencyKey;
use App\Shared\Domain\EventBus;
use App\Shared\Domain\TransactionManager;

/**
 * Handler del comando RegisterCheckIn: LA RUTA CRÍTICA.
 *
 * Atomicidad (patrón Outbox): dentro de UNA sola transacción se hacen dos cosas
 *   1. guardar el acceso físico (access_logs)
 *   2. guardar el evento CheckedIn en el outbox (vía EventBus)
 * Si el commit falla, no queda ni acceso ni evento. Si tiene éxito, quedan los
 * dos. Nunca hay un acceso registrado sin su evento pendiente ("acceso fantasma").
 *
 * Idempotencia del cliente: si el torno no recibe la respuesta 201 a tiempo
 * (por ejemplo, el proceso PHP muere justo después del commit), puede reintentar
 * el MISMO check-in enviando la misma Idempotency-Key. Este handler detecta esa
 * repetición y devuelve el access_log_id ya existente en vez de crear un
 * segundo acceso físico — sin volver a publicar el evento, porque ya se publicó
 * en el intento original.
 *
 * Importante: NO se llama a ninguna API externa ni a Engagement aquí. La
 * operación es rápida y síncrona; todo lo demás ocurre después, por eventos.
 */
final class RegisterCheckInHandler
{
    public function __construct(
        private readonly AccessLogRepository $repository,
        private readonly EventBus $eventBus,
        private readonly TransactionManager $transactions,
    ) {
    }

    public function __invoke(RegisterCheckInCommand $command): string
    {
        // Camino sin idempotencia explícita: comportamiento original, siempre crea.
        if ($command->idempotencyKey === null) {
            return $this->createNew($command);
        }

        // ¿Ya existe un acceso con esta clave? (reintento del cliente)
        $existing = $this->repository->findByIdempotencyKey($command->idempotencyKey);
        if ($existing !== null) {
            return $existing->id;
        }

        try {
            return $this->createNew($command);
        } catch (DuplicateIdempotencyKey) {
            // Condición de carrera: otra request con la misma clave ganó la
            // inserción entre nuestra comprobación y nuestro INSERT. No es un
            // error para el cliente: le devolvemos el registro que sí quedó
            // guardado, como si hubiéramos encontrado el duplicado desde el inicio.
            return $this->repository->findByIdempotencyKey($command->idempotencyKey)->id;
        }
    }

    private function createNew(RegisterCheckInCommand $command): string
    {
        return $this->transactions->run(function () use ($command): string {
            $checkIn = CheckIn::register($command->userId, $command->branchId, $command->idempotencyKey);

            $this->repository->save($checkIn);
            $this->eventBus->publish(...$checkIn->pullDomainEvents());

            return $checkIn->id;
        });
    }
}
