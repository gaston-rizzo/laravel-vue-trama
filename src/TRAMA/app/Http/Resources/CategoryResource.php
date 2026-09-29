<?php

/* ============================================================================
 * RESOURCE: CategoryResource.php
 * ============================================================================
 *
 * Prepara una sección para enviarla al frontend.
 *
 * Toma una categoría de la base de datos y devuelve solo los campos que Vue
 * necesita para mostrar navegación, filtros, portada de sección y bloques de
 * noticias. De esta forma el frontend no depende de nombres internos del modelo.
 * ============================================================================ */

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    /**
     * Devuelve los datos públicos de la categoría con nombres estables para Vue.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'accent_color' => $this->accent_color,
            'cover_image' => $this->cover_image,
            'sort_order' => $this->sort_order,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
