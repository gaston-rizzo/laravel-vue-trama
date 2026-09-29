<?php

/* ============================================================================
 * MIGRATION: create_contact_messages_table.php
 * ============================================================================
 *
 * Guarda consultas enviadas desde la página pública de Contacto de TRAMA.
 *
 * El formulario incluye un motivo específico para problemas de cuenta, de modo
 * que una persona bloqueada pueda utilizar el enlace ofrecido desde el login.
 * ============================================================================ */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea la bandeja mínima de consultas públicas.
     */
    public function up(): void
    {
        Schema::create('contact_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('reason', 40)->index();
            $table->string('name', 120);
            $table->string('email');
            $table->text('message');
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Elimina la tabla creada para las consultas públicas.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
