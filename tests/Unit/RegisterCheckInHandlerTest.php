<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\AccessControl\Application\RegisterCheckIn\RegisterCheckInCommand;
use App\AccessControl\Application\RegisterCheckIn\RegisterCheckInHandler;
use App\AccessControl\Domain\AccessLogRepository;
use App\AccessControl\Domain\CheckIn;
use App\AccessControl\Domain\DuplicateIdempotencyKey;
use App\Shared\Domain\DomainEvent;
use App\Shared\Domain\EventBus;
use App\Shared\Domain\TransactionManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests del handler de la ruta crítica, con dobles en memoria de sus 3 puertos
 * (repositorio, bus de eventos, transacciones). Nada de red, nada de base de
 * datos real: así se puede verificar en aislamiento la lógica de idempotencia.
 */
final class RegisterCheckInHandlerTest extends TestCase
{
    public function test_crea_un_nuevo_acceso_y_publica_su_evento(): void
    {
        $repository = new InMemoryAccessLogRepository();
        $eventBus = new InMemoryEventBus();
        $handler = new RegisterCheckInHandler($repository, $eventBus, new ImmediateTransactionManager());

        $id = $handler(new RegisterCheckInCommand('user-1', 'branch-1'));

        $this->assertCount(1, $repository->saved);
        $this->assertSame($id, $repository->saved[0]->id);
        $this->assertCount(1, $eventBus->published);
    }

    public function test_sin_idempotency_key_cada_llamada_crea_un_acceso_nuevo(): void
    {
        $repository = new InMemoryAccessLogRepository();
        $handler = new RegisterCheckInHandler($repository, new InMemoryEventBus(), new ImmediateTransactionManager());

        $handler(new RegisterCheckInCommand('user-1', 'branch-1'));
        $handler(new RegisterCheckInCommand('user-1', 'branch-1'));

        $this->assertCount(2, $repository->saved); // comportamiento previo, sin cambios
    }

    public function test_un_reintento_con_la_misma_idempotency_key_no_duplica_el_acceso(): void
    {
        $repository = new InMemoryAccessLogRepository();
        $eventBus = new InMemoryEventBus();
        $handler = new RegisterCheckInHandler($repository, $eventBus, new ImmediateTransactionManager());

        $firstId = $handler(new RegisterCheckInCommand('user-1', 'branch-1', 'key-abc'));
        $secondId = $handler(new RegisterCheckInCommand('user-1', 'branch-1', 'key-abc'));

        $this->assertSame($firstId, $secondId);
        $this->assertCount(1, $repository->saved); // no se creó un segundo acceso
        $this->assertCount(1, $eventBus->published); // el evento no se volvió a publicar
    }

    public function test_bajo_condicion_de_carrera_devuelve_el_registro_que_gano_la_insercion(): void
    {
        // Simula: dos requests concurrentes pasan la comprobación "no existe"
        // antes de que cualquiera confirme su INSERT. La segunda en llegar al
        // repositorio recibe la violación de UNIQUE de la base de datos.
        $repository = new InMemoryAccessLogRepository();
        $repository->simulateRaceConditionOnNextSave('key-abc');

        $handler = new RegisterCheckInHandler($repository, new InMemoryEventBus(), new ImmediateTransactionManager());

        $id = $handler(new RegisterCheckInCommand('user-1', 'branch-1', 'key-abc'));

        // El repositorio ya tenía (simulado) un ganador de la carrera; debemos
        // recibir ESE id, no un error ni un segundo registro.
        $this->assertSame($repository->raceWinnerId, $id);
    }
}

// --- Dobles en memoria de los puertos ---

final class ImmediateTransactionManager implements TransactionManager
{
    public function run(callable $callback): mixed
    {
        return $callback(); // sin base de datos real: ejecuta el callback tal cual
    }
}

final class InMemoryEventBus implements EventBus
{
    /** @var DomainEvent[] */
    public array $published = [];

    public function publish(DomainEvent ...$events): void
    {
        array_push($this->published, ...$events);
    }
}

final class InMemoryAccessLogRepository implements AccessLogRepository
{
    /** @var CheckIn[] */
    public array $saved = [];
    private array $byIdempotencyKey = [];

    private ?string $raceOnKey = null;
    public ?string $raceWinnerId = null;

    public function simulateRaceConditionOnNextSave(string $idempotencyKey): void
    {
        $this->raceOnKey = $idempotencyKey;
        $this->raceWinnerId = 'winner-of-the-race-id';
    }

    public function save(CheckIn $checkIn): void
    {
        if ($this->raceOnKey !== null && $checkIn->idempotencyKey === $this->raceOnKey) {
            // "Otro request" ya insertó con esta clave justo antes que nosotros.
            $this->byIdempotencyKey[$this->raceOnKey] = CheckIn::fromState(
                id: $this->raceWinnerId,
                userId: $checkIn->userId,
                branchId: $checkIn->branchId,
                checkedInAt: $checkIn->checkedInAt,
                idempotencyKey: $this->raceOnKey,
            );
            $this->raceOnKey = null;

            throw DuplicateIdempotencyKey::forKey($checkIn->idempotencyKey);
        }

        $this->saved[] = $checkIn;
        if ($checkIn->idempotencyKey !== null) {
            $this->byIdempotencyKey[$checkIn->idempotencyKey] = $checkIn;
        }
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?CheckIn
    {
        return $this->byIdempotencyKey[$idempotencyKey] ?? null;
    }
}
