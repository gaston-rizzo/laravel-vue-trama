<?php

/* ============================================================================
 * RESOURCE: ArticleCardResource.php
 * ============================================================================
 *
 * Prepara una noticia para vistas resumidas del portal.
 *
 * Se usa cuando Vue necesita mostrar una noticia como vista previa: portada,
 * resultados de búsqueda, rankings, página de una sección y noticias
 * relacionadas al final de un artículo. Devuelve título, bajada, imagen,
 * sección, autor, etiquetas y fechas, pero no envía el cuerpo completo de la
 * noticia porque esas pantallas no muestran el texto principal.
 * ============================================================================ */

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class ArticleCardResource extends JsonResource
{
    /**
     * Arma el arreglo que Vue usa para pintar una tarjeta de noticia.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Identidad y textos que se ven en tarjetas públicas.
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'subtitle' => $this->subtitle,
            'excerpt' => $this->excerpt,
            // Si la portada física falta, el modelo entrega noticia-default.webp.
            'cover_image' => $this->publicCoverImageUrl(),
            'cover_alt' => $this->cover_alt,
            // Banderas usadas por la portada y la cinta urgente.
            'is_breaking' => (bool) $this->is_breaking,
            'is_featured' => (bool) $this->is_featured,            
            'reading_time' => $this->reading_time,
            'views' => $this->views,
            'published_at' => $this->published_at?->toISOString(),
            // whenLoaded evita consultas automáticas si el controller no pidió categoría.
            'category' => $this->whenLoaded('category', fn () => [
                'name' => $this->category->name,
                'slug' => $this->category->slug,
                'accent_color' => $this->category->accent_color,
            ]),
            // Datos mínimos del autor para tarjetas y rankings.
            'author' => $this->whenLoaded('author', fn () => [
                'id' => $this->author->id,
                'name' => $this->author->name,
                'job_title' => $this->author->job_title,
                // avatarUrl() usa el fallback genérico si la foto física falta.
                'avatar' => $this->author->avatarUrl(),
                'profile_url' => route('journalists.show', [
                    'user' => $this->author->id,
                    'slug' => Str::slug($this->author->name),
                ]),
            ]),
            // Etiquetas públicas solo se envían si ya fueron cargadas.
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($tag) => [
                'name' => $tag->name,
                'slug' => $tag->slug,
            ])->values()),
        ];
    }
}
