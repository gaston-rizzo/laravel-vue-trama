<?php

/* ============================================================================
 * CONTROLLER: CommentController.php
 * ============================================================================
 *
 * Recibe comentarios y respuestas enviados desde la página de una noticia.
 *
 * Los comentarios enviados por usuarios registrados se guardan primero como
 * "processing": ese estado significa exclusivamente que el análisis automático
 * todavía no terminó. El comentario permanece oculto mientras el Job ejecuta
 * directamente el coordinador Node "scripts/comments/moderate-comment.mjs".
 * Al finalizar, el estado cambia a "approved", "pending" o "rejected".
 * "pending" queda reservado para los casos que realmente requieren
 * revisión humana. Las intervenciones internas autorizadas pueden publicarse
 * directamente. Las respuestas solo pueden colgar de un comentario principal
 * aprobado, así la conversación mantiene un único nivel de profundidad.
 * También alterna el "Me gusta" de un usuario sobre comentarios ya aprobados.
 * ============================================================================ */

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Jobs\ModerateComment;
use App\Http\Resources\CommentResource;
use App\Models\Article;
use App\Models\Comment;
use App\Models\CommentReport;
use App\Support\OperationFailureHandler;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

use Throwable;

class CommentController extends Controller
{
    // Cantidad máxima de comentarios principales que se cargan por vez.
    private const MAIN_COMMENT_PAGE_SIZE = 10;

    // Cantidad máxima de respuestas que se cargan por vez.
    private const REPLY_PAGE_SIZE = 10;

    // Cantidad de respuestas que se muestran inicialmente en cada comentario.
    private const REPLY_INITIAL_SIZE = 3;

    // Cantidad máxima de comentarios o respuestas permitidos por usuario
    // dentro del período definido por USER_COMMENT_LIMIT_SECONDS.
    private const USER_COMMENT_LIMIT = 5;

    // Duración del límite de comentarios, expresada en segundos.
    // 600 segundos equivalen a 10 minutos.
    private const USER_COMMENT_LIMIT_SECONDS = 600;

    // Cantidad máxima de ediciones o eliminaciones permitidas por usuario
    // durante un período corto.
    private const USER_COMMENT_ACTION_LIMIT = 20;

    // 60 segundos equivalen a un minuto.
    private const USER_COMMENT_ACTION_LIMIT_SECONDS = 60;

    // Cantidad máxima de acciones de Me gusta permitidas por usuario
    // dentro de la ventana de tiempo definida más abajo.
    //
    // Cada acción de agregar o quitar un "Me gusta" consume un intento.
    // Ejemplo:
    // - Like en comentario A        -> 1 intento.
    // - Like en comentario B        -> 2 intentos.
    // - Quitar Like en comentario A -> 3 intentos.
    //
    // Al alcanzar 10 acciones, las siguientes se bloquean hasta que venza
    // la ventana configurada.
    private const USER_LIKE_LIMIT = 10;

    // Duración de la ventana del límite de Me gusta: 300 segundos = 5 minutos.
    //
    // Ejemplo:
    // si un usuario realiza 10 acciones de "Me gusta" dentro de esos 5 minutos,
    // no podrá realizar una nueva hasta que el rate limit vuelva a permitirlo.
    private const USER_LIKE_LIMIT_SECONDS = 300;

    public function __construct(

    private readonly OperationFailureHandler $operationFailureHandler,
    ) {
    }

