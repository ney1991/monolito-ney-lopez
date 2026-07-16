<?php

declare(strict_types=1);

namespace App\Engagement\Domain\Phrase;

/**
 * Puerto de persistencia del modelo de ESCRITURA de Engagement.
 */
interface MotivationalPhraseRepository
{
    public function save(MotivationalPhrase $phrase): void;

    /** Idempotencia: ¿ya procesamos este evento (por su id)? */
    public function existsForAccessLog(string $accessLogId): bool;
}
