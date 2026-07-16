<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Engagement\Domain\Quote\Quote;
use App\Engagement\Domain\Quote\QuoteUnavailable;
use App\Engagement\Infrastructure\Http\DummyJsonQuoteProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use PHPUnit\Framework\TestCase;

/**
 * Tests del ADAPTADOR de la API externa (Anti-Corruption Layer).
 *
 * Objetivo: demostrar la INVERSIÓN DE DEPENDENCIAS y el manejo de fallos.
 * No se hace ninguna llamada de red real: se sustituye el cliente HTTP por un
 * doble (Http fake). El dominio pide un QuoteProviderPort y aquí verificamos que
 * el adaptador cumple ese contrato incluso cuando el tercero falla.
 */
final class DummyJsonQuoteProviderTest extends TestCase
{
    private const URL = 'https://dummyjson.com/quotes/random';

    private function provider(Factory $http): DummyJsonQuoteProvider
    {
        return new DummyJsonQuoteProvider($http, self::URL, 3.0);
    }

    public function test_traduce_el_json_del_tercero_a_un_value_object_quote(): void
    {
        $http = new Factory();
        $http->fake([
            '*' => Factory::response(['id' => 1, 'quote' => 'Just do it', 'author' => 'Nike'], 200),
        ]);

        $quote = $this->provider($http)->fetchRandom();

        $this->assertInstanceOf(Quote::class, $quote);
        $this->assertSame('Just do it', $quote->text);
        $this->assertSame('Nike', $quote->author);
    }

    public function test_un_error_500_sostenido_se_traduce_a_quote_unavailable(): void
    {
        $http = new Factory();
        $http->fake(['*' => Factory::response('boom', 500)]);

        $this->expectException(QuoteUnavailable::class);

        $this->provider($http)->fetchRandom();
    }

    public function test_un_cambio_de_contrato_json_no_rompe_el_dominio(): void
    {
        // El tercero responde 200 pero con un shape inesperado (sin quote/author)
        $http = new Factory();
        $http->fake(['*' => Factory::response(['message' => 'changed API'], 200)]);

        $this->expectException(QuoteUnavailable::class);

        $this->provider($http)->fetchRandom();
    }

    public function test_un_timeout_de_red_se_traduce_a_quote_unavailable(): void
    {
        $http = new Factory();
        $http->fake(function () {
            throw new ConnectionException('cURL error 28: timeout');
        });

        $this->expectException(QuoteUnavailable::class);

        $this->provider($http)->fetchRandom();
    }
}
