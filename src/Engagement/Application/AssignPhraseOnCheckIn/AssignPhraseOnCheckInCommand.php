<?php

declare(strict_types=1);

namespace App\Engagement\Application\AssignPhraseOnCheckIn;

/**
 * Entrada del caso de uso de Engagement.
 *
 * OJO: son PRIMITIVAS, no el objeto CheckedIn de AccessControl. Engagement NO
 * importa clases del otro módulo; solo conoce el contrato de datos (el JSON del
 * evento). Ese es el desacoplamiento que exige la arquitectura.
 */
final class AssignPhraseOnCheckInCommand
{
    public function __construct(
        public readonly string $accessLogId,
        public readonly string $userId,
        public readonly string $checkedInAt,
    ) {
    }
}
