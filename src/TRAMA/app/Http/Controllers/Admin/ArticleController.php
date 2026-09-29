<?php

/* ============================================================================
 * CONTROLLER: ArticleController.php
 * ============================================================================
 *
 * Controla la gestión de noticias del panel editorial de TRAMA.
 *
 * Muestra la tabla privada del equipo, prepara los formularios de creación y
 * edición, valida permisos, procesa imágenes de portada y entrega a Vue los
 * datos necesarios para cada pantalla.
 *
 * Los cambios importantes de una noticia, como enviarla a revisión, publicarla,
 * programarla, devolverla para corrección o archivarla, se delegan al servicio
 * de flujo editorial para que cada acción quede registrada de forma consistente.
 * ============================================================================ */

namespace App\Http\Controllers\Admin;

use App\Support\TramaClock;

use App\Services\Images\ArticleCoverProcessor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ArticleRequest;
use App\Http\Resources\AdminArticleResource;
use App\Http\Requests\Editorial\ArticleIndexRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\Editorial\AdminArticleListResource;
use App\Models\Article;
use App\Models\ArticleRevision;
use App\Models\Category;
use App\Models\Tag;
use App\Services\Editorial\ArticleWorkflowService;
use App\Services\HtmlSanitizer;
use App\Services\Editorial\ArticleIndexQueryService;
use App\Support\TramaBridge;
use App\Support\TramaLog;
use App\Support\OperationFailureHandler;

use Carbon\CarbonImmutable;

use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

use Inertia\Response;

use RuntimeException;
use Throwable;

class ArticleController extends Controller
{
    public function __construct(
        private readonly OperationFailureHandler $operationFailures,
    ) {
    }

    /**
     * Etiquetas visibles de los estados editoriales.
     *
     * @var array<string, string>
     */
    private const STATUS_LABELS = [
        'draft' => 'En borrador',
        'review' => 'En revisión',
        'needs_changes' => 'Devuelta para corrección',
        'scheduled' => 'Programada',
        'published' => 'Publicada',
        'archived' => 'Archivada',
    ];

