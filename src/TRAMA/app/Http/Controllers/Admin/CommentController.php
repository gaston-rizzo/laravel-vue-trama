<?php

/* ============================================================================
 * CONTROLLER: CommentController.php
 * ============================================================================
 *
 * Administra la moderación de comentarios dentro del panel editorial.
 *
 * El listado utiliza una grilla paginada y obtiene únicamente los registros
 * correspondientes a la página actual. La búsqueda, los filtros, el
 * ordenamiento y la paginación se resuelven desde Laravel antes de enviar los
 * datos a la interface.
 *
 * La construcción de la consulta se delega a CommentIndexQueryService, que
 * contiene los filtros disponibles, los conteos utilizados por la grilla y
 * la preparación del listado.
 *
 * El detalle completo de un comentario se obtiene solamente cuando el editor
 * abre el modal de moderación. De esta forma la grilla continúa enviando un
 * resumen compacto y el contenido adicional se carga bajo demanda.
 *
 * La moderación incluye únicamente comentarios y respuestas de usuarios registrados. Las
 * intervenciones del periodista autor de la nota y del Equipo TRAMA quedan
 * fuera de este flujo porque se publican directamente como contenido interno
 * autorizado.
 *
 * Las acciones sensibles vuelven a validar los permisos del usuario aunque
 * las rutas del módulo ya se encuentren protegidas mediante middleware.
 * ============================================================================ */

namespace App\Http\Controllers\Admin;

use App\Support\TramaClock;

use App\Http\Controllers\Controller;
use App\Http\Requests\Editorial\CommentIndexRequest;
use App\Http\Requests\Editorial\CommentModerationRequest;
use App\Http\Resources\Editorial\AdminCommentDetailResource;
use App\Http\Resources\Editorial\AdminCommentListResource;
use App\Models\Comment;
use App\Models\CommentReport;
use App\Models\User;
use App\Models\UserModerationReview;
use App\Services\Editorial\CommentIndexQueryService;
use App\Support\TramaBridge;
use App\Support\TramaLog;
use App\Support\OperationFailureHandler;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

use Inertia\Response;

use Throwable;

