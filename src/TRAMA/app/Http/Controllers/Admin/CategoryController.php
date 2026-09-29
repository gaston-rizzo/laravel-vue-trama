<?php

/* ============================================================================
 * CONTROLLER: CategoryController.php
 * ============================================================================
 *
 * Administra las categorías editoriales de TRAMA desde el panel.
 *
 * Permite listar, filtrar, crear, editar, activar, desactivar y eliminar
 * categorías, además de procesar sus imágenes y coordinar su orden editorial.
 *
 * Las categorías nuevas se crean activas para que puedan utilizarse de inmediato
 * en el flujo de noticias. Su aparición en el portal público se determina por
 * separado y requiere que la categoría esté completa y tenga al menos una
 * noticia publicada disponible para lectura.
 * ============================================================================ */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CategoryIndexRequest;
use App\Http\Requests\Admin\CategoryRequest;
use App\Models\Category;
use App\Services\Categories\CategoryEditorialOrderService;
use App\Services\Images\CategoryCoverProcessor;
use App\Support\OperationFailureHandler;
use App\Support\TramaBridge;
use App\Support\TramaClock;
use App\Support\TramaLog;

use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use Inertia\Response;

use RuntimeException;
use Throwable;

class CategoryController extends Controller
{
    public function __construct(
        private readonly OperationFailureHandler $operationFailureHandler,
        private readonly CategoryCoverProcessor $coverProcessor,
        private readonly CategoryEditorialOrderService $categoryOrder,
    ) {
    }

