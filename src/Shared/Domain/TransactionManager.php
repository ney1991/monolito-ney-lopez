<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Puerto que abstrae "ejecutar esto dentro de una transacción atómica".
 *
 * Sin este puerto, los handlers de aplicación tendrían que llamar directo a la
 * fachada `DB::transaction()` de Laravel, acoplándose al framework y volviendo
 * imposible testearlos sin arrancar una base de datos real. Con el puerto, un
 * test puede inyectar un doble que simplemente ejecuta el callback tal cual.
 */
interface TransactionManager
{
    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(callable $callback): mixed;
}