class CommentController extends Controller
{
    public function __construct(
        private readonly OperationFailureHandler $operationFailureHandler,
    ) {
    }
    /**
     * Muestra la grilla de moderación de comentarios.
     *
     * Laravel recibe los filtros desde la URL, los valida mediante
     * CommentIndexRequest y devuelve únicamente las filas correspondientes
     * a la página solicitada.
     */
    public function index(
        CommentIndexRequest $request,
        CommentIndexQueryService $commentIndexQuery,
    ): Response {
        /*
         * Segunda barrera de autorización además del middleware encargado
         * de proteger el módulo de moderación.
         */
        abort_unless(
            $request->user()?->canModerateComments(),
            403,
            'No tenés permisos para moderar comentarios.'
        );

        /*
         * Obtiene únicamente los parámetros que fueron limpiados y validados
         * previamente por CommentIndexRequest.
         */
        $filters = $request->validated();

        /*
         * Mantiene una estructura uniforme de filtros para Vue aunque algunos
         * parámetros no estén presentes en la URL.
         *
         * page se excluye porque corresponde exclusivamente a la navegación
         * de la paginación y no a los controles visibles de filtrado.
         */
        $visibleFilters = [
            'queue' => '',
            'search' => '',
            'type' => '',
            'author' => '',
            'article' => '',
            'date_from' => null,
            'date_to' => null,
            'sort' => 'created_at',
            'direction' => 'desc',
            'per_page' => 25,
            ...Arr::except(
                $filters,
                ['page'],
            ),
        ];

        /*
         * Los valores provenientes de la query string pueden llegar como texto.
         * Vue recibe per_page como número para mantener un tipo consistente.
         */
        $visibleFilters['per_page'] = (int) $visibleFilters['per_page'];

        /*
         * Estructura vacía utilizada como respaldo si ocurre un error inesperado
         * durante la consulta.
         *
         * Conserva exactamente la forma esperada por Index.vue para permitir
         * mostrar el mensaje de error sin romper la pantalla completa.
         */
        $commentsPayload = [
            'data' => [],
            'pagination' => [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $visibleFilters['per_page'],
                'total' => 0,
                'from' => null,
                'to' => null,
                'links' => [],
            ],
        ];

        /*
         * Conteos utilizados por los filtros principales de moderación.
         *
         * attention representa la cantidad de comentarios que requieren
         * intervención del equipo, por ejemplo pendientes o reportados.
         */
        $queueCounts = [
            'total' => 0,
            'pending' => 0,
            'reported' => 0,
            'rejected' => 0,
            'automatic_rejected' => 0,
            'approved' => 0,
            'attention' => 0,
        ];

        /*
         * Mensaje enviado a la interface cuando la carga falla por un problema
         * que no requiere reemplazar toda la página por un error global.
         */
        $loadError = '';

        try {
            /*
             * Ejecuta la consulta aplicando búsqueda, filtros, ordenamiento
             * y paginación directamente desde Laravel.
             *
             * Solamente se recuperan los comentarios pertenecientes a la
             * página actual.
             */
            $comments = $commentIndexQuery->paginate(
                $filters,
            );

            /*
             * Calcula los totales mostrados en los filtros principales:
             * Todos, Pendientes, Reportados, Rechazados editoriales,
             * Rechazos automáticos y Aprobados.
             *
             * Los conteos respetan los filtros secundarios activos para que
             * representen correctamente el conjunto que está consultando
             * el editor.
             */
            $queueCounts = $commentIndexQuery->getQueueCounts(
                $filters,
            );

            /*
             * AdminCommentListResource limita cada registro a la información
             * necesaria para representar una fila de la grilla.
             *
             * Esto evita enviar relaciones o datos que no son utilizados por
             * el listado y mantiene liviana la respuesta.
             */
            $commentsPayload = [
                'data' => AdminCommentListResource::collection(
                    $comments->getCollection(),
                )->resolve($request),

                /*
                 * Metadatos necesarios para construir la navegación entre
                 * páginas y mostrar el rango actual de resultados.
                 */
                'pagination' => [
                    'current_page' => $comments->currentPage(),
                    'last_page' => $comments->lastPage(),
                    'per_page' => $comments->perPage(),
                    'total' => $comments->total(),
                    'from' => $comments->firstItem(),
                    'to' => $comments->lastItem(),
                    'links' => $comments->linkCollection()->toArray(),
                ],
            ];
        } catch (Throwable $exception) {
            /*
             * Cuando MySQL no está disponible se conserva el tratamiento global
             * configurado por TRAMA para errores de conexión con la base.
             *
             * En ese caso la excepción continúa su recorrido normal y no se
             * transforma artificialmente en una grilla vacía.
             */
            if (
                $exception instanceof QueryException
                && str_contains(
                    $exception->getMessage(),
                    'SQLSTATE[HY000] [2002]'
                )
            ) {
                throw $exception;
            }

            /*
             * Los detalles técnicos quedan registrados exclusivamente en el log.
             *
             * La interface recibe solamente un mensaje comprensible para el editor,
             * sin exponer consultas, archivos, líneas ni información interna.
             */
            TramaLog::error(
                'Falló la carga de la grilla de moderación de comentarios.',
                [
                    'area' => 'comments',
                    'operation' => 'load_moderation_index',
                    'user_id' => $request->user()?->id,
                    'filters' => $visibleFilters,
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                    'exception_file' => $exception->getFile(),
                    'exception_line' => $exception->getLine(),
                ]
            );

            $loadError = 'No pudimos cargar los comentarios en este momento. Intentá nuevamente.';
        }

        /*
         * Renderiza la pantalla de moderación con los filtros actuales,
         * los comentarios paginados, los conteos y cualquier error de carga
         * que deba mostrarse dentro de la interface.
         */
        return TramaBridge::render(
            'Admin/Comments/Index',
            [
                'filters' => $visibleFilters,
                'comments' => $commentsPayload,
                'queueCounts' => $queueCounts,
                'loadError' => $loadError,
            ],
        );
    }

