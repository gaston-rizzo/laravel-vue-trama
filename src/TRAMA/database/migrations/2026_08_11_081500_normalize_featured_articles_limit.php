<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Reduce a siete las noticias que actualmente reservan lugar en portada.
     *
     * Conserva las siete más recientes según su fecha programada, publicada o
     * de última edición. Las demás siguen publicadas, pero pasan a estado de
     * portada normal porque is_featured queda en false.
     */
    public function up(): void
    {
        $extraFeaturedIds = DB::table('articles')
            ->where('is_featured', true)
            ->whereIn('status', ['published', 'scheduled'])
            ->orderByRaw('COALESCE(scheduled_at, published_at, updated_at) DESC')
            ->orderByDesc('id')
            ->pluck('id')
            ->slice(7)
            ->values();

        if ($extraFeaturedIds->isEmpty()) {
            return;
        }

        DB::table('articles')
            ->whereIn('id', $extraFeaturedIds->all())
            ->update([
                'is_featured' => false,
            ]);
    }

    /**
     * La normalización no puede revertirse de forma segura porque no es posible
     * saber qué noticias habían sido destacadas antes de aplicar este ajuste.
     */
    public function down(): void
    {
        // No se restauran marcas editoriales antiguas de manera automática.
    }
};
