<?php

/* ============================================================================
 * CONTROLLER: TagController.php
 * ============================================================================
 *
 * Administra etiquetas temáticas de TRAMA.
 *
 * Permite crear, editar, activar, desactivar, eliminar etiquetas sin uso y
 * fusionar duplicados mediante una operación transaccional.
 * ============================================================================ */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MergeTagRequest;
use App\Http\Requests\Admin\TagRequest;
use App\Http\Requests\Admin\TagIndexRequest;
use App\Models\Tag;
use App\Services\Editorial\TagMergeService;
use App\Support\OperationFailureHandler;
use App\Support\TramaBridge;
use App\Support\TramaLog;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Response;
use Throwable;

class TagController extends Controller
{
    private const ACTIVE_LIMIT = 100;

    public function __construct(
        private readonly OperationFailureHandler $operationFailureHandler,
    ) {
    }
    /**
     * Muestra etiquetas activas, inactivas y fusionadas con cantidad de noticias.
     */
    public function index(TagIndexRequest $request): Response
    {
        $filters = [
            'q' => '',
            'status' => '',
            'usage' => '',
            'sort' => 'name',
            'direction' => 'asc',
            'per_page' => 25,
            ...$request->validated(),
        ];
        $filters['q'] = (string) ($filters['q'] ?? '');
        $filters['status'] = (string) ($filters['status'] ?? '');
        $filters['usage'] = (string) ($filters['usage'] ?? '');
        $filters['per_page'] = (int) $filters['per_page'];

        $payload = [
            'data' => [],
            'pagination' => [
                'current_page' => 1, 'last_page' => 1, 'per_page' => $filters['per_page'],
                'total' => 0, 'from' => null, 'to' => null, 'links' => [],
            ],
        ];
        $activeCount = 0;
        $mergeTargets = [];
        $loadError = '';

        try {
            // Se muestra también la etiqueta destino cuando una etiqueta fue fusionada.
            $query = Tag::query()
                // mergedInto trae el nombre de la etiqueta principal si esta quedó fusionada.
                ->with(['mergedInto:id,name'])
                // articles_count indica cuántas noticias usan la etiqueta.
                ->withCount('articles')
                // Filtro textual por nombre de etiqueta.
                ->when($filters['q'], fn ($builder, $term) => $builder->where('name', 'like', "%{$term}%"))
                ->when($filters['status'] === 'active', fn ($builder) => $builder->where('is_active', true)->whereNull('merged_into_id'))
                ->when($filters['status'] === 'inactive', fn ($builder) => $builder->where('is_active', false)->whereNull('merged_into_id'))
                ->when($filters['status'] === 'merged', fn ($builder) => $builder->whereNotNull('merged_into_id'))
                ->when($filters['usage'] === 'with_articles', fn ($builder) => $builder->has('articles'))
                ->when($filters['usage'] === 'without_articles', fn ($builder) => $builder->doesntHave('articles'));

            if ($filters['sort'] === 'articles_count') {
                $query->orderBy('articles_count', $filters['direction']);
            } else {
                // Orden alfabético para localizar etiquetas rápido.
                $query->orderBy('name', $filters['direction']);
            }
            $query->orderBy('id');

            $tags = $query->paginate($filters['per_page'])->withQueryString();
            $payload = [
                'data' => $tags->getCollection()->map(fn (Tag $tag) => $this->serializeTag($tag))->values()->all(),
                'pagination' => [
                    'current_page' => $tags->currentPage(), 'last_page' => $tags->lastPage(),
                    'per_page' => $tags->perPage(), 'total' => $tags->total(),
                    'from' => $tags->firstItem(), 'to' => $tags->lastItem(),
                    'links' => $tags->linkCollection()->toArray(),
                ],
            ];

            $activeCount = Tag::query()->where('is_active', true)->whereNull('merged_into_id')->count();
            $mergeTargets = Tag::query()
                ->where('is_active', true)
                ->whereNull('merged_into_id')
                ->orderBy('name')
                ->limit(self::ACTIVE_LIMIT)
                ->get(['id', 'name'])
                ->map(fn (Tag $tag) => ['id' => $tag->id, 'name' => $tag->name])
                ->all();
        } catch (Throwable $exception) {
            if ($exception instanceof QueryException
                && str_contains($exception->getMessage(), 'SQLSTATE[HY000] [2002]')) {
                throw $exception;
            }

            TramaLog::error('No se pudo cargar la administración de etiquetas.', [
                'controller' => 'TagController', 'method' => 'index', 'status' => 500,
                'user_id' => $request->user()?->id, 'filters' => $filters,
                'exception_class' => $exception::class, 'exception_message' => $exception->getMessage(),
                'exception_file' => $exception->getFile(), 'exception_line' => $exception->getLine(),
            ]);
            $loadError = 'No pudimos cargar las etiquetas en este momento. Intentá nuevamente.';
        }

        return TramaBridge::render('Admin/Tags/Index', [
            'tags' => $payload,
            'filters' => $filters,
            'activeCount' => $activeCount,
            'activeLimit' => self::ACTIVE_LIMIT,
            'mergeTargets' => $mergeTargets,
            'loadError' => $loadError,
        ]);
    }

