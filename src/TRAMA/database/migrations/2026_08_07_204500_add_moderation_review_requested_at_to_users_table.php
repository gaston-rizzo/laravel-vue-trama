<?php

/* ============================================================================
 * MIGRATION: add_moderation_review_requested_at_to_users_table.php
 * ============================================================================
 *
 * Agrega una marca simple para identificar usuarios registrados que un editor
 * derivó manualmente al administrador desde la moderación de comentarios.
 *
 * El campo no aplica sanciones ni bloqueos. Su única función es indicar que la
 * cuenta requiere revisión administrativa. Las medidas sobre la cuenta se
 * administran por separado desde el módulo de usuarios.
 * ============================================================================ */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega la fecha de solicitud de revisión administrativa.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('moderation_review_requested_at')
                ->nullable()
                ->after('password_reset_required_at')
                ->index();
        });
    }

    /**
     * Elimina la marca de revisión administrativa.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex([
                'moderation_review_requested_at',
            ]);

            $table->dropColumn('moderation_review_requested_at');
        });
    }
};