    /**
     * Devuelve el detalle completo utilizado por el modal de moderación.
     *
     * La grilla carga solamente fragmentos compactos. Esta petición recupera el
     * cuerpo completo, el contexto de una respuesta, los reportes abiertos y la
     * información necesaria para decidir las acciones disponibles.
     */
    public function show(
        Request $request,
        Comment $comment,
    ): JsonResponse {
        /*
         * La ruta ya está protegida por EnsureCommentModerationAccess, pero el
         * permiso vuelve a comprobarse antes de exponer información del modal.
         */
        abort_unless(
            $request->user()?->canModerateComments(),
            403,
            'No tenés permisos para moderar comentarios.'
        );

        /*
         * El modal pertenece exclusivamente al flujo de moderación de usuarios registrados.
         * Una intervención del periodista o del Equipo TRAMA no puede abrirse
         * mediante una URL directa aunque no figure en la grilla.
         */
        $this->ensureRegisteredUserCommentCanBeModerated($comment);

        /*
         * Las relaciones se cargan en una sola preparación para evitar consultas
         * adicionales desde AdminCommentDetailResource.
         */
        $comment
            ->load([
                'article:id,title,slug',
                'user:id,name,email,role,is_active,disabled_at,blocked_reason,moderation_review_requested_at,moderation_review_reason,moderation_review_note,moderation_review_resolved_at,moderation_review_resolution,moderation_review_resolved_by_id',
                'user.moderationReviewResolvedBy:id,name',
                'parent.user:id,name,email,role,is_active,disabled_at,blocked_reason,moderation_review_requested_at,moderation_review_reason,moderation_review_note,moderation_review_resolved_at,moderation_review_resolution,moderation_review_resolved_by_id',
                'parent.user.moderationReviewResolvedBy:id,name',
                'reports' => fn ($reports) => $reports
                    ->where(
                        'status',
                        CommentReport::STATUS_OPEN,
                    )
                    ->with('user:id,name,email')
                    ->oldest('id'),
            ])
            ->loadCount([
                'replies',
                'reports as open_reports_count' => fn ($reports) => $reports->where(
                    'status',
                    CommentReport::STATUS_OPEN,
                ),
            ]);

        return response()->json([
            'comment' => (new AdminCommentDetailResource(
                $comment,
            ))->resolve($request),
        ]);
    }

