<?php

/* ============================================================================
 * ACTION: CreateNewUser.php
 * ============================================================================
 *
 * Crea cuentas nuevas desde el registro público.
 *
 * Fortify llama a esta clase cuando alguien envía el formulario de registro.
 * La clase valida nombre, email y contraseña, garantiza que el email no exista
 * y guarda al usuario con rol público y acceso activo.
 * ============================================================================ */

namespace App\Actions\Fortify;

use App\Models\User;
use App\Rules\StrictEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Valida los datos del registro y crea el usuario público.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        // Las reglas se ejecutan en backend para que nadie pueda saltarlas desde el navegador.
        Validator::make($input, [
            'name' => ['required', 'string', 'min:3', 'max:40', 'regex:/\pL/u'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:50',
                new StrictEmail(),
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
            'password_confirmation' => ['required', 'string', 'max:20'],
            'redirect' => ['nullable', 'string', 'max:255'],
        ], [
            'name.max' => 'El nombre no puede superar los 40 caracteres.',
            'name.min' => 'El nombre debe tener al menos 3 caracteres.',
            'name.regex' => 'El nombre debe incluir al menos una letra.',
            'email.required' => 'Ingresá un correo electrónico válido.',
            'email.email' => 'Ingresá un correo electrónico válido.',
            'email.max' => 'El correo no puede superar los 50 caracteres.',
            'password.min' => 'La contraseña debe tener entre 8 y 20 caracteres e incluir al menos una letra y un número.',
            'password.max' => 'La contraseña no puede superar los 20 caracteres.',
            'password.regex' => 'La contraseña debe tener entre 8 y 20 caracteres e incluir al menos una letra y un número.',
            'password_confirmation.max' => 'La confirmación no puede superar los 20 caracteres.',
        ])->validate();

        // Toda cuenta creada desde la pantalla pública queda con rol reader.
        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => Hash::make($input['password']),
            'role' => 'reader',
            'is_active' => true,
        ]);
    }
}
