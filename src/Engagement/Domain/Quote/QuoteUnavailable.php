<?php

declare(strict_types=1);

namespace App\Engagement\Domain\Quote;

use RuntimeException;

/**
 * Excepción de dominio: no se pudo obtener una frase del proveedor externo.
 *
 * El adaptador (ACL) traduce CUALQUIER fallo de infraestructura (timeout, 500,
 * JSON con contrato inesperado) a esta excepción del dominio. Así el resto del
 * sistema razona en términos de negocio, no de detalles de HTTP.
 */
final class QuoteUnavailable extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self("No se pudo obtener una frase: {$reason}");
    }
}
