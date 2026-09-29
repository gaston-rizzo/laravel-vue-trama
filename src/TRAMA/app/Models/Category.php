<?php

/* ============================================================================
 * MODEL: Category.php
 * ============================================================================
 *
 * Representa las secciones de noticias de TRAMA.
 *
 * Una categoría activa puede utilizarse en contenido nuevo. Solo participa del
 * portal y recibe orden editorial cuando además conserva sus datos públicos
 * completos y tiene al menos una noticia publicada disponible para lectura.
 * ============================================================================ */

namespace App\Models;

use App\Models\Concerns\UsesTramaClock;

use App\Support\TramaClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use UsesTramaClock;
    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'accent_color',
        'cover_image',
        'sort_order',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // Define si la sección se encuentra habilitada para uso editorial.
            'is_active' => 'boolean',
            // null significa que todavía no participa del orden público.
            'sort_order' => 'integer',
        ];
    }

    /**
     * Limita una consulta a categorías que realmente pueden mostrarse al lector.
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        $editorialNow = TramaClock::now();

        return $query
            ->where('is_active', true)
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->whereNotNull('accent_color')
            ->where('accent_color', '!=', '')
            ->whereNotNull('cover_image')
            ->where('cover_image', '!=', '')
            ->whereHas('articles', function (Builder $articles) use ($editorialNow): void {
                $articles
                    ->where('status', 'published')
                    ->whereNotNull('published_at')
                    ->where('published_at', '<=', $editorialNow);
            });
    }

    /**
     * Relación completa con noticias, sin filtrar estados, porque administración
     * necesita conservar también borradores, revisión, programadas y archivadas.
     */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }
}
