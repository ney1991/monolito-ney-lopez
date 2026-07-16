<?php

declare(strict_types=1);

namespace App\Engagement\Domain\Quote;

use InvalidArgumentException;

/**
 * Value Object del dominio: una frase motivacional.
 *
 * El dominio de Engagement conoce el CONCEPTO "frase" (texto + autor), pero
 * IGNORA por completo de dónde viene: nada de HTTP, JSON ni dummyjson. Esa
 * traducción la hace el adaptador (ACL) antes de construir este objeto.
 */
final class Quote
{
    public function __construct(
        public readonly string $text,
        public readonly string $author,
    ) {
        if (trim($text) === '') {
            throw new InvalidArgumentException('La frase no puede estar vacía.');
        }
    }

    public static function fallback(): self
    {
        // Frase por defecto si el proveedor externo no está disponible:
        // el usuario siempre tiene dashboard aunque la API de terceros falle.
        return new self('Sigue adelante, un día a la vez.', 'Anónimo');
    }
}
