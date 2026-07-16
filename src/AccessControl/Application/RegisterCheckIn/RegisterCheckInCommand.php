<?php

declare(strict_types=1);

namespace App\AccessControl\Application\RegisterCheckIn;

/**
 * Command (CQRS - lado de escritura): intención de registrar una entrada.
 *
 * Es un DTO inmutable con los datos mínimos de la operación. No tiene lógica.
 *
 * $idempotencyKey es opcional: si el cliente (torno) la envía, un reintento con
 * la misma clave devuelve el acceso ya registrado en vez de duplicarlo. Sin
 * ella, cada llamada crea un acceso nuevo (comportamiento previo).
 */
final class RegisterCheckInCommand
{
    public function __construct(
        public readonly string $userId,
        public readonly string $branchId,
        public readonly ?string $idempotencyKey = null,
    ) {
    }
}
