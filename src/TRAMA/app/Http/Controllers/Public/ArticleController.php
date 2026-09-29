<?php

/* ============================================================================
 * CONTROLLER: ArticleController.php
 * ============================================================================
 *
 * Muestra noticias publicadas en el portal público.
 *
 * Busca la noticia por slug, registra una vista sin guardar la IP en claro,
 * carga comentarios aprobados y entrega a Vue los datos necesarios para pintar
 * la página de lectura y las notas relacionadas.
 * ============================================================================ */

namespace App\Http\Controllers\Public;

use App\Support\TramaClock;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdvertisementResource;
use App\Http\Resources\ArticleCardResource;
use App\Http\Resources\ArticleDetailResource;
use App\Models\Article;
use App\Models\ArticleView;
use App\Models\Comment;
use App\Services\AdvertisementSelectorService;
use App\Services\ArticleQueryService;
use App\Support\AdvertisementPlacement;
use App\Support\TramaBridge;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use Inertia\Response;

class ArticleController extends Controller
{
    /**
     * Muestra una noticia publicada.
     */
    public function show(
        string $slug,
        Request $request,
        ArticleQueryService $articles,
        AdvertisementSelectorService $advertisements
    ): Response
    {
        // Si el slug no pertenece a una noticia publicada, Laravel devuelve 404.
        $article = $articles->findPublishedBySlug($slug);

        // Registra la vista para actualizar las estadísticas de la noticia
        // sin guardar la IP ni los datos del navegador en texto claro.
        DB::transaction(function () use ($article, $request): void {
            $article->increment('views');

            ArticleView::query()->create([
                'article_id' => $article->id,
                'ip_hash' => hash('sha256', (string) $request->ip()),
                'user_agent_hash' => hash('sha256', (string) $request->userAgent()),
                'viewed_on' => TramaClock::today()->toDateString(),
            ]);
        });

        // Se refresca el artículo para que el contador de vistas llegue actualizado a Vue.
        $article = $article->fresh(['author', 'category', 'tags']);

        // La página pública muestra comentarios aprobados para todos. Si el
        // usuario inició sesión, también ve sus propios comentarios pendientes.
        // Solo se cargan los primeros 10 comentarios principales y las primeras
        // 3 respuestas de cada uno; el resto se pide desde Vue con botones.
        $currentUserId = $request->user()?->id;

        // Cuenta solo comentarios principales visibles para decidir si Vue debe
        // mostrar el botón "Ver más comentarios".
        $visibleMainCommentsCount = $article->comments()
            // parent_id null identifica el primer nivel de conversación.
            ->whereNull('parent_id');

        // Aplica la regla de visibilidad pública para contar solamente los comentarios
        // principales que este usuario puede ver.
        $visibleMainCommentsCount = $this->addPublicCommentVisibilityFilter(
            $visibleMainCommentsCount,
            $currentUserId
        )
            // Devuelve el total sin traer todos los registros.
            ->count();

        // Cuenta comentarios principales y respuestas visibles para mostrar el
        // total general de la sección "Comentarios".
        $visibleCommentsCount = $this->addPublicCommentVisibilityFilter(
            // Incluye principales y respuestas porque acá no se filtra parent_id.
            $article->comments(),
            $currentUserId
        )
            // Devuelve un número, no una colección de modelos.
            ->count();

        /*
         * Indica si esta cuenta posee cualquier participación de la noticia todavía
         * en "processing", sea comentario principal o respuesta.
         *
         * La página utiliza esta bandera exclusivamente para activar un polling
         * liviano de estado. Cuando la última fila deja "processing", Vue recarga
         * solamente la información de la noticia y detiene las consultas.
         */
        $hasProcessingComments = $currentUserId
            ? $article->comments()
                ->where('user_id', $currentUserId)
                ->where('status', 'processing')
                ->exists()
            : false;

        /*
         * Indica si existe un comentario principal propio que todavía está siendo
         * validado automáticamente.
         *
         * "processing" es deliberadamente distinto de "pending": el primero significa
         * que Node aún está trabajando; el segundo significa que el análisis terminó
         * y decidió enviar el caso a moderación humana.
         */
        $hasProcessingMainComment = $currentUserId
            ? $article->comments()
                ->where('user_id', $currentUserId)
                ->whereNull('parent_id')
                ->where('status', 'processing')
                ->exists()
            : false;

        // Indica si el usuario ya tiene un comentario principal pendiente en
        // esta noticia. Aquí "pending" significa exclusivamente revisión humana.
        $hasPendingMainComment = $currentUserId
            ? $article->comments()
                // Se revisa únicamente esta noticia porque la restricción es por nota.
                ->where('user_id', $currentUserId)
                // parent_id null identifica comentarios principales.
                ->whereNull('parent_id')
                // Solo identifica comentarios que todavía esperan moderación.
                ->where('status', 'pending')
                // exists devuelve true/false sin cargar el comentario completo.
                ->exists()
            : false;

        /*
         * Un comentario principal processing, pendiente o aprobado se considera activo.
         *
         * Mientras exista uno de esos estados, el usuario registrado no puede enviar otro
         * comentario principal en la misma noticia. Un comentario rechazado no
         * bloquea una participación nueva.
         */
        $hasActiveMainComment = $currentUserId
            ? $article->comments()
                ->where('user_id', $currentUserId)
                ->whereNull('parent_id')
                ->whereIn(
                    'status',
                    [
                        'processing',
                        'pending',
                        'approved',
                    ],
                )
                ->exists()
            : false;

        // Guarda en el artículo la cantidad de comentarios principales visibles.
        $article->setAttribute('visible_main_comments_count', $visibleMainCommentsCount);
        // Guarda la cantidad total de comentarios visibles, incluyendo respuestas.
        $article->setAttribute('visible_comments_count', $visibleCommentsCount);
        // Indica si existe cualquier comentario/respuesta propio todavía en procesamiento.
        $article->setAttribute('has_processing_comments', $hasProcessingComments);
        // Indica si específicamente el comentario principal propio sigue en procesamiento.
        $article->setAttribute('has_processing_main_comment', $hasProcessingMainComment);
        // Indica si el usuario actual ya tiene un comentario principal en revisión humana.
        $article->setAttribute('has_pending_main_comment', $hasPendingMainComment);
        // Indica si ya posee un comentario principal processing, pendiente o aprobado.
        $article->setAttribute('has_active_main_comment', $hasActiveMainComment);

        // Busca el comentario indicado en la URL, si el usuario abrió la noticia apuntando a uno concreto.
        $targetComment = $this->targetComment($request, $article, $currentUserId);

        // Carga la primera tanda de comentarios principales visibles de la noticia,
        // junto con las relaciones necesarias para mostrarlos correctamente en Vue.
        $initialComments = $this->publicCommentRelations(
            $this->publicMainCommentsQuery($article, $currentUserId),
            $currentUserId
        )
            // Orden inicial de comentarios principales: más recientes primero.
            ->when(
                $currentUserId,
                fn (Builder $comments) => $comments->orderByRaw(
                    'CASE WHEN user_id = ? THEN 0 ELSE 1 END',
                    [$currentUserId]
                )
            )
            ->latest()
            // Carga inicial: máximo 10 comentarios principales.
            ->limit(10)
            ->get();

        if ($targetComment) {
            $this->includeTargetComment($initialComments, $article, $targetComment, $currentUserId);
        }

        // Se asigna la relación manualmente porque, si la URL llega desde
        // "Mis comentarios", puede sumar el comentario objetivo a la primera tanda.
        $article->setRelation('comments', $initialComments->unique('id')->values());
        $article->setAttribute('comments_next_offset', min(10, $visibleMainCommentsCount));

        // Cada espacio lateral recibe una publicidad activa elegida para esta carga de página.
        $articleAdvertisements = $advertisements->getActiveAdvertisementsForPlacements([
            AdvertisementPlacement::ARTICLE_SIDEBAR_TOP,
            AdvertisementPlacement::ARTICLE_SIDEBAR_BOTTOM,
        ]);

        // Los Resources definen qué campos exactos recibe el frontend.
        return TramaBridge::render('Public/ArticleShow', [
            'article' => (new ArticleDetailResource($article))->resolve(),
            // "Seguir leyendo" muestra tres noticias para completar una fila limpia.
            'related' => ArticleCardResource::collection($articles->related($article, 3))->resolve(),
            'advertisements' => [
                'sidebar_top' => $this->advertisementResource($articleAdvertisements->get(AdvertisementPlacement::ARTICLE_SIDEBAR_TOP)),
                'sidebar_bottom' => $this->advertisementResource($articleAdvertisements->get(AdvertisementPlacement::ARTICLE_SIDEBAR_BOTTOM)),
            ],
        ]);
    }

