<?php

/* ============================================================================
 * RESOURCE: ArticleDetailResource.php
 * ============================================================================
 *
 * Prepara una noticia para la página de lectura.
 *
 * Toma el modelo Article cargado desde la base y devuelve exactamente los datos
 * que necesita Vue para mostrar la noticia abierta: título, bajada, cuerpo,
 * imagen, autor, sección, etiquetas y comentarios visibles para quien abre la
 * página. Los comentarios pendientes solo se incluyen para su propio autor.
 * ============================================================================ */

namespace App\Http\Resources;

use App\Models\Comment;
use App\Models\CommentReport;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class ArticleDetailResource extends JsonResource
{
    /**
     * Arma el arreglo que recibe Vue cuando el usuario abre una noticia.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Cantidad de comentarios principales que Vue ya recibió en la carga inicial.
        $commentsNextOffset = (int) ($this->comments_next_offset ?? $this->comments->count());
        // Total de comentarios principales visibles para quien abrió la noticia.
        $visibleMainCommentsCount = (int) ($this->visible_main_comments_count ?? $this->comments->count());

        // Usuario que está leyendo la noticia, si inició sesión.
        $currentUser = $request->user();

        /*
        * Identificación pública que tendría una respuesta del usuario actual.
        *
        * null significa que no necesita una identificación especial.
        */
        $replyContext = $currentUser?->publicCommentContextFor($this->resource);

        $replyIdentity = match ($replyContext) {
            Comment::AUTHOR_CONTEXT_ARTICLE_AUTHOR => 'Autor de la nota',
            Comment::AUTHOR_CONTEXT_TRAMA_TEAM => 'Equipo TRAMA',
            default => null,
        };

        return [
            // Identidad y contenido principal de la noticia.
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'subtitle' => $this->subtitle,
            'excerpt' => $this->excerpt,
            // Cuerpo listo para renderizar como HTML seguro en Vue.
            'body' => $this->formattedBody(),
            // Nunca se expone una URL rota al portal público.
            'cover_image' => $this->publicCoverImageUrl(),
            'cover_alt' => $this->cover_alt,
            'image_credit' => $this->image_credit,
            'image_source_url' => $this->image_source_url,
            'image_license' => $this->image_license,
            'reading_time' => $this->reading_time,
            'views' => $this->views,
            'created_at' => $this->created_at?->toISOString(),
            'published_at' => $this->published_at?->toISOString(),
            'category' => [
                // Datos de sección usados para etiqueta visual y enlace.
                'name' => $this->category->name,
                'slug' => $this->category->slug,
                'accent_color' => $this->category->accent_color,
            ],
            'author' => [
                // Datos públicos del autor mostrados junto al artículo.
                'id' => $this->author->id,
                'name' => $this->author->name,
                'job_title' => $this->author->job_title,
                'bio' => $this->author->bio,
                // Evita exponer una ruta rota si el avatar fue eliminado fuera del panel.
                'avatar' => $this->author->avatarUrl(),
                'profile_url' => route('journalists.show', [
                    'user' => $this->author->id,
                    'slug' => Str::slug($this->author->name),
                ]),
            ],
            'tags' => $this->tags->map(fn ($tag) => [
                // Etiquetas públicas asociadas a la noticia.
                'name' => $tag->name,
                'slug' => $tag->slug,
            ])->values(),
            /*
            * Permisos públicos de participación calculados por Laravel.
            *
            * Vue utiliza estas banderas solamente para mostrar u ocultar controles.
            * Las mismas reglas vuelven a comprobarse en el controller al recibir
            * cualquier petición.
            */
            'comment_permissions' => [
                'can_create_main' => $currentUser?->canCreatePublicMainComment() ?? false,
                'can_reply' => $currentUser?->canReplyToPublicComments($this->resource) ?? false,
                'can_like' => $currentUser?->canLikePublicComments() ?? false,
                'reply_identity' => $replyIdentity,
            ],
            /*
            * Motivos disponibles dentro del modal "Reportar comentario".
            *
            * Los valores internos y sus textos se definen en CommentReport para evitar
            * mantener listas distintas entre Laravel y Vue.
            */
            'comment_report_reasons' => CommentReport::reasonOptions(),
            // Convierte la primera tanda de comentarios ya cargada por el controller
            // al mismo formato que usan los endpoints de "Ver más".
            'comments' => CommentResource::collection($this->comments)->resolve($request),
            // Offset inicial: Vue ya recibió esta cantidad de comentarios principales.
            'comments_next_offset' => $commentsNextOffset,
            // Cantidad de comentarios principales visibles que todavía no se enviaron.
            'comments_remaining' => max($visibleMainCommentsCount - $commentsNextOffset, 0),
            // Bandera para mostrar u ocultar el botón "Ver más comentarios".
            'comments_has_more' => $visibleMainCommentsCount > $commentsNextOffset,
            // Total general visible: comentarios principales más respuestas.
            'comments_total_count' => (int) ($this->visible_comments_count ?? $this->comments->count()),
            // True mientras Node todavía está validando cualquier comentario o respuesta
            // de esta cuenta dentro de la noticia. Activa únicamente el refresco liviano
            // de estado en Vue; el contenido processing nunca se expone.
            'has_processing_comments' => (bool) ($this->has_processing_comments ?? false),
            // True mientras Node todavía está validando el comentario principal del usuario registrado.
            // `processing` no es moderación humana y el comentario aún no se muestra en la noticia.
            'has_processing_main_comment' => (bool) ($this->has_processing_main_comment ?? false),
            // True cuando el análisis automático terminó y el comentario principal
            // fue derivado realmente a moderación humana.
            'has_pending_main_comment' => (bool) ($this->has_pending_main_comment ?? false),
            // True cuando existe un comentario principal processing, pendiente o aprobado.
            // Mientras sea true no se ofrece un segundo comentario principal.
            'has_active_main_comment' => (bool) ($this->has_active_main_comment ?? false),
        ];
    }

    /**
     * Convierte contenido histórico en texto plano a HTML de párrafos.
     *
     * Las noticias nuevas ya se guardan como HTML sanitizado. Esta conversión
     * mantiene legibles las noticias sembradas antes de incorporar el editor
     * enriquecido.
     */
    private function formattedBody(): string
    {
        $body = trim((string) $this->body);

        if ($body === '' || str_contains($body, '<')) {
            // Si ya está vacío o ya contiene HTML, se devuelve sin envolver.
            return $body;
        }

        // Si era texto plano histórico, cada bloque separado por línea vacía se
        // convierte en un párrafo HTML.
        return collect(preg_split('/\n{2,}/', $body) ?: [])
            // Quita bloques vacíos creados por saltos de línea de más.
            ->filter()
            // Escapa texto plano y lo envuelve en párrafos HTML seguros.
            ->map(fn (string $paragraph) => '<p>'.e(trim($paragraph)).'</p>')
            // Une todos los párrafos en un único string HTML para Vue.
            ->implode('');
    }

}
