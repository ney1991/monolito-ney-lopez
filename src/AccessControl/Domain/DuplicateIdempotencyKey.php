<?php

declare(strict_types=1);

namespace App\AccessControl\Domain;

use RuntimeException;

/**
 * Se lanza cuando dos requests concurrentes intentan registrar un check-in con
 * la misma Idempotency-Key al mismo tiempo (condición de carrera).
 *
 * La restricción UNIQUE de la base de datos es la única fuente de verdad
 * confiable bajo concurrencia: comprobar "existe → si no, crear" en dos pasos
 * separados no es seguro (dos requests pueden pasar la comprobación antes de
 * que cualquiera confirme su escritura). Por eso el adaptador de persistencia
 * intenta el INSERT igual y traduce la violación de UNIQUE de PostgreSQL a esta
 * excepción de dominio; el handler la captura y responde con el registro que
 * ganó la carrera, en vez de fallar.
 */
final class DuplicateIdempotencyKey extends RuntimeException
{
    public static function forKey(string $idempotencyKey): self
    {
        return new self("Ya existe un check-in con la clave de idempotencia: {$idempotencyKey}");
    }
}
