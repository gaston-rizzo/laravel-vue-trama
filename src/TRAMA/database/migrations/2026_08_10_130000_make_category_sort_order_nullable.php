<?php

use App\Support\TramaClock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El orden editorial pasa a existir solamente para categorías visibles.
     * Las posiciones existentes se normalizan en una secuencia correlativa.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')
                ->nullable()
                ->default(null)
                ->change();
        });

        $editorialNow = TramaClock::now();

        $visibleIds = DB::table('categories')
            ->where('is_active', true)
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->whereNotNull('accent_color')
            ->where('accent_color', '!=', '')
            ->whereNotNull('cover_image')
            ->where('cover_image', '!=', '')
            ->whereExists(function ($query) use ($editorialNow): void {
                $query
                    ->selectRaw('1')
                    ->from('articles')
                    ->whereColumn('articles.category_id', 'categories.id')
                    ->whereNull('articles.deleted_at')
                    ->where('articles.status', 'published')
                    ->whereNotNull('articles.published_at')
                    ->where('articles.published_at', '<=', $editorialNow);
            })
            ->orderByRaw('sort_order IS NULL ASC')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        DB::table('categories')->update(['sort_order' => null]);

        foreach ($visibleIds as $index => $id) {
            DB::table('categories')
                ->where('id', $id)
                ->update(['sort_order' => $index + 1]);
        }
    }

    /** Recupera el esquema histórico con 0 para categorías sin posición pública. */
    public function down(): void
    {
        DB::table('categories')
            ->whereNull('sort_order')
            ->update(['sort_order' => 0]);

        Schema::table('categories', function (Blueprint $table): void {
            $table->unsignedInteger('sort_order')
                ->nullable(false)
                ->default(0)
                ->change();
        });
    }
};
