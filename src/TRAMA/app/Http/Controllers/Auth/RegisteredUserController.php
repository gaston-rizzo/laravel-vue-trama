<?php

/* ============================================================================
 * CONTROLLER: RegisteredUserController.php
 * ============================================================================
 *
 * Procesa el registro público de usuarios en TRAMA.
 *
 * Usa la misma acción de creación configurada para Fortify, pero controla el
 * envío del correo de verificación para evitar el login automático. Si SMTP
 * falla, el usuario recién creado se elimina y el formulario recibe un error.
 * ============================================================================ */

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\CreateNewUser;
use App\Http\Controllers\Controller;
use App\Support\TramaBridge;
use App\Support\TramaLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Response;
use Throwable;

class RegisteredUserController extends Controller
{
    /**
     * Muestra el formulario público de registro.
     */
    public function create(Request $request): Response
    {
        // La URL de retorno se conserva solamente si es una ruta local segura.
        return TramaBridge::render('Auth/Register', [
            'redirect' => $this->safeRedirectPath($request->query('redirect')),
        ]);
    }

    /**
     * Crea el usuario, envía la verificación y evita el login automático.
     *
     * @throws ValidationException
     */
    public function store(Request $request, CreateNewUser $creator): RedirectResponse|JsonResponse
    {
        // CreateNewUser contiene las reglas de validación reales del registro.
        $user = $creator->create($request->all());

        try {
            // El mail se envía acá para poder capturar fallos SMTP y registrar contexto útil.
            $user->sendEmailVerificationNotification();
        } catch (Throwable $exception) {
            TramaLog::error(TramaLog::mailFailureMessage($exception), [
                'controller' => 'RegisteredUserController',
                'method' => 'store',
                'name' => $user->name,
                'email' => $user->email,
            ]);

            // Si el mail no salió, la cuenta no queda creada a medias.
            $user->delete();

            throw ValidationException::withMessages([
                'email' => __('auth.email_delivery_failed'),
            ]);
        }

        // Por seguridad, el registro nunca deja una sesión abierta.
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(null, 204);
        }

        // El usuario queda en la pantalla de aviso hasta abrir el enlace del correo.
        return redirect()
            ->route('verification.notice')
            ->with('status', 'verification-link-sent');
    }

    /**
     * Permite redirecciones internas y rechaza URLs externas.
     */
    private function safeRedirectPath(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || ! str_starts_with($value, '/') || str_starts_with($value, '//')) {
            return null;
        }

        return $value;
    }
}
