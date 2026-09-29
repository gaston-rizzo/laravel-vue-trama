<?php

/* ============================================================================
 * RESOURCE: AdminArticleListResource.php
 * ============================================================================
 *
 * Define la estructura de cada noticia enviada al listado editorial.
 *
 * Devuelve únicamente los datos que necesita la tabla: identificación,
 * portada, título, estado, autor, categoría, vistas y fechas editoriales.
 *
 * Excluye campos pesados o innecesarios como body, subtitle, excerpt,
 * search_text, etiquetas completas, historial y contenido HTML.
 *
 * Ejemplo de salida:
 *
 *      [
 *          'id' => 25,
 *          'title' => 'Congreso debate una nueva reforma',
 *          'slug' => 'congreso-debate-una-nueva-reforma',
 *          'status' => 'published',
 *          'cover_image' => '/images/articles/congreso.webp',
 *          'views' => 3200,
 *          'author' => [
 *              'id' => 4,
 *              'name' => 'Bruno Salvatierra',
 *          ],
 *          'category' => [
 *              'id' => 2,
 *              'name' => 'Política',
 *              'slug' => 'politica',
 *          ],
 *          'published_at' => '2026-08-05T12:30:00-03:00',
 *          'scheduled_at' => null,
 *          'updated_at' => '2026-08-05T13:15:00-03:00',
 *      ]
 * ============================================================================ */

namespace App\Http\Resources\Editorial;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminArticleListResource extends JsonResource
{
    /**
     * Transforma una noticia en los datos utilizados por el listado editorial.
     *
     * Las relaciones author y category deben venir precargadas desde la consulta
     * para evitar consultas adicionales por cada noticia.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /*
             * Datos principales utilizados para identificar la noticia y abrir
             * sus acciones desde el listado.
             */
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'status' => $this->status,
            'is_breaking' => (bool) $this->is_breaking,
            'is_featured' => (bool) $this->is_featured,
            'cover_image' => $this->cover_image,
            // La grilla puede advertir si la base apunta a una portada inexistente.
            'cover_image_exists' => $this->coverImageExists(),
            'views' => $this->views,

            /*
             * El autor se incluye solamente cuando la relación fue precargada
             * por ArticleIndexQueryService.
             */
            'author' => $this->whenLoaded(
                'author',
                fn ($author): array => [
                    'id' => $author->id,
                    'name' => $author->name,
                ],
            ),

            /*
             * Una noticia todavía en borrador puede no tener categoría. Cuando
             * la relación es null, Laravel devuelve category como null.
             */
            'category' => $this->whenLoaded(
                'category',
                fn ($category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                ],
            ),

            /*
             * Las fechas se entregan en formato ISO 8601 para que Vue pueda
             * mostrarlas y ordenarlas sin depender del formato de MariaDB.
             */
            'published_at' => $this->published_at?->toIso8601String(),
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}