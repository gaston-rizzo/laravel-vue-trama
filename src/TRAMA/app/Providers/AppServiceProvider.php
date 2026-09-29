<?php

/* ============================================================================
 * PROVIDER: AppServiceProvider.php
 * ============================================================================
 *
 * Registra configuraciones y comportamientos globales utilizados por TRAMA
 * durante el arranque de la aplicación.
 *
 * En este provider se ajusta el prefetch de Vite y se vinculan observers con
 * los modelos que necesitan reaccionar automáticamente ante cambios persistidos.
 *
 * Por ejemplo, ArticleObserver mantiene sincronizado el orden editorial de las
 * categorías cuando una noticia comienza o deja de estar publicada.
 * ============================================================================ */

namespace App\Providers;

use App\Models\Article;
use App\Observers\ArticleObserver;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /** Registra servicios adicionales en el contenedor cuando sean necesarios. */
    public function register(): void
    {
        // Los servicios utilizados por los observers se resuelven automáticamente.
    }

    /** Configura Vite y los observers globales de la aplicación. */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Mantiene el orden de categorías sincronizado cuando cambia una noticia.
        Article::observe($this->app->make(ArticleObserver::class));
    }
}
