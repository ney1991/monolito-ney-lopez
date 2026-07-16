<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Shared — Transactional Outbox (bandeja de salida de eventos de dominio)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox', function (Blueprint $table) {
            $table->uuid('id')->primary();           // = eventId (idempotencia)
            $table->uuid('aggregate_id');
            $table->string('event_name');
            $table->string('routing_key');
            $table->jsonb('payload');
            $table->timestampTz('occurred_on');
            $table->timestampTz('published_at')->nullable(); // null = pendiente
            $table->timestampsTz();

            // El relay consulta constantemente los pendientes: lo indexamos
            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox');
    }
};
