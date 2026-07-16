<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Engagement — modelo de LECTURA desnormalizado (CQRS).
// Acceso + frase ya combinados: el dashboard lee sin JOINs.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_read_model', function (Blueprint $table) {
            $table->uuid('access_log_id')->primary();
            $table->uuid('user_id')->index();          // filtro del endpoint
            $table->timestampTz('checked_in_at');
            $table->text('quote_text');
            $table->string('quote_author');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_read_model');
    }
};
