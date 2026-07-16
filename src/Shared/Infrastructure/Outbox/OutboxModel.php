<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo Eloquent de la tabla `outbox`.
 *
 * Es infraestructura pura: representa la bandeja de salida de eventos que se
 * escribe DENTRO de la transacción del comando y que el relay publica después.
 */
class OutboxModel extends Model
{
    protected $table = 'outbox';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'aggregate_id',
        'event_name',
        'routing_key',
        'payload',
        'occurred_on',
        'published_at',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public $timestamps = true;
}