    /**
     * Muestra la grilla paginada con búsqueda, estado, orden y noticias publicadas.
     */
    public function index(CategoryIndexRequest $request): Response
    {
        $filters = [
            'q' => '',
            'status' => '',
            'sort' => 'sort_order',
            'direction' => 'asc',
            'per_page' => 25,
            ...$request->validated(),
        ];

        $filters['q'] = (string) ($filters['q'] ?? '');
        $filters['status'] = (string) ($filters['status'] ?? '');
        $filters['sort'] = (string) ($filters['sort'] ?? 'sort_order');
        $filters['direction'] = (string) ($filters['direction'] ?? 'asc');
        $filters['per_page'] = (int) ($filters['per_page'] ?? 25);

        $payload = [
            'data' => [],
            'pagination' => [
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $filters['per_page'],
                'total' => 0,
                'from' => null,
                'to' => null,
                'links' => [],
            ],
        ];
        $publishedCount = 0;
        $editorialPositionCount = 0;
        $loadError = '';

        try {
            $editorialNow = TramaClock::now();

            // El total de noticias se conserva únicamente para impedir borrados con historial.
            $query = Category::query()
                ->withCount('articles')
                // Este contador distingue contenido asociado de contenido realmente público.
                ->withCount([
                    'articles as published_articles_count' => function ($articles) use ($editorialNow): void {
                        $articles
                            ->where('status', 'published')
                            ->whereNotNull('published_at')
                            ->where('published_at', '<=', $editorialNow);
                    },
                ])
                ->when(
                    $filters['q'],
                    fn ($builder, $term) => $builder->where('name', 'like', "%{$term}%")
                )
                ->when(
                    $filters['status'] === 'published',
                    fn ($builder) => $builder
                        ->where('is_active', true)
                        ->whereHas('articles', function ($articles) use ($editorialNow): void {
                            $articles
                                ->where('status', 'published')
                                ->whereNotNull('published_at')
                                ->where('published_at', '<=', $editorialNow);
                        })
                )
                ->when(
                    $filters['status'] === 'active',
                    fn ($builder) => $builder
                        ->where('is_active', true)
                        ->whereDoesntHave('articles', function ($articles) use ($editorialNow): void {
                            $articles
                                ->where('status', 'published')
                                ->whereNotNull('published_at')
                                ->where('published_at', '<=', $editorialNow);
                        })
                )
                ->when(
                    $filters['status'] === 'inactive',
                    fn ($builder) => $builder->where('is_active', false)
                );

            // El ordenamiento real se ejecuta sobre todo el conjunto antes de paginar.
            if ($filters['sort'] === 'name') {
                $query->orderBy('name', $filters['direction'])
                    ->orderByRaw('sort_order IS NULL ASC')
                    ->orderBy('sort_order')
                    ->orderBy('id');
            } elseif ($filters['sort'] === 'published_articles_count') {
                $query->orderBy('published_articles_count', $filters['direction'])
                    ->orderBy('name')
                    ->orderBy('id');
            } elseif ($filters['sort'] === 'status') {
                // Estado derivado: PUBLICADA, ACTIVA e INACTIVA.
                $direction = $filters['direction'] === 'desc' ? 'DESC' : 'ASC';
                $query->orderByRaw(
                    "CASE
                        WHEN is_active = 0 THEN 3
                        WHEN EXISTS (
                            SELECT 1
                            FROM articles
                            WHERE articles.category_id = categories.id
                              AND articles.deleted_at IS NULL
                              AND articles.status = 'published'
                              AND articles.published_at IS NOT NULL
                              AND articles.published_at <= ?
                        ) THEN 1
                        ELSE 2
                    END {$direction}",
                    [$editorialNow],
                )
                    ->orderByRaw('sort_order IS NULL ASC')
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->orderBy('id');
            } else {
                // Las categorías sin posición pública quedan siempre al final.
                $query->orderByRaw('sort_order IS NULL ASC')
                    ->orderBy('sort_order', $filters['direction'])
                    ->orderBy('name')
                    ->orderBy('id');
            }

            $categories = $query
                ->paginate($filters['per_page'])
                ->withQueryString();

            $payload = [
                'data' => $categories->getCollection()
                    ->map(fn (Category $category) => $this->serializeCategory($category))
                    ->values()
                    ->all(),
                'pagination' => [
                    'current_page' => $categories->currentPage(),
                    'last_page' => $categories->lastPage(),
                    'per_page' => $categories->perPage(),
                    'total' => $categories->total(),
                    'from' => $categories->firstItem(),
                    'to' => $categories->lastItem(),
                    'links' => $categories->linkCollection()->toArray(),
                ],
            ];

            // PUBLICADA equivale exactamente a una categoría que participa del portal.
            $publishedCount = $this->categoryOrder->count();
            $editorialPositionCount = $publishedCount;
        } catch (Throwable $exception) {
            if (
                $exception instanceof QueryException
                && str_contains($exception->getMessage(), 'SQLSTATE[HY000] [2002]')
            ) {
                throw $exception;
            }

            TramaLog::error('No se pudo cargar la administración de categorías.', [
                'controller' => 'CategoryController',
                'method' => 'index',
                'status' => 500,
                'user_id' => $request->user()?->id,
                'filters' => $filters,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
            ]);

            $loadError = 'No pudimos cargar las categorías en este momento. Intentá nuevamente.';
        }

        return TramaBridge::render('Admin/Categories/Index', [
            'categories' => $payload,
            'filters' => $filters,
            'publishedCount' => $publishedCount,
            'publishedLimit' => CategoryEditorialOrderService::PUBLIC_LIMIT,
            'editorialPositionCount' => $editorialPositionCount,
            'loadError' => $loadError,
        ]);
    }

    /**
     * Crea una categoría activa y procesa su portada antes de persistirla.
     */
    public function store(CategoryRequest $request): RedirectResponse
    {
        $createdCover = null;

        try {
            DB::transaction(function () use ($request, &$createdCover): void {
                $data = $request->validated();

                // Toda categoría nueva queda disponible inmediatamente para Noticias.
                // El límite de 12 se aplica solo cuando la sección llega al portal público.
                $this->ensureCoverPathAvailable($data['name']);

                // El slug público se genera una sola vez y se conserva como URL histórica.
                $data['slug'] = $this->uniqueSlug($data['name']);
                $data['is_active'] = true;
                // Sin publicaciones todavía no existe una posición editorial pública.
                $data['sort_order'] = null;

                $image = $request->file('cover_image_file');
                unset($data['cover_image_file']);

                if (! $image instanceof UploadedFile) {
                    throw ValidationException::withMessages([
                        'cover_image_file' => 'Seleccioná una imagen para la categoría.',
                    ]);
                }

                $createdCover = $this->processCategoryCover(
                    $image->getPathname(),
                    $data['name'],
                );
                $data['cover_image'] = $createdCover;

                Category::query()->create($data);
            }, attempts: 3);
        } catch (Throwable $exception) {
            // Si la base rechazó el alta después de crear el archivo, elimina el huérfano.
            if (
                is_string($createdCover)
                && ! Category::query()->where('cover_image', $createdCover)->exists()
            ) {
                $this->coverProcessor->deleteManaged($createdCover);
            }

            $this->operationFailureHandler->fail(
                $exception,
                'admin_categories',
                'create_category',
                'No pudimos crear la categoría. Intentá nuevamente.',
                ['user_id' => $request->user()?->id],
            );
        }

        return back()->with('status', 'Categoría creada.');
    }

