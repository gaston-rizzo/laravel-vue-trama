<?php

/* ============================================================================
 * SERVICE: ArticleIndexQueryService.php
 * ============================================================================
 *
 * Construye la consulta utilizada por el listado editorial de noticias.
 *
 * Aplica las reglas de visibilidad correspondientes al usuario autenticado,
 * la búsqueda FULLTEXT, los filtros de estado, categoría, autor y fecha, el
 * ordenamiento y la paginación numerada.
 *
 * La consulta selecciona únicamente las columnas necesarias para mostrar cada
 * fila de la tabla. También precarga las relaciones de autor y categoría para
 * evitar consultas adicionales por cada noticia.
 *
 * Las etiquetas no se cargan junto con los resultados porque no se muestran
 * dentro de la tabla ni cuentan con un filtro propio en este listado.
 *
 * Sin embargo, sus nombres forman parte de la columna indexada search_text.
 * Por ese motivo, el buscador general puede encontrar una noticia mediante una
 * etiqueta asociada sin necesidad de cargar la relación tags en cada resultado.
 *
 * La búsqueda utiliza la columna indexada search_text, que reúne el título,
 * subtítulo, bajada, cuerpo convertido a texto plano, categoría y etiquetas
 * de cada noticia.
 *
 * Ejemplo de filtros recibidos:
 *
 * [
 *     'search' => 'reforma digital',
 *     'status' => 'published',
 *     'category_id' => 2,
 *     'author' => 'lucía',
 *     'date_from' => '2026-08-01',
 *     'date_to' => '2026-08-31',
 *     'sort' => 'updated_at',
 *     'direction' => 'desc',
 *     'per_page' => 25,
 *     'page' => 3,
 * ]
 *
 * En este ejemplo, el servicio devuelve la tercera página de noticias
 * publicadas que coinciden con "reforma digital", pertenecen a la categoría
 * indicada, fueron escritas por un autor cuyo nombre contiene "lucía" y fueron
 * editadas dentro del rango de fechas establecido.
 *
 * Los resultados se ordenan desde la última edición más reciente y se muestran
 * en páginas de 25 noticias.
 * ============================================================================ */

namespace App\Services\Editorial;

use App\Models\Article;
use App\Models\Category;
use App\Models\User;

