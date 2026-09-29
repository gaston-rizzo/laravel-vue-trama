<?php

/* ============================================================================
 * SERVICE: CommentIndexQueryService.php
 * ============================================================================
 *
 * Construye la consulta utilizada por la grilla de moderación de comentarios.
 *
 * Laravel conserva toda la responsabilidad de búsqueda, filtros,
 * ordenamiento y paginación. TanStack Table recibe únicamente las filas de la
 * página actual y nunca intenta filtrar o paginar miles de comentarios dentro
 * del navegador.
 *
 * La consulta permite trabajar con:
 *
 *      - todos los comentarios;
 *      - comentarios por revisar;
 *      - pendientes;
 *      - comentarios con reportes abiertos;
 *      - rechazados por decisión editorial;
 *      - rechazos automáticos;
 *      - aprobados;
 *      - comentarios principales o respuestas;
 *      - búsqueda por contenido del comentario;
 *      - autor;
 *      - noticia;
 *      - rango de fechas;
 *      - ordenamiento seguro por columnas conocidas.
 *
 * Los reportes abiertos se calculan mediante withCount(). De esta forma la
 * columna "Reportes" puede mostrarse y ordenarse sin cargar todas las filas de
 * comment_reports para cada comentario.
 * ============================================================================ */

namespace App\Services\Editorial;

