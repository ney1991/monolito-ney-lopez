<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Engagement — modelo de escritura (frase asignada por acceso)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motivational_phrases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->uuid('access_log_id')->unique(); // idempotencia: 1 frase por acceso
            $table->text('quote_text');
            $table->string('quote_author');
            $table->timestampTz('checked_in_at');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motivational_phrases');
    }
};
