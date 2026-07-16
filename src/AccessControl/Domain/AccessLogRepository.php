<?php

declare(strict_types=1);

namespace App\AccessControl\Domain;

/**
 * Puerto de persistencia del acceso físico.
 *
 * El dominio define QUÉ necesita (guardar y consultar un CheckIn); la
 * infraestructura decide CÓMO (Eloquent/PostgreSQL). El dominio no conoce
 * Eloquent.
 */
interface AccessLogRepository
{
    /** @throws DuplicateIdempotencyKey si ya existe un acceso con la misma clave. */
    public function save(CheckIn $checkIn): void;

    /** Busca un acceso ya registrado con esa clave de idempotencia (o null). */
    public function findByIdempotencyKey(string $idempotencyKey): ?CheckIn;
}
