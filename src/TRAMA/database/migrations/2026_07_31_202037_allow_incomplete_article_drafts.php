<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permite que los borradores se guarden antes de completar
     * título, categoría y cuerpo.
     */
    public function up(): void
    {
        // La clave foránea debe quitarse antes
        // de cambiar category_id a nullable.
        Schema::table('articles', function (Blueprint $table): void {
            $table->dropForeign(['category_id']);
        });

        Schema::table('articles', function (Blueprint $table): void {
            // La categoría puede faltar temporalmente en un borrador.
            $table->unsignedBigInteger('category_id')
                ->nullable()
                ->change();

            // El título puede faltar temporalmente en un borrador.
            $table->string('title', 180)
                ->nullable()
                ->change();

            // El cuerpo puede faltar temporalmente en un borrador.
            $table->longText('body')
                ->nullable()
                ->change();
        });

        // Restaura la clave foránea con la misma protección
        // contra la eliminación de categorías utilizadas.
        Schema::table('articles', function (Blueprint $table): void {
            $table->foreign('category_id')
                ->references('id')
                ->on('categories')
                ->restrictOnDelete();
        });
    }

    /**
     * Vuelve a exigir esos campos en la base de datos.
     */
    public function down(): void
    {
        $fallbackCategoryId = DB::table('categories')
            ->orderBy('id')
            ->value('id');

        // No puede restaurarse NOT NULL si existen noticias
        // sin categoría y tampoco existe una categoría de respaldo.
        if (
            DB::table('articles')->whereNull('category_id')->exists()
            && $fallbackCategoryId === null
        ) {
            throw new RuntimeException(
                'No se puede revertir la migración porque hay borradores sin categoría.'
            );
        }

        // Completa temporalmente los valores nulos antes
        // de restaurar las columnas obligatorias.
        DB::table('articles')
            ->whereNull('category_id')
            ->update(['category_id' => $fallbackCategoryId]);

        DB::table('articles')
            ->whereNull('title')
            ->update(['title' => 'Borrador sin título']);

        DB::table('articles')
            ->whereNull('body')
            ->update(['body' => '']);

        Schema::table('articles', function (Blueprint $table): void {
            $table->dropForeign(['category_id']);
        });

        Schema::table('articles', function (Blueprint $table): void {
            $table->unsignedBigInteger('category_id')
                ->nullable(false)
                ->change();

            $table->string('title', 180)
                ->nullable(false)
                ->change();

            $table->longText('body')
                ->nullable(false)
                ->change();
        });

        Schema::table('articles', function (Blueprint $table): void {
            $table->foreign('category_id')
                ->references('id')
                ->on('categories')
                ->restrictOnDelete();
        });
    }
};