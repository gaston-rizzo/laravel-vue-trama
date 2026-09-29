<?php

/* ============================================================================
 * REQUEST: CategoryIndexRequest.php
 * ============================================================================
 *
 * Valida búsqueda, estado, ordenamiento y paginación de categorías.
 *
 * Laravel ordena sobre el conjunto completo. La columna Publicadas utiliza solo
 * noticias realmente visibles y Orden editorial coloca al final las categorías
 * que todavía no participan de la navegación pública.
 * ============================================================================ */

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryIndexRequest extends FormRequest
{
    /** Solo administración puede consultar esta grilla. */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['published', 'active', 'inactive'])],
            'sort' => [
                'nullable',
                Rule::in(['sort_order', 'name', 'published_articles_count', 'status']),
            ],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([25, 50, 100])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** Normaliza los parámetros de la URL antes de validar. */
    protected function prepareForValidation(): void
    {
        $sort = trim((string) $this->query('sort', 'sort_order')) ?: 'sort_order';

        // Conserva compatibilidad con URLs generadas antes de renombrar columnas.
        if ($sort === 'articles_count') {
            $sort = 'published_articles_count';
        } elseif ($sort === 'is_active') {
            $sort = 'status';
        }

        $this->merge([
            'q' => trim((string) $this->query('q', '')) ?: null,
            'status' => trim((string) $this->query('status', '')) ?: null,
            'sort' => $sort,
            'direction' => trim((string) $this->query('direction', 'asc')) ?: 'asc',
            'per_page' => (int) $this->query('per_page', 25),
        ]);
    }
}
