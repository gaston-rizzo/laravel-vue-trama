<?php

/* ============================================================================
 * CONTROLLER: CategoryController.php
 * ============================================================================
 *
 * Controlador público de secciones.
 *
 * Lista noticias publicadas dentro de una categoría para construir páginas tipo
 * Economía, Tecnología, Mundo o Cultura sin duplicar lógica en Vue.
 * ============================================================================ */

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleCardResource;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Support\TramaBridge;
use Inertia\Response;

class CategoryController extends Controller
{
    /**
     * Muestra una categoría pública con sus noticias.
     */
    public function show(string $slug): Response
    {
        // La sección debe estar activa y tener al menos una noticia publicada.
        $category = Category::query()
            ->publiclyVisible()
            ->where('slug', $slug)
            ->firstOrFail();

        // El listado muestra solo noticias publicadas de esa sección.
        $articles = $category
            ->articles()
            // Reutiliza la misma regla pública aplicada al resto del portal.
            ->publiclyVisible()
            ->with(['author', 'category', 'tags'])
            ->latest('published_at')
            ->paginate(9);

        // La categoría viaja junto con sus noticias para construir el encabezado visual.
        return TramaBridge::render('Public/CategoryShow', [
            'category' => (new CategoryResource($category))->resolve(),
            'articles' => [
                'data' => ArticleCardResource::collection($articles->getCollection())->resolve(),
                'links' => $articles->linkCollection(),
                'meta' => [
                    'current_page' => $articles->currentPage(),
                    'last_page' => $articles->lastPage(),
                    'per_page' => $articles->perPage(),
                    'total' => $articles->total(),
                ],
            ],
        ]);
    }
}
