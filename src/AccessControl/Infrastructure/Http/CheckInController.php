<?php

declare(strict_types=1);

namespace App\AccessControl\Infrastructure\Http;

use App\AccessControl\Application\RegisterCheckIn\RegisterCheckInCommand;
use App\AccessControl\Application\RegisterCheckIn\RegisterCheckInHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Controlador HTTP del check-in (adaptador de entrada).
 *
 * Su única labor: validar el request, construir el Command y delegar en el
 * handler. No contiene lógica de negocio. Devuelve 201 de inmediato: la frase
 * motivacional llegará después, de forma asíncrona.
 *
 * Header opcional `Idempotency-Key`: si el cliente (torno) la envía y reintenta
 * el mismo check-in con la misma clave, se le devuelve el registro ya existente
 * en vez de duplicar el acceso físico. Ver RegisterCheckInHandler.
 */
final class CheckInController
{
    public function __invoke(Request $request, RegisterCheckInHandler $handler): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
            'branch_id' => ['required', 'uuid'],
        ]);

        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey !== null && ! Str::isUuid($idempotencyKey)) {
            return response()->json([
                'message' => 'El header Idempotency-Key debe ser un UUID válido.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $accessLogId = $handler(new RegisterCheckInCommand(
            userId: $data['user_id'],
            branchId: $data['branch_id'],
            idempotencyKey: $idempotencyKey,
        ));

        return response()->json([
            'access_log_id' => $accessLogId,
            'status' => 'checked_in',
        ], JsonResponse::HTTP_CREATED);
    }
}
