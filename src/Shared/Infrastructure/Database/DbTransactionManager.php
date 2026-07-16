<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database;

use App\Shared\Domain\TransactionManager;
use Illuminate\Support\Facades\DB;

/**
 * Adaptador real: delega en la fachada de transacciones de Laravel/PostgreSQL.
 */
final class DbTransactionManager implements TransactionManager
{
    public function run(callable $callback): mixed
    {
        return DB::transaction($callback);
    }
}
