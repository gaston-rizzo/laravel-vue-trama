<?php

/* ============================================================================
 * ACTION: UpdateUserPassword.php
 * ============================================================================
 *
 * Cambia la contraseña desde una sesión ya iniciada.
 *
 * Fortify llama a esta clase desde el perfil del usuario. Antes de guardar la
 * contraseña nueva, exige la contraseña actual para impedir cambios no
 * autorizados desde una sesión abierta por otra persona.
 * ============================================================================ */

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    /**
     * Valida la contraseña actual y guarda la contraseña nueva.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        // current_password:web compara contra la contraseña del usuario autenticado.
        Validator::make($input, [
            'current_password' => ['required', 'string', 'max:20', 'current_password:web'],
            'password' => $this->passwordRules(),
            'password_confirmation' => ['required', 'string', 'max:20'],
        ], [
            'current_password.max' => 'La contraseña actual no puede superar los 20 caracteres.',
            'current_password.current_password' => __('The provided password does not match your current password.'),
            'password.min' => 'La contraseña debe tener entre 8 y 20 caracteres e incluir al menos una letra y un número.',
            'password.max' => 'La contraseña no puede superar los 20 caracteres.',
            'password.regex' => 'La contraseña debe tener entre 8 y 20 caracteres e incluir al menos una letra y un número.',
            'password_confirmation.max' => 'La confirmación no puede superar los 20 caracteres.',
        ])->validate();

        // Al cambiar contraseña se limpia cualquier obligación de restablecimiento.
        $user->forceFill([
            'password' => Hash::make($input['password']),
            'password_reset_required_at' => null,
        ])->save();
    }
}
