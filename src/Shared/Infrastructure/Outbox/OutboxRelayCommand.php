<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Outbox;

use App\Shared\Infrastructure\RabbitMq\RabbitMqPublisher;
use Illuminate\Console\Command;
use Throwable;

/**
 * Relay del Outbox (proceso worker independiente).
 *
 * Lee en bucle los eventos persistidos en `outbox` que aún no se publicaron y
 * los entrega al broker. Al confirmarse la publicación, marca `published_at`.
 *
 * Garantía: entrega AT-LEAST-ONCE (al menos una vez). Si el proceso muere entre
 * publicar y marcar, el evento se reenviará; por eso el consumidor deduplica por
 * eventId. Nunca se pierde un evento aunque el broker esté caído: queda en la
 * tabla hasta que pueda entregarse.
 */
final class OutboxRelayCommand extends Command
{
    protected $signature = 'outbox:relay {--batch=50 : Eventos por iteración} {--sleep=1 : Segundos entre iteraciones}';

    protected $description = 'Publica en el broker los eventos de dominio pendientes en el Outbox';

    public function handle(RabbitMqPublisher $publisher): int
    {
        $batch = (int) $this->option('batch');
        $sleep = (int) $this->option('sleep');

        $this->info('[outbox:relay] Iniciado. Publicando eventos pendientes...');

        while (true) {
            try {
                $pending = OutboxModel::query()
                    ->whereNull('published_at')
                    ->orderBy('created_at')
                    ->limit($batch)
                    ->get();

                foreach ($pending as $row) {
                    $publisher->publish(
                        routingKey: $row->routing_key,
                        message: [
                            'eventId' => $row->id,
                            'eventName' => $row->event_name,
                            'aggregateId' => $row->aggregate_id,
                            'occurredOn' => $row->occurred_on,
                            'payload' => $row->payload,
                        ],
                    );

                    $row->published_at = now();
                    $row->save();
                }
            } catch (Throwable $e) {
                // El broker puede estar temporalmente caído: reintentamos en la
                // siguiente iteración sin perder los eventos (siguen en la tabla).
                $this->error('[outbox:relay] '.$e->getMessage());
            }

            sleep(max(1, $sleep));
        }
    }
}
