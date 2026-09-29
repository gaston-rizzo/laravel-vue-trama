<?php

/* ============================================================================
 * REQUEST: PublicContactRequest.php
 * ============================================================================
 *
 * Valida las consultas enviadas desde la página pública Contacto.
 *
 * Reglas principales:
 * - Nombre: 2 a 40 caracteres.
 * - Email: formato válido, validación estricta de dominio y máximo 50.
 * - Mensaje: 15 a 1200 caracteres útiles, con una sola línea vacía máxima.
 *
 * Los valores de texto se recortan antes de validar para evitar que espacios
 * al inicio o al final alteren artificialmente las longitudes mínimas.
 * ============================================================================ */

namespace App\Http\Requests;

use App\Rules\StrictEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PublicContactRequest extends FormRequest
{
    /**
     * La página de contacto está disponible tanto para invitados
     * como para usuarios autenticados.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normaliza los campos de texto antes de ejecutar las reglas.
     *
     * El mensaje conserva saltos normales y una sola línea vacía entre párrafos.
     * Tres o más Enter consecutivos se reducen a dos, incluso si las líneas
     * intermedias contienen espacios o tabulaciones.
     */
    protected function prepareForValidation(): void
    {
        $message = str_replace(
            ["\r\n", "\r"],
            "\n",
            (string) $this->input('message', '')
        );

        $message = preg_replace(
            "/[ \t]*\n[ \t]*/",
            "\n",
            $message
        ) ?? $message;

        $message = preg_replace(
            "/\n{3,}/",
            "\n\n",
            $message
        ) ?? $message;

        $this->merge([
            'reason' => is_string($this->reason)
                ? trim($this->reason)
                : $this->reason,

            'name' => is_string($this->name)
                ? trim($this->name)
                : $this->name,

            'email' => is_string($this->email)
                ? trim($this->email)
                : $this->email,

            'message' => trim($message),
        ]);
    }

    /**
     * Reglas de los campos visibles del formulario.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => [
                'bail',
                'required',
                Rule::in([
                    'account',
                    'editorial',
                    'advertising',                    
                    'other',
                ]),
            ],

            'name' => [
                'bail',
                'required',
                'string',
                'min:2',
                'max:40',
            ],

            'email' => [
                'bail',
                'required',
                'string',
                'email',
                'max:50',
                new StrictEmail(),
            ],

            'message' => [
                'bail',
                'required',
                'string',
                'min:15',
                'max:1200',
            ],
        ];
    }

    /**
     * Mensajes visibles en la página pública.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Seleccioná el motivo de la consulta.',
            'reason.in' => 'El motivo seleccionado no es válido.',

            'name.required' => 'Ingresá tu nombre.',
            'name.string' => 'El nombre ingresado no es válido.',
            'name.min' => 'El nombre debe tener al menos 2 caracteres.',
            'name.max' => 'El nombre no puede superar los 40 caracteres.',

            'email.required' => 'Ingresá tu correo.',
            'email.string' => 'El correo ingresado no es válido.',
            'email.email' => 'Ingresá un correo electrónico válido.',
            'email.max' => 'El correo no puede superar los 50 caracteres.',

            'message.required' => 'Escribí tu consulta.',
            'message.string' => 'La consulta ingresada no es válida.',
            'message.min' => 'La consulta debe tener al menos 15 caracteres.',
            'message.max' => 'La consulta no puede superar los 1200 caracteres.',
        ];
    }
}