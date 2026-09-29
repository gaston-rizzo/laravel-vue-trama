<?php

/* ============================================================================
 * CONTROLLER: SearchController.php
 * ============================================================================
 *
 * Controlador de búsqueda pública.
 *
 * Ejecuta búsquedas simples sobre noticias publicadas y permite filtrar por
 * categoría para que la experiencia de lectura se sienta como un medio real.
 * ============================================================================ */

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleCardResource;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\ArticleQueryService;
use App\Support\TramaBridge;
use Illuminate\Http\Request;
use Inertia\Response;

class SearchController extends Controller
{
    /**
     * Muestra resultados de búsqueda.
     */
    public function __invoke(Request $request, ArticleQueryService $articles): Response
    {
        // q es texto libre; category filtra por slug de sección.
        $term = trim((string) $request->query('q'));
        $category = $request->query('category');

        // La búsqueda solo devuelve noticias publicadas.
        $results = $articles->searchPublishedArticles($term, $category);

        // Se reenvían filtros para que el formulario conserve la búsqueda del usuario.
        return TramaBridge::render('Public/Search', [
            'filters' => [
                'q' => $term,
                'category' => $category,
            ],
            'categories' => CategoryResource::collection(
                Category::query()
                    // El filtro solo ofrece categorías con contenido publicado visible.
                    ->publiclyVisible()
                    // Mantiene el mismo orden que la navegación principal.
                    ->orderBy('sort_order')
                    ->get()
            )->resolve(),
            'results' => [
                'data' => ArticleCardResource::collection($results->getCollection())->resolve(),
                'links' => $results->linkCollection(),
                'meta' => [
                    'current_page' => $results->currentPage(),
                    'last_page' => $results->lastPage(),
                    'per_page' => $results->perPage(),
                    'total' => $results->total(),
                ],
            ],
        ]);
    }
}
