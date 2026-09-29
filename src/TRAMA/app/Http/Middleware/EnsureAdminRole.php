<?php

/* ============================================================================
 * MIDDLEWARE: EnsureAdminRole.php
 * ============================================================================
 *
 * Restringe módulos administrativos del panel editorial de TRAMA.
 *
 * Solo el rol administrador puede gestionar cuentas, categorías y etiquetas.
 * Editores y periodistas conservan acceso a las funciones editoriales que les
 * corresponden, pero no a la configuración estructural del CMS.
 * ============================================================================ */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminRole
{
    /**
     * Permite continuar únicamente a una cuenta administrativa activa.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Permite acceder únicamente a cuentas activas
        // autorizadas para administrar la estructura del CMS.
        if (! $request->user()?->canManageSystem()) {
            abort(403, 'Solo un administrador puede acceder a esta sección.');
        }

        return $next($request);
    }
}
