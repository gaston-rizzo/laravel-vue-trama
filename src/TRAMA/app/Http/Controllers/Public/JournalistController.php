<?php

/* ============================================================================
 * CONTROLLER: JournalistController.php
 * ============================================================================
 *
 * Muestra el perfil público del periodista de TRAMA.
 *
 * La pantalla permite abrir el nombre de un autor desde una noticia y ver su
 * información pública: foto, cargo, biografía breve, cantidad de noticias
 * publicadas, secciones donde escribió y archivo paginado de notas visibles en
 * el portal.
 * ============================================================================ */

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleCardResource;
use App\Models\Article;
use App\Models\User;
use App\Support\TramaBridge;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Response;

class JournalistController extends Controller
{
    /**
     * Muestra el perfil del periodista y sus noticias publicadas.
     */
    public function show(Request $request, User $user, ?string $slug = null): Response
    {
        // Solo se muestran perfiles de cuentas internas que pueden firmar noticias.
        abort_unless(in_array($user->role, User::EDITORIAL_ROLES, true), 404);

        // Consulta base: todas las noticias públicas escritas por este periodista.
        $publishedArticlesQuery = $this->publishedArticlesQuery($user);

        // Slug público del periodista usado para construir enlaces del perfil.
        $journalistSlug = Str::slug($user->name);

        // Cantidad total de noticias visibles que firmó el periodista.
        $publishedArticlesCount = (clone $publishedArticlesQuery)->count();

        // Última fecha de publicación, usada como dato resumido en la cabecera.
        $latestPublishedAt = (clone $publishedArticlesQuery)->max('published_at');

        // Secciones principales donde escribió, ordenadas por cantidad de noticias.
        $sections = $this->sectionsWrittenByJournalist($publishedArticlesQuery);

        // Sección elegida desde los filtros de la misma página del periodista.
        $activeSectionSlug = $this->activeSectionSlug($request, $sections);

        // El archivo se filtra solo cuando el slug pertenece a una sección del periodista.
        $filteredArticlesQuery = (clone $publishedArticlesQuery)
            ->when($activeSectionSlug, function (Builder $query) use ($activeSectionSlug): void {
                $query->whereHas('category', fn (Builder $category) => $category->where('slug', $activeSectionSlug));
            });

        // Archivo paginado de notas. Cada página muestra 9 para mantener una grilla estable.
        $articles = $filteredArticlesQuery
            ->with(['author', 'category', 'tags'])
            ->latest('published_at')
            ->paginate(9)
            ->withQueryString();

        return TramaBridge::render('Public/JournalistShow', [
            'journalist' => [
                // Datos públicos del autor. No se expone email porque no hace falta
                // para navegar el sitio y evita publicar datos de contacto personales.
                'id' => $user->id,
                'name' => $user->name,
                'slug' => $journalistSlug,
                'job_title' => $user->job_title,
                'bio' => $user->bio,
                // El perfil público usa el avatar genérico si la foto física ya no existe.
                'avatar' => $user->avatarUrl(),
                'published_articles_count' => $publishedArticlesCount,
                'latest_published_at' => $latestPublishedAt,
                'sections' => $this->sectionsWithFilterUrls($user, $journalistSlug, $sections),
                'profile_url' => route('journalists.show', [
                    'user' => $user->id,
                    'slug' => $journalistSlug,
                ]),
            ],
            'filters' => [
                // El filtro activo permite que Vue pinte "Todos" o una sección como seleccionada.
                'active_section' => $activeSectionSlug,
                'all_url' => route('journalists.show', [
                    'user' => $user->id,
                    'slug' => $journalistSlug,
                ]),
            ],
            'articles' => [
                // Noticias listas para reutilizar ArticleCard en Vue.
                'data' => ArticleCardResource::collection($articles->getCollection())->resolve($request),
                'links' => $articles->linkCollection(),
                'meta' => [
                    'current_page' => $articles->currentPage(),
                    'last_page' => $articles->lastPage(),
                    'per_page' => $articles->perPage(),
                    'total' => $articles->total(),
                ],
            ],
        ]);
    }

    /**
     * Devuelve la consulta de noticias públicas escritas por un periodista.
     */
    private function publishedArticlesQuery(User $user): Builder
    {
        return Article::query()
            // author_id guarda qué usuario firmó la noticia.
            ->where('author_id', $user->id)
            // Si la categoría fue desactivada, sus noticias tampoco aparecen acá.
            ->publiclyVisible();
    }

    /**
     * Resume en qué secciones escribió más el periodista.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sectionsWrittenByJournalist(Builder $publishedArticlesQuery): array
    {
        return (clone $publishedArticlesQuery)
            // Une la tabla categories para obtener nombre, slug y color de cada sección.
            ->join('categories', 'articles.category_id', '=', 'categories.id')
            // Cuenta cuántas noticias del periodista hay en cada sección.
            ->selectRaw('categories.name, categories.slug, categories.accent_color, COUNT(*) as articles_count')
            // Agrupa por sección para que el conteo salga separado por categoría.
            ->groupBy('categories.id', 'categories.name', 'categories.slug', 'categories.accent_color')
            // Las secciones con más notas aparecen primero.
            ->orderByDesc('articles_count')
            // Se muestran pocas secciones para que la cabecera no se vuelva pesada.
            ->limit(4)
            ->get()
            ->map(fn ($section) => [
                'name' => $section->name,
                'slug' => $section->slug,
                'accent_color' => $section->accent_color,
                'articles_count' => (int) $section->articles_count,
            ])
            ->values()
            ->all();
    }

    /**
     * Devuelve el slug de sección activo solo si pertenece al periodista.
     *
     * @param array<int, array<string, mixed>> $sections
     */
    private function activeSectionSlug(Request $request, array $sections): ?string
    {
        $requestedSection = (string) $request->query('seccion', '');

        if ($requestedSection === '') {
            return null;
        }

        $availableSectionSlugs = collect($sections)->pluck('slug');

        return $availableSectionSlugs->contains($requestedSection) ? $requestedSection : null;
    }

    /**
     * Agrega a cada sección la URL que filtra el archivo en esta misma página.
     *
     * @param array<int, array<string, mixed>> $sections
     * @return array<int, array<string, mixed>>
     */
    private function sectionsWithFilterUrls(User $user, string $journalistSlug, array $sections): array
    {
        return collect($sections)
            ->map(function (array $section) use ($user, $journalistSlug): array {
                return [
                    ...$section,
                    'filter_url' => route('journalists.show', [
                        'user' => $user->id,
                        'slug' => $journalistSlug,
                        'seccion' => $section['slug'],
                    ]),
                ];
            })
            ->values()
            ->all();
    }
}
