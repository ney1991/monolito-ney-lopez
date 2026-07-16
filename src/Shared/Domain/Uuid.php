<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Generador de UUID v4 en PHP nativo (RFC 4122), sin depender de ninguna
 * librería ni del framework.
 *
 * Antes el dominio usaba `Illuminate\Support\Str::uuid()` — funcionalmente
 * correcto, pero una dependencia innecesaria de Laravel dentro de la capa de
 * dominio. `random_bytes()` es parte del core de PHP desde 7.0; esta clase deja
 * el dominio 100% libre de imports de infraestructura o de terceros.
 */
final class Uuid
{
    public static function generate(): string
    {
        $bytes = random_bytes(16);

        // Fija la versión (4) y el variant (RFC 4122) según el estándar.
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    private function __construct()
    {
        // Clase estática: no se instancia.
    }
}
