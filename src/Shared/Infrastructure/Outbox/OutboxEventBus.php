<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Domain\DomainEvent;
use App\Shared\Domain\EventBus;

/**
 * Implementación del EventBus basada en el patrón Transactional Outbox.
 *
 * NO habla con RabbitMQ. Solo INSERTA el evento en la tabla `outbox`. Como esta
 * escritura ocurre dentro de la MISMA transacción del comando (guardar el
 * acceso), o se guardan ambas cosas o ninguna: nunca hay "accesos fantasma".
 *
 * El envío real al broker lo hace OutboxRelayCommand de forma asíncrona.
 */
final class OutboxEventBus implements EventBus
{
    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            OutboxModel::create([
                'id' => $event->eventId,
                'aggregate_id' => $event->aggregateId,
                'event_name' => $event::eventName(),
                'routing_key' => $event->routingKey(),
                'payload' => $event->toPrimitives(),
                'occurred_on' => $event->occurredOn,
                'published_at' => null, // aún no publicado en el broker
            ]);
        }
    }
}