    /**
     * Convierte un banner en datos listos para Vue o devuelve null si no existe.
     *
     * @return array<string, mixed>|null
     */
    private function advertisementResource(?object $advertisement): ?array
    {
        return $advertisement
            ? (new AdvertisementResource($advertisement))->resolve()
            : null;
    }

    /**
     * Consulta base de comentarios principales visibles para el usuario actual.
     */
    private function publicMainCommentsQuery(Article $article, ?int $currentUserId): Builder
    {
        return Comment::query()
            // Limita la búsqueda a la noticia que se está leyendo.
            ->where('article_id', $article->id)
            // parent_id null identifica comentarios principales, no respuestas.
            ->whereNull('parent_id')
            // Aplica aprobados públicos y pendientes propios del usuario actual.
            ->where(function (Builder $comments) use ($currentUserId): void {
                $this->addPublicCommentVisibilityFilter($comments, $currentUserId);
            });
    }

    /**
     * Prepara la consulta de comentarios con todos los datos que necesita
     * la página pública de la noticia para mostrarlos correctamente en Vue.
     *
     * Agrega contadores, usuario asociado, estado de Me gusta del usuario actual,
     * respuestas visibles y cualquier otra relación necesaria para evitar consultas
     * adicionales por cada comentario después de ejecutar la consulta principal.
     *
     * $currentUserId se utiliza para cargar solamente información específica del
     * usuario autenticado, como su Me gusta o su reporte sobre cada comentario.
     */
    private function publicCommentRelations(Builder $query, ?int $currentUserId): Builder
    {
        return $query
            // Agrega cantidades sin cargar todas las filas relacionadas.
            ->withCount([
                // Cantidad total de Me gusta del comentario principal.
                'likes',
                // Cantidad total de respuestas visibles para saber si quedan
                // respuestas ocultas detrás del botón "Ver respuestas".
                'replies as visible_replies_count' => fn ($replies) => $this->addPublicCommentVisibilityFilter($replies, $currentUserId),
                // Cantidad de respuestas escritas por el usuario actual en este hilo.

                // Cantidad de respuestas activas escritas por el usuario actual en este hilo.
                // Una respuesta processing, pendiente o aprobada impide volver a mostrar "Responder".
                // Una respuesta rechazada queda cerrada y permite realizar un nuevo intento.
                'replies as own_replies_count' => fn ($replies) => $currentUserId
                    ? $replies
                        ->where('user_id', $currentUserId)
                        ->whereIn('status', ['processing', 'pending', 'approved'])
                    : $replies->whereRaw('1 = 0'),
            ])
            // Carga relaciones que Vue necesita ya resueltas.
            ->with([
                // Autor del comentario.
                'user',
                // Solo se carga el voto del usuario actual; sirve para pintar el
                // botón de Me gusta como activo o inactivo.
                'likes' => fn ($likes) => $currentUserId
                    ? $likes->where('user_id', $currentUserId)
                    : $likes->whereRaw('1 = 0'),
                /*
                * Carga solamente el reporte realizado por el usuario actual.
                *
                * Si existe, Vue sabe que esa cuenta ya reportó el comentario
                * y no vuelve a ofrecerle la misma acción.
                */
                'reports' => fn ($reports) => $currentUserId
                    ? $reports->where('user_id', $currentUserId)
                    : $reports->whereRaw('1 = 0'),
                // Primeras respuestas visibles del comentario principal.
                'replies' => fn ($replies) => $replies
                    // No muestra respuestas pendientes de otros usuarios.
                    ->where(function (Builder $comments) use ($currentUserId): void {
                        $this->addPublicCommentVisibilityFilter($comments, $currentUserId);
                    })
                    // Cantidad de Me gusta de cada respuesta.
                    ->withCount('likes')
                    // Autor y voto actual de cada respuesta.
                    ->with([
                        'user',
                        'likes' => fn ($likes) => $currentUserId
                            ? $likes->where('user_id', $currentUserId)
                            : $likes->whereRaw('1 = 0'),
                        'reports' => fn ($reports) => $currentUserId
                            ? $reports->where('user_id', $currentUserId)
                            : $reports->whereRaw('1 = 0'),
                    ])
                    // Ordena respuestas de más antigua a más nueva.
                    ->oldest()
                    // Carga solo las primeras 3 respuestas al abrir la noticia.
                    ->limit(3),
            ]);
    }

