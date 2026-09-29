<?php

/* ============================================================================
 * RESOURCE: AdminCommentListResource.php
 * ============================================================================
 *
 * Define los datos de cada fila enviada a la grilla de moderación.
 *
 * Cada fila incluye el estado, un fragmento del comentario, el autor,
 * la noticia asociada, el tipo, la cantidad de reportes y la fecha.
 *
 * Ejemplo:
 *
 *      [
 *          'id' => 145,
 *          'status' => 'approved',
 *          'body_preview' => 'No estoy de acuerdo con esta medida...',
 *          'type' => 'comment',
 *          'reports_count' => 2,
 *      ]
 * ============================================================================ */

namespace App\Http\Resources\Editorial;

use App\Models\Comment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class AdminCommentListResource extends JsonResource
{
    /**
     * Transforma un comentario en una fila de la grilla de moderación.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /*
         * Las relaciones necesarias deben venir precargadas desde
         * CommentIndexQueryService.
         *
         * relationLoaded() evita que el Resource provoque consultas adicionales
         * por cada registro y previene problemas de tipo N+1.
         */
        $user = $this->resource->relationLoaded('user')
            ? $this->user
            : null;

        $article = $this->resource->relationLoaded('article')
            ? $this->article
            : null;

        /*
         * Prioriza los datos actuales de la cuenta asociada al comentario.
         *
         * Si la relación con el usuario ya no puede resolverse, utiliza los
         * datos históricos guardados junto al comentario como respaldo.
         */
        $authorName = $this->user_id === null
            ? 'Usuario eliminado'
            : ($user?->name
                ?? $this->author_name
                ?? 'Cuenta eliminada');

        $authorEmail = $this->user_id === null
            ? null
            : ($user?->email
                ?? $this->author_email);

        return [
            /*
             * Identificación y estado actual del comentario.
             */
            'id' => $this->id,
            'status' => $this->status,
            // Permite separar rechazos editoriales de rechazos automáticos.
            'moderation_source' => $this->moderation_source,
            // Código corto útil para auditoría y para el modal de detalle.
            'moderation_reason' => $this->moderation_reason,

            /*
             * Fragmento compacto utilizado en la columna Comentario.
             *
             * squish() elimina saltos de línea y espacios repetidos para que el
             * contenido no altere innecesariamente la altura de la fila.
             *
             * limit() restringe la longitud del texto enviado a la grilla.
             */
            'body_preview' => Str::of((string) $this->body)
                ->squish()
                ->limit(180)
                ->toString(),

            /*
             * parent_id permite identificar si el registro corresponde a un
             * comentario principal o a una respuesta.
             *
             * Un valor null representa un comentario principal.
             * Un valor distinto de null representa una respuesta.
             */
            'parent_id' => $this->parent_id,

            'type' => $this->parent_id === null
                ? 'comment'
                : 'reply',

            /*
             * Información compacta del autor utilizada por la grilla.
             *
             * context conserva el valor interno guardado en author_context.
             * context_label expone una etiqueta comprensible cuando el comentario
             * pertenece a una intervención identificada del equipo de TRAMA.
             */
            'author' => [
                'id' => $this->user_id,
                'name' => $authorName,
                'email' => $authorEmail,
                'context' => $this->author_context,
                'context_label' => $this->authorContextLabel(),
                // La grilla necesita una señal rápida cuando administración ya
                // bloqueó la cuenta autora, sin cargar el motivo completo.
                'account_blocked' => $user !== null && ! $user->is_active,
            ],

            /*
             * Información mínima de la noticia asociada al comentario.
             *
             * Si la relación no está disponible se devuelve null para mantener
             * una estructura segura y predecible en la interface.
             */
            'article' => $article
                ? [
                    'id' => $article->id,
                    'title' => $article->title,
                    'slug' => $article->slug,
                ]
                : null,

            /*
             * Cantidad de reportes que continúan abiertos para el comentario.
             *
             * El valor llega calculado desde la consulta principal mediante
             * open_reports_count. Si no está presente se utiliza cero.
             */
            'reports_count' => (int) (
                $this->open_reports_count
                ?? 0
            ),

            /*
             * Fecha en formato ISO 8601 para que Vue pueda formatearla según
             * las necesidades visuales de la grilla.
             */
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Traduce el contexto interno del autor a una etiqueta comprensible
     * dentro del panel editorial.
     */
    private function authorContextLabel(): ?string
    {
        return match ($this->author_context) {
            Comment::AUTHOR_CONTEXT_ARTICLE_AUTHOR => 'Autor de la nota',
            Comment::AUTHOR_CONTEXT_TRAMA_TEAM => 'Equipo TRAMA',
            default => null,
        };
    }
}
