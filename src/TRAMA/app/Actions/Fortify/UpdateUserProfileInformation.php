<?php

/* ============================================================================
 * ACTION: UpdateUserProfileInformation.php
 * ============================================================================
 *
 * Actualiza nombre y email del perfil autenticado.
 *
 * Fortify usa esta clase desde la pantalla de perfil. Si el usuario cambia su
 * email, la cuenta vuelve a quedar pendiente de verificación para confirmar que
 * el nuevo correo realmente le pertenece.
 * ============================================================================ */

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Valida y guarda nombre/email del usuario autenticado.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        // El email debe seguir siendo único, excepto para el usuario que se edita.
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],

            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users')->ignore($user->id),
            ],
        ])->validate();

        // Cambiar email invalida la verificación anterior.
        if ($input['email'] !== $user->email) {
            $this->updateVerifiedUser($user, $input);
        } else {
            $user->forceFill([
                'name' => $input['name'],
                'email' => $input['email'],
            ])->save();
        }
    }

    /**
     * Guarda el email nuevo y dispara verificación si el modelo la requiere.
     *
     * @param  array<string, string>  $input
     */
    protected function updateVerifiedUser(User $user, array $input): void
    {
        // email_verified_at queda en null hasta que el usuario confirme el nuevo correo.
        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
            'email_verified_at' => null,
        ])->save();

        // Solo se envía notificación si el modelo implementa verificación de email.
        if ($user instanceof MustVerifyEmail) {
            $user->sendEmailVerificationNotification();
        }
    }
}
