<?php

/* ============================================================================
 * REQUEST: MergeTagRequest.php
 * ============================================================================
 *
 * Valida el destino de una fusión de etiquetas.
 *
 * La etiqueta elegida debe existir, permanecer activa y ser diferente de la
 * etiqueta que se está absorbiendo.
 * ============================================================================ */

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MergeTagRequest extends FormRequest
{
    /**
     * Solo un administrador puede fusionar etiquetas.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /**
     * Reglas de selección de la etiqueta principal.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'target_tag_id' => [
                'required',
                'integer',
                Rule::exists('tags', 'id')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->whereNull('merged_into_id')),
                Rule::notIn([(int) $this->route('tag')?->id]),
            ],
        ];
    }
}
