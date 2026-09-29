<?php

/* ============================================================================
 * REQUEST: UpdateEmployeeRequest.php
 * ============================================================================
 *
 * Valida cambios realizados sobre empleados internos.
 *
 * La identidad de los lectores no se modifica desde administración. Para el
 * equipo interno, el correo se edita como identificador + @trama.test y Laravel
 * reconstruye el valor completo antes de validar su unicidad. La biografía se
 * exige únicamente a perfiles editoriales y queda vacía para administradores.
 * El nombre completo permanece único entre el equipo interno.
 * ============================================================================ */

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    private const EMAIL_DOMAIN = '@trama.test';

    public function authorize(): bool
    {
        /** @var User|null $managedUser */
        $managedUser = $this->route('user');

        return $this->user()?->isAdmin() === true
            && $managedUser?->role !== 'reader';
    }

    /** Normaliza los campos y genera el correo completo. */
    protected function prepareForValidation(): void
    {
        $emailLocal = Str::lower(
            trim((string) $this->input('email_local', ''))
        );

        $this->merge([
            'name' => $this->normalizeSingleLine((string) $this->input('name', '')),
            'email_local' => $emailLocal,
            'email' => $emailLocal !== '' ? $emailLocal.self::EMAIL_DOMAIN : '',
            'job_title' => $this->normalizeSingleLine((string) $this->input('job_title', '')),
            // La biografía corresponde solamente a editor y periodista.
            'bio' => $this->input('role') === 'admin'
                ? null
                : $this->normalizeSingleLine((string) $this->input('bio', '')),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var User|null $managedUser */
        $managedUser = $this->route('user');

        return [
            'name' => [
                'required',
                'string',
                'min:3',
                'max:40',
                Rule::unique('users', 'name')
                    ->where(fn ($query) => $query->whereIn('role', User::EDITORIAL_ROLES))
                    ->ignore($managedUser?->id),
            ],
            'email_local' => [
                'required',
                'string',
                'max:39',
                'regex:/^[A-Za-z0-9](?:[A-Za-z0-9._-]{0,37}[A-Za-z0-9])?$/',
            ],
            'email' => [
                'required',
                'email',
                'max:50',
                Rule::unique('users', 'email')->ignore($managedUser?->id),
            ],
            'role' => ['required', Rule::in(['admin', 'editor', 'journalist'])],
            'job_title' => ['required', 'string', 'max:35'],
            'bio' => [
                Rule::requiredIf($this->input('role') !== 'admin'),
                'nullable',
                'string',
                'min:30',
                'max:300',
            ],
            'avatar_file' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:4096',
                'dimensions:min_width=600,min_height=600',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Ingresá el nombre y apellido del empleado.',
            'name.min' => 'El nombre debe tener al menos 3 caracteres.',
            'name.max' => 'El nombre no puede superar los 40 caracteres.',
            'name.unique' => 'Ya existe un empleado con este nombre.',

            'email_local.required' => 'Ingresá el correo del empleado.',
            'email_local.max' => 'El identificador del correo es demasiado largo.',
            'email_local.regex' => 'Usá solo letras, números, puntos, guiones o guiones bajos antes de @trama.test.',
            'email.required' => 'Ingresá el correo del empleado.',
            'email.email' => 'El correo generado no es válido.',
            'email.max' => 'El correo no puede superar los 50 caracteres.',
            'email.unique' => 'Ya existe una cuenta con ese correo.',

            'role.required' => 'Seleccioná un rol para el empleado.',
            'role.in' => 'El rol seleccionado no es válido.',
            'job_title.required' => 'Ingresá el cargo del empleado.',
            'job_title.max' => 'El cargo no puede superar los 35 caracteres.',

            'bio.required' => 'Ingresá una biografía para el empleado.',
            'bio.min' => 'La biografía debe tener al menos 30 caracteres.',
            'bio.max' => 'La biografía no puede superar los 300 caracteres.',

            'avatar_file.image' => 'La foto del empleado debe ser una imagen válida.',
            'avatar_file.mimes' => 'La foto debe estar en formato JPG, JPEG, PNG o WEBP.',
            'avatar_file.max' => 'La foto no puede superar los 4 MB.',
            'avatar_file.dimensions' => 'La foto debe medir al menos 600 × 600 píxeles.',
        ];
    }

    private function normalizeSingleLine(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }
}
