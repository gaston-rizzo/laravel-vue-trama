<?php

/* ============================================================================
 * SERVICE: EditorialStatsService.php
 * ============================================================================
 *
 * Prepara las métricas que se muestran en el dashboard del CMS.
 *
 * Calcula indicadores de noticias, visualizaciones, comentarios, usuarios,
 * categorías, etiquetas y publicidades según los permisos del usuario.
 *
 * El periodista recibe datos de sus propias noticias, el editor recibe
 * información de la redacción y el administrador recibe métricas del sistema.
 * ============================================================================ */

namespace App\Services;

use App\Support\TramaClock;

use App\Models\Article;
use App\Models\ArticleView;
use App\Models\Advertisement;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class EditorialStatsService
{
    /**
     * Devuelve los indicadores principales correspondientes al rol del usuario.
     *
     * Ejemplos:
     * - Administrador: empleados activos, usuarios registrados, categorías,
     *   etiquetas y publicidades activas.
     * - Editor: estados generales de noticias, comentarios pendientes
     *   y autores activos.
     * - Periodista: estados, vistas y rendimiento de sus propias noticias.
     *
     * @return array<string, int>
     */
    public function getDashboardIndicatorsForUser(User $user): array
    {
        // El administrador recibe indicadores de los módulos que puede gestionar.
        if ($user->canManageSystem()) {
            return [
                // Cuentas internas habilitadas para trabajar en el CMS.
                'Empleados activos' => User::query()
                    ->whereIn('role', ['admin', 'editor', 'journalist'])
                    ->where('is_active', true)
                    ->count(),

                // Cuentas registradas para utilizar las funciones del portal público.
                // No tienen acceso al panel administrativo.
                'Usuarios registrados' => User::query()
                    ->where('role', 'reader')
                    ->count(),

                // Secciones que actualmente pueden utilizarse en el portal.
                'Categorías activas' => Category::query()
                    ->where('is_active', true)
                    ->count(),

                // Etiquetas activas que no fueron fusionadas dentro de otra.
                'Etiquetas activas' => Tag::query()
                    ->where('is_active', true)
                    ->whereNull('merged_into_id')
                    ->count(),

                // Banners habilitados desde el panel administrativo.
                'Publicidades activas' => Advertisement::query()
                    ->where('is_active', true)
                    ->count(),
            ];
        }

        // Obtiene únicamente las noticias que el periodista o editor puede consultar.
        $articles = $this->visibleArticlesForUser($user);

        $viewsLabel = $user->canReviewArticles()
            ? 'Vistas hoy'
            : 'Vistas';

        $indicators = [
            // Noticias publicadas y actualmente visibles en el portal.
            'Publicadas' => (clone $articles)
                ->where('status', 'published')
                ->whereNotNull('published_at')
                ->where('published_at', '<=', TramaClock::now())
                ->count(),

            // Borradores propios que todavía no ingresaron al flujo editorial.
            'Borradores' => (clone $articles)
                ->where('status', 'draft')
                ->count(),

            // Noticias enviadas para que un editor tome una decisión.
            'En revisión' => (clone $articles)
                ->where('status', 'review')
                ->count(),

            // Noticias devueltas al autor para realizar correcciones.
            'Devueltas' => (clone $articles)
                ->where('status', 'needs_changes')
                ->count(),

            // Noticias que tienen una publicación futura programada.
            'Programadas' => (clone $articles)
                ->where('status', 'scheduled')
                ->count(),

            // Noticias retiradas del portal sin eliminar su historial.
            'Archivadas' => (clone $articles)
                ->where('status', 'archived')
                ->count(),

            // Lecturas relevantes para el rol actual.
            $viewsLabel => $this->dashboardViewsForUser($user, $articles),
        ];

        // Solo el editor recibe métricas relacionadas con moderación y redacción.
        if ($user->canReviewArticles()) {
            $indicators['Comentarios pendientes'] = Comment::query()
                ->where('status', 'pending')
                ->count();

            $indicators['Autores activos'] = User::query()
                ->whereIn('role', ['editor', 'journalist'])
                ->where('is_active', true)
                ->count();
        }

        return $indicators;
    }

    /**
     * Devuelve las noticias publicadas con más visualizaciones.
     *
     * El ranking respeta la visibilidad editorial del usuario y excluye noticias
     * que todavía no están disponibles en el portal público.
     */
    public function getMostViewedArticlesForDashboard(User $user)
    {
        return $this->visibleArticlesForUser($user)
            // Carga el autor y la categoría utilizados en el dashboard.
            ->with(['author', 'category'])
            // Solo incluye noticias publicadas y actualmente visibles.
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', TramaClock::now())
            // Coloca primero las noticias con mayor cantidad de vistas.
            ->orderByDesc('views')
            // Limita el ranking a seis noticias.
            ->take(6)
            ->get();
    }

    /**
     * Construye la consulta de noticias visibles para el usuario según su rol.
     *
     * El periodista queda limitado a sus propias noticias. El editor puede consultar
     * todas sus noticias y las noticias de otros autores que ya salieron de borrador.
     */
    private function visibleArticlesForUser(User $user): Builder
    {
        $articles = Article::query();

        // El periodista solo puede consultar sus propias noticias.
        if (! $user->canReviewArticles()) {
            return $articles->where('author_id', $user->id);
        }

        // El editor conserva acceso a todas sus noticias, incluidos sus borradores.
        // De otros autores solo ve noticias que ya ingresaron al flujo editorial.
        return $articles->where(function (Builder $visible) use ($user): void {
            $visible
                ->where('author_id', $user->id)
                ->orWhere('status', '!=', 'draft');
            });
    }

    /**
     * Calcula las vistas del dashboard segÃºn el rol.
     *
     * El periodista conserva el acumulado de sus propias notas. El editor recibe
     * la actividad del dÃ­a editorial actual para evitar que el panel principal
     * muestre como operativo todo el histÃ³rico sembrado del portal.
     */
    private function dashboardViewsForUser(User $user, Builder $articles): int
    {
        if (! $user->canReviewArticles()) {
            return (clone $articles)->sum('views');
        }

        return ArticleView::query()
            ->whereDate('viewed_on', TramaClock::today())
            ->whereHas('article', function (Builder $article) use ($user): void {
                $article
                    ->publiclyVisible()
                    ->where(function (Builder $visible) use ($user): void {
                        $visible
                            ->where('author_id', $user->id)
                            ->orWhere('status', '!=', 'draft');
                    });
            })
            ->count();
    }
}
