<?php

/* ============================================================================
 * MIDDLEWARE: EnsureArticleAccess.php
 * ============================================================================
 *
 * Protege las rutas relacionadas con noticias.
 *
 * Solo permite el acceso a periodistas y editores activos.
 * El administrador no participa del flujo editorial.
 * ============================================================================ */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureArticleAccess
{
    /**
     * Permite continuar únicamente a usuarios
     * autorizados para trabajar con noticias.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->canAccessArticles()) {
            abort(403, 'No tenés permisos para acceder a las noticias.');
        }

        return $next($request);
    }
}