<?php

/* ============================================================================
 * MIDDLEWARE: AdvanceTramaClock.php
 * ============================================================================
 *
 * Registra cada petición web como actividad del usuario para mantener
 * actualizado el reloj editorial de TRAMA durante la sesión.
 *
 * El middleware no inventa fechas ni modifica modelos. Solamente avisa al reloj
 * editorial que existe una sesión activa. TramaClock decide si continúa la
 * sesión actual o si reanuda una sesión nueva después de una pausa prolongada.
 *
 * Los comandos de fondo no pasan por este middleware. Por eso el scheduler puede
 * consultar TramaClock::now() sin mantener despierto el reloj durante toda la
 * noche cuando nadie está navegando el portal.
 * ============================================================================ */

namespace App\Http\Middleware;

use App\Support\TramaClock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdvanceTramaClock
{
    public function handle(Request $request, Closure $next): Response
    {
        TramaClock::activate();

        try {
            return $next($request);
        } finally {
            /*
             * También se registra el final de la request. Así una operación que
             * tarda algunos segundos en validar, procesar una imagen y guardar
             * revisiones no deja al reloj anclado en el segundo de entrada.
             */
            TramaClock::finishWebRequest();
        }
    }
}
