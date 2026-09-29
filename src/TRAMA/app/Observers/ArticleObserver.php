<?php

/* ============================================================================
 * OBSERVER: ArticleObserver.php
 * ============================================================================
 *
 * Mantiene sincronizado el orden editorial de las categorías cuando una noticia
 * comienza o deja de estar disponible públicamente.
 *
 * El observer se ejecuta después del commit para trabajar únicamente con cambios
 * que ya fueron confirmados correctamente por la base de datos.
 *
 * Ejemplo:
 *
 *     #1 Política
 *     #2 Economía
 *     #3 Tecnología
 *
 * Si se publica la primera noticia de la categoría Ciencia, el sistema detecta
 * que la sección ya puede participar del portal y le asigna automáticamente la
 * siguiente posición disponible:
 *
 *     #1 Política
 *     #2 Economía
 *     #3 Tecnología
 *     #4 Ciencia
 *
 * Si Ciencia pierde posteriormente su última noticia publicada, deja de
 * participar del orden editorial y las posiciones restantes se compactan.
 * ============================================================================ */

namespace App\Observers;

use App\Models\Article;
use App\Services\Categories\CategoryEditorialOrderService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class ArticleObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly CategoryEditorialOrderService $categoryOrder,
    ) {
    }

    /** Primera publicación, cambio de estado, fecha o categoría. */
    public function saved(Article $article): void
    {
        if (
            ! $article->wasRecentlyCreated
            && ! $article->wasChanged(['status', 'published_at', 'category_id'])
        ) {
            return;
        }

        $this->categoryOrder->synchronize((int) $article->category_id);
    }

    /** El borrado lógico puede retirar la última noticia pública de una categoría. */
    public function deleted(Article $article): void
    {
        $this->categoryOrder->synchronize((int) $article->category_id);
    }

    /** Restaurar una noticia publicada puede volver a hacer visible una categoría. */
    public function restored(Article $article): void
    {
        $this->categoryOrder->synchronize((int) $article->category_id);
    }

    /** Mantiene la secuencia consistente también ante un borrado definitivo. */
    public function forceDeleted(Article $article): void
    {
        $this->categoryOrder->synchronize((int) $article->category_id);
    }
}
