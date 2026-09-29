<?php

/* ============================================================================
 * SERVICE: ArticleQueryService.php
 * ============================================================================
 *
 * Servicio de consultas públicas de noticias.
 *
 * Reúne las consultas que necesita el sitio público de TRAMA: portada,
 * búsqueda, páginas de categoría, rankings de lectura y noticias relacionadas.
 * Los controladores usan este servicio para recibir datos ya filtrados,
 * ordenados y con sus relaciones principales cargadas.
 * ============================================================================ */

namespace App\Services;

use App\Support\TramaClock;

use App\Models\Article;
use App\Models\Category;

use Carbon\CarbonImmutable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ArticleQueryService
{
    /**
     * Devuelve todas las secciones de noticias que se muestran en la portada.
     *
     * Incluye la noticia principal, la cinta de urgentes, destacadas, últimas
     * noticias, ranking de más leídas y secciones por categoría.
     *
     * @return array<string, mixed>
     */
    public function getHomePageSections(): array
    {
        // Consulta base para noticias publicadas: se clona en cada sección para
        // reutilizar filtros comunes sin mezclar condiciones entre consultas.
        $editorialNow = $this->editorialNow();
        $frontPageStartsAt = $this->frontPageStartsAt();

        $base = Article::query()
            // Solo entran noticias cuya categoría también continúa publicada.
            ->publiclyVisible()
            // Portada usa una ventana de 7 días desde la publicación.
            ->where('published_at', '>=', $frontPageStartsAt)
            ->with(['author', 'category', 'tags'])
            ->latest('published_at');

        return [
            // Noticia principal de portada.
            'hero' => (clone $base)->where('is_featured', true)->first(),
            // Noticias marcadas como urgentes para la cinta superior.
            'breaking' => (clone $base)->where('is_breaking', true)->take(4)->get(),
            // Noticias destacadas restantes, sin repetir la principal.
            'featured' => (clone $base)->where('is_featured', true)->skip(1)->take(6)->get(),
            // Últimas noticias publicadas para el bloque de actualidad.
            'latest' => (clone $base)->take(8)->get(),
            // Ranking por cantidad de vistas, independiente del orden por fecha.
            'mostRead' => (clone $base)->reorder()->orderByDesc('views')->take(8)->get(),
            // Categorías activas para navegación y filtros visibles.
            'categories' => Category::query()
                // Solo se muestran categorías activas que ya tienen contenido publicado.
                ->publiclyVisible()
                // Respeta el orden configurado por el equipo editorial.
                ->orderBy('sort_order')
                ->get(),
            // Secciones de portada agrupadas por categoría.
            'categorySections' => $this->getHomePageCategorySections(),
        ];
    }

    /**
     * Devuelve las categorías activas con sus últimas noticias publicadas.
     *
     * Cada categoría incluye hasta cuatro noticias para construir la sección
     * "Cobertura por área" de la portada.
     *
     * @return Collection<int, Category>
     */
    public function getHomePageCategorySections(): Collection
    {
        $editorialNow = $this->editorialNow();
        $frontPageStartsAt = $this->frontPageStartsAt();

        return Category::query()
            // La categoría debe ser pública y además tener contenido reciente para este bloque.
            ->publiclyVisible()
            ->whereHas('articles', function ($query) use ($editorialNow, $frontPageStartsAt): void {
                $query
                    ->where('status', 'published')
                    ->whereNotNull('published_at')
                    ->where('published_at', '<=', $editorialNow)
                    ->where('published_at', '>=', $frontPageStartsAt);
            })
            // Respeta el orden configurado en el panel editorial.
            ->orderBy('sort_order')
            ->with(['articles' => function ($query) use ($editorialNow, $frontPageStartsAt): void {
                $query
                    // Dentro de cada categoría solo entran noticias visibles.
                    ->where('status', 'published')
                    ->whereNotNull('published_at')
                    ->where('published_at', '<=', $editorialNow)
                    // La portada muestra cobertura reciente: 7 días desde published_at.
                    ->where('published_at', '>=', $frontPageStartsAt)
                    // Autor y categoría se cargan para evitar consultas extra en Vue.
                    ->with(['author', 'category'])
                    // Las más nuevas aparecen primero dentro del bloque.
                    ->latest('published_at')
                    // La portada muestra una muestra breve de cada categoría.
                    ->take(4);
            }])
            ->get();
    }

    /**
     * Busca noticias publicadas por término libre.
     */
    public function searchPublishedArticles(?string $term, ?string $categorySlug = null)
    {
        $query = Article::query()
            // Una categoría inactiva retira también sus noticias del buscador público.
            ->publiclyVisible();

        $this->applySearchTerm($query, $term);

        return $query
            ->when($categorySlug, function ($query) use ($categorySlug): void {
                $query->whereHas(
                    'category',
                    fn ($category) => $category
                        ->publiclyVisible()
                        ->where('slug', $categorySlug)
                );
            })
            ->with(['author', 'category', 'tags'])
            ->latest('published_at')
            ->paginate(9)
            ->withQueryString();
    }

    /**
     * Devuelve el momento actual del reloj editorial persistente de TRAMA.
     *
     * La fecha y la hora salen juntas de TramaClock. Así una publicación pública
     * nunca se compara contra la hora real del equipo que abre el portfolio.
     */
    private function editorialNow(): CarbonImmutable
    {
        return TramaClock::now();
    }

    /**
     * Devuelve desde qué fecha una noticia cuenta como reciente para portada.
     *
     * Después de siete días la noticia sigue publicada y accesible, pero deja de
     * ocupar espacios de portada como urgentes o destacadas.
     */
    private function frontPageStartsAt(): CarbonImmutable
    {
        return $this->editorialNow()
            ->subDays(7)
            ->startOfDay();
    }

    /**
     * Devuelve una noticia publicada por slug.
     */
    public function findPublishedBySlug(string $slug): Article
    {
        return Article::query()
            // La URL deja de ser pública si su categoría fue desactivada.
            ->publiclyVisible()
            ->with([
                'author',
                'category',
                'tags',
                // Solo comentarios aprobados forman parte de la carga básica.
                'comments' => fn($query) => $query->where('status', 'approved')->latest(),
            ])
            ->where('slug', $slug)
            ->firstOrFail();
    }

    /**
     * Devuelve notas relacionadas por categoría y etiquetas.
     */
    public function related(Article $article, int $limit = 4)
    {
        $tagIds = $article->tags->pluck('id');

        return Article::query()
            // Las relacionadas también respetan la disponibilidad de su categoría.
            ->publiclyVisible()
            ->with(['author', 'category'])
            ->whereKeyNot($article->id)
            ->where(function ($query) use ($article, $tagIds): void {
                $query
                    ->where('category_id', $article->category_id)
                    ->orWhereHas('tags', fn($tag) => $tag->whereIn('tags.id', $tagIds));
            })
            ->latest('published_at')
            ->take($limit)
            ->get();
    }

    /**
     * Agrega condiciones de búsqueda por texto libre a una consulta de noticias.
     *
     * Busca el término en título, subtítulo, bajada, categoría y etiquetas.
     *
     * No revisa el cuerpo completo de la noticia porque genera resultados poco
     * precisos: una palabra perdida dentro del texto puede hacer aparecer una
     * nota cuyo título no parece relacionado con lo que el usuario escribió.
     * Si el término está vacío no modifica la consulta.
     */
    private function applySearchTerm(Builder $query, ?string $term): void
    {
        if (! $term) {
            return;
        }

        // Escapa comodines de SQL para que % y _ se busquen como caracteres reales.
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

        $query->where(function (Builder $inner) use ($like): void {
            $inner
                // Título visible de la noticia.
                ->where('title', 'like', $like)
                // Subtítulo mostrado debajo del título principal.
                ->orWhere('subtitle', 'like', $like)
                // Resumen usado en tarjetas y portada.
                ->orWhere('excerpt', 'like', $like)
                // Nombre de la categoría asociada.
                ->orWhereHas('category', fn (Builder $category) => $category->where('name', 'like', $like))
                // Nombre de cualquiera de sus etiquetas.
                ->orWhereHas('tags', fn (Builder $tag) => $tag->where('name', 'like', $like));
        });
    }
}
