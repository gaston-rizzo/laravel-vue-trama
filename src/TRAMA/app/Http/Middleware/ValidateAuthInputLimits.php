<?php

/* ============================================================================
 * MIDDLEWARE: ValidateAuthInputLimits.php
 * ============================================================================
 *
 * Aplica límites comunes a los formularios de autenticación administrados
 * internamente por Fortify.
 *
 * Algunos endpoints de Fortify validan formato y presencia, pero no todos
 * exponen un máximo configurable para email o contraseña. Este middleware
 * agrega esos límites antes de que el controlador del paquete procese la
 * petición, de modo que el backend mantenga exactamente los mismos valores
 * que la interfaz de TRAMA.
 * ============================================================================ */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ValidateAuthInputLimits
{
    /**
     * Valida únicamente los endpoints de autenticación que necesitan límites
     * adicionales. Las pantallas GET no se modifican.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('login') && $request->isMethod('post')) {
            $request->validate([
                'email' => ['required', 'string', 'email', 'max:50'],
                'password' => ['required', 'string', 'max:20'],
            ], [
                'email.email' => 'Ingresá un correo electrónico válido.',
                'email.max' => 'El correo no puede superar los 50 caracteres.',
                'password.max' => 'La contraseña no puede superar los 20 caracteres.',
            ]);
        }

        if ($request->routeIs('password.email') && $request->isMethod('post')) {
            $request->validate([
                'email' => ['required', 'string', 'email', 'max:50'],
            ], [
                'email.email' => 'Ingresá un correo electrónico válido.',
                'email.max' => 'El correo no puede superar los 50 caracteres.',
            ]);

            $email = mb_strtolower(trim((string) $request->input('email')));
            $request->merge(['email' => $email]);

            $emailKey = 'password-reset-email:'.sha1($email.'|'.$request->ip());
            $ipKey = 'password-reset-ip:'.sha1((string) $request->ip());

            if (
                RateLimiter::tooManyAttempts($emailKey, 1) ||
                RateLimiter::tooManyAttempts($ipKey, 6)
            ) {
                return back()
                    ->withInput($request->only('email'))
                    ->with('status', trans('passwords.sent'));
            }

            RateLimiter::hit($emailKey, 300);
            RateLimiter::hit($ipKey, 3600);
        }

        if ($request->routeIs('password.update') && $request->isMethod('post')) {
            $request->validate([
                'email' => ['required', 'string', 'email', 'max:50'],
                'password' => ['required', 'string', 'min:8', 'max:20', 'regex:/\pL/u', 'regex:/\d/u'],
                'password_confirmation' => ['required', 'string', 'max:20'],
            ], [
                'email.email' => 'Ingresá un correo electrónico válido.',
                'email.max' => 'El correo no puede superar los 50 caracteres.',
                'password.min' => 'La contraseña debe tener entre 8 y 20 caracteres e incluir al menos una letra y un número.',
                'password.max' => 'La contraseña no puede superar los 20 caracteres.',
                'password.regex' => 'La contraseña debe tener entre 8 y 20 caracteres e incluir al menos una letra y un número.',
                'password_confirmation.max' => 'La confirmación no puede superar los 20 caracteres.',
            ]);
        }

        if ($request->routeIs('password.confirm') && $request->isMethod('post')) {
            $request->validate([
                'password' => ['required', 'string', 'max:20'],
            ], [
                'password.max' => 'La contraseña no puede superar los 20 caracteres.',
            ]);
        }

        if ($request->routeIs('user-password.update')) {
            $request->validate([
                'current_password' => ['required', 'string', 'max:20'],
                'password' => ['required', 'string', 'min:8', 'max:20', 'regex:/\pL/u', 'regex:/\d/u'],
                'password_confirmation' => ['required', 'string', 'max:20'],
            ], [
                'current_password.max' => 'La contraseña actual no puede superar los 20 caracteres.',
                'password.min' => 'La contraseña debe tener entre 8 y 20 caracteres e incluir al menos una letra y un número.',
                'password.max' => 'La contraseña no puede superar los 20 caracteres.',
                'password.regex' => 'La contraseña debe tener entre 8 y 20 caracteres e incluir al menos una letra y un número.',
                'password_confirmation.max' => 'La confirmación no puede superar los 20 caracteres.',
            ]);
        }

        return $next($request);
    }
}
