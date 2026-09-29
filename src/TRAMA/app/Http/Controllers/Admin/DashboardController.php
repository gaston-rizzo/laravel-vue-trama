<?php

/* ============================================================================
 * CONTROLLER: DashboardController.php
 * ============================================================================
 *
 * Prepara el panel principal del equipo editorial de TRAMA.
 *
 * Reúne métricas, noticias pendientes de revisión, ranking de lectura y últimas
 * acciones registradas para que cada usuario vea el estado operativo del portal
 * según su rol.
 * ============================================================================ */

namespace App\Http\Controllers\Admin;

use App\Support\TramaClock;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminArticleResource;
use App\Models\AdvertisementDailyMetric;
use App\Models\Article;
use App\Models\ArticleRevision;
use App\Models\Comment;
use App\Models\User;
use App\Services\EditorialStatsService;
use App\Support\AdvertisementPlacement;
use App\Support\TramaBridge;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Muestra el resumen operativo del panel editorial para el usuario autenticado.
     */
    public function __invoke(Request $request, EditorialStatsService $stats): Response
    {
        $user = $request->user();

        // El administrador no participa del módulo de noticias,
        // por eso no recibe actividad editorial.
        $activity = collect();

        // Solo periodistas y editores reciben actividad editorial en el panel.
        if ($user->canAccessArticles()) {
            // Inicia la consulta de revisiones con la noticia y el usuario responsable.
            $activityQuery = ArticleRevision::query()
                ->with(['article:id,title,slug', 'user:id,name'])
                ->whereHas('article', function (Builder $article) use ($user): void {
                    // El periodista solo puede ver actividad de sus propias noticias.
                    if (! $user->canReviewArticles()) {
                        $article->where('author_id', $user->id);

                        return;
                    }

                    // El editor ve sus propias noticias y las noticias ajenas
                    // después de que abandonan el estado borrador.
                    $article->where(function (Builder $visible) use ($user): void {
                        $visible
                            ->where('author_id', $user->id)
                            ->orWhere('status', '!=', 'draft');
                    });
                });

            // Transforma las últimas acciones visibles para el usuario actual.
            $activity = $activityQuery
                ->latest()
                // Mantiene el historial breve para evitar un panel excesivamente cargado.
                ->take(6)
                ->get()
                ->map(fn ($revision) => [
                    'id' => $revision->id,
                    'action' => $revision->action,
                    'status_from' => $revision->status_from,
                    'status_to' => $revision->status_to,
                    'article' => $revision->article?->only(['title', 'slug']),
                    'user' => $revision->user?->only(['name']),
                    'created_at' => $revision->created_at?->toISOString(),
                ]);
        }

        // El administrador no participa del flujo de noticias,
        // por eso no recibe artículos pendientes de revisión.
        $pendingReview = collect();

        if ($user->canAccessArticles()) {
            // El periodista ve únicamente sus propias noticias en revisión.
            // El editor ve todas las noticias enviadas a revisión.
            $pendingReview = Article::query()
                ->with(['author', 'category', 'tags'])
                ->when(
                    ! $user->canReviewArticles(),
                    fn ($query) => $query->where('author_id', $user->id)
                )
                ->where('status', 'review')
                ->latest('updated_at')
                ->take(6)
                ->get();
        }

        // Vue recibe colecciones ya transformadas y permisos mínimos para mostrar botones.
        return TramaBridge::render('Admin/Dashboard', [
            'stats' => $stats->getDashboardIndicatorsForUser($user),
            'topArticles' => $user->canAccessArticles()
                ? AdminArticleResource::collection(
                    $stats->getMostViewedArticlesForDashboard($user)
                )->resolve()
                : [],
            'pendingReview' => AdminArticleResource::collection($pendingReview)->resolve(),
            'activity' => $activity,
            'recentActivity' => [
                'publishedArticles' => $this->recentPublishedArticles($user),
                'pendingComments' => $this->recentPendingComments($user),
                'registeredUsers' => $this->recentRegisteredUsers($user),
                'bannerClicks' => $this->recentBannerClicks($user),
            ],
            'permissions' => [
                // Permite entrar al módulo de noticias.
                'can_access_articles' => $user->canAccessArticles(),

                // Permite revisar, devolver, publicar y archivar noticias.
                'can_review' => $user->canReviewArticles(),

                // Permite aprobar o rechazar comentarios.
                'can_moderate_comments' => $user->canModerateComments(),

                // Permite administrar usuarios, categorías, etiquetas,
                // publicidades y configuración del sistema.
                'can_manage_system' => $user->canManageSystem(),

                // Se utiliza para comprobar si una noticia pertenece al usuario.
                'user_id' => $user->id,
            ],
        ]);
    }

    /**
     * Devuelve las últimas noticias publicadas que puede ver el usuario del panel.
     *
     * Los periodistas solo ven sus propias noticias.
     * Los editores ven publicaciones de todo el equipo.
     * El administrador no recibe este listado.
     *
     * @return list<array<string, mixed>>
     */
    private function recentPublishedArticles(User $user): array
    {
        // El administrador no recibe listados de noticias en su dashboard.
        if (! $user->canAccessArticles()) {
            return [];
        }

        return Article::query()
            // Carga autor y categoría para mostrar contexto sin consultas extras desde Vue.
            ->with(['author:id,name', 'category:id,name'])
            // Un periodista no debe ver en el dashboard publicaciones de otros autores.
            ->when($user->role === 'journalist', fn ($query) => $query->where('author_id', $user->id))
            // Solo entran noticias publicadas y actualmente visibles en el portal.
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', TramaClock::now())
            // La publicación más nueva queda arriba.
            ->latest('published_at')
            // Mantiene el panel breve: cinco filas son suficientes para lectura rápida.
            ->take(5)
            ->get()
            ->map(fn (Article $article) => [
                'id' => $article->id,
                'title' => $article->title,
                'slug' => $article->slug,
                'author' => $article->author?->name,
                'category' => $article->category?->name,
                'published_at' => $article->published_at?->format('d/m/Y H:i'),
            ])
            ->values()
            ->all();
    }

    /**
     * Devuelve comentarios pendientes que todavía necesitan moderación.
     *
     * Solo el editor recibe esta lista porque es quien puede
     * aprobar o rechazar comentarios desde el panel.
     *
     * @return list<array<string, mixed>>
     */
    private function recentPendingComments(User $user): array
    {
        if (! $user->canModerateComments()) {
            return [];
        }

        return Comment::query()
            // Article permite enlazar el comentario con la noticia afectada.
            ->with(['article:id,title,slug'])
            // TRAMA usa moderación previa, por eso pending significa "esperando revisión".
            ->where('status', 'pending')
            // Los comentarios nuevos aparecen primero para atenderlos rápido.
            ->latest()
            ->take(5)
            ->get()
            ->map(fn (Comment $comment) => [
                'id' => $comment->id,
                'body' => str($comment->body)->limit(90)->toString(),
                'author_name' => $comment->author_name,
                'article_title' => $comment->article?->title,
                'article_slug' => $comment->article?->slug,
                'created_at' => $comment->created_at?->format('d/m/Y H:i'),
            ])
            ->values()
            ->all();
    }

    /**
     * Devuelve las últimas cuentas creadas en TRAMA.
     *
     * Esta información es sensible para operación interna, por eso se limita al
     * administrador. Editores y periodistas reciben un arreglo vacío.
     *
     * @return list<array<string, mixed>>
     */
    private function recentRegisteredUsers(User $user): array
    {
        if (! $user->canManageSystem()) {
            return [];
        }

        return User::query()
            // Ordena por creación para mostrar las cuentas más recientes.
            ->latest()
            ->take(5)
            ->get(['id', 'name', 'email', 'role', 'email_verified_at', 'created_at'])
            ->map(fn (User $registeredUser) => [
                'id' => $registeredUser->id,
                'name' => $registeredUser->name,
                'email' => $registeredUser->email,
                'role' => $registeredUser->role,
                'verified' => $registeredUser->email_verified_at !== null,
                'created_at' => $registeredUser->created_at?->format('d/m/Y H:i'),
            ])
            ->values()
            ->all();
    }

    /**
     * Devuelve la actividad reciente de clicks acumulados por banner y día.
     *
     * TRAMA no guarda cada click individual. Guarda totales diarios por banner,
     * entonces esta lista muestra los últimos días con clicks registrados para
     * cada publicidad.
     *
     * @return list<array<string, mixed>>
     */
    private function recentBannerClicks(User $user): array
    {
        if (! $user->canManageSystem()) {
            return [];
        }

        return AdvertisementDailyMetric::query()
            // Carga el banner para mostrar marca, nombre y ubicación.
            ->with(['advertisement:id,name,brand,placement'])
            // Solo interesan filas que hayan recibido al menos un click.
            ->where('clicks_count', '>', 0)
            // Primero los días más recientes; si hay empate, los banners con más clicks suben.
            ->orderByDesc('date')
            ->orderByDesc('clicks_count')
            ->take(5)
            ->get()
            ->map(fn (AdvertisementDailyMetric $metric) => [
                'id' => $metric->id,
                'brand' => $metric->advertisement?->brand,
                'name' => $metric->advertisement?->name,
                'placement' => $metric->advertisement
                    ? AdvertisementPlacement::label($metric->advertisement->placement)
                    : null,
                'date' => $metric->date?->format('d/m/Y'),
                'clicks' => $metric->clicks_count,
            ])
            ->values()
            ->all();
    }
}
