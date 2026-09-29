<?php

/* ============================================================================
 * MIDDLEWARE: EnsureActiveAccount.php
 * ============================================================================
 *
 * Expulsa de TRAMA a cualquier cuenta autenticada que haya sido bloqueada.
 *
 * La comprobación se ejecuta para todas las rutas web. De esta manera, una
 * cuenta bloqueada no puede seguir comentando, respondiendo, dando Me gusta,
 * reportando ni utilizando pantallas privadas aunque ya tuviera una sesión
 * abierta antes de que el administrador aplicara el bloqueo.
 *
 * La página que ya estaba renderizada en el navegador puede seguir visible,
 * pero la siguiente petición al servidor invalida la sesión y redirige al login
 * con un mensaje claro y un acceso a la página de contacto.
 * ============================================================================ */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    /**
     * Finaliza la sesión de una cuenta bloqueada antes de continuar la petición.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->with(
                    'account_blocked',
                    'Tu cuenta se encuentra bloqueada y no puede acceder a TRAMA. Si considerás que se trata de un error, contactá al equipo de TRAMA.'
                );
        }

        return $next($request);
    }
}
