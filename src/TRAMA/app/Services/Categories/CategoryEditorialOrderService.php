<?php

/* ============================================================================
 * SERVICE: CategoryEditorialOrderService.php
 * ============================================================================
 *
 * Mantiene el orden editorial de las categorías que realmente participan
 * del portal público.
 *
 * Solo intervienen categorías activas, con sus datos completos y con al menos
 * una noticia publicada. La secuencia siempre se mantiene correlativa:
 * 1, 2, 3... sin duplicados ni posiciones vacías.
 *
 * Las categorías que todavía no cumplen las condiciones para mostrarse
 * públicamente conservan sort_order = null.
 *
 * Ejemplo:
 *
 *     #1 Política
 *     #2 Economía
 *     #3 Tecnología
 *
 * Si Ciencia publica su primera noticia, pasa a participar del orden y recibe
 * automáticamente la siguiente posición disponible:
 *
 *     #1 Política
 *     #2 Economía
 *     #3 Tecnología
 *     #4 Ciencia
 *
 * Si después Economía deja de tener noticias publicadas, sale del orden y las
 * posiciones restantes se compactan:
 *
 *     #1 Política
 *     #2 Tecnología
 *     #3 Ciencia
 * ============================================================================ */

namespace App\Services\Categories;

use App\Models\Category;
use App\Support\TramaClock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CategoryEditorialOrderService
{
    /** Máximo de categorías que pueden mostrarse simultáneamente en el portal. */
    public const PUBLIC_LIMIT = 12;

    /** Devuelve cuántas categorías participan actualmente del orden público. */
    public function count(): int
    {
        return Category::query()
            ->publiclyVisible()
            ->count();
    }


    /**
     * Impide que la primera publicación de una categoría cree una sección pública
     * número 13. Las categorías que ya están publicadas no consumen un cupo nuevo.
     */
    public function ensureCanPublishInCategory(int $categoryId): void
    {
        $this->lockCategories();

        $category = Category::query()->whereKey($categoryId)->firstOrFail();

        // Una categoría inactiva no se muestra públicamente aunque conserve noticias
        // con estado published, por lo que esta publicación no ocupa un cupo nuevo.
        if (! $category->is_active || ! $this->hasCompletePublicData($category)) {
            return;
        }

        // Si la categoría ya participa del portal, publicar otra noticia no aumenta
        // la cantidad de secciones públicas y siempre debe seguir permitido.
        if (Category::query()->publiclyVisible()->whereKey($categoryId)->exists()) {
            return;
        }

        $this->ensurePublicLimitAvailable();
    }

    /**
     * Comprueba si una categoría inactiva con publicaciones puede volver al portal.
     * Reactivar una categoría sin noticias publicadas no consume ningún cupo.
     */
    public function ensureCanReactivate(Category $category): void
    {
        $this->lockCategories();

        $locked = Category::query()->whereKey($category->id)->firstOrFail();

        if (! $this->hasCompletePublicData($locked)) {
            return;
        }

        $hasPublishedArticles = $locked->articles()
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', TramaClock::now())
            ->exists();

        if (! $hasPublishedArticles) {
            return;
        }

        $this->ensurePublicLimitAvailable();
    }

    /**
     * Recalcula toda la secuencia pública.
     *
     * Si $appendCategoryId acaba de adquirir visibilidad y todavía no tenía orden,
     * se coloca al final. Esto cubre la primera publicación y la reactivación.
     */
    public function synchronize(?int $appendCategoryId = null): void
    {
        DB::transaction(function () use ($appendCategoryId): void {
            $categories = $this->orderedVisibleCategories();

            if ($appendCategoryId !== null) {
                $append = $categories->firstWhere('id', $appendCategoryId);

                if ($append !== null && $append->sort_order === null) {
                    $categories = $categories
                        ->reject(fn (Category $category) => $category->id === $appendCategoryId)
                        ->values()
                        ->push($append);
                }
            }

            $this->rewritePositions($categories);
        }, attempts: 3);
    }

    /**
     * Mueve una categoría pública a otra posición y desplaza automáticamente
     * las categorías comprendidas entre la posición anterior y la nueva.
     */
    public function moveToPosition(int $categoryId, int $position): void
    {
        DB::transaction(function () use ($categoryId, $position): void {
            $categories = $this->orderedVisibleCategories();
            $count = $categories->count();

            $target = $categories->firstWhere('id', $categoryId);

            if ($target === null) {
                throw ValidationException::withMessages([
                    'sort_order' => 'El orden editorial solo está disponible para categorías visibles en el portal.',
                ]);
            }

            if ($position < 1 || $position > $count) {
                throw ValidationException::withMessages([
                    'sort_order' => "Elegí una posición editorial entre 1 y {$count}.",
                ]);
            }

            $remaining = $categories
                ->reject(fn (Category $category) => $category->id === $categoryId)
                ->values();

            $before = $remaining->take($position - 1);
            $after = $remaining->slice($position - 1);

            $ordered = $before
                ->concat([$target])
                ->concat($after)
                ->values();

            $this->rewritePositions($ordered);
        }, attempts: 3);
    }

    /** Bloquea las filas de categorías para serializar altas públicas simultáneas. */
    private function lockCategories(): void
    {
        Category::query()
            ->select('id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** Lanza un error amigable cuando ya existen doce categorías publicadas. */
    private function ensurePublicLimitAvailable(): void
    {
        if ($this->count() < self::PUBLIC_LIMIT) {
            return;
        }

        throw ValidationException::withMessages([
            'operation' => 'TRAMA admite un máximo de 12 categorías publicadas. Desactivá una categoría pública antes de publicar esta sección.',
        ]);
    }

    /** Verifica los datos mínimos necesarios para que una sección pueda ser pública. */
    private function hasCompletePublicData(Category $category): bool
    {
        return filled($category->name)
            && filled($category->description)
            && filled($category->accent_color)
            && filled($category->cover_image);
    }

    /**
     * Obtiene las categorías que realmente participan del orden público.
     * Las que ya tienen posición conservan su secuencia; las nuevas quedan al final.
     *
     * @return Collection<int, Category>
     */
    private function orderedVisibleCategories(): Collection
    {
        return Category::query()
            ->publiclyVisible()
            ->orderByRaw('sort_order IS NULL ASC')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'sort_order']);
    }

    /**
     * Reescribe la secuencia completa para impedir duplicados y huecos.
     * También limpia cualquier orden residual de categorías no visibles.
     *
     * @param Collection<int, Category> $categories
     */
    private function rewritePositions(Collection $categories): void
    {
        $ids = $categories
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Una categoría fuera del portal no conserva una posición pública reservada.
        $notVisible = DB::table('categories')->whereNotNull('sort_order');

        if ($ids !== []) {
            $notVisible->whereNotIn('id', $ids);
        }

        $notVisible->update(['sort_order' => null]);

        if ($ids === []) {
            return;
        }

        // Primero libera las posiciones actuales para que nunca haya colisiones.
        DB::table('categories')
            ->whereIn('id', $ids)
            ->update(['sort_order' => null]);

        foreach ($ids as $index => $id) {
            DB::table('categories')
                ->where('id', $id)
                ->update(['sort_order' => $index + 1]);
        }
    }
}