use App\Models\Comment;
use App\Models\CommentReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class CommentIndexQueryService
{
    /**
     * Estados que pertenecen actualmente al flujo de moderación de comentarios.
     *
     * @var list<string>
     */
    private const MODERATION_STATUSES = [
        'pending',
        'approved',
        'rejected',
    ];

    /**
     * Devuelve únicamente la página solicitada por la grilla de moderación.
     *
     * @param array<string, mixed> $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        /*
         * La consulta base ya incorpora los joins necesarios para filtrar y
         * ordenar por autor y noticia sin ejecutar una consulta adicional por
         * cada fila.
         */
        $query = $this->baseQuery()
            ->select('comments.*')

            /*
             * Cuenta solamente reportes que todavía requieren revisión.
             *
             * Los reportes dismissed ya fueron atendidos y no deben aumentar la
             * prioridad visual de la fila.
             */
            ->withCount([
                'reports as open_reports_count' => fn (Builder $reports) =>
                    $reports->where(
                        'status',
                        CommentReport::STATUS_OPEN,
                    ),
            ])

            /*
             * Relaciones mostradas en cada fila.
             *
             * Se seleccionan únicamente las columnas utilizadas por la grilla
             * para evitar enviar información innecesaria a Vue.
             */
            ->with([
                'article:id,title,slug',
                'user:id,name,email,is_active,disabled_at',
            ]);

        /*
         * Primero se aplican los filtros por contenido del comentario, autor,
         * noticia, tipo de participación y fecha.
         */
        $this->applyCommonFilters(
            $query,
            $filters,
        );

        /*
         * Después se aplica la pestaña principal de moderación.
         */
        $this->applyQueueFilter(
            $query,
            $filters['queue'] ?? null,
        );

        /*
         * Finalmente se ordena mediante una lista cerrada de columnas.
         */
        $this->applyOrdering(
            $query,
            $filters,
        );

        /*
         * paginate() devuelve solamente la página actual y genera la información
         * necesaria para Anterior, números de página y Siguiente.
         */
        return $query
            ->paginate(
                perPage: (int) ($filters['per_page'] ?? 25),
                columns: ['*'],
                pageName: 'page',
                page: (int) ($filters['page'] ?? 1),
            )
            ->withQueryString();
    }

    /**
     * Calcula las cantidades mostradas dentro de las pestañas de la grilla.
     *
     * Respeta los filtros por contenido del comentario, tipo, autor, noticia y
     * fechas, pero ignora queue para poder calcular simultáneamente las
     * cantidades de todas las pestañas.
     *
     * @param array<string, mixed> $filters
     * @return array{
     *     total: int,
     *     pending: int,
     *     reported: int,
     *     rejected: int,
     *     automatic_rejected: int,
     *     approved: int,
     *     attention: int
     * }
     */
    public function getQueueCounts(array $filters): array
    {
        $query = $this->baseQuery();

        $this->applyCommonFilters(
            $query,
            $filters,
        );

        /*
         * Total de comentarios que coinciden con los filtros aplicados.
         */
        $total = (clone $query)
            ->count('comments.id');

        /*
         * Comentarios pendientes que todavía esperan una decisión editorial.
         */
        $pending = (clone $query)
            ->where('comments.status', 'pending')
            ->count('comments.id');

        /*
         * Comentarios que poseen al menos un reporte abierto.
         *
         * whereHas utiliza EXISTS y evita duplicar comentarios cuando un mismo
         * comentario tiene varios reportes.
         */
        $reported = (clone $query)
            ->whereHas(
                'reports',
                fn (Builder $reports) => $reports->where(
                    'status',
                    CommentReport::STATUS_OPEN,
                ),
            )
            ->count('comments.id');

        /*
         * Rechazos editoriales.
         *
         * Los registros históricos con source null también se consideran
         * editoriales por compatibilidad defensiva con bases anteriores.
         */
        $rejected = (clone $query)
            ->where('comments.status', 'rejected')
            ->where(
                function (Builder $source): void {
                    $source
                        ->whereNull('comments.moderation_source')
                        ->orWhere(
                            'comments.moderation_source',
                            'editorial',
                        );
                },
            )
            ->count('comments.id');

        /*
         * Rechazos realizados directamente por el pipeline automático.
         */
        $automaticRejected = (clone $query)
            ->where('comments.status', 'rejected')
            ->where('comments.moderation_source', 'automatic')
            ->count('comments.id');

        /*
         * Comentarios cuyo estado actual es aprobado.
         */
        $approved = (clone $query)
            ->where('comments.status', 'approved')
            ->count('comments.id');

        /*
         * Por revisar reúne en un solo grupo los comentarios pendientes y los
         * comentarios que poseen al menos un reporte abierto.
         *
         * La condición se calcula como una única consulta para que el número
         * mostrado coincida exactamente con las filas obtenidas al seleccionar
         * queue=attention.
         */
        $attention = (clone $query)
            ->where(
                function (Builder $attention): void {
                    $attention
                        ->where(
                            'comments.status',
                            'pending',
                        )
                        ->orWhereHas(
                            'reports',
                            fn (Builder $reports) => $reports->where(
                                'status',
                                CommentReport::STATUS_OPEN,
                            ),
                        );
                },
            )
            ->count('comments.id');

        return [
            'total' => $total,
            'pending' => $pending,
            'reported' => $reported,
            'rejected' => $rejected,
            'automatic_rejected' => $automaticRejected,
            'approved' => $approved,
            'attention' => $attention,
        ];
    }

    /**
     * Construye la consulta base utilizada para obtener los comentarios
     * paginados y calcular las cantidades mostradas en los filtros de moderación.
     */
    private function baseQuery(): Builder
    {
        return Comment::query()
            /*
             * El join con users permite filtrar y ordenar por los datos actuales
             * de la cuenta. Es LEFT JOIN porque el usuario puede haber sido
             * eliminado y el comentario conserva author_name y author_email.
             */
            ->leftJoin(
                'users as comment_users',
                'comment_users.id',
                '=',
                'comments.user_id',
            )

            /*
             * Cada comentario pertenece a una noticia. LEFT JOIN mantiene la
             * consulta defensiva ante datos históricos inconsistentes.
             */
            ->leftJoin(
                'articles as comment_articles',
                'comment_articles.id',
                '=',
                'comments.article_id',
            )

            /*
             * El panel de moderación trabaja exclusivamente con participación
             * de usuarios registrados.
             *
             * author_context en null identifica comentarios y respuestas de
             * usuarios registrados comunes. Las intervenciones internas del
             * periodista autor de la nota y del Equipo TRAMA se publican ya
             * autorizadas y no deben aparecer en esta grilla.
             */
            ->whereNull('comments.author_context')

            /*
             * La grilla trabaja únicamente con estados pertenecientes al flujo
             * normal de moderación.
             */
            ->whereIn(
                'comments.status',
                self::MODERATION_STATUSES,
            );
    }

    /**
     * Aplica los filtros compartidos por todas las pestañas.
     *
     * @param array<string, mixed> $filters
     */
    private function applyCommonFilters(
        Builder $query,
        array $filters,
    ): void {
        /*
         * Búsqueda por contenido del comentario.
         *
         * Revisa únicamente el cuerpo del comentario. Los filtros de autor y
         * noticia permanecen separados para que cada campo tenga una función
         * específica dentro de la barra de filtros.
         */
        if (! empty($filters['search'])) {
            $query->where(
                'comments.body',
                'like',
                $this->likePattern(
                    (string) $filters['search'],
                ),
            );
        }

        /*
         * Tipo de participación.
         *
         * parent_id null      → comentario principal.
         * parent_id con valor → respuesta.
         */
        if (($filters['type'] ?? null) === 'comment') {
            $query->whereNull('comments.parent_id');
        } elseif (($filters['type'] ?? null) === 'reply') {
            $query->whereNotNull('comments.parent_id');
        }

        /*
         * Filtro específico por autor.
         *
         * Permite buscar tanto por nombre como por correo. Primero utiliza los
         * datos actuales de la cuenta y también contempla los datos históricos
         * conservados dentro del comentario.
         */
        if (! empty($filters['author'])) {
            $pattern = $this->likePattern(
                (string) $filters['author'],
            );

            $query->where(
                function (Builder $author) use ($pattern): void {
                    $author
                        ->where(
                            'comment_users.name',
                            'like',
                            $pattern,
                        )
                        ->orWhere(
                            'comment_users.email',
                            'like',
                            $pattern,
                        )
                        ->orWhere(
                            'comments.author_name',
                            'like',
                            $pattern,
                        )
                        ->orWhere(
                            'comments.author_email',
                            'like',
                            $pattern,
                        );
                },
            );
        }

        /*
         * Filtro específico por título de noticia.
         */
        if (! empty($filters['article'])) {
            $query->where(
                'comment_articles.title',
                'like',
                $this->likePattern(
                    (string) $filters['article'],
                ),
            );
        }

        /*
         * Desde incluye el día completo comenzando a las 00:00:00.
         */
        if (! empty($filters['date_from'])) {
            $query->where(
                'comments.created_at',
                '>=',
                CarbonImmutable::createFromFormat(
                    'Y-m-d',
                    (string) $filters['date_from'],
                )->startOfDay(),
            );
        }

        /*
         * Hasta incluye el día completo terminando a las 23:59:59.999999.
         */
        if (! empty($filters['date_to'])) {
            $query->where(
                'comments.created_at',
                '<=',
                CarbonImmutable::createFromFormat(
                    'Y-m-d',
                    (string) $filters['date_to'],
                )->endOfDay(),
            );
        }
    }

    /**
     * Aplica el grupo principal seleccionado por el editor.
     *
     * attention corresponde al acceso "Por revisar" del resumen superior y
     * reúne pendientes y comentarios con reportes abiertos.
     */
    private function applyQueueFilter(
        Builder $query,
        mixed $queue,
    ): void {
        switch ($queue) {
            case 'attention':
                /*
                 * Por revisar reúne comentarios pendientes y comentarios con al
                 * menos un reporte abierto. whereHas utiliza EXISTS, por lo que
                 * un comentario con varios reportes sigue apareciendo una sola vez.
                 */
                $query->where(
                    function (Builder $attention): void {
                        $attention
                            ->where(
                                'comments.status',
                                'pending',
                            )
                            ->orWhereHas(
                                'reports',
                                fn (Builder $reports) => $reports->where(
                                    'status',
                                    CommentReport::STATUS_OPEN,
                                ),
                            );
                    },
                );
                break;

            case 'pending':
                $query->where(
                    'comments.status',
                    'pending',
                );
                break;

            case 'reported':
                /*
                 * Un comentario aparece en Reportados mientras conserve al menos
                 * un reporte abierto que todavía requiere revisión.
                 */
                $query->whereHas(
                    'reports',
                    fn (Builder $reports) => $reports->where(
                        'status',
                        CommentReport::STATUS_OPEN,
                    ),
                );
                break;

            case 'rejected':
                /*
                 * La pestaña Rechazados conserva únicamente decisiones editoriales.
                 * Los null históricos se incluyen como compatibilidad adicional.
                 */
                $query
                    ->where(
                        'comments.status',
                        'rejected',
                    )
                    ->where(
                        function (Builder $source): void {
                            $source
                                ->whereNull('comments.moderation_source')
                                ->orWhere(
                                    'comments.moderation_source',
                                    'editorial',
                                );
                        },
                    );
                break;

            case 'automatic_rejected':
                $query
                    ->where(
                        'comments.status',
                        'rejected',
                    )
                    ->where(
                        'comments.moderation_source',
                        'automatic',
                    );
                break;

            case 'approved':
                $query->where(
                    'comments.status',
                    'approved',
                );
                break;

            default:
                /*
                 * Sin una pestaña específica se muestran todos los estados de
                 * moderación disponibles.
                 */
                break;
        }
    }

    /**
     * Aplica un ordenamiento seguro y estable.
     *
     * @param array<string, mixed> $filters
     */
    private function applyOrdering(
        Builder $query,
        array $filters,
    ): void {
        $sort = (string) ($filters['sort'] ?? 'created_at');

        $direction = ($filters['direction'] ?? 'desc') === 'asc'
            ? 'asc'
            : 'desc';

        switch ($sort) {
            case 'status':
                /*
                 * Orden lógico de estados en lugar del orden alfabético de sus
                 * códigos internos.
                 */
                $query->orderByRaw(
                    "CASE comments.status\n"
                    ."WHEN 'pending' THEN 1\n"
                    ."WHEN 'approved' THEN 2\n"
                    ."WHEN 'rejected' THEN 3\n"
                    ."ELSE 4 END {$direction}"
                );
                break;

            case 'author':
                /*
                 * Prioriza el nombre actual de la cuenta y utiliza author_name
                 * histórico cuando la relación ya no existe.
                 */
                $query->orderByRaw(
                    "COALESCE(comment_users.name, comments.author_name, '') {$direction}"
                );
                break;

            case 'article':
                $query->orderBy(
                    'comment_articles.title',
                    $direction,
                );
                break;

            case 'type':
                /*
                 * Comentarios principales y respuestas se ordenan mediante una
                 * expresión determinista basada en parent_id.
                 */
                $query->orderByRaw(
                    "CASE WHEN comments.parent_id IS NULL THEN 0 ELSE 1 END {$direction}"
                );
                break;

            case 'reports':
                /*
                 * open_reports_count es la columna calculada por withCount().
                 */
                $query->orderBy(
                    'open_reports_count',
                    $direction,
                );
                break;

            case 'created_at':
            default:
                $query->orderBy(
                    'comments.created_at',
                    $direction,
                );
                break;
        }

        /*
         * Desempate estable para que dos filas con el mismo valor de orden no
         * cambien arbitrariamente de posición entre páginas.
         */
        $query->orderBy(
            'comments.id',
            $direction,
        );
    }

    /**
     * Escapa comodines SQL y devuelve un patrón seguro para LIKE.
     */
    private function likePattern(string $value): string
    {
        /*
         * % y _ son comodines de LIKE. La barra invertida los convierte en texto
         * literal cuando el usuario los escribe dentro del filtro.
         */
        $escaped = addcslashes(
            trim($value),
            '\\%_',
        );

        return '%'.$escaped.'%';
    }
}