    /**
     * Crea una etiqueta con URL generada automáticamente.
     */
    public function store(TagRequest $request): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request): void {
                $data = $request->validated();
                if ((bool) $data['is_active']) {
                    $this->ensureActiveLimit();
                }

                // El backend genera el slug para evitar duplicados por diferencias de escritura.
                $data['slug'] = $this->uniqueSlug($data['name']);

                // Crea la etiqueta con nombre, descripción, estado y slug final.
                Tag::query()->create($data);
            }, attempts: 3);
        } catch (Throwable $exception) {
            $this->operationFailureHandler->fail($exception, 'admin_tags', 'create_tag',
                'No pudimos crear la etiqueta. Intentá nuevamente.', ['user_id' => $request->user()?->id]);
        }

        return back()->with('status', 'Etiqueta creada.');
    }

    /**
     * Actualiza nombre, descripción y estado sin modificar la URL existente.
     */
    public function update(TagRequest $request, Tag $tag): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $tag): void {
                $locked = Tag::query()->whereKey($tag->id)->lockForUpdate()->firstOrFail();
                // Una etiqueta fusionada queda como alias histórico y ya no debe editarse.
                if ($locked->merged_into_id !== null) {
                    throw ValidationException::withMessages([
                        'tag' => 'Una etiqueta fusionada no puede volver a editarse.',
                    ]);
                }

                $data = $request->validated();
                if (! $locked->is_active && (bool) $data['is_active']) {
                    $this->ensureActiveLimit($locked->id);
                }

                // Actualiza solo campos permitidos por TagRequest.
                $locked->update($data);
            }, attempts: 3);
        } catch (Throwable $exception) {
            $this->operationFailureHandler->fail($exception, 'admin_tags', 'update_tag',
                'No pudimos guardar los cambios de la etiqueta. Intentá nuevamente.',
                ['tag_id' => $tag->id, 'user_id' => $request->user()?->id]);
        }

        return back()->with('status', 'Etiqueta actualizada.');
    }

    /**
     * Activa o desactiva una etiqueta disponible para nuevas noticias.
     */
    public function toggleActive(Request $request, Tag $tag): RedirectResponse
    {
        try {
            DB::transaction(function () use ($tag): void {
                $locked = Tag::query()->whereKey($tag->id)->lockForUpdate()->firstOrFail();
                // Las etiquetas fusionadas no se reactivan porque apuntan a otra etiqueta.
                if ($locked->merged_into_id !== null) {
                    throw ValidationException::withMessages([
                        'tag' => 'La etiqueta ya fue fusionada con otra.',
                    ]);
                }

                if (! $locked->is_active) {
                    $this->ensureActiveLimit($locked->id);
                }

                // Invierte disponibilidad para nuevas noticias sin eliminar historial.
                $locked->update(['is_active' => ! $locked->is_active]);
            }, attempts: 3);
        } catch (Throwable $exception) {
            $this->operationFailureHandler->fail($exception, 'admin_tags', 'toggle_tag',
                'No pudimos cambiar el estado de la etiqueta. Intentá nuevamente.',
                ['tag_id' => $tag->id, 'user_id' => $request->user()?->id]);
        }

        $tag->refresh();
        return back()->with('status', $tag->is_active ? 'Etiqueta activada.' : 'Etiqueta desactivada.');
    }

    /**
     * Fusiona una etiqueta dentro de otra y reasigna todas sus noticias.
     */
    public function merge(MergeTagRequest $request, Tag $tag, TagMergeService $service): RedirectResponse
    {
        try {
            // El request valida que la etiqueta destino exista y no sea la misma.
            $target = Tag::query()
                // Busca la etiqueta destino validada por el formulario.
                ->findOrFail($request->integer('target_tag_id'));

            // El servicio reasigna relaciones y deja la etiqueta original como fusionada.
            $service->merge($tag, $target);
        } catch (Throwable $exception) {
            $this->operationFailureHandler->fail($exception, 'admin_tags', 'merge_tag',
                'No pudimos fusionar la etiqueta. Intentá nuevamente.',
                ['tag_id' => $tag->id, 'user_id' => $request->user()?->id]);
        }

        return back()->with('status', "La etiqueta se fusionó con {$target->name}.");
    }

    /**
     * Elimina únicamente etiquetas sin noticias ni historial de fusión.
     */
    public function destroy(Request $request, Tag $tag): RedirectResponse
    {
        try {
            DB::transaction(function () use ($tag): void {
                // El bloqueo protege la verificación de uso y el borrado.
                $locked = Tag::query()->whereKey($tag->id)->lockForUpdate()->firstOrFail();

                if ($locked->merged_into_id !== null || $locked->articles()->exists() || $locked->mergedTags()->exists()) {
                    // Si hay noticias o etiquetas absorbidas, borrar rompería historial.
                    throw ValidationException::withMessages([
                        'tag' => 'La etiqueta tiene historial y debe conservarse, desactivarse o fusionarse.',
                    ]);
                }

                // Solo se elimina cuando no tiene ningún vínculo histórico.
                $locked->delete();
            }, attempts: 3);
        } catch (Throwable $exception) {
            $this->operationFailureHandler->fail($exception, 'admin_tags', 'delete_tag',
                'No pudimos eliminar la etiqueta. Intentá nuevamente.',
                ['tag_id' => $tag->id, 'user_id' => $request->user()?->id]);
        }

        return back()->with('status', 'Etiqueta eliminada.');
    }

    /**
     * Verifica el límite de etiquetas disponibles para nuevas noticias.
     */
    private function ensureActiveLimit(?int $ignoreId = null): void
    {
        $query = Tag::query()->where('is_active', true)->whereNull('merged_into_id')->lockForUpdate();
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }
        if ($query->get(['id'])->count() >= self::ACTIVE_LIMIT) {
            throw ValidationException::withMessages([
                'is_active' => 'TRAMA admite un máximo de 100 etiquetas activas.',
            ]);
        }
    }

    /**
     * Normaliza una etiqueta para una fila de la grilla administrativa.
     *
     * @return array<string, mixed>
     */
    private function serializeTag(Tag $tag): array
    {
        return [
            'id' => $tag->id,
            'name' => $tag->name,
            'slug' => $tag->slug,
            'description' => $tag->description,
            'is_active' => (bool) $tag->is_active,
            'articles_count' => (int) $tag->articles_count,
            // Si existe, muestra a qué etiqueta principal fue absorbida.
            'merged_into' => $tag->mergedInto?->only(['id', 'name']),
        ];
    }

    /**
     * Genera una URL temática única a partir del nombre.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'etiqueta';
        $slug = $base;
        $suffix = 2;

        // Si la URL ya está tomada, se agregan sufijos incrementales.
        while (Tag::query()->where('slug', $slug)->exists()) {
            // Ejemplo: economia, economia-2, economia-3.
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
