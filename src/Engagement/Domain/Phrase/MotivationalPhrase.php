<?php

declare(strict_types=1);

namespace App\Engagement\Domain\Phrase;

use App\Engagement\Domain\Quote\Quote;
use Illuminate\Support\Str;

/**
 * Entidad del dominio Engagement: la frase asignada a un usuario tras un acceso.
 * Es el modelo de ESCRITURA (normalizado) de este contexto.
 */
final class MotivationalPhrase
{
    public function __construct(
        public readonly string $id,
        public readonly string $userId,
        public readonly string $accessLogId,
        public readonly Quote $quote,
        public readonly string $checkedInAt,
    ) {
    }

    public static function assign(string $userId, string $accessLogId, Quote $quote, string $checkedInAt): self
    {
        return new self(
            id: (string) Str::uuid(),
            userId: $userId,
            accessLogId: $accessLogId,
            quote: $quote,
            checkedInAt: $checkedInAt,
        );
    }
}
