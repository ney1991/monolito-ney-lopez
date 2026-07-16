<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use Illuminate\Support\Str;

/**
 * Clase base de todos los eventos de dominio.
 *
 * Un evento representa "algo que ya ocurrió" en el dominio (nombre en pasado:
 * CheckedIn). Viaja entre módulos serializado como primitivas (arrays/strings),
 * nunca como objetos de otro módulo: así ningún dominio depende de las clases
 * del otro.
 */
abstract class DomainEvent
{
    public function __construct(
        public readonly string $aggregateId,   // id de la entidad que originó el evento
        public readonly string $eventId,       // id único del evento (idempotencia)
        public readonly string $occurredOn,    // ISO-8601
    ) {
    }

    /** Nombre estable del evento (contrato de integración). */
    abstract public static function eventName(): string;

    /** Routing key con la que se publica en el broker. */
    abstract public function routingKey(): string;

    /** Cuerpo específico del evento como primitivas. */
    abstract public function toPrimitives(): array;

    /** Reconstruye el evento a partir de primitivas (lo usa el consumidor). */
    abstract public static function fromPrimitives(
        string $aggregateId,
        array $body,
        string $eventId,
        string $occurredOn
    ): static;

    public static function nextId(): string
    {
        return (string) Str::uuid();
    }

    public static function now(): string
    {
        return now()->toIso8601String();
    }
}