    /**
     * Aplica una decisión editorial sobre un comentario.
     *
     * Aprobar y reject pueden ejecutarse desde la grilla o desde el modal. En la
     * interface, reject aparece como Rechazar para pendientes y Retirar para aprobados.
     * Mantener se utiliza cuando un comentario aprobado posee reportes abiertos
     * y el editor decide conservarlo publicado.
     */
    public function update(
        CommentModerationRequest $request,
        Comment $comment,
    ): RedirectResponse {
        /*
         * La modificación afecta directamente el estado de moderación del
         * comentario, por lo que se vuelve a comprobar explícitamente que
         * el usuario actual tenga permisos para realizar la acción.
         */
        abort_unless(
            $request->user()?->canModerateComments(),
            403,
            'No tenés permisos para moderar comentarios.'
        );

        /*
         * Las acciones internas approve, reject y maintain están reservadas al
         * contenido escrito por usuarios registrados. En la interface, reject se muestra como
         * "Rechazar" para pendientes y "Retirar" para aprobados. Las intervenciones
         * internas de TRAMA quedan fuera del panel y de las peticiones directas.
         */
        $this->ensureRegisteredUserCommentCanBeModerated($comment);

        $action = (string) $request->validated('action');
        $editorId = (int) $request->user()->id;

        try {
            DB::transaction(
                function () use (
                $comment,
                $action,
                $editorId,
            ): void {
                /*
                 * Bloquea la fila para impedir que dos decisiones editoriales se
                 * apliquen al mismo comentario al mismo tiempo.
                 */
                $lockedComment = Comment::query()
                    ->whereKey($comment->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                 * Se vuelve a comprobar sobre la fila bloqueada para que la regla
                 * de exclusión de contenido interno también quede garantizada
                 * dentro de la transacción.
                 */
                $this->ensureRegisteredUserCommentCanBeModerated($lockedComment);

                if ($action === 'approve') {
                    /*
                     * Solamente un comentario pendiente puede aprobarse.
                     *
                     * Rejected es un estado terminal: una vez que el editor rechazó
                     * una participación, esa misma fila no puede volver a publicarse.
                     */
                    if ($lockedComment->status !== 'pending') {
                        throw ValidationException::withMessages([
                            'action' => $lockedComment->status === 'rejected'
                                ? 'Un comentario rechazado no puede volver a aprobarse.'
                                : 'Solo podés aprobar un comentario pendiente.',
                        ]);
                    }

                    /*
                     * Los comentarios principales de usuarios registrados deben mantener una sola
                     * participación aprobada por usuario y noticia.
                     *
                     * El bloqueo del usuario serializa esta decisión con un posible
                     * nuevo envío público realizado por la misma cuenta.
                     */
                    if (
                        $lockedComment->parent_id === null
                        && $lockedComment->user_id !== null
                    ) {
                        User::query()
                            ->whereKey($lockedComment->user_id)
                            ->lockForUpdate()
                            ->first();

                        $hasAnotherApprovedMainComment = Comment::query()
                            ->where('article_id', $lockedComment->article_id)
                            ->where('user_id', $lockedComment->user_id)
                            ->whereNull('parent_id')
                            ->where('id', '!=', $lockedComment->id)
                            ->where('status', 'approved')
                            ->exists();

                        if ($hasAnotherApprovedMainComment) {
                            throw ValidationException::withMessages([
                                'action' => 'Este usuario ya tiene otro comentario principal aprobado en la noticia.',
                            ]);
                        }
                    }

                    $lockedComment->update([
                        'status' => 'approved',
                        'moderation_source' => 'editorial',
                        'moderation_reason' => 'editorial_approved',
                    ]);

                    return;
                }

                if ($action === 'reject') {
                    /*
                     * La acción interna reject rechaza un pendiente o retira un
                     * aprobado del contenido público. En ambos casos el estado final
                     * es rejected y la fila no vuelve a aceptar decisiones de estado.
                     */
                    if ($lockedComment->status === 'rejected') {
                        throw ValidationException::withMessages([
                            'action' => 'El comentario ya está rechazado.',
                        ]);
                    }

                    $lockedComment->update([
                        'status' => 'rejected',
                        'moderation_source' => 'editorial',
                        'moderation_reason' => 'editorial_rejected',
                    ]);

                    /*
                     * Si se retira un comentario principal, todas sus respuestas
                     * activas quedan retiradas también, incluidas las que todavía están
                     * en "processing". Las filas permanecen en la base
                     * para conservar el historial, pero el hilo no puede quedar
                     * parcialmente publicado debajo de un padre rechazado.
                     */
                    if ($lockedComment->parent_id === null) {
                        Comment::query()
                            ->where('parent_id', $lockedComment->id)
                            ->whereIn('status', ['processing', 'pending', 'approved'])
                            ->update([
                                'status' => 'rejected',
                                'moderation_source' => 'editorial',
                                'moderation_reason' => 'parent_editorial_rejected',
                                'updated_at' => TramaClock::now(),
                            ]);
                    }

                    /*
                     * Si existían reportes abiertos, quedan registrados como
                     * resueltos porque la moderación tomó una acción sobre el
                     * comentario que los originó.
                     */
                    $this->reviewOpenReports(
                        $lockedComment,
                        $editorId,
                        CommentReport::STATUS_RESOLVED,
                    );

                    return;
                }

                /*
                 * Mantener solamente tiene sentido cuando el comentario continúa
                 * aprobado y posee al menos un reporte abierto pendiente de revisión.
                 */
                if ($lockedComment->status !== 'approved') {
                    throw ValidationException::withMessages([
                        'action' => 'Solo podés mantener un comentario que ya está aprobado.',
                    ]);
                }

                $reviewedReports = $this->reviewOpenReports(
                    $lockedComment,
                    $editorId,
                    CommentReport::STATUS_DISMISSED,
                );

                if ($reviewedReports > 0) {
                    $lockedComment->update([
                        'moderation_source' => 'editorial',
                        'moderation_reason' => 'editorial_maintained',
                    ]);
                }

                if ($reviewedReports === 0) {
                    throw ValidationException::withMessages([
                        'action' => 'El comentario no tiene reportes abiertos para revisar.',
                    ]);
                }
            },
                attempts: 3,
            );
        } catch (Throwable $exception) {
            $this->operationFailureHandler->fail(
                $exception,
                'admin_comments',
                'moderate_comment',
                'No pudimos completar la moderación del comentario. Intentá nuevamente.',                
                [
                    'comment_id' => $comment->id,
                    'action' => $action,
                    'user_id' => $request->user()?->id,
                ],
            );
        }

        $message = match ($action) {
            'approve' => 'Comentario aprobado.',
            'reject' => $comment->status === 'approved'
                ? 'Comentario retirado.'
                : 'Comentario rechazado.',
            'maintain' => 'Comentario mantenido y reportes revisados.',
            default => 'Comentario moderado.',
        };

        /*
         * Regresa a la misma pantalla para que Inertia actualice la fila, los
         * conteos y la paginación sin perder filtros ni posición de lectura.
         */
        return back()->with(
            'status',
            $message,
        );
    }

    /**
     * Elimina definitivamente un comentario desde el modal de moderación.
     *
     * Si el comentario principal posee respuestas, la relación definida en la
     * base elimina también esas respuestas. El modal informa esa consecuencia
     * antes de permitir confirmar la operación.
     */
    public function destroy(
        Request $request,
        Comment $comment,
    ): RedirectResponse {
        abort_unless(
            $request->user()?->canModerateComments(),
            403,
            'No tenés permisos para moderar comentarios.'
        );

        /*
         * Eliminar desde este controlador es una acción de moderación de usuarios registrados.
         * Las intervenciones internas no se administran desde este modal.
         */
        $this->ensureRegisteredUserCommentCanBeModerated($comment);

        try {
            $deletedReplies = DB::transaction(
                function () use ($comment): int {
                $lockedComment = Comment::query()
                    ->whereKey($comment->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                 * La comprobación se repite sobre la fila bloqueada antes de
                 * ejecutar la eliminación definitiva.
                 */
                $this->ensureRegisteredUserCommentCanBeModerated($lockedComment);

                /*
                 * El número se conserva únicamente para construir un mensaje
                 * comprensible después de eliminar el comentario.
                 */
                $repliesCount = $lockedComment->replies()->count();

                $lockedComment->delete();

                return $repliesCount;
            },
                attempts: 3,
            );
        } catch (Throwable $exception) {
            $this->operationFailureHandler->fail(
                $exception,
                'admin_comments',
                'delete_comment',
                'No pudimos eliminar el comentario. Intentá nuevamente.',
                [
                    'comment_id' => $comment->id,
                    'user_id' => $request->user()?->id,
                ],
            );
        }

        return back()->with(
            'status',
            $deletedReplies > 0
                ? 'Comentario y respuestas asociadas eliminados.'
                : 'Comentario eliminado.',
        );
    }

    /**
     * Deriva manualmente al administrador la cuenta pública que escribió
     * el comentario abierto en el modal.
     *
     * La operación no suspende ni bloquea al usuario. Solamente registra la
     * fecha de solicitud para que el administrador pueda identificar la cuenta
     * dentro del listado de usuarios registrados.
     */
    public function referToAdmin(
        Request $request,
        Comment $comment,
    ): RedirectResponse {
        abort_unless(
            $request->user()?->canModerateComments(),
            403,
            'No tenés permisos para moderar comentarios.'
        );

        /*
         * Solo una participación de usuario registrado puede originar una derivación de la
         * cuenta al administrador desde el panel de moderación.
         */
        $this->ensureRegisteredUserCommentCanBeModerated($comment);

        if ($comment->user_id === null) {
            throw ValidationException::withMessages([
                'user' => 'Este comentario no pertenece a un usuario registrado que pueda derivarse.',
            ]);
        }

        $request->merge([
            'note' => preg_replace(
                '/\s+/u',
                ' ',
                trim((string) $request->input('note', '')),
            ),
        ]);

        $validated = $request->validate([
            'reason' => [
                'required',
                'string',
                Rule::in(['spam', 'abuse', 'inappropriate', 'repeated', 'other']),
            ],
            'note' => [
                'required',
                'string',
                'min:10',
                'max:300',
            ],
        ], [
            'reason.required' => 'Seleccioná un motivo para solicitar la revisión.',
            'reason.in' => 'El motivo seleccionado no es válido.',
            'note.required' => 'Ingresá una observación para administración.',
            'note.min' => 'La observación debe tener al menos 10 caracteres.',
            'note.max' => 'La observación no puede superar los 300 caracteres.',
        ]);

        $reviewNote = (string) $validated['note'];
        $comment->loadMissing('article');

        try {
            $wasAlreadyRequested = DB::transaction(
                function () use ($request, $comment, $validated, $reviewNote): bool {
                $user = User::query()
                    ->whereKey($comment->user_id)
                    ->lockForUpdate()
                    ->first();

                if (! $user || $user->role !== 'reader') {
                    throw ValidationException::withMessages([
                        'user' => 'La cuenta asociada no puede derivarse a revisión administrativa.',
                    ]);
                }

                if (! $user->is_active) {
                    throw ValidationException::withMessages([
                        'user' => 'La cuenta ya está bloqueada y no requiere una nueva revisión administrativa.',
                    ]);
                }

                if ($user->hasPendingModerationReview()) {
                    return true;
                }

                $requestedAt = TramaClock::now();
                $commentExcerpt = Str::limit(
                    preg_replace('/\s+/u', ' ', trim((string) $comment->body)),
                    500,
                    '...'
                );
                $articleTitle = Str::limit(
                    (string) ($comment->article?->title ?? ''),
                    180,
                    ''
                ) ?: null;

                /*
                 * users conserva el estado de la revisión más reciente para que
                 * las grillas puedan resolver filtros sin consultar todo el
                 * historial. Los ciclos anteriores permanecen en la tabla de
                 * auditoría user_moderation_reviews.
                 */
                $user->update([
                    'moderation_review_requested_at' => $requestedAt,
                    'moderation_review_reason' => $validated['reason'],
                    'moderation_review_note' => $reviewNote,
                    'moderation_review_requested_by_id' => $request->user()?->id,
                    'moderation_review_comment_id' => $comment->id,
                    'moderation_review_comment_excerpt' => $commentExcerpt,
                    'moderation_review_article_title' => $articleTitle,
                    'moderation_review_resolved_at' => null,
                    'moderation_review_resolution' => null,
                    'moderation_review_resolved_by_id' => null,
                ]);

                UserModerationReview::query()->create([
                    'user_id' => $user->id,
                    'requested_at' => $requestedAt,
                    'reason' => $validated['reason'],
                    'note' => $reviewNote,
                    'requested_by_id' => $request->user()?->id,
                    'comment_id' => $comment->id,
                    'comment_excerpt' => $commentExcerpt,
                    'article_title' => $articleTitle,
                ]);

                return false;
            },
                attempts: 3,
            );
        } catch (Throwable $exception) {
            $this->operationFailureHandler->fail(
                $exception,
                'admin_comments',
                'refer_user_to_admin',
                'No pudimos solicitar la revisión del usuario. Intentá nuevamente.',
                [
                    'comment_id' => $comment->id,
                    'user_id' => $request->user()?->id,
                ],
            );
        }

        return back()->with(
            'status',
            $wasAlreadyRequested
                ? 'La revisión del usuario ya estaba solicitada.'
                : 'Revisión administrativa solicitada.',
        );
    }

    /**
     * Impide utilizar el panel de moderación sobre contenido que no pertenece a
     * la grilla editorial.
     *
     * author_context en null identifica participación de usuarios registrados. Los valores
     * article_author y trama_team corresponden respectivamente al periodista
     * autor de la nota y al Equipo TRAMA, cuyas respuestas se publican ya
     * autorizadas y no forman parte de esta grilla.
     *
     * También se excluye expresamente "processing". Ese estado significa que el
     * pipeline automático todavía no terminó y, por definición, aún no existe
     * ninguna decisión que el editor deba revisar. Esta comprobación adicional
     * impide abrir o modificar una fila processing incluso mediante una URL
     * administrativa escrita manualmente.
     */
    private function ensureRegisteredUserCommentCanBeModerated(
        Comment $comment,
    ): void {
        abort_unless(
            $comment->author_context === null
            && in_array(
                $comment->status,
                [
                    'pending',
                    'approved',
                    'rejected',
                ],
                true
            ),
            404,
            'Este comentario no pertenece al flujo de moderación.'
        );
    }

    /**
     * Marca todos los reportes abiertos de un comentario como revisados.
     *
     * dismissed indica que el comentario se mantiene publicado.
     * resolved indica que la moderación tomó una acción sobre el comentario.
     */
    private function reviewOpenReports(
        Comment $comment,
        int $editorId,
        string $status,
    ): int {
        return $comment
            ->reports()
            ->where(
                'status',
                CommentReport::STATUS_OPEN,
            )
            ->update([
                'status' => $status,
                'reviewed_by' => $editorId,
                'reviewed_at' => TramaClock::now(),
                'updated_at' => TramaClock::now(),
            ]);
    }
}
