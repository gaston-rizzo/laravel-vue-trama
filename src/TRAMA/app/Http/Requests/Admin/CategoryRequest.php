<?php

/* ============================================================================
 * REQUEST: CategoryRequest.php
 * ============================================================================
 *
 * Valida los datos editoriales obligatorios de una categoría.
 *
 * Nombre, descripción, color e imagen deben quedar completos. El orden editorial
 * no se define al crear: aparece únicamente cuando la categoría está activa y
 * ya tiene al menos una noticia publicada visible en el portal.
 * ============================================================================ */

namespace App\Http\Requests\Admin;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
{
    /** Solo el administrador puede modificar la estructura de categorías. */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Category|null $category */
        $category = $this->route('category');

        return [
            'name' => [
                'required',
                'string',
                'min:3',
                'max:30',
                Rule::unique('categories', 'name')->ignore($category?->id),
            ],
            'description' => ['required', 'string', 'min:20', 'max:120'],
            'accent_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            // Solo se envía desde Editar cuando la categoría ya participa del portal.
            'sort_order' => ['nullable', 'integer', 'min:1', 'max:12'],
            'cover_image_file' => [
                Rule::requiredIf(
                    $this->isMethod('post')
                    || ! ($category instanceof Category)
                    || blank($category->cover_image)
                ),
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
        ];
    }

    /**
     * Limpia los campos breves para impedir espacios repetidos y saltos de línea.
     * La descripción se persiste siempre como un único párrafo.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->normalizeSingleLine($this->input('name')),
            'description' => $this->normalizeSingleLine($this->input('description')),
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Ingresá un nombre para la categoría.',
            'name.min' => 'El nombre debe tener al menos 3 caracteres.',
            'name.max' => 'El nombre no puede superar los 30 caracteres.',
            'name.unique' => 'Ya existe una categoría con ese nombre.',
            'description.required' => 'Ingresá una descripción para la categoría.',
            'description.min' => 'La descripción debe tener al menos 20 caracteres.',
            'description.max' => 'La descripción no puede superar los 120 caracteres.',
            'accent_color.required' => 'Elegí un color para la categoría.',
            'accent_color.regex' => 'Ingresá un color hexadecimal válido.',
            'sort_order.integer' => 'La posición editorial debe ser un número entero.',
            'sort_order.min' => 'La posición editorial debe comenzar en 1.',
            'sort_order.max' => 'La posición editorial no puede superar 12.',
            'cover_image_file.required' => 'Seleccioná una imagen para la categoría.',
            'cover_image_file.image' => 'El archivo seleccionado debe ser una imagen válida.',
            'cover_image_file.mimes' => 'La imagen debe ser JPG, JPEG, PNG o WEBP.',
            'cover_image_file.max' => 'La imagen no puede superar los 5 MB.',
        ];
    }

    /** Reduce cualquier valor textual a una sola línea normalizada. */
    private function normalizeSingleLine(mixed $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }
}
