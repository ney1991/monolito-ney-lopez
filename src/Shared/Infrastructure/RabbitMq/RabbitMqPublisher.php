<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\RabbitMq;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Publica mensajes en el exchange de eventos de dominio.
 */
final class RabbitMqPublisher
{
    public function __construct(
        private readonly RabbitMqConnection $connection,
        private readonly string $exchange,
    ) {
    }

    /**
     * @param array $message  cuerpo ya serializable (se codifica a JSON)
     */
    public function publish(string $routingKey, array $message, array $headers = []): void
    {
        $amqpMessage = new AMQPMessage(
            json_encode($message, JSON_THROW_ON_ERROR),
            [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT, // sobrevive reinicios
                'application_headers' => new \PhpAmqpLib\Wire\AMQPTable($headers),
            ]
        );

        $this->connection->channel()->basic_publish($amqpMessage, $this->exchange, $routingKey);
    }
}
