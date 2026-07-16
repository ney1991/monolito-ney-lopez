<?php

declare(strict_types=1);

namespace App\Engagement\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

/** Modelo Eloquent de `motivational_phrases` (modelo de escritura). */
class MotivationalPhraseModel extends Model
{
    protected $table = 'motivational_phrases';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'access_log_id',
        'quote_text',
        'quote_author',
        'checked_in_at',
    ];

    public $timestamps = true;
}
