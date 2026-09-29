<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Guarda el anclaje mínimo necesario para continuar el reloj entre sesiones.
     *
     * editorial_anchor_at pertenece al tiempo ficticio visible de TRAMA.
     * real_anchor_epoch y last_real_activity_epoch guardan solamente segundos Unix:
     * sirven como cronómetro técnico para medir una sesión, pero no almacenan la
     * fecha real del equipo como si fuera una fecha editorial del portal.
     */
    public function up(): void
    {
        Schema::create('trama_clock_state', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->dateTime('editorial_anchor_at');
            $table->unsignedBigInteger('real_anchor_epoch');
            $table->unsignedBigInteger('last_real_activity_epoch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trama_clock_state');
    }
};
