<?php

declare(strict_types=1);

namespace App\Engagement\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo Eloquent de `dashboard_read_model` (modelo de LECTURA, desnormalizado).
 *
 * Una fila = un acceso YA combinado con su frase. El dashboard lee de aquí
 * directamente, sin JOINs entre access_logs y motivational_phrases.
 */
class DashboardReadModel extends Model
{
    protected $table = 'dashboard_read_model';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'access_log_id',
        'user_id',
        'checked_in_at',
        'quote_text',
        'quote_author',
    ];

    public $timestamps = true;
}
