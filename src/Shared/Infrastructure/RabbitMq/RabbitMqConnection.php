<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\RabbitMq;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;

/**
 * Gestiona la conexión AMQP y declara la topología del broker.
 *
 * Topología (idempotente: declarar algo ya existente no falla):
 *   - exchange `domain_events`      (topic)   → enruta por routing key
 *   - exchange `domain_events.dlx`  (topic)   → Dead Letter Exchange
 *   - cola `engagement.assign_phrase`         → bindeada a `access.checked_in`,
 *       con x-dead-letter-exchange apuntando a la DLX
 *   - cola `engagement.assign_phrase.dlq`     → recibe los mensajes muertos
 */
final class RabbitMqConnection
{
    private ?AMQPStreamConnection $connection = null;
    private ?AMQPChannel $channel = null;

    public function __construct(private readonly array $config)
    {
    }

    public function channel(): AMQPChannel
    {
        if ($this->channel instanceof AMQPChannel && $this->channel->is_open()) {
            return $this->channel;
        }

        $this->connection = new AMQPStreamConnection(
            $this->config['host'],
            $this->config['port'],
            $this->config['user'],
            $this->config['password'],
            $this->config['vhost'],
        );

        $this->channel = $this->connection->channel();
        $this->declareTopology($this->channel);

        return $this->channel;
    }

    private function declareTopology(AMQPChannel $channel): void
    {
        $exchange = $this->config['exchange'];
        $dlx = $this->config['dlx'];

        // Exchanges (durables para sobrevivir reinicios del broker)
        $channel->exchange_declare($exchange, AMQPExchangeType::TOPIC, false, true, false);
        $channel->exchange_declare($dlx, AMQPExchangeType::TOPIC, false, true, false);

        foreach ($this->config['queues'] as $queue) {
            // Cola principal: los mensajes rechazados van a la DLX
            $channel->queue_declare(
                $queue['name'],
                false,
                true,   // durable
                false,
                false,
                false,
                new \PhpAmqpLib\Wire\AMQPTable([
                    'x-dead-letter-exchange' => $dlx,
                    'x-dead-letter-routing-key' => $queue['routing_key'],
                ])
            );
            $channel->queue_bind($queue['name'], $exchange, $queue['routing_key']);

            // Dead Letter Queue: almacena mensajes que agotaron reintentos
            $channel->queue_declare($queue['dlq'], false, true, false, false);
            $channel->queue_bind($queue['dlq'], $dlx, $queue['routing_key']);
        }
    }

    public function close(): void
    {
        $this->channel?->close();
        $this->connection?->close();
        $this->channel = null;
        $this->connection = null;
    }
}