    /**
     * Busca el comentario pedido por la URL si el usuario tiene permiso para verlo.
     */
    private function targetComment(Request $request, Article $article, ?int $currentUserId): ?Comment
    {
        $targetCommentId = (int) $request->query('comentario', 0);

        if ($targetCommentId <= 0) {
            return null;
        }

        return Comment::query()
            // El comentario debe pertenecer a la noticia abierta.
            ->where('article_id', $article->id)
            // Aplica aprobados públicos y pendientes propios del usuario actual.
            ->where(function (Builder $comments) use ($currentUserId): void {
                $this->addPublicCommentVisibilityFilter($comments, $currentUserId);
            })
            // Busca el comentario exacto solicitado desde "Mis comentarios".
            ->whereKey($targetCommentId)
            ->first();
    }

    /**
     * Asegura que el comentario solicitado directamente por el usuario aparezca
     * dentro de la primera tanda mostrada en la página pública de la noticia.
     *
     * Esto se utiliza cuando se abre una noticia apuntando a un comentario concreto
     * y ese comentario no quedó incluido por el orden o la paginación normal.         
     *
     * Ejemplo: la noticia tiene 40 comentarios y "Mis comentarios" apunta a uno que
     * quedó fuera de los primeros 10. Este método lo carga aparte y lo agrega a
     * $initialComments para que Vue pueda mostrarlo.
     *
     * $currentUserId permite cargar datos propios del usuario, como Me gusta o reportes.
     */
    private function includeTargetComment($initialComments, Article $article, Comment $targetComment, ?int $currentUserId): void
    {
        $targetMainId = $targetComment->parent_id ?: $targetComment->id;
        $targetMain = $initialComments->firstWhere('id', $targetMainId);

        if (! $targetMain) {
            $targetMain = $this->publicCommentRelations(
                $this->publicMainCommentsQuery($article, $currentUserId)->whereKey($targetMainId),
                $currentUserId
            )->first();

            if ($targetMain) {
                // prepend lo deja visible inmediatamente para que el ancla exista en el DOM.
                $initialComments->prepend($targetMain);
            }
        }

        if ($targetMain && $targetComment->parent_id) {
            $this->includeTargetReply($targetMain, $targetComment, $currentUserId);
        }
    }

