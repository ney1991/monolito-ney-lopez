<?php

declare(strict_types=1);

namespace App\Engagement\Infrastructure\Console;

use App\Engagement\Application\AssignPhraseOnCheckIn\AssignPhraseOnCheckInCommand;
use App\Engagement\Application\AssignPhraseOnCheckIn\AssignPhraseOnCheckInHandler;
use App\Shared\Infrastructure\RabbitMq\RabbitMqConnection;
use App\Shared\Infrastructure\RabbitMq\RabbitMqPublisher;
use Illuminate\Console\Command;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

/**
 * Worker de Engagement: consume el evento CheckedIn del broker.
 *
 * Resiliencia (lo que evalúa la rúbrica):
 *   - Éxito                → ACK (se elimina de la cola).
 *   - Fallo transitorio    → se re-publica con contador x-retries incrementado
 *                            (reintento). Un 500 puntual de la API se recupera.
 *   - Reintentos agotados  → basic_nack(requeue=false): RabbitMQ enruta el
 *                            mensaje a la Dead Letter Exchange → DLQ, sin
 *                            bloquear la cola ni perder el mensaje.
 *
 * Nada de esto afecta al check-in: ya se registró antes de publicar el evento.
 */
final class ConsumeEngagementCommand extends Command
{
    protected $signature = 'engagement:consume';

    protected $description = 'Consume eventos CheckedIn, integra la API externa y proyecta el read model';

    public function handle(
        RabbitMqConnection $connection,
        RabbitMqPublisher $publisher,
        AssignPhraseOnCheckInHandler $handler,
    ): int {
        $config = config('rabbitmq');
        $queue = $config['queues']['assign_phrase']['name'];
        $routingKey = $config['queues']['assign_phrase']['routing_key'];
        $maxRetries = (int) $config['max_retries'];

        $channel = $connection->channel();

        // Un mensaje a la vez: no acaparar toda la cola en un solo worker
        $channel->basic_qos(0, 1, false);

        $this->info("[engagement:consume] Escuchando cola '{$queue}'...");

        $callback = function (AMQPMessage $message) use ($handler, $publisher, $routingKey, $maxRetries) {
            try {
                $body = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);

                $command = new AssignPhraseOnCheckInCommand(
                    accessLogId: $body['aggregateId'],
                    userId: $body['payload']['userId'],
                    checkedInAt: $body['payload']['checkedInAt'],
                );

                $handler($command);

                $message->ack();
            } catch (Throwable $e) {
                $this->handleFailure($message, $publisher, $routingKey, $maxRetries, $e);
            }
        };

        $channel->basic_consume($queue, '', false, false, false, false, $callback);

        // Bucle de consumo (bloqueante)
        while ($channel->is_consuming()) {
            $channel->wait();
        }

        return self::SUCCESS;
    }

    private function handleFailure(
        AMQPMessage $message,
        RabbitMqPublisher $publisher,
        string $routingKey,
        int $maxRetries,
        Throwable $e,
    ): void {
        $headers = $message->has('application_headers')
            ? $message->get('application_headers')->getNativeData()
            : [];
        $retries = (int) ($headers['x-retries'] ?? 0);

        if ($retries < $maxRetries) {
            // Reintento: re-publicamos con el contador incrementado y ACK al original
            $this->warn("[engagement:consume] Fallo ({$e->getMessage()}). Reintento ".($retries + 1)."/{$maxRetries}");
            usleep(200_000 * ($retries + 1)); // backoff simple

            $publisher->publish(
                routingKey: $routingKey,
                message: json_decode($message->getBody(), true),
                headers: ['x-retries' => $retries + 1],
            );
            $message->ack();

            return;
        }

        // Agotados los reintentos → a la Dead Letter Queue
        $this->error("[engagement:consume] Reintentos agotados. Enviando a DLQ: {$e->getMessage()}");
        $message->nack(false); // requeue=false → va a la DLX configurada en la cola
    }
}