    /**
     * Muestra el listado editorial de noticias.
     *
     * La búsqueda, la visibilidad, los filtros, el ordenamiento y la paginación
     * se delegan a ArticleIndexQueryService.
     *
     * El controlador solamente coordina los datos que recibe Laravel y prepara
     * las propiedades enviadas a la pantalla de Vue.
     */
   public function index(
    ArticleIndexRequest $request,
    ArticleIndexQueryService $articleIndexQuery,
    ): Response {
       /*
        * ArticleIndexRequest ya limpió y validó todos los parámetros recibidos
        * desde la URL.
        *
        * Ejemplo:
        *
        * [
        *     'search' => 'reforma digital',
        *     'status' => 'published',
        *     'sort' => 'updated_at',
        *     'direction' => 'desc',
        *     'per_page' => 25,
        * ]
        */
        $filters = $request->validated();

       /*
        * El servicio conoce al usuario autenticado porque la visibilidad del
        * listado cambia según se trate de un periodista o de un editor.
        */
        $user = $request->user();

       /*
        * page no se envía como filtro visible porque solamente identifica la página
        * actual del listado. Cuando cambia la búsqueda, un filtro, el ordenamiento o
        * la cantidad de registros por página, la navegación debe volver a la primera.
        *
        * Se definen valores predeterminados para que Vue reciba siempre la misma
        * estructura, aunque algunos parámetros no estén presentes en la URL.
        *
        * Los filtros validados reemplazan esos valores predeterminados, excepto page,
        * que pertenece exclusivamente al estado de la paginación.
        */
        $visibleFilters = [
            'search' => '',
            'status' => '',
            'homepage' => '',
            'category_id' => null,
            'without_category' => false,
            'author_id' => null,
            'author' => '',
            'date_from' => null,
            'date_to' => null,
            'sort' => 'updated_at',
            'direction' => 'desc',
            'per_page' => 25,
            ...Arr::except($filters, ['page']),
        ];

       /*
        * Laravel valida per_page como entero, pero los parámetros de la URL pueden
        * conservarse internamente como texto. Se convierte antes de enviarlo a Vue.
        */
        $visibleFilters['per_page'] = (int) $visibleFilters['per_page'];

       /*
        * Valores seguros utilizados solamente cuando alguna consulta necesaria
        * para construir el listado falla de manera inesperada.
        *
        * Permiten devolver Index.vue con la misma estructura de propiedades sin
        * fingir que la consulta se completó correctamente.
        */
        $articlesPayload = [
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

        $statusCounts = [
            'total' => 0,
            'statuses' => array_fill_keys(
                Article::STATUSES,
                0,
            ),
        ];

        $reviewAndNeedsChangesCounts = [
            'review' => 0,
            'needs_changes' => 0,
        ];

        $categories = [];

       /*
        * El mensaje permanece vacío durante una carga normal.
        *
        * Solamente se completa cuando ocurre un error inesperado que permite
        * conservar la pantalla del listado.
        */
        $loadError = '';

        try {
           /*
            * Obtiene solamente las noticias correspondientes a la página actual.
            *
            *
            * No se cargan todas las noticias en memoria y no se utiliza OFFSET para
            * recorrer páginas profundas.
            */
            $articles = $articleIndexQuery->paginate(
                $user,
                $filters,
            );

           /*
            * Calcula las cantidades mostradas dentro de los filtros de estado.
            *
            * Estos conteos respetan la búsqueda y los filtros activos, pero ignoran
            * status para poder obtener simultáneamente todos los estados.
            */
            $statusCounts = $articleIndexQuery
                ->getArticleCountsForStatusFilters(
                    $user,
                    $filters,
                );

           /*
            * Obtiene los indicadores generales de noticias esperando revisión y
            * noticias propias que requieren cambios.
            *
            * Estos valores no dependen de los filtros aplicados a la tabla.
            */
            $reviewAndNeedsChangesCounts = $articleIndexQuery
                ->getReviewAndNeedsChangesCounts($user);

           /*
            * Categorías disponibles dentro del filtro del listado editorial.
            *
            * Se cargan dentro del mismo bloque protegido porque también dependen
            * de información almacenada en la base de datos.
            */
            $categories = $this->filterCategories();

           /*
            * Convierte las noticias de la página actual al formato que necesita Index.vue.
            *
            * AdminArticleListResource envía solamente los datos necesarios para mostrar
            * cada fila del listado editorial, por ejemplo título, autor, estado, categoría
            * y fechas. No incluye información pesada o innecesaria para esta pantalla
            */            
            $articlesPayload = [
                'data' => AdminArticleListResource::collection(
                    $articles->getCollection(),
                )->resolve(),

               /*
                * Datos utilizados por la paginación numerada del listado.
                *
                * linkCollection() genera los enlaces Anterior, números de página
                * y Siguiente conservando los filtros actuales de la URL.
                */
                'pagination' => [
                    'current_page' => $articles->currentPage(),
                    'last_page' => $articles->lastPage(),
                    'per_page' => $articles->perPage(),
                    'total' => $articles->total(),
                    'from' => $articles->firstItem(),
                    'to' => $articles->lastItem(),
                    'links' => $articles->linkCollection()->toArray(),
                ],
            ];
        } catch (Throwable $exception) {
           /*
            * Una caída completa de MySQL no pertenece al manejo interno del
            * listado editorial.
            *
            * Se vuelve a lanzar para que bootstrap/app.php continúe mostrando
            * la página global 503 que ya maneja este escenario.
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
            * Registra el detalle técnico del fallo sin exponerlo al usuario.
            *
            * El mensaje real de la excepción queda solamente en los logs.
            */
            TramaLog::error(
                'Falló la carga del listado editorial de noticias.',
                [
                    'area' => 'articles',
                    'operation' => 'load_index',
                    'user_id' => $user->id,
                    'page' => (int) ($filters['page'] ?? 1),
                    'search' => $visibleFilters['search'] ?: null,
                    'status' => $visibleFilters['status'] ?: null,
                    'homepage' => $visibleFilters['homepage'] ?: null,
                    'category_id' => $visibleFilters['category_id'],
                    'author_id' => $visibleFilters['author_id'],
                    'per_page' => $visibleFilters['per_page'],
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                    'exception_file' => $exception->getFile(),
                    'exception_line' => $exception->getLine(),
                ],
            );

           /*
            * Vue recibe solamente un mensaje amigable.
            *
            * Nunca se envía al navegador el texto técnico de la excepción.
            */
            $loadError =
                'No pudimos cargar las noticias en este momento. '
                .'Intentá nuevamente.';
        }

        return TramaBridge::render('Admin/Articles/Index', [
            /*
             * Estado actual de búsqueda, filtros y ordenamiento.
            */
            'filters' => $visibleFilters,
           /*
            * Día del reloj editorial actual. La hora viaja por las ventanas de
            * programación y por los timestamps completos; este valor se mantiene
            * como fecha simple porque el filtro de la grilla trabaja por día.
            */
            'referenceDate' => $this->editorialNow()->toDateString(),
           /*
            * Categorías disponibles dentro del filtro del listado editorial.
            */
            'categories' => $categories,
           /*
            * Opciones disponibles para los botones de estado según el rol.
            */
            'statusOptions' => $this->allStatusOptions($request),
           /*
            * Cantidades que acompañan los botones Todas, Borradores, En revisión,
            * Programadas, Publicadas y Archivadas.
            */
            'statusCounts' => $statusCounts,
           /*
            * Indicadores superiores del flujo editorial enviados a Index.vue.
            *
            * reviewCount resume noticias en revisión y changesCount las noticias
            * devueltas para corrección dentro del alcance visible del usuario.
            */
            'reviewCount' => $reviewAndNeedsChangesCounts['review'],
            'changesCount' => $reviewAndNeedsChangesCounts['needs_changes'],
           /*
            * Permisos y alcance visible del usuario autenticado.
            */
            'permissions' => $this->articlePermissions($request),
           /*
            * Mensaje general mostrado cuando la carga del listado falla por una
            * causa inesperada y la pantalla puede mantenerse disponible.
            */
            'loadError' => $loadError,
           /*
            * El recurso liviano transforma únicamente las noticias de la página
            * actual. No procesa cuerpo, bajada, etiquetas ni HTML.
            *
            * Cuando la carga falla, articlesPayload conserva esta misma estructura
            * con una colección vacía y una paginación segura.
            */
            'articles' => $articlesPayload,
        ]);
    }

    /**
     * Muestra el formulario vacío con categorías y etiquetas activas.
     */
    public function create(Request $request): Response
    {
        // El formulario nuevo parte sin artículo, pero necesita opciones de
        // categorías, etiquetas, estados, permisos y fechas editoriales.
        return TramaBridge::render('Admin/Articles/Form', [
            // No existe una noticia todavía porque se está abriendo el formulario de creación.
            'article' => null,
            // Categorías activas disponibles para clasificar la nueva noticia.
            'categories' => $this->formCategories(),
            // Etiquetas activas disponibles para asociar a la nueva noticia.
            'tags' => $this->formTags(),
            // Indica a Vue que debe funcionar en modo de creación.
            'mode' => 'create',
            // El formulario nuevo permite editar todos los campos habilitados por el rol.
            'readOnly' => false,
            // Estados editoriales que el usuario puede seleccionar al crear la noticia.
            'statusOptions' => $this->statusOptions($request),
            // Permisos del usuario autenticado dentro del módulo de noticias.
            'permissions' => $this->articlePermissions($request),
            // Día correspondiente al reloj editorial persistente de TRAMA.
            'referenceDate' => $this->editorialNow()->format('Y-m-d'),
            // Fecha y hora mínimas y máximas permitidas para programar una publicación.
            'scheduleWindow' => $this->scheduleWindow(),
            // Una noticia nueva todavía no posee devoluciones editoriales.
            'feedback' => [],
            // Una noticia nueva todavía no posee historial de versiones.
            'revisions' => [],
        ]);
    }

    /**
     * Crea una noticia, sincroniza etiquetas, portada y primera revisión.
     */
    public function store(
        ArticleRequest $request,
        ArticleWorkflowService $workflow,
        ArticleCoverProcessor $coverProcessor,
    ): RedirectResponse {
        $requestedStatus =
            $request->string('status')->toString() ?: 'draft';

        $failure = $this->operationFailureDetails(
            $requestedStatus
        );

        $newCover = null;

        try {
            // El request ya validó campos obligatorios, permisos básicos y formato.
            $data = $this->prepareArticleData($request);
            $coverAsset = null;

            if ($request->hasFile('cover_image_file')) {
                $newCover = $this->processCover(
                    $coverProcessor,
                    $request->file('cover_image_file')->getPathname(),
                    $data['slug']
                );

                $data = [
                    ...$data,
                    ...$this->coverAttributes(
                        $newCover,
                        $data['title']
                    ),
                ];

                $coverAsset = $this->coverAssetPayload(
                    $newCover,
                    $data['title'],
                    $request->user()->id
                );
            }

            $article = $workflow->create(
                $data,
                array_map(
                    'intval',
                    $request->input('tag_ids', [])
                ),
                $request->user(),
                $coverAsset,
            );
        } catch (Throwable $exception) {
            /*
            * Si se creó una portada pero la noticia no pudo guardarse,
            * elimina el archivo que ya no posee una referencia válida.
            */
            $this->deleteUploadedCover($newCover);

            $this->operationFailures->fail(
                exception: $exception,
                area: 'articles',
                operation: $failure['operation'],
                userMessage: $failure['message'],
                context: [
                    'controller' => class_basename(self::class),
                    'method' => __FUNCTION__,
                    'user_id' => $request->user()?->id,
                    'requested_status' => $requestedStatus,
                ],
            );
        }

        return redirect()
            ->route('admin.articles.edit', $article)
            ->with(
                'status',
                $this->successMessage(
                    $article->status,
                    created: true
                )
            );
    }

    /**
     * Muestra contenido, configuración, devoluciones e historial de versiones.
     */
    public function edit(Request $request, Article $article): Response
    {
        // Primero se valida que el usuario pueda ver esta noticia.
        $this->authorizeArticleView($request, $article);

        // Se cargan relaciones que la pantalla necesita: autor, sección,
        // etiquetas, devoluciones editoriales e historial de revisiones.
        $article->load([
            'author',
            'category',
            'tags',
            'reviewFeedback.returnedBy:id,name',
            'reviewFeedback.resolvedBy:id,name',
            'revisions.user:id,name',
            'revisions.restoredFrom:id,created_at',
        ]);

        // Convierte los cuerpos antiguos en texto plano a HTML estructurado y seguro.
        $sanitizer = app(HtmlSanitizer::class);

        // El modo solo lectura permite consultar una noticia cerrada sin habilitar cambios.
        return TramaBridge::render('Admin/Articles/Form', [
            'article' => (new AdminArticleResource($article))->resolve(),
            'categories' => $this->formCategories($article),
            'tags' => $this->formTags($article),
            'mode' => 'edit',
            // El editor puede modificar las noticias a las que tiene acceso.
            //
            // El periodista puede editar únicamente sus noticias cuando estén
            // en un estado habilitado, como borrador o devuelta para corrección.
            'readOnly' => ! $request->user()->canReviewArticles()
                && ! in_array(
                    $article->status,
                    Article::JOURNALIST_EDITABLE_STATUSES,
                    true
                ),
            'statusOptions' => $this->statusOptions($request, $article),
            // Permisos del usuario dentro del módulo de noticias.
            'permissions' => $this->articlePermissions($request),         
            // Fecha editorial configurada utilizada por TRAMA para mostrar fechas.
            'referenceDate' => $this->editorialNow()->format('Y-m-d'),
            // Límites de fecha y hora permitidos para programar o reprogramar.
            'scheduleWindow' => $this->scheduleWindow(),
            // Devoluciones editoriales asociadas a la noticia,
            // ordenadas desde la más reciente e incluyendo quién las realizó.
            'feedback' => $article->reviewFeedback
                ->sortByDesc('id')
                ->map(fn ($feedback) => [
                    'id' => $feedback->id,
                    'message' => $feedback->message,
                    'returned_by' => $feedback->returnedBy?->name,
                    'resolved_by' => $feedback->resolvedBy?->name,
                    'resolved_at' => $feedback->resolved_at?->toISOString(),
                    'created_at' => $feedback->created_at?->toISOString(),
                    'is_open' => $feedback->resolved_at === null,
                ])->values(),
                'revisions' => $article->revisions
                    ->sortByDesc('id')
                    ->map(function (ArticleRevision $revision) use ($sanitizer): array {
                        $snapshot = $revision->snapshot ?? [];

                        // Las revisiones antiguas pueden guardar el cuerpo como texto plano.
                        // Se convierte a HTML para conservar párrafos y saltos de línea.
                        if (isset($snapshot['body'])) {
                            $snapshot['body'] = $sanitizer->sanitize(
                                (string) $snapshot['body']
                            );
                        }

                        return [
                            'id' => $revision->id,
                            'action' => $revision->action,
                            'status_from' => $revision->status_from,
                            'status_to' => $revision->status_to,
                            'snapshot' => $snapshot,
                            'changed_fields' => $revision->changed_fields ?? [],
                            'restored_from_revision_id' => $revision->restored_from_revision_id,
                            'user' => $revision->user?->only(['id', 'name']),
                            'created_at' => $revision->created_at?->toISOString(),
                        ];
                    })
                    ->values(),
        ]);
    }

    /**
     * Actualiza contenido, relaciones, portada y revisión de forma coherente.
     */
    public function update(
        ArticleRequest $request,
        Article $article,
        ArticleWorkflowService $workflow,
        ArticleCoverProcessor $coverProcessor,
    ): RedirectResponse {
        // Una noticia publicada, programada o ajena puede quedar bloqueada para periodistas.
        $this->authorizeArticleUpdate($request, $article);

        $requestedStatus =
        $request->string('status')->toString()
        ?: $article->status;

        $failure = $this->operationFailureDetails(
            $requestedStatus,
            $article,
        );

        $newCover = null;

        try {        
            /*
            * Detecta cuando un editor está tomando una decisión sobre una noticia
            * ajena que todavía se encuentra en revisión.
            *
            * Puede devolverla, programarla o publicarla, pero no modificar directamente
            * el contenido escrito por el periodista autor.
            */
            $isReviewingForeignArticle =
                $request->user()->canReviewArticles()
                && $article->author_id !== $request->user()->id
                && $article->status === 'review';
            
            $coverAsset = null;
            $removeCoverAsset = false;
            $data = $this->prepareArticleData($request, $article);

            if ($isReviewingForeignArticle) {
                /*
                * Desde la actualización general solamente se permiten las decisiones
                * de programar o publicar. La devolución utiliza su ruta específica
                * y exige una observación editorial.
                */
                abort_unless(
                    in_array($data['status'], ['scheduled', 'published'], true),
                    403,
                    'El contenido de una noticia ajena en revisión no puede modificarse.'
                );

                /*
                * Una petición manual tampoco puede reemplazar ni quitar la portada
                * mientras el editor revisa contenido escrito por otra persona.
                */
                abort_if(
                    $request->hasFile('cover_image_file')
                    || $request->boolean('remove_cover_image'),
                    403,
                    'La portada de una noticia ajena en revisión no puede modificarse.'
                );

               /*
                * Conserva el contenido y la configuración pertenecientes al periodista.
                *
                * Las marcas "Urgente" y "Destacada" no se restauran desde la noticia porque son
                * decisiones editoriales que el editor puede tomar durante la revisión.
                *
                * El editor puede decidir el nuevo estado, la fecha de publicación o
                * programación y las marcas editoriales de portada.
                */
                $data = array_replace($data, [
                    'category_id' => $article->category_id,
                    'title' => $article->title,
                    'subtitle' => $article->subtitle,
                    'excerpt' => $article->excerpt,
                    'body' => $article->body,
                    'reading_time' => $article->reading_time,
                   /*
                    * Al programar o publicar por primera vez, reemplaza el slug interno
                    * usando el título real guardado por el periodista.
                    */
                    'slug' => Str::startsWith($article->slug, 'borrador-')
                        ? $this->uniqueSlugForTitle($article->title)
                        : $article->slug,
                ]);
            }

           /*
            * Comprueba si la noticia abandonó el nombre interno "borrador-UUID"
            * y recibió el nombre definitivo generado desde el título.
            */
            $changedToFinalName =
                Str::startsWith($article->slug, 'borrador-')
                && ! Str::startsWith($data['slug'], 'borrador-');

            // Comprueba si el usuario cargó una nueva imagen de portada.
            if ($request->hasFile('cover_image_file')) {
               /*
                * Procesa la nueva portada y utiliza el identificador actual
                * de la noticia para generar el nombre del archivo.
                */
                $newCover = $this->processCover(
                    $coverProcessor,
                    $request->file('cover_image_file')->getPathname(),
                    $data['slug']
                );

                // Actualiza la ruta de la portada y sus atributos.
                $data = [
                    ...$data,
                    ...$this->coverAttributes(
                        $newCover,
                        $data['title']
                    ),
                ];

                // Prepara el registro de la nueva portada para media_assets.
                $coverAsset = $this->coverAssetPayload(
                    $newCover,
                    $data['title'],
                    $request->user()->id
                );
            } elseif ($request->boolean('remove_cover_image')) {
                // La eliminación de portada limpia también crédito, origen y licencia.
                $data = [
                    ...$data,
                    ...$this->coverAttributes(null, null),
                ];

                $removeCoverAsset = true;
            } elseif (
                $changedToFinalName
                && filled($article->cover_image)
            ) {
               /*
                * Si la noticia se programa o publica sin cargar otra imagen,
                * copia la portada actual utilizando un nombre basado en el título.
                */
                $newCover = $this->copyCoverWithFinalName(
                    $coverProcessor,
                    $article->cover_image,
                    $data['slug']
                );

                // La copia pasa a ser la portada actual de la noticia.
                $data['cover_image'] = $newCover;
                $data['cover_alt'] = $data['title'];

                // Registra la copia como la portada actual dentro de media_assets.
                $coverAsset = $this->coverAssetPayload(
                    $newCover,
                    $data['title'],
                    $request->user()->id
                );
            }

            /*
            * Durante la revisión de una noticia ajena se conservan las etiquetas
            * originales aunque una petición manual intente reemplazarlas.
            */
            $tagIds = $isReviewingForeignArticle
                ? $article->tags()
                    ->pluck('tags.id')
                    ->map(fn ($id): int => (int) $id)
                    ->all()
                : array_map(
                    'intval',
                    $request->input('tag_ids', [])
                );

            // El servicio actualiza contenido, etiquetas, portada y revisión en una transacción.
            $updated = $workflow->update(
                $article,
                $data,
                $tagIds,
                $request->user(),
                coverAsset: $coverAsset,
                removeCoverAsset: $removeCoverAsset,
            );
        
        } catch (Throwable $exception) {
            /*
            * La portada nueva o la copia con nombre definitivo no deben quedar
            * en el disco cuando la actualización de la noticia fue revertida.
            */
            $this->deleteUploadedCover($newCover);

            $this->operationFailures->fail(
                exception: $exception,
                area: 'articles',
                operation: $failure['operation'],
                userMessage: $failure['message'],
                context: [
                    'controller' => class_basename(self::class),
                    'method' => __FUNCTION__,
                    'article_id' => $article->id,
                    'user_id' => $request->user()?->id,
                    'current_status' => $article->status,
                    'requested_status' => $requestedStatus,
                ],
            );
        }

        return back()->with('status', $this->successMessage($updated->status));
    }

    /**
     * Archiva una noticia sin eliminar su contenido ni su historial.
     */
    public function destroy(
        Request $request,
        Article $article,
        ArticleWorkflowService $workflow,
    ): RedirectResponse {
        abort_unless(
            $request->user()?->canReviewArticles(),
            403,
            'Solo un editor puede archivar noticias.'
        );

        try {
            $workflow->archive(
                $article,
                $request->user()
            );
        } catch (Throwable $exception) {
            $this->operationFailures->fail(
                exception: $exception,
                area: 'articles',
                operation: 'archive_article',
                userMessage: 'No se pudo archivar la noticia. No se realizaron cambios. Intentá nuevamente.',
                context: [
                    'controller' => class_basename(self::class),
                    'method' => __FUNCTION__,
                    'article_id' => $article->id,
                    'user_id' => $request->user()?->id,
                    'current_status' => $article->status,
                ],
            );
        }

        return redirect()
            ->route('admin.articles.index')
            ->with('status', 'Noticia archivada.');
    }

    /**
     * Elimina definitivamente un borrador o una noticia devuelta.
     */
    public function deletePermanently(
        Request $request,
        Article $article,
        ArticleWorkflowService $workflow,
    ): RedirectResponse {
        $isReturnedArticle =
            $article->status === 'needs_changes';

        try {
            $deletedStatus = $workflow->deletePermanently(
                $article,
                $request->user()
            );
        } catch (Throwable $exception) {
            $this->operationFailures->fail(
                exception: $exception,
                area: 'articles',
                operation: $isReturnedArticle
                    ? 'discard_returned_article'
                    : 'delete_draft',
                userMessage: $isReturnedArticle
                    ? 'No se pudo descartar la noticia. No se realizaron cambios. Intentá nuevamente.'
                    : 'No se pudo eliminar el borrador. No se realizaron cambios. Intentá nuevamente.',
                context: [
                    'controller' => class_basename(self::class),
                    'method' => __FUNCTION__,
                    'article_id' => $article->id,
                    'user_id' => $request->user()?->id,
                    'current_status' => $article->status,
                ],
            );
        }

        $message = $deletedStatus === 'needs_changes'
            ? 'Noticia descartada.'
            : 'Borrador eliminado.';

        return redirect()
            ->route('admin.articles.index')
            ->with('status', $message);
    }

    /**
     * Prepara los datos de la noticia antes de crearla o actualizarla.
     *
     * @return array<string, mixed>
     */
    private function prepareArticleData(ArticleRequest $request, ?Article $article = null): array
    {
        // Se eliminan campos auxiliares del formulario que no pertenecen a la tabla articles.
        $data = Arr::except($request->validated(), [
            'tag_ids',
            'cover_image_file',
            'remove_cover_image',
            'slug',
        ]);

        /*
        * Obtiene el título y el estado que tendrá la noticia
        * después de completar este guardado.
        */
        $title = $data['title'] ?? null;
        $status = (string) ($data['status'] ?? 'draft');

        // Noticia nueva
        if ($article === null) {
            /*
            * Si un editor crea la noticia directamente como programada o publicada,
            * genera inmediatamente el identificador definitivo desde el título.
            *
            * En borrador o revisión conserva "borrador-UUID" porque todavía
            * no ingresó a la publicación definitiva.
            */
            $data['slug'] = in_array($status, ['scheduled', 'published'], true)
                // Si la noticia se crea programada o publicada, genera el identificador definitivo desde el título.
                ? $this->uniqueSlugForTitle($title)
                // Si la noticia se crea como borrador o revisión, mantiene un identificador interno "borrador-UUID".
                : 'borrador-'.Str::uuid()->toString();
        } elseif (
            // Cuando la noticia se programa o publica por primera vez,
            // se reemplaza "borrador-UUID" por un identificador generado desde el título.
            // Más adelante, ese identificador también se usará como nombre base de la portada.
            Str::startsWith($article->slug, 'borrador-')
            && in_array($status, ['scheduled', 'published'], true)
        ) {            
            // Genera el identificador definitivo a partir del título de la noticia.
            $data['slug'] = $this->uniqueSlugForTitle($title);
        } else {
            // En cualquier otro caso conserva el identificador actual.
            $data['slug'] = $article->slug;
        }

        // Solo el editor puede decidir qué noticias aparecen como urgentes
        // o destacadas dentro de la portada del portal.
        $canManageHomepage = $request->user()->canReviewArticles();

        $data['is_breaking'] =
            $canManageHomepage
            && $request->boolean('is_breaking');

        $data['is_featured'] =
            $canManageHomepage
            && $request->boolean('is_featured');

        // El cuerpo puede ser null mientras la noticia sea un borrador.
        $data['reading_time'] = max(
            2,
            (int) ceil(
                str_word_count(
                    strip_tags((string) ($data['body'] ?? ''))
                ) / 220
            )
        );

        // El estado define qué fecha queda activa y evita combinaciones contradictorias.
        if ($data['status'] === 'published') {
            $data['published_at'] = $data['published_at'] ?? $this->editorialNow();
            $data['scheduled_at'] = null;
        } elseif ($data['status'] === 'scheduled') {
            $data['published_at'] = null;
        } else {
            $data['published_at'] = null;
            $data['scheduled_at'] = null;
        }

        return $data;
    }

    /**
     * Devuelve la ventana de fechas disponible para programar noticias.
     *
     * TRAMA usa el reloj editorial completo. El mínimo conserva tanto el día
     * ficticio como la hora persistente de la sesión; nunca se combina el 19/07
     * con la hora real del equipo que abre el proyecto.
     *
     * @return array{min: string, max: string}
     */
    private function scheduleWindow(): array
    {
        $minimum = $this->editorialNow()->setSecond(0);

        return [
            'min' => $minimum->format('Y-m-d\TH:i'),
            'max' => TramaClock::referenceDate()
                ->addDays(30)
                ->endOfDay()
                ->format('Y-m-d\TH:i'),
        ];
    }

    /**
     * Devuelve el momento actual del reloj editorial persistente de TRAMA.
     *
     * La fecha y la hora se resuelven juntas para que formularios, publicaciones
     * y programaciones compartan exactamente la misma cronología de demostración.
     */
    private function editorialNow(): CarbonImmutable
    {
        return TramaClock::now();
    }

    /**
     * Genera el identificador definitivo de la noticia a partir de su título.
     *
     * Este identificador se utiliza en la URL de la noticia y como nombre base
     * de su imagen de portada en formato WebP.
     *
     * Se genera cuando la noticia se programa o publica por primera vez.
     *
     * @throws ValidationException Si otra noticia ya utiliza el mismo identificador.
     */
    private function uniqueSlugForTitle(string $title): string
    {
        /*
        * Str::slug(), un método de Laravel, convierte el título en un identificador
        * apto para usar en una URL: pasa el texto a minúsculas, reemplaza los
        * espacios por guiones y elimina o transforma caracteres problemáticos.
        *
        * Ejemplo:
        *      "América Latina coordina una agenda climática"
        *      se convierte en:
        *      "america-latina-coordina-una-agenda-climatica"
        *
        * Si el resultado queda vacío, el operador ?: utiliza "noticia"
        * como valor de respaldo.
        *
        * Str::limit() limita el resultado a 210 caracteres y el tercer
        * parámetro vacío evita agregar puntos suspensivos al recortarlo.
        */
        $base = Str::limit(
            Str::slug($title) ?: 'noticia',
            210,
            ''
        );

        /*
        * Comprueba que ninguna otra noticia, incluso eliminada, utilice
        * el mismo identificador generado desde el título.
        *
        * Si ya existe, se detiene la publicación o programación y se muestra
        * el error en el campo del título.
        */
        if (
            Article::withTrashed()
                ->where('slug', $base)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'title' => 'Ya existe una noticia con un título que genera la misma URL.',
            ]);
        }

