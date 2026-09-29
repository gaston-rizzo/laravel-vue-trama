<?php

/* ============================================================================
 * MIGRATION: create_cache_table.php
 * ============================================================================
 *
 * Crea las tablas de caché y bloqueos de Laravel.
 *
 * Permite utilizar el driver database para datos temporales y locks compartidos entre procesos.
 * ============================================================================ */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea las tablas usadas por caché y bloqueos distribuidos.
     */
    public function up(): void
    {
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration')->index();
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration')->index();
        });
    }

    /**
     * Elimina las tablas de caché y bloqueos.
     */
    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};
