<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Impide que un mismo usuario guarde más de una respuesta
     * dentro del mismo comentario principal.
     */
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table): void {
            $table->unique(
                ['parent_id', 'user_id'],
                'comments_parent_user_unique'
            );
        });
    }

    /**
     * Elimina la restricción agregada por esta migración.
     */
    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table): void {
            $table->dropUnique('comments_parent_user_unique');
        });
    }
};