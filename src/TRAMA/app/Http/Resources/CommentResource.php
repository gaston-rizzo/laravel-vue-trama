<?php

/* ============================================================================
 * RESOURCE: CommentResource.php
 * ============================================================================
 *
 * Prepara un comentario para enviarlo a Vue.
 *
 * Define el formato único que usa la página de una noticia para mostrar
 * comentarios principales y respuestas: autor, texto, estado de moderación,
 * cantidad de Me gusta, voto del usuario actual y respuestas cargadas. También
 * informa si quedan más respuestas disponibles para pedirlas por tandas.
 * ============================================================================ */

namespace App\Http\Resources;

use App\Models\Comment;
use App\Models\CommentReport;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommentResource extends JsonResource
{
    /**
     * Convierte el modelo Comment en los datos que consume el frontend.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Usuario autenticado que está leyendo la noticia, si existe.
        $currentUserId = $request->user()?->id;
        // Sirve para decidir si puede editar o eliminar su propio comentario.
        $isOwnComment = $currentUserId && $this->user_id === $currentUserId;

        /*
         * Indica si el usuario actual ya reportó este comentario.
         *
         * La relación viene filtrada desde el controller para no ejecutar una consulta
         * adicional desde el Resource por cada comentario mostrado.
         */
        $currentUserReport = $this->relationLoaded('reports')
            ? $this->reports->first()
            : null;

        $reportedByCurrentUser = $currentUserReport instanceof CommentReport
            && $currentUserReport->status === CommentReport::STATUS_OPEN;

        $reviewedReportByCurrentUser = $currentUserReport instanceof CommentReport
            && $currentUserReport->status !== CommentReport::STATUS_OPEN;

        // Si el controller cargó respuestas, se cuenta cuántas llegaron en esta
        // tanda. Si no las cargó, se usa 0 para evitar consultas adicionales.
        $loadedRepliesCount = $this->relationLoaded('replies')
            ? $this->replies->count()
            : 0;

        // visible_replies_count viene de withCount en el controller. Representa
        // todas las respuestas que el usuario puede ver, no solo las cargadas.
        $visibleRepliesCount = (int) ($this->visible_replies_count ?? $loadedRepliesCount);

        return [
            // Identidad del comentario y del hilo.
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            // Si la cuenta fue eliminada, el comentario conserva el texto sin exponer identidad.
            'author_name' => $this->user_id === null
                ? 'Usuario eliminado'
                : ($this->user?->name ?? $this->author_name),
            /*
            * Identificación especial para integrantes de TRAMA.
            *
            * El valor quedó guardado junto con el comentario, por lo que no cambia
            * aunque el usuario posteriormente tenga otro rol.
            */
            'author_badge' => match ($this->author_context) {
                Comment::AUTHOR_CONTEXT_ARTICLE_AUTHOR => 'Autor de la nota',
                Comment::AUTHOR_CONTEXT_TRAMA_TEAM => 'Equipo TRAMA',
                default => null,
            },
            'body' => $this->body,
            // Estado de moderación: pending, approved o rejected.
            'status' => $this->status,
            // Permite que Vue quite acciones que no tienen sentido sobre contenido propio.
            'is_own_comment' => (bool) $isOwnComment,
            // Los pendientes se muestran solo a su autor con aclaración visual.
            'visible_only_to_author' => $this->status === 'pending',
            // Editar solo está permitido mientras siga pendiente.
            'can_edit' => $isOwnComment && $this->status === 'pending',
            // El autor puede eliminar pendientes o publicados propios.
            'can_delete' => $isOwnComment && in_array($this->status, ['pending', 'approved'], true),
            // Si el usuario ya respondió este comentario principal, Vue no vuelve
            // a ofrecerle la posibilidad de crear otra respuesta.
            'has_own_reply' => (int) ($this->own_replies_count ?? 0) > 0,
            // Cantidad total de Me gusta calculada por withCount.
            'likes_count' => (int) ($this->likes_count ?? 0),
            // Indica si el usuario actual ya votó este comentario.
            'liked_by_current_user' => $this->relationLoaded('likes') && $this->likes->isNotEmpty(),
            /*
            * Solo un lector activo puede reportar un comentario aprobado de otra persona
            * que todavía no haya reportado previamente.
            *
            * Las intervenciones identificadas como Autor de la nota o Equipo TRAMA
            * pertenecen al equipo interno y no pueden ser reportadas desde el portal.
            */
            'can_report' => ($request->user()?->canReportPublicComments() ?? false)
                && ! $isOwnComment
                && $this->status === 'approved'
                && $this->author_context === null
                && ! $currentUserReport,
            // Permite mostrar "Reportado" después de que esa cuenta ya realizó la acción.
            'reported_by_current_user' => $reportedByCurrentUser,
            'report_reviewed_by_current_user' => $reviewedReportByCurrentUser,
            'created_at' => $this->created_at?->toISOString(),
            // Solo serializa respuestas si ya fueron cargadas con eager loading.
            // Esto evita que el Resource dispare consultas escondidas por cada fila.
            'replies' => $this->relationLoaded('replies')
                ? self::collection($this->replies)->resolve($request)
                : [],
            // Offset que Vue manda en el próximo pedido para seguir desde la
            // cantidad de respuestas que ya recibió.
            'replies_next_offset' => $loadedRepliesCount,
            // Cantidad de respuestas visibles que todavía quedan sin cargar.
            'replies_remaining' => max($visibleRepliesCount - $loadedRepliesCount, 0),
            // Bandera directa para que Vue sepa si muestra el botón de cargar más.
            'replies_has_more' => $visibleRepliesCount > $loadedRepliesCount,
        ];
    }
}
