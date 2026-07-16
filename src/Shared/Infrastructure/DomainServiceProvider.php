<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\AccessControl\Domain\AccessLogRepository;
use App\AccessControl\Infrastructure\Persistence\EloquentAccessLogRepository;
use App\Engagement\Domain\Phrase\DashboardProjectionRepository;
use App\Engagement\Domain\Phrase\MotivationalPhraseRepository;
use App\Engagement\Domain\Quote\QuoteProviderPort;
use App\Engagement\Infrastructure\Http\DummyJsonQuoteProvider;
use App\Engagement\Infrastructure\Persistence\EloquentDashboardProjectionRepository;
use App\Engagement\Infrastructure\Persistence\EloquentMotivationalPhraseRepository;
use App\Shared\Domain\EventBus;
use App\Shared\Domain\TransactionManager;
use App\Shared\Infrastructure\Database\DbTransactionManager;
use App\Shared\Infrastructure\Outbox\OutboxEventBus;
use App\Shared\Infrastructure\RabbitMq\RabbitMqConnection;
use App\Shared\Infrastructure\RabbitMq\RabbitMqPublisher;
use Illuminate\Support\ServiceProvider;

/**
 * Punto único donde se conectan PUERTOS (interfaces del dominio) con sus
 * ADAPTADORES (implementaciones de infraestructura).
 *
 * Aquí vive la Inversión de Dependencias: el dominio declara "necesito un
 * QuoteProviderPort"; este provider decide que la implementación concreta es
 * DummyJsonQuoteProvider. Cambiar de proveedor = cambiar una línea aquí.
 */
final class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // --- Bus de eventos: implementado con el patrón Outbox ---
        $this->app->bind(EventBus::class, OutboxEventBus::class);

        // --- Transacciones: puerto que abstrae DB::transaction() de Laravel ---
        $this->app->bind(TransactionManager::class, DbTransactionManager::class);

        // --- Conexión y publisher de RabbitMQ (singletons) ---
        $this->app->singleton(RabbitMqConnection::class, function () {
            return new RabbitMqConnection(config('rabbitmq'));
        });

        $this->app->singleton(RabbitMqPublisher::class, function ($app) {
            return new RabbitMqPublisher(
                $app->make(RabbitMqConnection::class),
                config('rabbitmq.exchange'),
            );
        });

        // --- AccessControl: puerto → adaptador ---
        $this->app->bind(AccessLogRepository::class, EloquentAccessLogRepository::class);

        // --- Engagement: puertos → adaptadores ---
        $this->app->bind(QuoteProviderPort::class, function ($app) {
            return new DummyJsonQuoteProvider(
                $app->make(\Illuminate\Http\Client\Factory::class),
                config('services.quotes.url'),
                config('services.quotes.timeout'),
            );
        });
        $this->app->bind(MotivationalPhraseRepository::class, EloquentMotivationalPhraseRepository::class);
        $this->app->bind(DashboardProjectionRepository::class, EloquentDashboardProjectionRepository::class);
    }
}
