<?php

declare(strict_types=1);

namespace App\Engagement\Application\AssignPhraseOnCheckIn;

use App\Engagement\Domain\Phrase\DashboardProjectionRepository;
use App\Engagement\Domain\Phrase\MotivationalPhrase;
use App\Engagement\Domain\Phrase\MotivationalPhraseRepository;
use App\Engagement\Domain\Quote\QuoteProviderPort;

/**
 * Caso de uso que reacciona (asíncronamente) al evento CheckedIn.
 *
 * Pasos:
 *   1. Idempotencia: si ya procesamos este acceso, no hacemos nada (el evento
 *      puede llegar duplicado por la garantía at-least-once del relay/broker).
 *   2. Obtener la frase del proveedor externo a través del PUERTO (ACL).
 *      Si lanza QuoteUnavailable, NO la capturamos: dejamos que propague para
 *      que el consumidor (infraestructura) reintente y, agotados los intentos,
 *      mande el mensaje a la Dead Letter Queue. Así los reintentos son reales.
 *      (Alternativa de producto: Quote::fallback() para degradar en vez de
 *      reintentar. Se discute en el README.)
 *   3. Persistir la frase (modelo de escritura).
 *   4. Proyectar al modelo de lectura desnormalizado (CQRS).
 */
final class AssignPhraseOnCheckInHandler
{
    public function __construct(
        private readonly QuoteProviderPort $quotes,
        private readonly MotivationalPhraseRepository $phrases,
        private readonly DashboardProjectionRepository $dashboard,
    ) {
    }

    public function __invoke(AssignPhraseOnCheckInCommand $command): void
    {
        // (1) Idempotencia
        if ($this->phrases->existsForAccessLog($command->accessLogId)) {
            return;
        }

        // (2) Integración con la API externa vía puerto.
        //     Si falla lanza QuoteUnavailable y propaga (reintento vía broker).
        $quote = $this->quotes->fetchRandom();

        // (3) Modelo de escritura
        $phrase = MotivationalPhrase::assign(
            userId: $command->userId,
            accessLogId: $command->accessLogId,
            quote: $quote,
            checkedInAt: $command->checkedInAt,
        );
        $this->phrases->save($phrase);

        // (4) Proyección al modelo de lectura (dashboard)
        $this->dashboard->project($phrase);
    }
}