        return $base;
    }

    /**
     * Procesa una portada y convierte cualquier rechazo en un error del formulario.
     *
     * @throws ValidationException
     */
    private function processCover(
        ArticleCoverProcessor $coverProcessor,
        string $sourcePath,
        string $articleSlug
    ): string {
        try {
            return $coverProcessor->process(
                $sourcePath,
                $articleSlug
            );
        } catch (RuntimeException $exception) {
            /*
            * Asocia el error al campo de portada para mostrarlo debajo de la imagen
            * en lugar de generar un error 500.
            */
            throw ValidationException::withMessages([
                'cover_image_file' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Copia la portada actual con el nombre definitivo generado desde el título.
     *
     * Convierte cualquier fallo del procesamiento en un error visible
     * debajo del campo de portada, evitando mostrar un error 500.
     *
     * @throws ValidationException
     */
    private function copyCoverWithFinalName(
        ArticleCoverProcessor $coverProcessor,
        string $currentCoverUrl,
        string $articleSlug
    ): string {
        try {
            return $coverProcessor->copyExistingCover(
                $currentCoverUrl,
                $articleSlug
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'cover_image_file' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Devuelve los atributos de portada almacenados en articles.
     *
     * @return array<string, mixed>
     */
    private function coverAttributes(?string $path, ?string $alt): array
    {
        // La portada subida desde el CMS no trae atribución externa por defecto.
        return [
            'cover_image' => $path,
            'cover_alt' => $alt,
            'image_credit' => null,
            'image_source_url' => null,
            'image_license' => null,
        ];
    }

    /**
     * Prepara metadatos de la portada para media_assets.
     *
     * @return array<string, mixed>
     */
    private function coverAssetPayload(string $path, ?string $alt, int $userId): array
    {
        // Se guarda tamaño cuando PHP puede leer la imagen; si falla, quedan nulos.
        $dimensions = @getimagesize(public_path(ltrim($path, '/'))) ?: [null, null];

        return [
            'uploaded_by' => $userId,
            'disk' => 'public',
            'usage' => 'cover',
            'path' => $path,
            'alt_text' => $alt,
            'credit' => null,
            'source_url' => null,
            'license' => null,
            'width' => $dimensions[0],
            'height' => $dimensions[1],
        ];
    }

    /**
     * Elimina una portada nueva cuando falla el guardado de la noticia.
     */
    private function deleteUploadedCover(?string $path): void
    {
        $coverUrl = rtrim(
            (string) config('trama.articles.cover_url'),
            '/'
        ).'/';

        // Solo permite eliminar archivos pertenecientes a la carpeta de portadas.
        if (! $path || ! Str::startsWith($path, $coverUrl)) {
            return;
        }

        $file =
            (string) config('trama.articles.cover_path')
            .DIRECTORY_SEPARATOR
            .basename($path);

        if (File::exists($file)) {
            File::delete($file);
        }
    }

    /**
     * Controla si el usuario puede abrir una noticia.
     *
     * El autor siempre puede consultar su propia noticia.
     *
     * Un editor puede consultar noticias ajenas solamente cuando
     * pertenecen a un periodista y ya ingresaron al flujo editorial.
     *
     * Las noticias pertenecientes a otros editores nunca son visibles.
     */
    private function authorizeArticleView(
        Request $request,
        Article $article
    ): void {
        $user = $request->user();

        /*
        * El autor siempre puede consultar su propia noticia.
        */
        if ($article->author_id === $user->id) {
            return;
        }

        /*
        * Se necesita conocer el rol del autor para diferenciar
        * noticias de periodistas y noticias de otros editores.
        */
        $article->loadMissing('author:id,role');

        /*
        * El editor puede consultar noticias ajenas solamente
        * cuando fueron escritas por un periodista.
        */
        if (
            $user->canReviewArticles()
            && $article->author?->role === 'journalist'
            && in_array(
                $article->status,
                [
                    'review',
                    'scheduled',
                    'published',
                    'archived',
                ],
                true
            )
        ) {
            return;
        }

        abort(403, 'No tenés permisos para consultar esta noticia.');
    }

    /**
     * Controla si el usuario puede modificar una noticia.
     *
     * Un editor puede modificar sus propias noticias y las noticias
     * de periodistas que estén bajo control editorial.
     *
     * Las noticias pertenecientes a otros editores nunca pueden
     * modificarse desde otra cuenta editorial.
     */
    private function authorizeArticleUpdate(
        Request $request,
        Article $article
    ): void {
        $user = $request->user();

        /*
        * Las noticias publicadas o archivadas no admiten
        * edición normal de contenido.
        */
        if (in_array($article->status, ['published', 'archived'], true)) {
            abort(403, 'Esta noticia ya no admite edición de contenido.');
        }

        /*
        * El editor puede modificar sus propias noticias.
        */
        if (
            $user->canReviewArticles()
            && $article->author_id === $user->id
        ) {
            return;
        }

        $article->loadMissing('author:id,role');

        /*
        * El editor puede trabajar con noticias ajenas solamente
        * cuando pertenecen a un periodista y están bajo
        * control editorial.
        */
        if (
            $user->canReviewArticles()
            && $article->author?->role === 'journalist'
            && in_array(
                $article->status,
                [
                    'review',
                    'scheduled',
                ],
                true
            )
        ) {
            return;
        }

        /*
        * El periodista puede modificar solamente sus propias
        * noticias en estados habilitados.
        */
        if (
            $article->author_id === $user->id
            && in_array(
                $article->status,
                Article::JOURNALIST_EDITABLE_STATUSES,
                true
            )
        ) {
            return;
        }

        abort(403, 'Esta noticia no está disponible para edición.');
    }

    /**
     * Devuelve las categorías utilizadas por el filtro del listado editorial.
     *
     * Se incluyen categorías activas e inactivas porque una noticia histórica
     * puede seguir asociada a una categoría que ya no se utiliza para publicaciones
     * nuevas.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function filterCategories(): array
    {
        return Category::query()
            /*
            * Respeta primero el orden configurado dentro del panel.
            */
            ->orderBy('sort_order')

            /*
            * Utiliza el nombre como desempate cuando dos categorías tienen
            * el mismo número de orden.
            */
            ->orderBy('name')

            /*
            * Solamente obtiene los campos necesarios para construir el selector.
            */
            ->get([
                'id',
                'name',
            ])

            /*
            * Convierte cada categoría en la estructura simple que necesita Vue.
            */
            ->map(
                static fn (Category $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                ],
            )
            ->values()
            ->all();
    }

    /**
     * Devuelve categorías disponibles para crear o editar una noticia.
     *
     * Al editar se incluye también la categoría actual aunque esté inactiva,
     * para que el formulario pueda conservar una clasificación histórica sin
     * volver a ofrecerla en noticias nuevas.
     *
     * @return array<int, mixed>
     */
    private function formCategories(?Article $article = null): array
    {
        // Una categoría inactiva solo aparece si ya pertenece a la noticia editada.
        $categories = Category::query()
            ->where(function ($query) use ($article): void {
                $query->where('is_active', true);

                if ($article !== null) {
                    $query->orWhere('id', $article->category_id);
                }
            })
            // Orden principal configurado en el panel.
            ->orderBy('sort_order')
            // Desempate por nombre para que el selector sea estable.
            ->orderBy('name')
            ->get();

        return CategoryResource::collection($categories)->resolve();
    }

    /**
     * Devuelve etiquetas activas y las etiquetas históricas de la noticia.
     *
     * @return array<int, array<string, mixed>>
     */
    private function formTags(?Article $article = null): array
    {
        $currentTagIds = $article?->tags()->pluck('tags.id')->all() ?? [];

        // Las etiquetas fusionadas o inactivas se ocultan, salvo que la noticia ya las tenga.
        $tags = Tag::query()
            ->select(['id', 'name', 'is_active', 'merged_into_id'])
            ->withCount('articles')
            ->where(function ($query) use ($currentTagIds): void {
                $query->where(function ($active): void {
                    $active->where('is_active', true)->whereNull('merged_into_id');
                });

                if ($currentTagIds !== []) {
                    $query->orWhereIn('id', $currentTagIds);
                }
            })
            ->orderBy('name')
            ->get();

        /*
         * La categoría no restringe qué etiqueta puede usarse. Estos conteos
         * históricos sirven únicamente para ordenar las sugerencias del buscador
         * de chips cuando el periodista elige una categoría.
         */
        $usageByTag = DB::table('article_tag')
            ->join('articles', 'articles.id', '=', 'article_tag.article_id')
            ->whereIn('article_tag.tag_id', $tags->pluck('id'))
            ->whereNotNull('articles.category_id')
            ->selectRaw('article_tag.tag_id, articles.category_id, COUNT(*) as usage_count')
            ->groupBy('article_tag.tag_id', 'articles.category_id')
            ->get()
            ->groupBy('tag_id')
            ->map(fn ($rows) => $rows->mapWithKeys(
                fn ($row) => [(string) $row->category_id => (int) $row->usage_count]
            )->all());

        return $tags
            ->map(fn (Tag $tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'is_active' => (bool) $tag->is_active,
                'merged' => $tag->merged_into_id !== null,
                'usage_total' => (int) $tag->articles_count,
                'usage_by_category' => $usageByTag->get($tag->id, []),
            ])
            ->values()
            ->all();
    }

    /**
     * Estados visibles según rol y situación actual de la noticia.
     *
     * @return array<int, array<string, string>>
     */
    private function statusOptions(Request $request, ?Article $article = null): array
    {
        // Solo el editor puede acceder a estados editoriales
        // como programada, publicada o archivada.
        if ($request->user()->canReviewArticles()) {
            /*
            * Al crear una noticia, el editor puede guardarla como borrador,
            * programarla o publicarla directamente.
            *
            * "En revisión", "Devuelta" y "Archivada" corresponden
            * a noticias que ya existen.
            */
            if ($article === null) {
                return $this->mapStatusOptions([
                    'draft',
                    'scheduled',
                    'published',
                ]);
            }

            // La devolución requiere una observación y se ejecuta desde su acción
            // específica. Solo se conserva en el selector cuando la noticia ya
            // está devuelta y se están guardando otros cambios.
            $allowed = array_values(array_filter(
                Article::STATUSES,
                fn (string $status): bool => $status !== 'needs_changes',
            ));

            if ($article->status === 'needs_changes') {
                $allowed[] = 'needs_changes';
            }
        } elseif ($article?->status === 'needs_changes') {
            // Una noticia devuelta debe mantenerse en ese estado hasta que se reenvíe.
            $allowed = ['needs_changes'];
        } else {
            // El periodista solo puede guardar borrador o enviar a revisión.
            $allowed = ['draft', 'review'];
        }

        return $this->mapStatusOptions($allowed);
    }

    /**
     * Construye las opciones de estado que se muestran como filtros
     * en el listado de noticias, según el rol del usuario autenticado.
     *
     * Los periodistas pueden filtrar sus noticias devueltas para corregirlas.
     * Los editores no reciben ese filtro porque las noticias devueltas
     * dejan temporalmente de formar parte de su cola editorial.
     *
     * @return array<int, array<string, string>>
     */
    private function allStatusOptions(Request $request): array
    {
        $statuses = Article::STATUSES;

        // Si el usuario puede revisar noticias, se trata de un editor.
        // En ese caso se oculta el estado "Devuelta para corrección"
        // porque esas noticias vuelven temporalmente al periodista autor.
        if ($request->user()->canReviewArticles()) {
            $statuses = array_values(
                // Conserva todos los estados excepto "needs_changes".
                array_filter(
                    $statuses,
                    fn (string $status): bool => $status !== 'needs_changes',
                )
            );
        }

        return collect($statuses)
            ->map(fn (string $status) => [
                'value' => $status,

                // Estado de una noticia individual:
                // Publicada, Archivada, Programada, etc.
                'label' => self::STATUS_LABELS[$status],

                // Texto exclusivo del filtro, que representa grupos de noticias.
                'filter_label' => match ($status) {
                    'scheduled' => 'Programadas',
                    'published' => 'Publicadas',
                    'archived' => 'Archivadas',
                    default => self::STATUS_LABELS[$status],
                },
            ])
            ->values()
            ->all();
    }

    /**
     * Convierte códigos de estado en opciones legibles para Vue.
     *
     * @param  list<string>  $statuses
     * @return array<int, array<string, string>>
     */
    private function mapStatusOptions(array $statuses): array
    {
        // Vue recibe value para enviar al backend y label para mostrar al usuario.
        return collect($statuses)
            ->map(fn (string $status) => [
                'value' => $status,
                'label' => self::STATUS_LABELS[$status],
            ])
            ->values()
            ->all();
    }

    /**
     * Devuelve los permisos del usuario actual dentro del módulo editorial de noticias.
     *
     * @return array<string, mixed>
     */
    private function articlePermissions(Request $request): array
    {
        // Solo el editor puede revisar y tomar decisiones editoriales.
        $canReview = $request->user()->canReviewArticles();

        return [
            // Permite revisar, devolver, publicar y restaurar noticias.
            'can_review' => $canReview,
            // Permite activar o desactivar las marcas editoriales de portada:
            // Urgente y Destacada.
            'can_manage_homepage' => $canReview,
            // Identifica al usuario que está utilizando el formulario.
            'user_id' => $request->user()->id,
            // Explica qué noticias puede ver en el listado.
            'scope_label' => $canReview
                ? 'Mis noticias y las del equipo'
                : 'Mis noticias',
        ];
    }

    /**
     * Define el código interno y el mensaje público de la operación solicitada.
     *
     * @return array{operation: string, message: string}
     */
    private function operationFailureDetails(
        string $requestedStatus,
        ?Article $article = null,
    ): array {
        if ($requestedStatus === 'review') {
            return $article?->status === 'needs_changes'
                ? [
                    'operation' => 'resubmit_article',
                    'message' => 'No se pudo reenviar la noticia a revisión. No se realizaron cambios. Intentá nuevamente.',
                ]
                : [
                    'operation' => 'submit_article',
                    'message' => 'No se pudo enviar la noticia a revisión. No se realizaron cambios. Intentá nuevamente.',
                ];
        }

        if ($requestedStatus === 'scheduled') {
            return $article?->status === 'scheduled'
                ? [
                    'operation' => 'save_scheduled_article',
                    'message' => 'No se pudieron guardar los cambios de la noticia programada. No se realizaron cambios. Intentá nuevamente.',
                ]
                : [
                    'operation' => 'schedule_article',
                    'message' => 'No se pudo programar la noticia. No se realizaron cambios. Intentá nuevamente.',
                ];
        }

        return match ($requestedStatus) {
            'draft' => [
                'operation' => 'save_draft',
                'message' => 'No se pudo guardar el borrador. No se realizaron cambios. Intentá nuevamente.',
            ],

            'needs_changes' => [
                'operation' => 'save_corrections',
                'message' => 'No se pudieron guardar las correcciones. No se realizaron cambios. Intentá nuevamente.',
            ],

            'published' => [
                'operation' => 'publish_article',
                'message' => 'No se pudo publicar la noticia. No se realizaron cambios. Intentá nuevamente.',
            ],

            default => [
                'operation' => 'save_article',
                'message' => 'No se pudo guardar la noticia. No se realizaron cambios. Intentá nuevamente.',
            ],
        };
    }

    /**
     * Devuelve el mensaje de confirmación correspondiente
     * al estado en el que quedó guardada la noticia.
     *
     * Si la noticia permanece como borrador, distingue
     * entre una creación nueva y una actualización.
     */
    private function successMessage(string $status, bool $created = false): string
    {
        return match ($status) {
            'review' => 'Noticia enviada a revisión editorial.',
            'published' => 'Noticia publicada.',
            'scheduled' => 'Noticia programada.',
            'needs_changes' => 'Correcciones guardadas.',
            default => $created ? 'Noticia guardada como borrador.' : 'Noticia actualizada.',
        };
    }
}