use Carbon\CarbonImmutable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ArticleIndexQueryService
{
    /**
     * Estados de noticias ajenas que quedan bajo control editorial.
     *
     * Un editor puede ver todas sus noticias. De otros autores solo puede ver
     * las noticias enviadas a revisión, programadas, publicadas o archivadas.
     *
     * @var list<string>
     */
    private const EDITOR_VISIBLE_TEAM_STATUSES = [
        'review',
        'scheduled',
        'published',
        'archived',
    ];

    /**
     * Devuelve una página numerada del listado editorial.
     *
     * Laravel obtiene únicamente las noticias correspondientes a la página
     * solicitada y calcula la cantidad total de resultados disponibles.
     *
     * Ejemplo:
     *
     * Si existen 230 noticias y se muestran 25 por página, Laravel devolverá
     * 25 noticias por petición y permitirá navegar entre diez páginas.
     *
     * @param array<string, mixed> $filters
     */
    public function paginate(
        User $user,
        array $filters,
    ): LengthAwarePaginator {
        /*
        * La consulta comienza aplicando la visibilidad correspondiente al usuario.
        *
        * Solamente se seleccionan las columnas necesarias para el listado. Se
        * excluyen body, excerpt, subtitle y search_text porque no se muestran en
        * la tabla y aumentarían innecesariamente el tamaño de la respuesta.
        */
        $query = $this->baseQuery($user, $filters)
            ->select([
                'articles.id',
                'articles.author_id',
                'articles.category_id',
                'articles.title',
                'articles.slug',
                'articles.status',
                'articles.cover_image',
                'articles.is_breaking',
                'articles.is_featured',
                'articles.reading_time',
                'articles.views',
                'articles.published_at',
                'articles.scheduled_at',
                'articles.created_at',
                'articles.updated_at',
            ])
            /*
            * Autor y categoría se muestran en cada fila y se precargan para evitar
            * consultas adicionales por cada noticia.
            */
            ->with([
                'author:id,name',
                'category:id,name,slug',
            ]);

        /*
        * La búsqueda y los filtros se aplican antes del ordenamiento y de la
        * paginación.
        */
        $this->applyFilters(
            $query,
            $filters,
        );

        /*
        * El orden estable evita que noticias con el mismo valor de ordenamiento
        * cambien arbitrariamente de posición entre distintas páginas.
        */
        $this->applyOrdering(
            $query,
            $filters,
        );

        /*
        * paginate() genera una paginación numerada y calcula:
        *
        * - página actual;
        * - última página;
        * - cantidad total de noticias;
        * - enlaces Anterior, números de página y Siguiente.
        *
        * Solamente se cargan las filas de la página actual.
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
     * Obtiene las cantidades de noticias mostradas en los filtros de estado.
     *
     * Calcula cuántas noticias visibles corresponden a cada estado editorial y
     * también devuelve la cantidad total.
     *
     * Respeta la búsqueda y los filtros activos de autor, categoría, etiqueta y
     * fechas. El filtro status se excluye para poder calcular simultáneamente las
     * cantidades de todos los estados.
     *
     * El ordenamiento, la cantidad por página y la página actual no intervienen
     * porque solamente modifican cómo se presentan o recorren los resultados.
     *
     * Ejemplo de resultado:
     *
     * [
     *     'total' => 72,
     *     'statuses' => [
     *         'draft' => 10,
     *         'review' => 5,
     *         'needs_changes' => 2,
     *         'scheduled' => 4,
     *         'published' => 45,
     *         'archived' => 6,
     *     ],
     * ]
     *
     * En este ejemplo, el usuario puede consultar 72 noticias según los filtros
     * activos: diez borradores, cinco en revisión, dos que requieren cambios,
     * cuatro programadas, cuarenta y cinco publicadas y seis archivadas.
     *
     * @param array<string, mixed> $filters
     * @return array{total: int, statuses: array<string, int>}
     */
    public function getArticleCountsForStatusFilters(
        User $user,
        array $filters,
    ): array {
        /*
        * Todos los estados comienzan en cero para que Vue reciba siempre la misma
        * estructura, aunque todavía no existan noticias para alguno de ellos.
        */
        $countsByStatus = array_fill_keys(
            Article::STATUSES,
            0,
        );

        /*
        * status se elimina porque deben calcularse todos los estados.
        *
        * El ordenamiento y la página actual tampoco modifican las cantidades
        * totales correspondientes a cada filtro.
        */
        $countFilters = Arr::except($filters, [
            'status',
            'sort',
            'direction',
            'per_page',
            'page',
        ]);

        /*
        * La consulta parte de las noticias que el usuario tiene permitido ver.
        */
        $query = $this->baseQuery($user, $countFilters);

        /*
        * Se conservan la búsqueda y los demás filtros para que las cantidades
        * coincidan con el conjunto de noticias consultado por el usuario.
        */
        $this->applyFilters(
            $query,
            $countFilters,
        );

        /*
        * GROUP BY obtiene las cantidades de todos los estados mediante una sola
        * consulta a la base de datos.
        */
        $counts = $query
            ->selectRaw('articles.status, COUNT(*) AS total')
            ->groupBy('articles.status')
            ->pluck('total', 'status');

        /*
        * Los estados encontrados reemplazan los ceros iniciales.
        */
        foreach ($counts as $status => $total) {
            if (array_key_exists($status, $countsByStatus)) {
                $countsByStatus[$status] = (int) $total;
            }
        }

        /*
        * El total se calcula sumando las cantidades de todos los estados.
        */
        return [
            'total' => array_sum($countsByStatus),
            'statuses' => $countsByStatus,
        ];
    }

    /**
     * Obtiene las cantidades de noticias en revisión y que requieren cambios.
     *
     * Estos valores se muestran en los indicadores del listado editorial y no
     * cambian cuando el usuario busca, ordena o filtra la tabla.
     *
     * Ejemplo de resultado:
     *
     * [
     *     'review' => 5,
     *     'needs_changes' => 2,
     * ]
     *
     * En este ejemplo, hay cinco noticias esperando revisión editorial y dos
     * noticias devueltas a sus autores porque requieren cambios.
     *
     * @return array{review: int, needs_changes: int}
     */
    public function getReviewAndNeedsChangesCounts(User $user): array
    {
        $counts = $this->baseQuery($user)
            ->selectRaw('articles.status, COUNT(*) AS total')
            ->whereIn('articles.status', [
                'review',
                'needs_changes',
            ])
            ->groupBy('articles.status')
            ->pluck('total', 'status');

        return [
            'review' => (int) ($counts['review'] ?? 0),
            'needs_changes' => (int) ($counts['needs_changes'] ?? 0),
        ];
    }

    /**
     * Construye la consulta inicial y limita las noticias según el rol.
     */
    private function baseQuery(User $user, array $filters = []): Builder
    {
        /*
        * El periodista solamente puede consultar noticias de su autoría,
        * independientemente de su estado editorial.
        */
        if (! $user->canReviewArticles()) {
            return Article::query()
                ->where(
                    'articles.author_id',
                    $user->id,
                );
        }

        /*
         * La gestión de portada es global para quienes pueden revisar noticias.
         * Cuando se usa ese filtro, el editor debe poder ver las siete noticias
         * que realmente ocupan o reservan lugares, aunque pertenezcan a otro
         * editor. applyFilters() limita luego este conjunto a publicadas o
         * programadas según el tratamiento de portada elegido.
         */
        if (in_array(
            $filters['homepage'] ?? null,
            ['featured', 'breaking', 'normal'],
            true,
        )) {
            return Article::query();
        }

        /*
        * El editor puede consultar:
        *
        * - sus propias noticias;
        * - noticias escritas por periodistas que se encuentren
        *   dentro de estados visibles para el equipo editorial.
        *
        * Las noticias pertenecientes a otros editores quedan
        * completamente fuera de su alcance.
        */
        return Article::query()
            ->where(function (Builder $scope) use ($user): void {
                $scope
                    ->where(
                        'articles.author_id',
                        $user->id,
                    )
                    ->orWhere(function (Builder $team): void {
                        $team
                            ->whereIn(
                                'articles.status',
                                self::EDITOR_VISIBLE_TEAM_STATUSES,
                            )
                            ->whereHas(
                                'author',
                                fn (Builder $author): Builder => $author->where(
                                    'role',
                                    'journalist',
                                ),
                            );
                    });
            });
    }

    /**
     * Aplica búsqueda, taxonomías, autor, estado y rango de actualización.
     *
     * @param array<string, mixed> $filters
     */
    private function applyFilters(
        Builder $query,
        array $filters,
    ): void {
        /*
         * ArticleIndexRequest ya limpió y validó el término. Acá solamente se
         * comprueba que exista antes de agregar la condición de búsqueda.
         */
        $search = $filters['search'] ?? null;

        /*
         * Aplica la búsqueda solamente cuando el parámetro recibido contiene texto.
         */
        if (is_string($search) && $search !== '') {
            /*
            * Extrae únicamente palabras válidas de tres caracteres o más.
            *
            * Esto elimina operadores especiales de la sintaxis FULLTEXT y permite
            * transformar cada palabra en una búsqueda segura por prefijo.
            */
            $searchTerms = $this->getSearchTerms($search);

            /*
            * Una búsqueda formada únicamente por caracteres descartados no debe
            * devolver todas las noticias.
            */
            if ($searchTerms === []) {
                $query->whereRaw('1 = 0');
            } elseif (DB::connection()->getDriverName() === 'mysql') {
                $booleanSearch = implode(
                    ' ',
                    array_map(
                        /*
                        * Recorre cada término y lo transforma al formato requerido por
                        * MariaDB para una búsqueda obligatoria por prefijo.
                        *
                        * Ejemplos:
                        * ciudad → +ciudad*
                        * noti   → +noti*
                        *
                        * static indica que esta función no utiliza $this ni necesita acceder
                        * al objeto ArticleIndexQueryService.
                        */
                        static fn (string $term): string =>
                            '+'.$term.'*',
                        $searchTerms,
                    ),
                );

                $query->whereFullText(
                    'articles.search_text',
                    $booleanSearch,
                    [
                        'mode' => 'boolean',
                    ],
                );
            } else {
                /*
                * PHPUnit utiliza SQLite en memoria y no reconoce MATCH ... AGAINST.
                *
                * Se aplica una condición LIKE por cada término para mantener durante
                * las pruebas el mismo comportamiento de búsqueda obligatoria.
                */
                foreach ($searchTerms as $term) {
                    $query->where(
                        'articles.search_text',
                        'like',
                        '%'.$term.'%',
                    );
                }
            }
        }

        /*
         * Determina si se solicitaron únicamente noticias sin categoría.
         */
        $withoutCategory = filter_var(
            $filters['without_category'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );

        /*
         * Los filtros simples se aplican directamente sobre columnas indexables
         * de articles cuando el parámetro correspondiente está presente.
         */
        /*
         * Filtra según el tratamiento de portada solicitado.
         *
         * Las destacadas y urgentes solo cuentan como tratamiento activo cuando
         * la noticia está publicada o programada. "normal" representa noticias
         * sin ninguna de las dos marcas editoriales.
         */
        $homepage = $filters['homepage'] ?? null;

        if ($homepage === 'featured') {
            $query
                ->where('articles.is_featured', true)
                ->whereIn('articles.status', ['published', 'scheduled']);
        } elseif ($homepage === 'breaking') {
            $query
                ->where('articles.is_breaking', true)
                ->whereIn('articles.status', ['published', 'scheduled']);
        } elseif ($homepage === 'normal') {
            $query
                ->where('articles.is_featured', false)
                ->where('articles.is_breaking', false)
                ->whereIn('articles.status', ['published', 'scheduled']);
        }

        $query
            /*
             * Limita el listado al estado editorial seleccionado.
             *
             * Por ejemplo: borrador, en revisión, publicada o archivada.
             */
            ->when(
                $filters['status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where(
                    'articles.status',
                    $status,
                ),
            )
           /*
            * Limita el listado a las noticias pertenecientes a la categoría
            * seleccionada por el usuario.
            *
            * Este filtro no se aplica cuando el usuario eligió "Sin categoría".
            */
            ->when(
                ! $withoutCategory
                    ? ($filters['category_id'] ?? null)
                    : null,
                fn (
                    Builder $query,
                    int|string $categoryId,
                ): Builder => $query->where(
                    'articles.category_id',
                    (int) $categoryId,
                ),
            )

            /*
            * Muestra únicamente noticias cuyo category_id es null.
            *
            * Esto incluye borradores que todavía no tienen una categoría seleccionada.
            */
            ->when(
                $withoutCategory,
                fn (Builder $query): Builder => $query->whereNull(
                    'articles.category_id',
                ),
            )
            /*
             * Limita el listado a las noticias escritas por un autor específico.
             *
             * Este filtro utiliza el identificador exacto del usuario y se mantiene
             * separado de la búsqueda parcial por nombre recibida mediante author.
             */
            ->when(
                $filters['author_id'] ?? null,
                fn (
                    Builder $query,
                    int|string $authorId,
                ): Builder => $query->where(
                    'articles.author_id',
                    (int) $authorId,
                ),
            )
            /*
            * Busca noticias cuyo autor contenga el texto escrito por el usuario.
            *
            * Por ejemplo, el valor "luc" puede encontrar a "Lucía Ferreyra".
            * whereHas() filtra mediante la relación author sin cargar usuarios adicionales.
            */
            ->when(
                $filters['author'] ?? null,
                fn (
                    Builder $query,
                    string $author,
                ): Builder => $query->whereHas(
                    'author',
                    fn (Builder $authorQuery): Builder => $authorQuery->where(
                        'users.name',
                        'like',
                        '%'.$author.'%',
                    ),
                ),
            );

        /*
         * El rango de fechas se aplica sobre updated_at porque el listado está
         * orientado a la actividad editorial y a la última modificación.
         *
         * startOfDay() incluye desde las 00:00:00 de la fecha inicial.
         */
        if (! empty($filters['date_from'])) {
            $dateFrom = CarbonImmutable::createFromFormat(
                'Y-m-d',
                (string) $filters['date_from'],
            )->startOfDay();

            $query->where(
                'articles.updated_at',
                '>=',
                $dateFrom,
            );
        }

        /*
         * endOfDay() incluye hasta las 23:59:59 de la fecha final, evitando
         * excluir noticias actualizadas durante ese mismo día.
         */
        if (! empty($filters['date_to'])) {
            $dateTo = CarbonImmutable::createFromFormat(
                'Y-m-d',
                (string) $filters['date_to'],
            )->endOfDay();

            $query->where(
                'articles.updated_at',
                '<=',
                $dateTo,
            );
        }
    }

    /**
     * Obtiene las palabras válidas utilizadas por la búsqueda editorial.
     *
     * Se admiten letras y números. También se reconocen correctamente letras
     * propias del español, como á, é, í, ó, ú, ü y ñ.
     *
     * Cada palabra debe tener al menos tres caracteres porque el índice FULLTEXT
     * actual fue configurado con ese tamaño mínimo.
     *
     * @return list<string>
     */
    private function getSearchTerms(string $search): array
    {
        /*
        * Convierte el texto a minúsculas para mantener términos uniformes tanto
        * en MariaDB como durante las pruebas ejecutadas mediante SQLite.
        */
        $normalizedSearch = mb_strtolower(
            $search,
            'UTF-8',
        );

        /*
        * Extrae bloques formados únicamente por letras o números.
        *
        * De esta manera caracteres propios del modo booleano, como +, -, *, comillas
        * o paréntesis, nunca llegan directamente desde el usuario a la consulta.
        */
        preg_match_all(
            '/[\p{L}\p{N}]{3,}/u',
            $normalizedSearch,
            $matches,
        );

        /*
        * Elimina términos repetidos y devuelve índices consecutivos.
        */
        return array_values(
            array_unique(
                $matches[0] ?? [],
            ),
        );
    }














    /**
     * Ordena el listado por la columna y dirección solicitadas.
     *
     * Los ordenamientos por categoría y autor utilizan subconsultas porque sus
     * nombres pertenecen a tablas relacionadas y no a la tabla articles.
     *
     * @param array<string, mixed> $filters
     */
    private function applyOrdering(
        Builder $query,
        array $filters,
    ): void {
        /*
        * ArticleIndexRequest limita los valores permitidos para sort.
        *
        * Estos valores predeterminados protegen también las llamadas internas que
        * invoquen el servicio sin enviar explícitamente un ordenamiento.
        */
        $sort = (string) ($filters['sort'] ?? 'updated_at');

        $direction = ($filters['direction'] ?? 'desc') === 'asc'
            ? 'asc'
            : 'desc';

        /*
        * Ordena por prioridad editorial de portada.
        *
        * Las dos marcas se combinan en un valor numérico para que el orden sea
        * estable y no dependa del orden alfabético de las etiquetas visibles.
        *
        * Prioridad ascendente:
        *
        * 1 → Normal
        * 2 → Destacada
        * 3 → Urgente
        * 4 → Destacada + Urgente
        *
        * Al ordenar en descendente se muestra primero el tratamiento editorial
        * de mayor prioridad; en ascendente se comienza por las noticias normales.
        */
        if ($sort === 'homepage') {
            $query
                ->selectRaw(
                    "CASE
                        WHEN articles.is_featured = 1
                            AND articles.is_breaking = 1 THEN 4
                        WHEN articles.is_breaking = 1 THEN 3
                        WHEN articles.is_featured = 1 THEN 2
                        ELSE 1
                    END AS editorial_sort_value",
                )
                ->orderBy(
                    'editorial_sort_value',
                    $direction,
                )
                ->orderBy(
                    'articles.id',
                    $direction,
                );

            return;
        }

        /*
        * Ordena alfabéticamente por el nombre de la categoría.
        *
        * La subconsulta recupera solamente el nombre relacionado con category_id.
        * Las noticias sin categoría permanecen siempre al final.
        */
        if ($sort === 'category') {
            $query
                ->addSelect([
                    'editorial_sort_value' => Category::query()
                        ->select('categories.name')
                        ->whereColumn(
                            'categories.id',
                            'articles.category_id',
                        )
                        ->limit(1),
                ])
                ->orderByRaw(
                    'CASE
                        WHEN editorial_sort_value IS NULL THEN 1
                        ELSE 0
                    END',
                )
                ->orderBy(
                    'editorial_sort_value',
                    $direction,
                )
                ->orderBy(
                    'articles.id',
                    $direction,
                );

            return;
        }

        /*
        * Ordena alfabéticamente por el nombre del autor.
        *
        * La subconsulta recupera solamente el nombre relacionado con author_id.
        * Las noticias sin un autor disponible permanecen siempre al final.
        */
        if ($sort === 'author') {
            $query
                ->addSelect([
                    'editorial_sort_value' => User::query()
                        ->select('users.name')
                        ->whereColumn(
                            'users.id',
                            'articles.author_id',
                        )
                        ->limit(1),
                ])
                ->orderByRaw(
                    'CASE
                        WHEN editorial_sort_value IS NULL THEN 1
                        ELSE 0
                    END',
                )
                ->orderBy(
                    'editorial_sort_value',
                    $direction,
                )
                ->orderBy(
                    'articles.id',
                    $direction,
                );

            return;
        }

        /*
        * Las demás columnas pertenecen directamente a articles.
        */
        $sortExpression = match ($sort) {
            'created_at' => 'articles.created_at',

            'published_at' =>
                "COALESCE(articles.published_at, '1000-01-01 00:00:00')",

            'scheduled_at' =>
                "COALESCE(articles.scheduled_at, '1000-01-01 00:00:00')",

            'title' =>
                "COALESCE(articles.title, '')",

            'status' => 'articles.status',
            'views' => 'articles.views',

            default => 'articles.updated_at',
        };

        /*
        * articles.id funciona como desempate estable cuando dos noticias tienen
        * el mismo título, estado, cantidad de vistas o fecha.
        */
        $query
            ->selectRaw(
                $sortExpression.' AS editorial_sort_value',
            )
            ->orderBy(
                'editorial_sort_value',
                $direction,
            )
            ->orderBy(
                'articles.id',
                $direction,
            );
    }
}