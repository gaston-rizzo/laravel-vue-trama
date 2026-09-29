<?php

/* ============================================================================
 * ACTION: ResetUserPassword.php
 * ============================================================================
 *
 * Guarda una nueva contraseña desde el enlace de recuperación.
 *
 * Fortify ejecuta esta clase después de validar el token enviado por email. La
 * clase valida la contraseña nueva, la guarda encriptada y elimina la marca que
 * obligaba al usuario a cambiar su acceso.
 * ============================================================================ */

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Valida y guarda la contraseña nueva.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function reset(User $user, array $input): void
    {
        // Se reutiliza la misma política de contraseña que en el registro.
        Validator::make($input, [
            'password' => $this->passwordRules(),
            'password_confirmation' => ['required', 'string', 'max:20'],
        ], [
            'password.min' => 'La contraseña debe tener entre 8 y 20 caracteres e incluir al menos una letra y un número.',
            'password.max' => 'La contraseña no puede superar los 20 caracteres.',
            'password.regex' => 'La contraseña debe tener entre 8 y 20 caracteres e incluir al menos una letra y un número.',
            'password_confirmation.max' => 'La confirmación no puede superar los 20 caracteres.',
        ])->validate();

        // La contraseña nunca se guarda en texto plano.
        $user->forceFill([
            'password' => Hash::make($input['password']),
            'password_reset_required_at' => null,
        ])->save();
    }
}
