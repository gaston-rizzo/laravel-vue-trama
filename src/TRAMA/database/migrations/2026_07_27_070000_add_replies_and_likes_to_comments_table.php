<?php

/* ============================================================================
 * MIGRATION: add_replies_and_likes_to_comments_table.php
 * ============================================================================
 *
 * Agrega respuestas de un solo nivel y votos positivos a los comentarios.
 *
 * parent_id identifica a qué comentario principal responde una fila. La tabla
 * comment_likes guarda qué usuario dio "Me gusta" a cada comentario y bloquea
 * votos duplicados con una clave única por comentario y usuario.
 * ============================================================================ */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea la relación de respuestas y la tabla de votos positivos.
     */
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table): void {
            if (! Schema::hasColumn('comments', 'parent_id')) {
                $table->foreignId('parent_id')
                    ->nullable()
                    ->after('article_id')
                    ->constrained('comments')
                    ->cascadeOnDelete();
            }
        });

        if (! Schema::hasTable('comment_likes')) {
            Schema::create('comment_likes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('comment_id')->constrained('comments')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['comment_id', 'user_id']);
            });
        }
    }

    /**
     * Revierte likes y respuestas para volver al esquema anterior.
     */
    public function down(): void
    {
        Schema::dropIfExists('comment_likes');

        Schema::table('comments', function (Blueprint $table): void {
            if (Schema::hasColumn('comments', 'parent_id')) {
                $table->dropForeign(['parent_id']);
                $table->dropColumn('parent_id');
            }
        });
    }
};
