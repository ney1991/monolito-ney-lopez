<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AccessControl — soporte de idempotencia en el check-in.
//
// Si el torno (o el cliente HTTP) reintenta el mismo check-in porque no recibió
// la respuesta a tiempo, este campo permite detectarlo y devolver el registro
// ya existente en vez de duplicar el acceso físico.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_logs', function (Blueprint $table) {
            $table->uuid('idempotency_key')->nullable()->unique()->after('branch_id');
        });
    }

    public function down(): void
    {
        Schema::table('access_logs', function (Blueprint $table) {
            $table->dropColumn('idempotency_key');
        });
    }
};
