<?php

/* ============================================================================
 * MIGRATION: add_automatic_moderation_to_comments_table.php
 * ============================================================================
 *
 * Agrega únicamente los tres campos que TRAMA necesita para coordinar la
 * moderación automática, distinguir decisiones automáticas/editoriales y guardar
 * una causa corta sin almacenar auditorías JSON ni fechas adicionales.
 *
 * La columna "comments.status" ya es string, por lo que puede aceptar el estado
 * técnico "processing" sin alterar su tipo.
 *
 * ESTADOS DESPUÉS DE ESTA INTEGRACIÓN
 * ---------------------------------------------------------------------------
 * processing -> el comentario fue recibido y Node todavía lo está analizando.
 *               No se publica y no aparece en moderación humana.
 * pending    -> el análisis automático terminó y requiere decisión editorial.
 * approved   -> comentario publicado.
 * rejected   -> comentario rechazado/retirado y conservado como historial.
 *
 * ORIGEN DE LA DECISIÓN
 * ---------------------------------------------------------------------------
 * moderation_source = automatic
 *     La transición fue tomada por el pipeline automático.
 *
 * moderation_source = editorial
 *     La transición fue tomada por un editor/moderador.
 *
 * Esta separación permite que dos filas con "status = rejected" se filtren como:
 *
 *     Rechazados             -> rechazo editorial.
 *     Rechazos automáticos   -> rechazo del pipeline automático.
 *
 * Los rechazados históricos ya existentes se marcan como "editorial", porque
 * fueron creados antes de incorporar el pipeline automático.
 *
 * moderation_revision:
 *     versión del texto que está siendo evaluada. Evita aplicar el resultado de
 *     un Job viejo sobre una edición posterior.
 *
 * moderation_reason:
 *     código corto de la causa principal: threat_high, toxicity_clear,
 *     spam_clear, language_review, editorial_rejected, etc.
 *
 * ============================================================================ */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Incorpora los campos auxiliares y clasifica el historial rechazado previo.
     */
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table): void {
            $table->unsignedInteger('moderation_revision')
                ->default(0)
                ->after('status');

            $table->string('moderation_source', 20)
                ->nullable()
                ->after('moderation_revision');

            $table->string('moderation_reason', 80)
                ->nullable()
                ->after('moderation_source');

            /*
             * El panel consulta frecuentemente status + source para separar los
             * rechazados editoriales de los automáticos.
             */
            $table->index(
                ['status', 'moderation_source'],
                'comments_status_moderation_source_index'
            );

            /*
             * Acelera las comprobaciones por noticia + usuario + estado usadas al
             * impedir duplicados activos y por el endpoint liviano que sólo existe
             * mientras el usuario espera que termine "processing".
             */
            $table->index(
                ['article_id', 'user_id', 'status'],
                'comments_article_user_status_index'
            );

            $table->index(
                'moderation_reason',
                'comments_moderation_reason_index'
            );
        });

        /*
         * Antes de esta migración no existían rechazos automáticos. Por lo tanto,
         * todo "rejected" histórico corresponde al flujo editorial previo.
         */
        DB::table('comments')
            ->where('status', 'rejected')
            ->whereNull('moderation_source')
            ->update([
                'moderation_source' => 'editorial',
            ]);
    }

    /**
     * Vuelve al esquema anterior sin modificar la columna status.
     */
    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table): void {
            $table->dropIndex(
                'comments_status_moderation_source_index'
            );

            $table->dropIndex(
                'comments_article_user_status_index'
            );

            $table->dropIndex(
                'comments_moderation_reason_index'
            );

            $table->dropColumn([
                'moderation_revision',
                'moderation_source',
                'moderation_reason',
            ]);
        });
    }
};
