<?php

/* ============================================================================
 * REQUEST: AdvertisementIndexRequest.php
 * ============================================================================
 *
 * Valida y normaliza los filtros del reporte administrativo de publicidades.
 *
 * La grilla trabaja con paginación del lado servidor para poder conservar el
 * historial de campañas aunque con el tiempo se acumulen cientos de banners.
 * El orden también se valida acá porque los encabezados de la tabla solamente
 * pueden solicitar columnas conocidas por Laravel.
 * ============================================================================ */

namespace App\Http\Requests\Admin;

use App\Support\AdvertisementPlacement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdvertisementIndexRequest extends FormRequest
{
    /**
     * El middleware de administración ya controla quién puede abrir este módulo.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Reglas de búsqueda, estado, período, orden y paginación.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'placement' => ['nullable', Rule::in(AdvertisementPlacement::values())],
            'brand' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', Rule::in(['active', 'scheduled', 'finished', 'paused'])],
            'period' => ['nullable', Rule::in(['all', '7', '30'])],
            'sort' => ['nullable', Rule::in(['banner', 'brand', 'name', 'placement', 'starts_at', 'status'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in([25, 50, 100])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Devuelve filtros con valores por defecto consistentes para Laravel y Vue.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $validated = $this->validated();
        $sort = (string) ($validated['sort'] ?? 'banner');

        // Compatibilidad con URLs generadas por la versión anterior del panel,
        // donde Marca y Campaña todavía aparecían en selects separados.
        if (in_array($sort, ['brand', 'name'], true)) {
            $sort = 'banner';
        }

        return [
            'q' => trim((string) ($validated['q'] ?? '')),
            'placement' => (string) ($validated['placement'] ?? ''),
            'brand' => (string) ($validated['brand'] ?? ''),
            'status' => (string) ($validated['status'] ?? ''),
            'period' => (string) ($validated['period'] ?? '30'),
            'sort' => $sort,
            'direction' => (string) ($validated['direction'] ?? 'asc'),
            'per_page' => (int) ($validated['per_page'] ?? 25),
        ];
    }
}
