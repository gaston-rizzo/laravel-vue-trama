<?php

/* ============================================================================
 * CONTROLLER: EmailVerificationPromptController.php
 * ============================================================================
 *
 * Muestra la pantalla que indica al usuario revisar su correo.
 *
 * Esta página puede verse sin sesión porque TRAMA no inicia sesión después del
 * registro. El enlace recibido por email es el que activa la cuenta.
 * ============================================================================ */

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\TramaBridge;
use Illuminate\Http\Request;
use Inertia\Response;

class EmailVerificationPromptController extends Controller
{
    /**
     * Renderiza el aviso posterior al registro o a la verificación correcta.
     */
    public function __invoke(Request $request): Response
    {
        // status permite mostrar el mensaje de "enlace enviado".
        // verified permite mostrar el mensaje de "cuenta verificada".
        return TramaBridge::render('Auth/VerifyEmail', [
            'status' => session('status'),
            'verified' => $request->boolean('verified'),
            'canResend' => $request->user() !== null && ! $request->user()->hasVerifiedEmail(),
        ]);
    }
}
