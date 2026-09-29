<?php

/* ============================================================================
 * MIDDLEWARE: EnsureEditorialAccess.php
 * ============================================================================
 *
 * Protege todas las rutas privadas del panel editorial de TRAMA.
 *
 * Solo permite cuentas activas con rol administrador, editor o periodista. Las
 * demás cuentas autenticadas permanecen en el portal público.
 * ============================================================================ */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEditorialAccess
{
    /**
     * Verifica que la cuenta tenga acceso editorial vigente.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->canAccessEditorial()) {
            abort(403, 'No tenés permisos para ingresar al panel editorial.');
        }

        return $next($request);
    }
}
