<?php

/* ============================================================================
 * REQUEST: ToggleUserStatusRequest.php
 * ============================================================================
 *
 * Valida el bloqueo o desbloqueo de cuentas administradas.
 *
 * Los lectores requieren motivo y una observación interna breve al bloquearse.
 * Los empleados no almacenan blocked_reason/blocked_note: su estado se representa
 * únicamente con is_active y disabled_at.
 * ============================================================================ */

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ToggleUserStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('blocked_note')) {
            return;
        }

        $note = str_replace(
            ["\r\n", "\r"],
            "\n",
            (string) $this->input('blocked_note', '')
        );

        $note = preg_replace(
            "/[ \t]*\n[ \t]*/",
            "\n",
            $note
        ) ?? $note;

        $note = preg_replace(
            "/\n{3,}/",
            "\n\n",
            $note
        ) ?? $note;

        $this->merge([
            'blocked_note' => trim($note),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var User|null $managedUser */
        $managedUser = $this->route('user');
        $blockingReader = $managedUser?->role === 'reader'
            && $managedUser?->is_active === true;

        return [
            'blocked_reason' => [
                Rule::requiredIf($blockingReader),
                'nullable',
                Rule::in(['moderation', 'abuse', 'spam', 'security', 'terms', 'other']),
            ],
            'blocked_note' => [
                Rule::requiredIf($blockingReader),
                'nullable',
                'string',
                'min:10',
                'max:200',
                'not_regex:/[\r\n]/',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'blocked_reason.required' => 'Seleccioná un motivo para bloquear la cuenta.',
            'blocked_reason.in' => 'El motivo de bloqueo seleccionado no es válido.',
            'blocked_note.required' => 'Ingresá una observación interna para bloquear la cuenta.',
            'blocked_note.min' => 'La observación interna debe tener al menos 10 caracteres.',
            'blocked_note.max' => 'La observación interna no puede superar los 200 caracteres.',
        ];
    }
}
