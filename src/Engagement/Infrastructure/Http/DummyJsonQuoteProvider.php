<?php

declare(strict_types=1);

namespace App\Engagement\Infrastructure\Http;

use App\Engagement\Domain\Quote\Quote;
use App\Engagement\Domain\Quote\QuoteProviderPort;
use App\Engagement\Domain\Quote\QuoteUnavailable;
use Illuminate\Http\Client\Factory as HttpClient;
use Throwable;

/**
 * ADAPTADOR (Anti-Corruption Layer) contra https://dummyjson.com/quotes/random.
 *
 * Aquí —y SOLO aquí— vive el conocimiento de HTTP y del formato JSON del tercero.
 * Responsabilidades del ACL:
 *   - Aplicar un timeout explícito (no heredar la latencia del tercero).
 *   - Traducir el JSON externo → Value Object Quote del dominio.
 *   - Validar el contrato: si el JSON no trae los campos esperados, lanzar
 *     QuoteUnavailable en vez de dejar pasar un shape ajeno al dominio.
 *   - Convertir CUALQUIER fallo (red, 5xx, JSON inválido) en QuoteUnavailable.
 *
 * Implementa el puerto QuoteProviderPort → Inversión de Dependencias.
 */
final class DummyJsonQuoteProvider implements QuoteProviderPort
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $url,
        private readonly float $timeout,
    ) {
    }

    public function fetchRandom(): Quote
    {
        try {
            $response = $this->http
                ->timeout($this->timeout)
                ->acceptJson()
                ->get($this->url);
        } catch (Throwable $e) {
            // Fallo de red / DNS / timeout de conexión
            throw QuoteUnavailable::because('error de red: '.$e->getMessage());
        }

        // Errores HTTP (500 sostenido, 404, etc.)
        if ($response->failed()) {
            throw QuoteUnavailable::because('HTTP '.$response->status());
        }

        $data = $response->json();

        // Validación del contrato: el tercero pudo cambiar su JSON
        if (! is_array($data) || ! isset($data['quote'], $data['author'])) {
            throw QuoteUnavailable::because('contrato JSON inesperado');
        }

        // Traducción al modelo del dominio
        return new Quote(
            text: (string) $data['quote'],
            author: (string) $data['author'],
        );
    }
}
