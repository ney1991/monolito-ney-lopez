<?php

declare(strict_types=1);

namespace App\AccessControl\Domain;

use App\Shared\Domain\DomainEvent;

/**
 * Evento de dominio: "un usuario registró su entrada".
 *
 * Es el ÚNICO punto de contacto de AccessControl con el exterior. Engagement
 * reaccionará a este evento, pero AccessControl no sabe que Engagement existe.
 */
final class CheckedIn extends DomainEvent
{
    public function __construct(
        string $accessLogId,
        public readonly string $userId,
        public readonly string $branchId,
        public readonly string $checkedInAt,
        ?string $eventId = null,
        ?string $occurredOn = null,
    ) {
        parent::__construct(
            aggregateId: $accessLogId,
            eventId: $eventId ?? self::nextId(),
            occurredOn: $occurredOn ?? self::now(),
        );
    }

    public static function eventName(): string
    {
        return 'access.checked_in';
    }

    public function routingKey(): string
    {
        return 'access.checked_in';
    }

    public function toPrimitives(): array
    {
        return [
            'userId' => $this->userId,
            'branchId' => $this->branchId,
            'checkedInAt' => $this->checkedInAt,
        ];
    }

    public static function fromPrimitives(
        string $aggregateId,
        array $body,
        string $eventId,
        string $occurredOn
    ): static {
        return new self(
            accessLogId: $aggregateId,
            userId: $body['userId'],
            branchId: $body['branchId'],
            checkedInAt: $body['checkedInAt'],
            eventId: $eventId,
            occurredOn: $occurredOn,
        );
    }
}
