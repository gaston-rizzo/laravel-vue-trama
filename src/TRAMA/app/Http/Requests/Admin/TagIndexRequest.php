<?php

/* ============================================================================
 * REQUEST: TagIndexRequest.php
 * ============================================================================
 *
 * Valida búsqueda, filtros, ordenamiento y paginación de etiquetas.
 *
 * La grilla puede acumular cientos de registros históricos, por lo que todos
 * estos parámetros se resuelven en Laravel y Vue recibe solo la página actual.
 * ============================================================================ */

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TagIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'merged'])],
            'usage' => ['nullable', Rule::in(['with_articles', 'without_articles'])],
            'sort' => ['nullable', Rule::in(['name', 'articles_count'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([25, 50, 100])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'q' => trim((string) $this->query('q', '')) ?: null,
            'status' => trim((string) $this->query('status', '')) ?: null,
            'usage' => trim((string) $this->query('usage', '')) ?: null,
            'sort' => trim((string) $this->query('sort', 'name')) ?: 'name',
            'direction' => trim((string) $this->query('direction', 'asc')) ?: 'asc',
            'per_page' => (int) $this->query('per_page', 25),
        ]);
    }
}
