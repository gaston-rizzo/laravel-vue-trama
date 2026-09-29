<?php

/* ============================================================================
 * MIDDLEWARE: ThrottleCommentAttempts.php
 * ============================================================================
 *
 * Limita los intentos de creación de comentarios y respuestas públicas.
 *
 * El control se ejecuta antes de entrar al controlador para que cada solicitud
 * quede contabilizada incluso si posteriormente ocurre una excepción inesperada.
 *
 * Cada usuario autenticado puede realizar hasta 5 intentos dentro de una ventana
 * de 10 minutos. Una vez vencida esa ventana, puede volver a intentarlo normalmente.
 *
 * Si alcanza el límite, se devuelve un mensaje amigable para que Inertia pueda
 * mostrarlo directamente en la interface pública de comentarios.
 * ============================================================================ */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ThrottleCommentAttempts
{
    /*
     * Cantidad máxima de solicitudes permitidas para crear comentarios
     * o respuestas dentro de la ventana definida.
     */
    private const MAX_ATTEMPTS = 5;

    /*
     * Duración de la ventana del límite: 10 minutos.
     */
    private const DECAY_SECONDS = 600;

    /**
     * Limita los intentos antes de ejecutar el controlador.
     *
     * La petición se contabiliza antes de continuar, por lo que también
     * cuenta aunque posteriormente ocurra una excepción inesperada.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->rateLimitKey($request);

        /*
         * Si la cuenta ya alcanzó el máximo permitido, Laravel devuelve
         * un error de validación amigable que Inertia muestra en pantalla.
         */
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);
            $minutes = max(1, (int) ceil($seconds / 60));

            /*
            * Además del mensaje visible, se envía la cantidad exacta de segundos
            * restantes para que Vue mantenga bloqueado el formulario hasta que
            * Laravel vuelva a permitir una nueva solicitud.
            */
            throw ValidationException::withMessages([
                'operation' => "Alcanzaste el límite de intentos para comentar. Podrás volver a intentarlo en {$minutes} minutos.",
                'retry_after_seconds' => (string) $seconds,
            ]);
        }

        /*
         * Consume el intento antes de entrar al controlador.
         *
         * De esta manera, incluso un error ocurrido al comienzo de store()
         * queda contabilizado dentro del límite.
         */
        RateLimiter::hit($key, self::DECAY_SECONDS);

        return $next($request);
    }

    /**
     * Genera una clave independiente para cada usuario autenticado.
     */
    private function rateLimitKey(Request $request): string
    {
        return 'public-comment-attempts:user:'.$request->user()->id;
    }
}