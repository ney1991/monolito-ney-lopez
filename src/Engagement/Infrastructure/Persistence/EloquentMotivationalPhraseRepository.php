<?php

declare(strict_types=1);

namespace App\Engagement\Infrastructure\Persistence;

use App\Engagement\Domain\Phrase\MotivationalPhrase;
use App\Engagement\Domain\Phrase\MotivationalPhraseRepository;

final class EloquentMotivationalPhraseRepository implements MotivationalPhraseRepository
{
    public function save(MotivationalPhrase $phrase): void
    {
        MotivationalPhraseModel::create([
            'id' => $phrase->id,
            'user_id' => $phrase->userId,
            'access_log_id' => $phrase->accessLogId,
            'quote_text' => $phrase->quote->text,
            'quote_author' => $phrase->quote->author,
            'checked_in_at' => $phrase->checkedInAt,
        ]);
    }

    public function existsForAccessLog(string $accessLogId): bool
    {
        return MotivationalPhraseModel::query()
            ->where('access_log_id', $accessLogId)
            ->exists();
    }
}
