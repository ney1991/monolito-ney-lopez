<?php

declare(strict_types=1);

namespace App\Engagement\Domain\Quote;

/**
 * PUERTO (Dependency Inversion): el dominio declara que necesita "obtener una
 * frase", sin decir cómo. La implementación concreta (Guzzle/Http contra
 * dummyjson) vive en la capa de infraestructura y se inyecta en runtime.
 *
 * Gracias a esto, el dominio y los tests no dependen de la red ni de un formato
 * JSON concreto: se puede sustituir por un doble de prueba trivialmente.
 */
interface QuoteProviderPort
{
    /**
     * @throws QuoteUnavailable si el proveedor externo no devuelve una frase válida.
     */
    public function fetchRandom(): Quote;
}