    /**
     * Asegura que una respuesta concreta aparezca dentro del comentario principal
     * cuando el usuario abrió la noticia apuntando directamente a esa respuesta.
     *
     * Normalmente, al cargar la noticia solo se muestran las primeras 3 respuestas
     * de cada comentario principal. Si la respuesta solicitada quedó fuera de esas
     * primeras 3, este método la carga por separado y la agrega a la colección de
     * respuestas que Vue recibirá inicialmente.
     *                
     * Ejemplo: un comentario tiene 8 respuestas y "Mis comentarios" apunta a una
     * de las últimas 5. Este método la carga aparte y la incluye en lo enviado a Vue.
     */     
    private function includeTargetReply(Comment $targetMain, Comment $targetComment, ?int $currentUserId): void
    {
        $loadedReplies = $targetMain->relationLoaded('replies')
            ? $targetMain->replies
            : collect();

        if ($loadedReplies->contains('id', $targetComment->id)) {
            return;
        }

        $targetReply = Comment::query()
            // La respuesta debe pertenecer al comentario principal que se va a mostrar.
            ->where('parent_id', $targetMain->id)
            // Aplica aprobados públicos y pendientes propios del usuario actual.
            ->where(function (Builder $comments) use ($currentUserId): void {
                $this->addPublicCommentVisibilityFilter($comments, $currentUserId);
            })
            // Busca la respuesta exacta solicitada desde la grilla.
            ->whereKey($targetComment->id)
            // Agrega la cantidad de Me gusta de la respuesta.
            ->withCount('likes')
            // Carga autor y voto del usuario actual.
            ->with([
                'user',
                'likes' => fn ($likes) => $currentUserId
                    ? $likes->where('user_id', $currentUserId)
                    : $likes->whereRaw('1 = 0'),
                'reports' => fn ($reports) => $currentUserId
                    ? $reports->where('user_id', $currentUserId)
                    : $reports->whereRaw('1 = 0'),
            ])
            ->first();

        if ($targetReply) {
            $targetMain->setRelation(
                'replies',
                $loadedReplies
                    ->push($targetReply)
                    ->unique('id')
                    ->sortBy('created_at')
                    ->values()
            );
        }
    }

    /**
     * Agrega la regla de visibilidad pública de comentarios a una consulta.
     *
     * Regla completa:
     * - Los comentarios aprobados se muestran a cualquier visitante.
     * - Los comentarios pendientes solo se muestran al usuario que los escribió.
     * - Los comentarios "processing` no se muestran todavía, ni siquiera a su autor.
     * - Los comentarios rechazados u ocultos no aparecen en la noticia pública.
     */
    private function addPublicCommentVisibilityFilter($query, ?int $currentUserId)
    {
        return $query->where(function (Builder $comments) use ($currentUserId): void {
            // Primera rama: comentarios aprobados, visibles para todos.
            $comments->where('status', 'approved');

            if ($currentUserId) {
                // Segunda rama: comentarios pendientes propios, visibles solo para su autor.
                $comments->orWhere(function (Builder $pendingComments) use ($currentUserId): void {
                    $pendingComments
                        // El comentario pendiente debe pertenecer al usuario actual.
                        ->where('user_id', $currentUserId)
                        // Solo se permite ver el estado pendiente, no rechazado ni oculto.
                        ->where('status', 'pending');
                });
            }
        });
    }
}
