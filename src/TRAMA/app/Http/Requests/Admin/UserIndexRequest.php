<?php

/* ============================================================================
 * REQUEST: UserIndexRequest.php
 * ============================================================================
 *
 * Valida los filtros y la paginación de empleados y usuarios registrados.
 *
 * El listado puede crecer a miles de cuentas, por lo que búsqueda, estado,
 * revisión, ordenamiento y cantidad por página siempre se resuelven en Laravel.
 * ============================================================================ */

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', Rule::in(['active', 'blocked'])],
            'review' => ['nullable', Rule::in(['requested', 'resolved', 'none'])],
            'sort' => ['nullable', Rule::in(['name', 'last_login_at', 'created_at', 'articles_count', 'comments_count'])],
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
            'review' => trim((string) $this->query('review', '')) ?: null,
            'sort' => trim((string) $this->query('sort', 'created_at')) ?: 'created_at',
            'direction' => trim((string) $this->query('direction', 'desc')) ?: 'desc',
            'per_page' => (int) $this->query('per_page', 25),
        ]);
    }
}
