<?php

/* ============================================================================
 * MIGRATION: create_jobs_table.php
 * ============================================================================
 *
 * Crea las tablas utilizadas por las colas de Laravel.
 *
 * Incluye trabajos pendientes, lotes de ejecución y registro de fallos para procesos asíncronos.
 * ============================================================================ */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea colas, lotes de trabajos y registro de fallos.
     */
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');

            /*
             * EXCEPCIÓN TÉCNICA AL RELOJ EDITORIAL:
             * failed_jobs pertenece a la infraestructura de Laravel y registra
             * cuándo falló realmente un trabajo del sistema. No es una fecha
             * editorial visible del portal, por eso conserva CURRENT_TIMESTAMP.
             */
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    /**
     * Elimina las tablas utilizadas por el sistema de colas.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
    }
};
