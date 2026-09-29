<?php

/* ============================================================================
 * CONTROLLER: EmailVerificationNotificationController.php
 * ============================================================================
 *
 * Reenvía el correo de verificación para usuarios ya autenticados.
 *
 * Cubre sesiones antiguas o cambios de email desde el perfil. Si el mailer
 * falla, el error SMTP queda registrado en el log personalizado de TRAMA.
 * ============================================================================ */

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\TramaLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Envía otro enlace de verificación al usuario actual.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        // Si el usuario ya verificó el email, no necesita otro enlace.
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('home');
        }

        try {
            // El modelo User decide qué notificación de verificación se usa.
            $request->user()->sendEmailVerificationNotification();
        } catch (Throwable $exception) {
            TramaLog::error(TramaLog::mailFailureMessage($exception), [
                'controller' => 'EmailVerificationNotificationController',
                'method' => 'store',
                'email' => $request->user()->email,
            ]);

            throw ValidationException::withMessages([
                'email' => __('auth.email_delivery_failed'),
            ]);
        }

        return back()->with('status', 'verification-link-sent');
    }
}
