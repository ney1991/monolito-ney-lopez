<?php

declare(strict_types=1);

namespace App\AccessControl\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo Eloquent de `access_logs` (detalle de infraestructura).
 * Vive en la capa de infraestructura, no en el dominio.
 */
class AccessLogModel extends Model
{
    protected $table = 'access_logs';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'branch_id',
        'checked_in_at',
        'idempotency_key',
    ];

    public $timestamps = true;
}
