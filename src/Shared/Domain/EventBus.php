<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Puerto para publicar eventos de dominio.
 *
 * La capa de aplicación depende de ESTA interfaz, no de RabbitMQ ni del Outbox.
 * La implementación concreta (OutboxEventBus) persiste el evento en la misma
 * transacción del comando; el relay lo entrega luego al broker.
 */
interface EventBus
{
    public function publish(DomainEvent ...$events): void;
}
