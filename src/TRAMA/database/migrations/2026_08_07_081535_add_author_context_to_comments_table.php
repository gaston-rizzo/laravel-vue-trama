<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Guarda el contexto público con el que fue escrito un comentario.
     *
     * null representa la participación normal de un lector.
     * article_author identifica una respuesta del periodista autor de la noticia.
     * trama_team identifica una intervención oficial de un editor de TRAMA.
     */
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table): void {
            $table->string('author_context', 30)
                ->nullable()
                ->after('author_email');
        });
    }

    /**
     * Elimina el contexto público agregado a los comentarios.
     */
    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table): void {
            $table->dropColumn('author_context');
        });
    }
};