<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Engagement\Application\AssignPhraseOnCheckIn\AssignPhraseOnCheckInCommand;
use App\Engagement\Application\AssignPhraseOnCheckIn\AssignPhraseOnCheckInHandler;
use App\Engagement\Domain\Phrase\DashboardProjectionRepository;
use App\Engagement\Domain\Phrase\MotivationalPhrase;
use App\Engagement\Domain\Phrase\MotivationalPhraseRepository;
use App\Engagement\Domain\Quote\Quote;
use App\Engagement\Domain\Quote\QuoteProviderPort;
use App\Engagement\Domain\Quote\QuoteUnavailable;
use PHPUnit\Framework\TestCase;

/**
 * Tests del caso de uso usando DOBLES de los puertos (sin Laravel, sin BD, sin red).
 * Demuestra que gracias a la inversión de dependencias el dominio es testeable
 * en aislamiento total.
 */
final class AssignPhraseOnCheckInHandlerTest extends TestCase
{
    public function test_asigna_la_frase_y_la_proyecta_en_el_read_model(): void
    {
        $quotes = new class implements QuoteProviderPort {
            public function fetchRandom(): Quote
            {
                return new Quote('Hazlo', 'Yo');
            }
        };
        $phrases = new InMemoryPhraseRepository();
        $dashboard = new InMemoryDashboardProjection();

        $handler = new AssignPhraseOnCheckInHandler($quotes, $phrases, $dashboard);
        $handler(new AssignPhraseOnCheckInCommand('acc-1', 'user-1', '2026-01-01T10:00:00Z'));

        $this->assertCount(1, $phrases->saved);
        $this->assertCount(1, $dashboard->projected);
        $this->assertSame('Hazlo', $dashboard->projected[0]->quote->text);
    }

    public function test_si_el_proveedor_falla_propaga_para_que_el_consumidor_reintente(): void
    {
        $quotes = new class implements QuoteProviderPort {
            public function fetchRandom(): Quote
            {
                throw QuoteUnavailable::because('HTTP 500');
            }
        };

        $handler = new AssignPhraseOnCheckInHandler(
            $quotes,
            new InMemoryPhraseRepository(),
            new InMemoryDashboardProjection(),
        );

        $this->expectException(QuoteUnavailable::class);
        $handler(new AssignPhraseOnCheckInCommand('acc-1', 'user-1', '2026-01-01T10:00:00Z'));
    }

    public function test_es_idempotente_si_el_evento_llega_duplicado(): void
    {
        $quotes = new class implements QuoteProviderPort {
            public int $calls = 0;
            public function fetchRandom(): Quote
            {
                $this->calls++;
                return new Quote('Hazlo', 'Yo');
            }
        };
        $phrases = new InMemoryPhraseRepository();
        $phrases->markProcessed('acc-1'); // ya procesado antes

        $handler = new AssignPhraseOnCheckInHandler($quotes, $phrases, new InMemoryDashboardProjection());
        $handler(new AssignPhraseOnCheckInCommand('acc-1', 'user-1', '2026-01-01T10:00:00Z'));

        $this->assertSame(0, $quotes->calls); // no se volvió a llamar a la API
    }
}

// --- Dobles en memoria de los puertos ---

final class InMemoryPhraseRepository implements MotivationalPhraseRepository
{
    /** @var MotivationalPhrase[] */
    public array $saved = [];
    private array $processed = [];

    public function save(MotivationalPhrase $phrase): void
    {
        $this->saved[] = $phrase;
        $this->processed[] = $phrase->accessLogId;
    }

    public function existsForAccessLog(string $accessLogId): bool
    {
        return in_array($accessLogId, $this->processed, true);
    }

    public function markProcessed(string $accessLogId): void
    {
        $this->processed[] = $accessLogId;
    }
}

final class InMemoryDashboardProjection implements DashboardProjectionRepository
{
    /** @var MotivationalPhrase[] */
    public array $projected = [];

    public function project(MotivationalPhrase $phrase): void
    {
        $this->projected[] = $phrase;
    }

    public function forUser(string $userId): array
    {
        return $this->projected;
    }
}