    /**
     * Actualiza los datos visibles sin cambiar el slug histórico de la categoría.
     */
    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        $previousCover = null;
        $finalCover = null;
        $uploadedNewCover = false;

        try {
            DB::transaction(function () use (
                $request,
                $category,
                &$previousCover,
                &$finalCover,
                &$uploadedNewCover,
            ): void {
                $locked = Category::query()
                    ->whereKey($category->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $data = $request->validated();
                $requestedPosition = array_key_exists('sort_order', $data)
                    && $data['sort_order'] !== null
                        ? (int) $data['sort_order']
                        : null;
                unset($data['sort_order']);

                $previousCover = $locked->cover_image;

                // Evita que dos nombres diferentes terminen compartiendo el mismo WebP.
                $this->ensureCoverPathAvailable($data['name'], $locked->id);

                $image = $request->file('cover_image_file');
                unset($data['cover_image_file']);

                if ($image instanceof UploadedFile) {
                    $uploadedNewCover = true;
                    $finalCover = $this->processCategoryCover(
                        $image->getPathname(),
                        $data['name'],
                    );
                } elseif (filled($locked->cover_image)) {
                    // Si cambió el nombre, el archivo existente adopta el nuevo nombre.
                    $finalCover = $this->renameCategoryCover(
                        (string) $locked->cover_image,
                        $data['name'],
                    );
                } else {
                    throw ValidationException::withMessages([
                        'cover_image_file' => 'Seleccioná una imagen para la categoría.',
                    ]);
                }

                $data['cover_image'] = $finalCover;

                // is_active no se modifica desde este modal; se administra en la grilla.
                $locked->update($data);

                // La posición solo puede cambiar si la categoría participa del portal.
                if ($requestedPosition !== null) {
                    $this->categoryOrder->moveToPosition($locked->id, $requestedPosition);
                } else {
                    $this->categoryOrder->synchronize();
                }
            }, attempts: 3);

            // Una imagen nueva con nombre distinto deja obsoleto el archivo anterior.
            if (
                $uploadedNewCover
                && is_string($previousCover)
                && $previousCover !== ''
                && $previousCover !== $finalCover
            ) {
                $this->coverProcessor->deleteManaged($previousCover);
            }
        } catch (Throwable $exception) {
            $this->operationFailureHandler->fail(
                $exception,
                'admin_categories',
                'update_category',
                'No pudimos guardar los cambios de la categoría. Intentá nuevamente.',
                ['category_id' => $category->id, 'user_id' => $request->user()?->id],
            );
        }

        return back()->with('status', 'Categoría actualizada.');
    }

