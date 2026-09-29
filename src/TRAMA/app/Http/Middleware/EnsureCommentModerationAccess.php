<?php

/* ============================================================================
 * MIDDLEWARE: EnsureCommentModerationAccess.php
 * ============================================================================
 *
 * Protege las rutas de moderación de comentarios.
 *
 * Solo los editores activos pueden acceder a este módulo.
 * Periodistas y administradores quedan fuera de la moderación.
 * ============================================================================ */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCommentModerationAccess
{
    /**
     * Permite continuar únicamente a usuarios
     * autorizados para moderar comentarios.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->canModerateComments()) {
            abort(403, 'No tenés permisos para moderar comentarios.');
        }

        return $next($request);
    }
}