<?php

/* ============================================================================
 * RESOURCE: AdminArticleResource.php
 * ============================================================================
 *
 * Prepara noticias para las pantallas privadas del panel editorial.
 *
 * Se usa cuando Vue muestra la tabla de noticias del admin y cuando abre el
 * formulario para crear o editar una noticia. Devuelve contenido editable,
 * estado editorial, autor, sección, etiquetas, portada y fechas necesarias para
 * trabajar la noticia desde el panel, sin enviar datos sensibles de otros
 * usuarios.
 * ============================================================================ */

namespace App\Http\Resources;

use App\Services\HtmlSanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminArticleResource extends JsonResource
{
    /**
     * Arma el arreglo que usa Vue en las pantallas privadas de noticias.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Campos de identidad y edición principal.
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'subtitle' => $this->subtitle,
            'excerpt' => $this->excerpt,
            // Convierte noticias antiguas en texto plano a párrafos HTML y conserva
            // correctamente el contenido enriquecido de las noticias nuevas.
            'body' => app(HtmlSanitizer::class)->sanitize(
                (string) $this->body
            ),
            // Portada actual y texto alternativo usados por el formulario.
            'cover_image' => $this->cover_image,
            // Permite distinguir una noticia sin portada de una ruta cuyo archivo
            // fue eliminado o renombrado manualmente fuera de TRAMA.
            'cover_image_exists' => $this->coverImageExists(),
            'cover_alt' => $this->cover_alt,
            // Estado editorial y marcas de portada que el panel puede modificar.
            'status' => $this->status,
            'is_breaking' => (bool) $this->is_breaking,
            'is_featured' => (bool) $this->is_featured,            
            'reading_time' => $this->reading_time,
            'views' => $this->views,
            // Formato compatible con input datetime-local de HTML.
            'published_at' => $this->published_at?->format('Y-m-d\TH:i'),
            'scheduled_at' => $this->scheduled_at?->format('Y-m-d\TH:i'),
            'category_id' => $this->category_id,
            'author_id' => $this->author_id,
            // Ids usados para dejar seleccionadas las etiquetas en el formulario.
            'tag_ids' => $this->whenLoaded('tags', fn () => $this->tags->pluck('id')->values()),
            // Categoría cargada para mostrar contexto en grillas y formularios.
            'category' => $this->whenLoaded(
                'category',
                function () {
                    // Un borrador puede no tener categoría todavía.
                    if ($this->category === null) {
                        return null;
                    }

                    return [
                        'name' => $this->category->name,
                        'slug' => $this->category->slug,
                        'accent_color' => $this->category->accent_color,
                    ];
                }
            ),
            // Autor cargado para permisos visuales y datos de la tabla.
            'author' => $this->whenLoaded('author', fn () => [
                'id' => $this->author->id,
                'name' => $this->author->name,
                'role' => $this->author->role,
            ]),
            'updated_at' => $this->updated_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
