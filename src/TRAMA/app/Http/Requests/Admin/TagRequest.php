<?php

/* ============================================================================
 * REQUEST: TagRequest.php
 * ============================================================================
 *
 * Valida la creación y edición de etiquetas temáticas.
 *
 * Evita nombres duplicados, normaliza los campos textuales y valida nombre,
 * descripción y disponibilidad. La selección de etiquetas activas para noticias
 * se resuelve después en el controlador.
 * ============================================================================ */

namespace App\Http\Requests\Admin;

use App\Models\Tag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TagRequest extends FormRequest
{
    /**
     * Solo un administrador puede administrar etiquetas.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /**
     * Normaliza los campos textuales antes de ejecutar las reglas.
     *
     * La descripción de una etiqueta es un texto administrativo breve de una
     * sola línea lógica. Puede ocupar varias líneas visuales dentro del textarea,
     * pero no conserva saltos de línea, tabulaciones ni espacios repetidos.
     *
     * Esto también impide saltarse la normalización enviando la petición
     * directamente sin pasar por Vue.
     */
    protected function prepareForValidation(): void
    {
        $name = preg_replace(
            '/\s+/u',
            ' ',
            trim((string) $this->input('name', ''))
        ) ?? '';

        $description = preg_replace(
            '/\s+/u',
            ' ',
            trim((string) $this->input('description', ''))
        ) ?? '';

        $this->merge([
            'name' => $name,
            'description' => $description,
        ]);
    }

    /**
     * Reglas del formulario de etiqueta.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Tag|null $tag */
        $tag = $this->route('tag');

        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:30',
                Rule::unique('tags', 'name')->ignore($tag?->id),
            ],

            'description' => [
                'required',
                'string',
                'min:10',
                'max:160',
            ],

            'is_active' => [
                'required',
                'boolean',
            ],
        ];
    }

    /**
     * Mensajes claros para validaciones normales del formulario administrativo.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Ingresá un nombre para la etiqueta.',
            'name.min' => 'El nombre debe tener al menos 2 caracteres.',
            'name.max' => 'El nombre no puede superar los 30 caracteres.',
            'name.unique' => 'Ya existe una etiqueta con ese nombre.',

            'description.required' => 'Ingresá una descripción para la etiqueta.',
            'description.min' => 'La descripción debe tener al menos 10 caracteres.',
            'description.max' => 'La descripción no puede superar los 160 caracteres.',

            'is_active.required' => 'Indicá si la etiqueta estará disponible para nuevas noticias.',
            'is_active.boolean' => 'El estado de la etiqueta no es válido.',
        ];
    }
}