    /**
     * Devuelve otra tanda de comentarios principales para la noticia.
     */
    public function index(Request $request, Article $article): JsonResponse
    {
        // Impide consultar comentarios mediante la ruta pública cuando la noticia
        // todavía no fue publicada o ya fue retirada del portal.
        abort_unless(
            Article::query()->publiclyVisible()->whereKey($article->id)->exists(),
            404
        );

        $currentUserId = $request->user()?->id;
        $offset = max(0, (int) $request->query('offset', 0));
        $sort = $this->normalizeSort((string) $request->query('sort', 'recent'));

        // Punto de partida de la consulta: usa la relación de Laravel para traer
        // solamente comentarios pertenecientes a esta noticia.
        $baseQuery = $article->comments()
            // parent_id en null significa comentario principal, no respuesta.
            ->whereNull('parent_id');

        $baseQuery = $this->addPublicCommentVisibilityFilter(
            // Aplica aprobados públicos y pendientes propios del usuario actual.
            $baseQuery,
            $currentUserId
        );

        // El total se calcula antes del recorte para saber si queda otra tanda.
        $totalMainComments = (clone $baseQuery)->count();
        $comments = $this->sortComments(
            (clone $baseQuery)
                // likes_count agrega una columna calculada con la cantidad total
                // de Me gusta que recibió cada comentario principal.
                ->withCount([
                    'likes',
                    // visible_replies_count cuenta todas las respuestas visibles
                    // para este usuario, aunque todavía no se carguen completas.
                    'replies as visible_replies_count' => fn ($replies) => $this->addPublicCommentVisibilityFilter($replies, $currentUserId),
                    // own_replies_count permite ocultar "Responder" solamente cuando el usuario
                    // ya posee una respuesta pendiente o aprobada dentro de este comentario.
                    // Una respuesta rechazada no bloquea un nuevo intento.
                    'replies as own_replies_count' => fn ($replies) => $currentUserId
                        ? $replies
                            ->where('user_id', $currentUserId)
                            ->whereIn('status', ['processing', 'pending', 'approved'])
                        : $replies->whereRaw('1 = 0'),
                ])
                // Carga relaciones necesarias para pintar la tanda sin hacer
                // consultas extra por cada comentario.
                ->with([
                    // Trae el usuario asociado para mostrar el nombre actualizado.
                    'user',
                    // Trae solo el Me gusta del usuario actual. Si existe, Vue
                    // marca el botón como activo; si no existe, queda apagado.
                    'likes' => fn ($likes) => $currentUserId
                        ? $likes->where('user_id', $currentUserId)
                        : $likes->whereRaw('1 = 0'),
                    /*
                     * Carga solamente el reporte realizado por el usuario actual.
                     *
                     * Si existe, Vue sabe que esta cuenta ya reportó el comentario
                     * y muestra "Reportado" en lugar de permitir reportarlo otra vez.
                     */
                     'reports' => fn ($reports) => $currentUserId
                        ? $reports->where('user_id', $currentUserId)
                        : $reports->whereRaw('1 = 0'),
                    // Carga las primeras respuestas visibles de cada comentario.
                    'replies' => fn ($replies) => $replies
                        // Misma regla de visibilidad: aprobadas para todos y
                        // pendientes solo para su propio autor.
                        ->where(function (Builder $comments) use ($currentUserId): void {
                            $this->addPublicCommentVisibilityFilter($comments, $currentUserId);
                        })
                        // Agrega likes_count en cada respuesta.
                        ->withCount('likes')
                        // Carga usuario y voto del usuario actual en cada respuesta.
                        ->with([
                            'user',
                            'likes' => fn ($likes) => $currentUserId
                                ? $likes->where('user_id', $currentUserId)
                                : $likes->whereRaw('1 = 0'),
                            'reports' => fn ($reports) => $currentUserId
                                ? $reports->where('user_id', $currentUserId)
                                : $reports->whereRaw('1 = 0'),
                        ])
                        // Las respuestas van de la más antigua a la más nueva para
                        // que la conversación se lea en orden natural.
                        ->oldest()
                        // Al abrir la noticia solo se muestran 3 respuestas.
                        ->limit(self::REPLY_INITIAL_SIZE),
                ]),
            $sort,
            $currentUserId
        )
            // Salta los comentarios ya mostrados en el navegador.
            ->skip($offset)
            // Devuelve como máximo 10 comentarios principales por click.
            ->take(self::MAIN_COMMENT_PAGE_SIZE)
            // Ejecuta la consulta y obtiene la colección final.
            ->get();

        $nextOffset = $offset + $comments->count();

        return response()->json([
            'comments' => CommentResource::collection($comments)->resolve($request),
            'next_offset' => $nextOffset,
            'remaining' => max($totalMainComments - $nextOffset, 0),
            'has_more' => $totalMainComments > $nextOffset,
            'total_main_comments' => $totalMainComments,
            // Cuenta comentarios principales y respuestas visibles para mostrar
            // el total general en el encabezado de la sección.
            'total_comments_count' => $this->addPublicCommentVisibilityFilter($article->comments(), $currentUserId)->count(),
        ]);
    }

    /**
     * Devuelve otra tanda de respuestas directas de un comentario principal.
     */
    public function replies(Request $request, Comment $comment): JsonResponse
    {
        // Solo un comentario principal puede contener respuestas.
        abort_unless($comment->parent_id === null, 404);

        // Las respuestas solo pueden consultarse cuando el comentario principal
        // está aprobado y pertenece a una noticia publicada y visible.
        // Así una llamada directa no podrá cargar respuestas de comentarios 
        // pertenecientes a una noticia archivada, programada o retirada.
        abort_unless(
            $comment->status === 'approved'
            && $comment->article()
                ->publiclyVisible()
                ->exists(),
            404
        );

        $currentUserId = $request->user()?->id;
        $offset = max(0, (int) $request->query('offset', 0));

        // Punto de partida: respuestas directas del comentario principal recibido.
        $baseQuery = $this->addPublicCommentVisibilityFilter(
            // Parte de las respuestas directas del comentario principal.
            $comment->replies(),
            $currentUserId
        );

        // Las respuestas se cargan siempre por fecha antigua primero para que el
        // hilo se lea de arriba hacia abajo.
        $totalReplies = (clone $baseQuery)->count();
        $replies = (clone $baseQuery)
            // Agrega likes_count para mostrar cuántos Me gusta tiene cada respuesta.
            ->withCount('likes')
            // Carga usuario y voto del usuario actual en la misma consulta preparada.
            ->with([
                // Trae el autor vinculado a la respuesta.
                'user',
                // Carga solo el Me gusta del usuario actual para saber si el botón
                // debe aparecer activo.
                'likes' => fn ($likes) => $currentUserId
                    ? $likes->where('user_id', $currentUserId)
                    : $likes->whereRaw('1 = 0'),
                // Carga solamente el reporte del usuario actual sobre esta respuesta.
                'reports' => fn ($reports) => $currentUserId
                    ? $reports->where('user_id', $currentUserId)
                    : $reports->whereRaw('1 = 0'),
            ])
            // Mantiene el orden cronológico de lectura dentro del hilo.
            ->oldest()
            // Salta las respuestas que el navegador ya tiene cargadas.
            ->skip($offset)
            // Devuelve como máximo 10 respuestas por click.
            ->take(self::REPLY_PAGE_SIZE)
            // Ejecuta la consulta.
            ->get();

        $nextOffset = $offset + $replies->count();

        return response()->json([
            'replies' => CommentResource::collection($replies)->resolve($request),
            'next_offset' => $nextOffset,
            'remaining' => max($totalReplies - $nextOffset, 0),
            'has_more' => $totalReplies > $nextOffset,
        ]);
    }

