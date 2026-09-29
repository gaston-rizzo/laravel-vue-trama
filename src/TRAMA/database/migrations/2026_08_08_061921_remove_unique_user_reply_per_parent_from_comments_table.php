<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Elimina la restricción que permitía guardar una única respuesta
     * por usuario dentro de cada comentario principal.
     *
     * Esa restricción impedía que un lector pudiera responder nuevamente
     * después de que una respuesta anterior hubiera sido rechazada.
     *
     * La regla actual se controla desde Laravel:
     *
     * - una respuesta pendiente bloquea una nueva respuesta;
     * - una respuesta aprobada bloquea una nueva respuesta;
     * - una respuesta rechazada queda cerrada y permite un nuevo intento.
     *
     * Antes de eliminar el índice único se crea un índice normal sobre
     * parent_id. MySQL necesita ese índice para mantener la clave foránea
     * que relaciona una respuesta con su comentario principal.
     */
    public function up(): void
    {
        /*
         * Mantiene indexado parent_id para que la clave foránea continúe
         * teniendo un índice válido después de eliminar el índice único.
         */
        Schema::table('comments', function (Blueprint $table): void {
            $table->index(
                'parent_id',
                'comments_parent_id_index'
            );
        });

        /*
         * Elimina la unicidad parent_id + user_id.
         *
         * A partir de este momento pueden existir varias respuestas históricas
         * del mismo usuario en un comentario, siempre que Laravel permita crear
         * la nueva participación según su estado de moderación.
         */
        Schema::table('comments', function (Blueprint $table): void {
            $table->dropUnique('comments_parent_user_unique');
        });
    }

    /**
     * Restaura la restricción anterior si se revierte esta migración.
     *
     * Primero vuelve a crear el índice único, que también puede servir como
     * índice para la relación de parent_id. Después elimina el índice normal
     * agregado por esta migración.
     *
     * La reversión solamente podrá completarse si no existen varias respuestas
     * del mismo usuario asociadas al mismo comentario principal.
     */
    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table): void {
            $table->unique(
                ['parent_id', 'user_id'],
                'comments_parent_user_unique'
            );
        });

        Schema::table('comments', function (Blueprint $table): void {
            $table->dropIndex('comments_parent_id_index');
        });
    }
};