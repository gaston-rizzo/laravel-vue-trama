<?php

/* ============================================================================
 * CONTROLLER: VerifyEmailController.php
 * ============================================================================
 *
 * Confirma el email desde el enlace de verificación enviado por correo.
 *
 * Busca al usuario por ID, valida que el hash coincida con su email y marca la
 * cuenta como verificada. No inicia sesión automáticamente.
 * ============================================================================ */

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VerifyEmailController extends Controller
{
    /**
     * Marca como verificado el email indicado por el enlace.
     */
    public function __invoke(Request $request, int $id, string $hash): RedirectResponse
    {
        // El ID identifica al usuario creado durante el registro.
        $user = User::findOrFail($id);

        // El hash evita verificar una cuenta con un correo distinto al firmado.
        abort_unless(hash_equals($hash, sha1($user->getEmailForVerification())), 403);

        // Si ya estaba verificado, solo vuelve a la pantalla de confirmación.
        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        // El usuario queda deslogueado; después de esto debe iniciar sesión.
        return redirect()->route('verification.notice', ['verified' => 1]);
    }
}
