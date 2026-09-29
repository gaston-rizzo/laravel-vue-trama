<?php

/* ============================================================================
 * CONTROLLER: HomeController.php
 * ============================================================================
 *
 * Controlador de portada pública.
 *
 * Arma la portada pública de TRAMA con noticia principal, cinta urgente,
 * destacadas, últimas noticias, más leídas y secciones por categoría para
 * Vue/Inertia.
 * ============================================================================ */

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleCardResource;
use App\Http\Resources\CategoryResource;
use App\Services\ArticleQueryService;
use App\Support\TramaBridge;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * Muestra la portada principal del portal.
     */
    public function __invoke(ArticleQueryService $articles): Response
    {
        // El servicio devuelve cada sección que necesita la portada pública.
        $homePageSections = $articles->getHomePageSections();

        // Se resuelven resources antes de enviarlos a Vue para dejar props simples.
        $categorySections = $homePageSections['categorySections']->map(fn ($category) => [
            'category' => (new CategoryResource($category))->resolve(),
            'articles' => ArticleCardResource::collection($category->articles)->resolve(),
        ])->values();

        // Cada sección se entrega separada para que Vue pinte hero, ticker,
        // noticias recientes, rankings y secciones de la portada.
        return TramaBridge::render('Public/Home', [
            'hero' => $homePageSections['hero'] ? (new ArticleCardResource($homePageSections['hero']))->resolve() : null,
            'breaking' => ArticleCardResource::collection($homePageSections['breaking'])->resolve(),
            'featured' => ArticleCardResource::collection($homePageSections['featured'])->resolve(),
            'latest' => ArticleCardResource::collection($homePageSections['latest'])->resolve(),
            'mostRead' => ArticleCardResource::collection($homePageSections['mostRead'])->resolve(),            
            'categories' => CategoryResource::collection($homePageSections['categories'])->resolve(),
            'categorySections' => $categorySections,
        ]);
    }
}