    /**
     * Informa si el usuario autenticado todavía posee algún comentario o respuesta
     * de esta noticia en estado "processing".
     *
     * Este endpoint es deliberadamente pequeño: la página pública lo consulta
     * solamente mientras existe una moderación automática en curso. No devuelve
     * el texto, scores ni detalles internos del pipeline; responde únicamente si
     * todavía queda trabajo automático pendiente para esta cuenta y esta noticia.
     *
     * Ejemplo:
     *
     *     GET /noticias/45/comentarios/estado-procesamiento
     *
     *     {
     *         "has_processing": true
     *     }
     *
     * Cuando pasa a false, Vue deja de consultar este endpoint y recarga solamente
     * la prop "article" mediante Inertia para reflejar el resultado final:
     *
     *     processing -> approved
     *     processing -> pending
     *     processing -> rejected
     *
     * El comentario "processing" continúa oculto durante todo este intervalo.
     */
    public function processingStatus(
        Request $request,
        Article $article,
    ): JsonResponse {
        /*
         * La ruta sólo puede utilizarse sobre una noticia publicada. Repite la misma
         * protección aplicada a los demás endpoints públicos de comentarios.
         */
        abort_unless(
            Article::query()
                ->publiclyVisible()
                ->whereKey($article->id)
                ->exists(),
            404
        );

        $currentUserId = (int) $request->user()->id;
        $trackedCommentId = max(0, (int) $request->query('comment_id', 0));

        /*
         * Se usa exists() para que MySQL pueda detener la consulta al encontrar la
         * primera fila. No se carga ningún Comment ni se envía contenido al navegador.
         *
         * Se contemplan comentario principal y respuestas porque ambos atraviesan
         * exactamente el mismo pipeline automático.
         */
        $trackedComment = null;

        if ($trackedCommentId > 0) {
            /*
             * Cuando Vue conoce el ID recién enviado, se consulta esa fila exacta.
             * Esto evita que un resultado automático viejo de la misma noticia
             * mantenga la pantalla esperando o muestre un aviso equivocado.
             */
            $trackedComment = $article
                ->comments()
                ->select([
                    'id',
                    'parent_id',
                    'status',
                    'moderation_source',
                    'moderation_revision',
                    'moderation_reason',
                    'updated_at',
                ])
                ->whereKey($trackedCommentId)
                ->where('user_id', $currentUserId)
                ->first();

            $hasProcessing = $trackedComment?->status === 'processing';
        } else {
            $hasProcessing = $article
                ->comments()
                ->where('user_id', $currentUserId)
                ->where('status', 'processing')
                ->exists();
        }

        /*
         * Cuando ya no queda trabajo automático pendiente, la pantalla pública
         * necesita saber cuál fue el destino final más reciente para mostrar un
         * aviso claro sin obligar al lector a recargar la noticia.
         *
         * No se devuelve el cuerpo del comentario: sólo el estado y la causa
         * interna que dejó el pipeline para que Vue traduzca eso a un mensaje
         * apto para el usuario.
         */
        $latestAutomaticResult = null;

        if (! $hasProcessing && $trackedComment) {
            $latestAutomaticResult = $trackedComment->moderation_source === 'automatic'
                && $trackedComment->moderation_revision > 0
                && in_array($trackedComment->status, ['approved', 'pending', 'rejected'], true)
                    ? $trackedComment
                    : null;
        } elseif (! $hasProcessing) {
            $latestAutomaticResult = $article
                ->comments()
                ->select([
                    'id',
                    'parent_id',
                    'status',
                    'moderation_reason',
                    'updated_at',
                ])
                ->where('user_id', $currentUserId)
                ->where('moderation_source', 'automatic')
                ->where('moderation_revision', '>', 0)
                ->whereIn('status', ['approved', 'pending', 'rejected'])
                ->latest('updated_at')
                ->latest('id')
                ->first();
        }

        return response()
            ->json([
                'has_processing' => $hasProcessing,
                'latest_result' => $latestAutomaticResult
                    ? [
                        'id' => $latestAutomaticResult->id,
                        'parent_id' => $latestAutomaticResult->parent_id,
                        'type' => $latestAutomaticResult->parent_id
                            ? 'reply'
                            : 'comment',
                        'status' => $latestAutomaticResult->status,
                        'reason' => $latestAutomaticResult->moderation_reason,
                    ]
                    : null,
            ])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Guarda un comentario principal o una respuesta según las reglas de participación.
     */
    public function store(Request $request, Article $article): RedirectResponse
    {
        try {
            // Impide enviar comentarios mediante la ruta directa cuando la noticia
            // todavía no fue publicada o ya fue retirada del portal.
            abort_unless(
                Article::query()->publiclyVisible()->whereKey($article->id)->exists(),
                404
            );

            // Normaliza el texto antes de validar y guardar. Si el usuario aprieta
            // Enter tres o más veces seguidas, esos saltos se reducen a un máximo de dos.
            $request->merge([
                'body' => $this->normalizeCommentBody((string) $request->input('body', '')),
            ]);

            // El texto se valida en backend aunque Vue también limite la longitud.
            $validated = $request->validate([
                'body' => ['required', 'string', 'min:8', 'max:1200'],
                'parent_id' => ['nullable', 'integer'],
            ], [
                'body.required' => 'Escribí un comentario antes de enviarlo.',
                'body.min' => 'El comentario debe tener al menos 8 caracteres.',
                'body.max' => 'El comentario no puede superar los 1200 caracteres.',
            ]);

            $parent = null;

            if (! empty($validated['parent_id'])) {
                // Solo se permite responder a comentarios principales ya aprobados.
                // Esto evita hilos infinitos y respuestas colgadas de contenido oculto.
                $parent = Comment::query()
                    // La respuesta debe pertenecer a la misma noticia abierta.
                    ->where('article_id', $article->id)
                    // Solo se responde a comentarios principales, nunca a otra respuesta.
                    ->whereNull('parent_id')
                    // Solo comentarios publicados pueden recibir respuestas públicas.
                    ->where('status', 'approved')
                    // Busca el comentario exacto enviado desde el formulario.
                    ->find($validated['parent_id']);

                if (! $parent) {
                    throw ValidationException::withMessages([
                        'parent_id' => 'No se puede responder ese comentario.',
                    ]);
                }
            }

            /*
            * Aplica las reglas de participación pública según el rol.
            *
            * Un usuario registrado puede crear comentarios principales y respuestas.
            * Un periodista solamente puede responder comentarios dentro de sus propias
            * noticias. Un editor solamente interviene mediante respuestas identificadas
            * como Equipo TRAMA. El administrador no participa públicamente.
            *
            * Esta validación se realiza en Laravel aunque Vue oculte los botones, porque
            * una petición HTTP construida manualmente tampoco debe poder saltear la regla.
            */
            $user = $request->user();

            if ($parent === null) {
                abort_unless(
                    $user->canCreatePublicMainComment(),
                    403
                );
            } else {
                abort_unless(
                    $user->canReplyToPublicComments($article),
                    403
                );

                /*
                * Responder el propio comentario principal no agrega una nueva
                * participación a la conversación y por eso no está permitido.
                *
                * La regla también se aplica en Laravel para impedir que una petición
                * HTTP manual pueda crear una respuesta que Vue no ofrece.
                */
                if ((int) $parent->user_id === (int) $user->id) {
                    throw ValidationException::withMessages([
                        'body' => 'No podés responder tu propio comentario.',
                    ]);
                }

                /*
                * Reportar un comentario y continuar respondiéndolo son acciones
                * incompatibles para una misma cuenta.
                *
                * La comprobación se hace también en Laravel para impedir que alguien
                * saltee la restricción de Vue mediante una petición HTTP manual.
                */
                $hasReportedParent = $parent->reports()
                    ->where('user_id', $user->id)
                    ->where('status', CommentReport::STATUS_OPEN)
                    ->exists();

                if ($hasReportedParent) {
                    throw ValidationException::withMessages([
                        'body' => 'No podés responder un comentario que ya reportaste.',
                    ]);
                }
            }

           /*
            * Guarda de forma permanente cómo participó el usuario.
            *
            * Esto evita que una respuesta histórica cambie de identificación pública
            * si posteriormente el usuario cambia de rol dentro de TRAMA.
            */
            $authorContext = $user->publicCommentContextFor($article);

            /*
            * Las respuestas realizadas por cuentas internas de TRAMA se publican
            * directamente porque pertenecen a usuarios previamente autorizados.
            *
            * El periodista solamente puede intervenir como "Autor de la nota"
            * dentro de sus propias publicaciones, mientras que el editor responde
            * como "Equipo TRAMA".
            *
            * La participación de usuarios registrados utiliza el flujo automático:
            * primero se guarda como "processing" y queda oculta. Luego un Job de
            * Laravel ejecuta directamente "scripts/comments/moderate-comment.mjs"
            * mediante Process::run(). Ese coordinador importa los cinco detectores y
            * puede aprobar, derivar a "pending" para revisión humana o rechazar según
            * idioma, links, amenazas, toxicidad y spam.
            */
            $commentStatus = in_array(
                $authorContext,
                [
                    Comment::AUTHOR_CONTEXT_ARTICLE_AUTHOR,
                    Comment::AUTHOR_CONTEXT_TRAMA_TEAM,
                ],
                true
            )
                ? 'approved'
                : 'processing';

            /*
             * La comprobación de duplicados y la creación se ejecutan dentro de una
             * misma transacción.
             *
             * La fila del usuario se bloquea mientras dura la operación para que dos
             * envíos simultáneos de la misma cuenta no puedan superar las validaciones
             * y crear dos participaciones incompatibles.
             */
            $comment = DB::transaction(
                function () use (
                    $article,
                    $authorContext,
                    $commentStatus,
                    $parent,
                    $user,
                    $validated,
                ): Comment {
                    $user->newQuery()
                        ->whereKey($user->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    // Si no se recibió parent_id, el usuario está enviando
                    // un comentario principal directamente sobre la noticia.
                    if ($parent === null) {
                        /*
                         * Un usuario puede tener como máximo un comentario principal
                         * activo por noticia.
                         *
                         * Processing, pendiente y aprobado bloquean un nuevo envío. Rechazado no
                         * bloquea porque esa participación ya quedó cerrada y el usuario registrado
                         * puede escribir un comentario principal nuevo.
                         */
                        $hasActiveMainComment = Comment::query()
                            ->where('article_id', $article->id)
                            ->where('user_id', $user->id)
                            ->whereNull('parent_id')
                            ->whereIn(
                                'status',
                                [
                                    'processing',
                                    'pending',
                                    'approved',
                                ],
                            )
                            ->exists();

                        if ($hasActiveMainComment) {
                            throw ValidationException::withMessages([
                                'body' => 'Ya tenés un comentario principal activo en esta noticia.',
                            ]);
                        }
                    } else {
                        /*
                        * Comprueba si este usuario ya tiene una respuesta activa dentro del
                        * comentario principal.
                        *
                        * Processing, pendiente y aprobado bloquean una nueva respuesta. Rechazado no bloquea
                        * porque esa participación ya quedó cerrada y el usuario registrado puede intentarlo
                        * nuevamente.
                        */
                        $hasActiveReply = Comment::query()
                            // La respuesta debe pertenecer a la misma noticia.
                            ->where('article_id', $article->id)
                            // La búsqueda corresponde únicamente al usuario actual.
                            ->where('user_id', $user->id)
                            // Limita la búsqueda a respuestas de este comentario principal.
                            ->where('parent_id', $parent->id)
                            // Processing, pendiente o aprobada continúan activas.
                            ->whereIn('status', ['processing', 'pending', 'approved'])
                            ->exists();

                        if ($hasActiveReply) {
                            throw ValidationException::withMessages([
                                'body' => 'Ya tenés una respuesta activa en este comentario.',
                            ]);
                        }
                    }

                    // El comentario queda ligado a la cuenta para poder moderar con trazabilidad.
                    return $article->comments()->create([
                        'parent_id' => $parent?->id,
                        'user_id' => $user->id,
                        'author_name' => $user->name,
                        'author_email' => $user->email,
                        'author_context' => $authorContext,
                        'body' => $validated['body'],
                        'status' => $commentStatus,

                        /*
                         * Los usuarios registrados comienzan en revisión 1. Las cuentas internas
                         * quedan en 0 porque no entran al pipeline automático.
                         */
                        'moderation_revision' => $commentStatus === 'processing'
                            ? 1
                            : 0,
                        'moderation_source' => $commentStatus === 'processing'
                            ? 'automatic'
                            : null,
                        'moderation_reason' => $commentStatus === 'processing'
                            ? 'automation_queued'
                            : null,
                    ]);
                },
                attempts: 3,
            );

            /*
             * El request HTTP no espera a los modelos. El comentario del usuario registrado ya
             * quedó persistido como "processing", todavía oculto, y la cola se ocupa
             * de ejecutar el coordinador Node. "processing" no equivale a moderación
             * humana: solamente representa una validación automática en curso.
             */
            if ($commentStatus === 'processing') {
                $this->dispatchAutomaticModeration($comment);
            }

            if ($authorContext === Comment::AUTHOR_CONTEXT_TRAMA_TEAM) {
                return back()->with(
                    'status',
                    'Respuesta publicada como Equipo TRAMA.'
                );
            }

            return back()
                ->with('status', $parent
                    ? 'Respuesta recibida. Estamos revisándola antes de publicarla.'
                    : 'Comentario recibido. Estamos revisándolo antes de publicarlo.')
                /*
                 * Vue consulta este ID concreto para cerrar el aviso local apenas
                 * el Job automático decide approved, pending o rejected.
                 */
                ->with('automatic_comment_id', $commentStatus === 'processing'
                    ? $comment->id
                    : null);
        } catch (Throwable $exception) {
            $isReply = filled($request->input('parent_id'));

            $this->operationFailureHandler->fail(
                $exception,
                'public_comments',
                $isReply ? 'create_reply' : 'create_comment',
                $isReply
                    ? 'No pudimos enviar la respuesta en este momento. Intentá nuevamente.'
                    : 'No pudimos enviar el comentario en este momento. Intentá nuevamente.',
                [
                    'article_id' => $article->id,
                    'parent_id' => $request->input('parent_id'),
                    'user_id' => $request->user()?->id,
                ]
            );
        }
    }

    /**
     * Edita un comentario o una respuesta que quedó en moderación humana.
     *
     * Un comentario en "processing" NO puede editarse porque Node está evaluando
     * exactamente esa versión del texto. Cuando un comentario "pending" se edita,
     * vuelve a "processing", incrementa su revisión y atraviesa nuevamente todo el
     * pipeline automático antes de poder publicarse.
     */
    public function update(Request $request, Comment $comment): RedirectResponse
    {
        try {
            $this->authorizeCommentOwner($request, $comment);

            if ($comment->status === 'processing') {
                throw ValidationException::withMessages([
                    'body' => 'El comentario todavía se está revisando. Esperá a que termine el análisis.',
                ]);
            }

            if ($comment->status !== 'pending') {
                throw ValidationException::withMessages([
                    'body' => 'Solo podés editar comentarios enviados a moderación humana.',
                ]);
            }

            // Se normaliza antes de validar para aplicar el mismo criterio que al crear.
            $request->merge([
                'body' => $this->normalizeCommentBody((string) $request->input('body', '')),
            ]);

            $validated = $request->validate([
                'body' => ['required', 'string', 'min:8', 'max:1200'],
            ], [
                'body.required' => 'Escribí un comentario antes de guardarlo.',
                'body.min' => 'El comentario debe tener al menos 8 caracteres.',
                'body.max' => 'El comentario no puede superar los 1200 caracteres.',
            ]);

            /*
            * Editar y eliminar comparten un límite porque ambas son escrituras
            * realizadas por el usuario sobre sus comentarios públicos.
            */
            $this->consumeRateLimit(
                $this->commentActionRateLimitKey($request),
                self::USER_COMMENT_ACTION_LIMIT,
                self::USER_COMMENT_ACTION_LIMIT_SECONDS,
                'Hiciste demasiadas modificaciones en poco tiempo. Esperá un minuto antes de volver a intentarlo.'
            );

            /*
             * La fila se bloquea porque puede existir un Job analizando la revisión
             * anterior al mismo tiempo. Si la edición gana la carrera, incrementa la
             * revisión y el resultado viejo será descartado al intentar aplicarse.
             */
            $comment = DB::transaction(
                function () use (
                    $comment,
                    $request,
                    $validated,
                ): Comment {
                    $lockedComment = Comment::query()
                        ->whereKey($comment->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    // La autorización y el estado se repiten sobre la fila bloqueada.
                    $this->authorizeCommentOwner(
                        $request,
                        $lockedComment
                    );

                    if ($lockedComment->status !== 'pending') {
                        throw ValidationException::withMessages([
                            'body' => $lockedComment->status === 'processing'
                                ? 'El comentario todavía se está revisando. Esperá a que termine el análisis.'
                                : 'Solo podés editar comentarios enviados a moderación humana.',
                        ]);
                    }

                    /*
                     * Una edición invalida por completo el análisis anterior.
                     *
                     * Por eso el comentario vuelve a "processing", se incrementa la
                     * revisión y se reemplazan el origen y la razón por los valores que
                     * indican que existe un nuevo análisis automático encolado. El Job
                     * nuevo será el único autorizado a sacar esta revisión de
                     * "processing".
                     */
                    $lockedComment->update([
                        'body' => $validated['body'],
                        'status' => 'processing',
                        'moderation_revision' =>
                            (int) $lockedComment->moderation_revision + 1,
                        'moderation_source' => 'automatic',
                        'moderation_reason' => 'automation_recheck_queued',
                    ]);

                    return $lockedComment;
                },
                attempts: 3,
            );

            // La edición completa vuelve a pasar por todo el pipeline automático.
            $this->dispatchAutomaticModeration($comment);

            return back()
                ->with(
                    'status',
                    'Comentario actualizado. Estamos revisándolo nuevamente antes de publicarlo.'
                )
                /*
                 * Igual que en el alta, Vue recibe el ID exacto de la participación
                 * que acaba de volver a "processing". Así la edición queda enlazada
                 * al polling automático y la pantalla puede reflejar approved, pending
                 * o rejected sin exigir una recarga manual del navegador.
                 */
                ->with('automatic_comment_id', $comment->id);
        } catch (Throwable $exception) {
            $this->operationFailureHandler->fail(
                $exception,
                'public_comments',
                'edit_comment',
                'No pudimos guardar los cambios del comentario en este momento. Intentá nuevamente.',
                [
                    'comment_id' => $comment->id,
                    'user_id' => $request->user()?->id,
                ]
            );
        }
    }

    /**
     * Elimina un comentario propio del sistema.
     */
    public function destroy(Request $request, Comment $comment): RedirectResponse
    {
        try {
            $this->authorizeCommentOwner($request, $comment);

            if (! in_array($comment->status, ['pending', 'approved'], true)) {
                throw ValidationException::withMessages([
                    'operation' => 'No se puede eliminar ese comentario.',
                ]);
            }

            /*
            * Comparte el límite de acciones con la edición para impedir
            * escrituras repetidas de forma abusiva sobre comentarios propios.
            */
            $this->consumeRateLimit(
                $this->commentActionRateLimitKey($request),
                self::USER_COMMENT_ACTION_LIMIT,
                self::USER_COMMENT_ACTION_LIMIT_SECONDS,
                'Hiciste demasiadas modificaciones en poco tiempo. Esperá un minuto antes de volver a intentarlo.'
            );

            $wasPending = $comment->status === 'pending';

            if ($wasPending) {
                // Si todavía no fue moderado, se elimina del sistema y deja de aparecer
                // en la noticia, en "Mis comentarios" y en el panel de moderación.
                $comment->delete();

                return back()->with('status', 'Comentario pendiente eliminado.');
            }

            // Si ya estaba publicado, se borra de la base y desaparece del portal.
            $comment->delete();

            return back()->with('status', 'Comentario eliminado.');
        } catch (Throwable $exception) {
            $this->operationFailureHandler->fail(
                $exception,
                'public_comments',
                'delete_comment',
                'No pudimos eliminar el comentario en este momento. Intentá nuevamente.',
                [
                    'comment_id' => $comment->id,
                    'user_id' => $request->user()?->id,
                ]
            );
        }
    }

    /**
     * Agrega o quita el "Me gusta" del usuario autenticado.
     */
    public function toggleLike(Request $request, Comment $comment): RedirectResponse
    {
        try {
            /*
            * Los Me gusta pertenecen a la participación de usuarios registrados.
            *
            * Periodistas, editores y administradores no utilizan reacciones públicas
            * como si fueran cuentas comunes del portal.
            */
            abort_unless(
                $request->user()->canLikePublicComments(),
                403
            );

            /*
             * Un usuario no puede marcar con Me gusta su propia participación.
             *
             * Vue oculta el botón en ese caso, pero Laravel vuelve a comprobar la
             * regla para impedir que una petición HTTP manual pueda saltearla.
             */
            abort_if(
                (int) $comment->user_id === (int) $request->user()->id,
                403,
                'No podés dar Me gusta a tu propio comentario.'
            );

            // Solo se puede marcar Me gusta en comentarios aprobados y visibles.
            // Si es una respuesta, su comentario principal también debe estar aprobado.
            abort_unless(
                $comment->status === 'approved'
                && (
                    $comment->parent_id === null
                    || $comment->parent()
                        ->where('status', 'approved')
                        ->exists()
                )
                && $comment->article()
                    ->publiclyVisible()
                    ->exists(),
                404
            );

            /*
             * Una cuenta que ya reportó este comentario no puede posteriormente
             * marcarlo con Me gusta.
             */
            if (
                $comment->reports()
                    ->where('user_id', $request->user()->id)
                    ->where('status', CommentReport::STATUS_OPEN)
                    ->exists()
            ) {
                throw ValidationException::withMessages([
                    'operation' => 'No podés dar Me gusta a un comentario que ya reportaste.',
                ]);
            }

            /*
            * Limita la cantidad de acciones de Me gusta que puede realizar
            * un mismo usuario dentro de un período de 5 minutos.
            *
            * Tanto agregar como quitar un "Me gusta" cuenta como una acción.
            * Esto evita que una cuenta pueda abusar del sistema presionando
            * repetidamente el mismo botón o reaccionando masivamente.
            *
            * También devuelve a Vue la cantidad exacta de segundos restantes
            * cuando el usuario alcanza el límite, para mantener una cuenta
            * regresiva real en la interface.
            */
            $this->consumeRateLimit(
                $this->commentLikeRateLimitKey($request),
                self::USER_LIKE_LIMIT,
                self::USER_LIKE_LIMIT_SECONDS,
                'Alcanzaste el límite de acciones de Me gusta. Esperá 5 minutos antes de volver a intentarlo.',
                'operation',
                'retry_after_seconds'
            );

            // Busca si este usuario ya había marcado Me gusta en este comentario.
            $like = $comment->likes()
                // La combinación comentario + usuario permite un único voto por persona.
                ->where('user_id', $request->user()->id)
                // first devuelve el registro existente o null si todavía no votó.
                ->first();

            if ($like) {
                $like->delete();

                return back()->with('status', 'Se quitó tu Me gusta.');
            }

            $comment->likes()->create([
                'user_id' => $request->user()->id,
            ]);

            return back()->with('status', 'Marcaste Me gusta.');
        } catch (Throwable $exception) {
            $this->operationFailureHandler->fail(
                $exception,
                'public_comments',
                'toggle_comment_like',
                'No pudimos actualizar tu Me gusta en este momento. Intentá nuevamente.',
                [
                    'comment_id' => $comment->id,
                    'user_id' => $request->user()?->id,
                ]
            );
        }
    }

    /**
     * Despacha la moderación automática sin convertir un problema de infraestructura
     * en un falso error de creación/edición para el usuario registrado.
     *
     * Si la cola no pudiera recibir el Job, el comentario no puede quedar
     * eternamente en "processing": se deriva a "pending" con una causa técnica para
     * que el panel editorial pueda resolverlo manualmente.
     */
    private function dispatchAutomaticModeration(Comment $comment): void
    {
        try {
            ModerateComment::dispatch(
                $comment->id,
                (int) $comment->moderation_revision
            );
        } catch (Throwable $exception) {
            report($exception);

            /*
             * Sólo se deriva a revisión humana si sigue siendo exactamente la misma
             * revisión en "processing". Así tampoco se pisa una decisión posterior.
             */
            Comment::query()
                ->whereKey($comment->id)
                ->where('status', 'processing')
                ->where(
                    'moderation_revision',
                    (int) $comment->moderation_revision
                )
                ->update([
                    'status' => 'pending',
                    'moderation_source' => 'automatic',
                    'moderation_reason' => 'automation_enqueue_error',
                ]);
        }
    }

    /**
     * Limpia el texto del comentario antes de guardarlo.
     */
    private function normalizeCommentBody(string $body): string
    {
        // Unifica saltos de línea de Windows, Linux y macOS al formato "\n".
        $normalizedBody = str_replace(["\r\n", "\r"], "\n", $body);

        // Elimina espacios o tabulaciones alrededor de los saltos para que una
        // línea visualmente vacía se guarde realmente vacía en la base.
        $normalizedBody = preg_replace(
            "/[ \t]*\n[ \t]*/",
            "\n",
            $normalizedBody
        ) ?? $normalizedBody;

        // Convierte tres o más saltos seguidos en dos. Dos saltos equivalen a
        // una sola línea vacía entre párrafos.
        $normalizedBody = preg_replace(
            "/\n{3,}/",
            "\n\n",
            $normalizedBody
        ) ?? $normalizedBody;

        return trim($normalizedBody);
    }

    /**
     * Clave del contador de comentarios del usuario autenticado.
     */
    private function commentRateLimitKey(Request $request): string
    {
        return 'comments:user:'.$request->user()->id;
    }

    /**
     * Clave compartida por ediciones y eliminaciones realizadas en el portal.
     */
    private function commentActionRateLimitKey(Request $request): string
    {
        return 'comment-actions:user:'.$request->user()->id;
    }

    /**
     * Clave utilizada para limitar acciones de Me gusta.
     */
    private function commentLikeRateLimitKey(Request $request): string
    {
        return 'comment-likes:user:'.$request->user()->id;
    }

    /**
     * Comprueba y consume un intento antes de ejecutar una escritura en la base.
     *
     * El intento se consume antes de guardar, editar, eliminar o votar para evitar
     * que un usuario pueda repetir cientos de peticiones cuando la operación falla
     * por un problema inesperado y la interface le permite intentar nuevamente.
     *
     * Cuando se recibe $retryAfterField, también devuelve la cantidad exacta
     * de segundos que faltan para que Laravel vuelva a permitir la operación.
     * Esto permite que Vue muestre una cuenta regresiva real en la interface.
     */
    private function consumeRateLimit(
        string $key,
        int $limit,
        int $decaySeconds,
        string $message,
        string $errorField = 'operation',
        ?string $retryAfterField = null,
    ): void {
        // Comprueba si el usuario ya consumió la cantidad máxima de intentos
        // permitidos dentro de la ventana de tiempo configurada.
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            // Prepara el mensaje principal que verá el usuario.
            $errors = [
                $errorField => $message,
            ];

            /*
            * Algunas operaciones, como Me gusta, necesitan conocer además
            * cuánto tiempo falta exactamente para que termine el bloqueo.
            *
            * availableIn() devuelve ese tiempo restante en segundos.
            */
            if ($retryAfterField !== null) {
                $seconds = max(
                    1,
                    RateLimiter::availableIn($key)
                );

                $errors[$retryAfterField] = (string) $seconds;
            }

            // Detiene la operación y devuelve todos los datos de validación
            // preparados para que Inertia los entregue a Vue.
            throw ValidationException::withMessages($errors);
        }

        // Registra este intento y mantiene la clave activa durante
        // la cantidad de segundos definida por el rate limit.
        RateLimiter::hit($key, $decaySeconds);
    }

        /**
     * Asegura que solo el autor pueda editar o eliminar su comentario.
     */
    private function authorizeCommentOwner(Request $request, Comment $comment): void
    {
        abort_unless($comment->user_id === $request->user()->id, 403);
    }

    /**
     * Acepta solamente los criterios de orden disponibles en la interface.
     */
    private function normalizeSort(string $sort): string
    {
        return in_array($sort, ['recent', 'valued', 'oldest'], true)
            ? $sort
            : 'recent';
    }

    /**
     * Aplica el mismo orden que ve el usuario en las pestañas de comentarios.
     */
    private function sortComments(Builder|Relation $query, string $sort, ?int $currentUserId): Builder|Relation
    {
        if ($currentUserId) {
            $query->orderByRaw(
                'CASE WHEN user_id = ? THEN 0 ELSE 1 END',
                [$currentUserId]
            );
        }

        if ($sort === 'valued') {
            return $query
                ->orderByDesc('likes_count')
                ->latest();
        }

        if ($sort === 'oldest') {
            return $query->oldest();
        }

        return $query->latest();
    }

    /**
     * Agrega la regla de visibilidad pública de comentarios a una consulta.
     *
     * Regla completa:
     * - Los comentarios aprobados se muestran a cualquier visitante.
     * - Los comentarios pendientes solo se muestran al usuario que los escribió.
     * - Los comentarios rechazados no aparecen en la noticia pública.
     */
    private function addPublicCommentVisibilityFilter(Builder|Relation $query, ?int $currentUserId): Builder|Relation
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
                        // Solo se permite ver el estado pendiente; los rechazados no se muestran.
                        ->where('status', 'pending');
                });
            }
        });
    }
}