    /**
     * Activa o desactiva una categoría para su utilización editorial.
     */
    public function toggleActive(Request $request, Category $category): RedirectResponse
    {
        try {
            DB::transaction(function () use ($category): void {
                $locked = Category::query()
                    ->whereKey($category->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $willActivate = ! $locked->is_active;

                if ($willActivate) {
                    $this->ensureCategoryCompleteForActivation($locked);
                    // Si conserva noticias publicadas, reactivarla puede devolverla al portal.
                    $this->categoryOrder->ensureCanReactivate($locked);
                }

                // Al cambiar de estado se libera cualquier posición anterior. Si la
                // categoría vuelve a ser pública, synchronize() la agrega al final.
                $locked->update([
                    'is_active' => $willActivate,
                    'sort_order' => null,
                ]);

                $this->categoryOrder->synchronize(
                    $willActivate ? $locked->id : null,
                );
            }, attempts: 3);
        } catch (Throwable $exception) {
            $this->operationFailureHandler->fail(
                $exception,
                'admin_categories',
                'toggle_category',
                'No pudimos cambiar el estado de la categoría. Intentá nuevamente.',
                ['category_id' => $category->id, 'user_id' => $request->user()?->id],
            );
        }

        $category->refresh();

        return back()->with(
            'status',
            $category->is_active ? 'Categoría activada.' : 'Categoría desactivada.'
        );
    }

    /**
     * Elimina una categoría únicamente cuando no conserva noticias asociadas.
     */
    public function destroy(Request $request, Category $category): RedirectResponse
    {
        $coverToDelete = null;

        try {
            DB::transaction(function () use ($category, &$coverToDelete): void {
                // El bloqueo impide que se asocie una noticia mientras se decide el borrado.
                $locked = Category::query()
                    ->whereKey($category->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->articles()->exists()) {
                    throw ValidationException::withMessages([
                        'category' => 'La categoría tiene noticias asociadas y debe desactivarse.',
                    ]);
                }

                $coverToDelete = $locked->cover_image;
                $locked->delete();
            }, attempts: 3);

            $this->coverProcessor->deleteManaged($coverToDelete);
            $this->categoryOrder->synchronize();
        } catch (Throwable $exception) {
            $this->operationFailureHandler->fail(
                $exception,
                'admin_categories',
                'delete_category',
                'No pudimos eliminar la categoría. Intentá nuevamente.',
                ['category_id' => $category->id, 'user_id' => $request->user()?->id],
            );
        }

        return back()->with('status', 'Categoría eliminada.');
    }

    /** Serializa una fila con total histórico, publicadas y posición editorial pública. */
    private function serializeCategory(Category $category): array
    {
        $publishedCount = (int) ($category->published_articles_count ?? 0);

        $isPubliclyVisible = (bool) $category->is_active
            && $publishedCount > 0
            && filled($category->name)
            && filled($category->description)
            && filled($category->accent_color)
            && filled($category->cover_image);

        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'accent_color' => $category->accent_color,
            'cover_image' => $category->cover_image,
            'cover_image_url' => $this->categoryImageUrl($category->cover_image),
            // Las categorías fuera del portal no muestran un orden residual.
            'sort_order' => $isPubliclyVisible ? $category->sort_order : null,
            'is_active' => (bool) $category->is_active,
            // El estado visible se deriva para evitar guardar valores contradictorios.
            'status' => ! $category->is_active
                ? 'inactive'
                : ($isPubliclyVisible ? 'published' : 'active'),
            // Se conserva el total únicamente para impedir eliminar categorías con historial.
            'articles_count' => (int) $category->articles_count,
            // La grilla muestra exclusivamente noticias publicadas y visibles por fecha.
            'published_articles_count' => $publishedCount,
            'is_publicly_visible' => $isPubliclyVisible,
        ];
    }

    /** Impide reactivar categorías históricas que todavía tengan datos incompletos. */
    private function ensureCategoryCompleteForActivation(Category $category): void
    {
        if (
            blank($category->name)
            || blank($category->description)
            || blank($category->accent_color)
            || blank($category->cover_image)
        ) {
            throw ValidationException::withMessages([
                'operation' => 'Completá nombre, descripción, color e imagen antes de activar la categoría.',
            ]);
        }
    }

    /** Evita colisiones entre nombres distintos que generan el mismo archivo WebP. */
    private function ensureCoverPathAvailable(string $name, ?int $ignoreId = null): void
    {
        $targetUrl = $this->coverProcessor->publicUrlForName($name);
        $query = Category::query()->where('cover_image', $targetUrl);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'Ese nombre genera el mismo archivo de imagen que otra categoría.',
            ]);
        }
    }

    /** Convierte cualquier rechazo del procesador en un error del campo de imagen. */
    private function processCategoryCover(
        string $sourcePath,
        string $categoryName,
    ): string {
        try {
            return $this->coverProcessor->process($sourcePath, $categoryName);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'cover_image_file' => $exception->getMessage(),
            ]);
        }
    }

    /** Renombra la portada existente y muestra cualquier fallo debajo del campo. */
    private function renameCategoryCover(
        string $currentCoverUrl,
        string $categoryName,
    ): string {
        try {
            return $this->coverProcessor->renameExisting($currentCoverUrl, $categoryName);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'cover_image_file' => $exception->getMessage(),
            ]);
        }
    }

    /** Normaliza la ruta guardada para poder mostrar la imagen desde Vue. */
    private function categoryImageUrl(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        return '/'.ltrim(str_replace('\\', '/', $path), '/');
    }

    /** Genera un slug público único sin exponer el campo en el formulario. */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'categoria';
        $slug = $base;
        $suffix = 2;

        // Si ya existe, se agregan sufijos hasta encontrar una URL libre.
        while (Category::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
