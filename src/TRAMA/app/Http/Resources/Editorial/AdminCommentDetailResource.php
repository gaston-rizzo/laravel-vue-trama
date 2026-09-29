<?php

/* ============================================================================
 * RESOURCE: AdminCommentDetailResource.php
 * ============================================================================
 *
 * Define la información completa mostrada dentro del modal de moderación.
 *
 * A diferencia de AdminCommentListResource, este recurso no limita el cuerpo
 * del comentario porque el editor necesita leer el contenido completo antes de
 * tomar una decisión.
 *
 * También incluye:
 *
 *      - autor actual o datos históricos de respaldo;
 *      - noticia asociada;
 *      - comentario principal cuando la fila es una respuesta;
 *      - reportes abiertos y sus motivos;
 *      - cantidad de respuestas directas;
 *      - estado de una derivación administrativa del autor.
 * ============================================================================ */

namespace App\Http\Resources\Editorial;

use App\Models\Comment;
use App\Models\CommentReport;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminCommentDetailResource extends JsonResource
{
    /**
     * Transforma un comentario en la estructura utilizada por el modal.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $comment = $this->resource;

        $user = $comment->relationLoaded('user')
            ? $comment->user
            : null;

        $article = $comment->relationLoaded('article')
            ? $comment->article
            : null;

        $parent = $comment->relationLoaded('parent')
            ? $comment->parent
            : null;

        $reports = $comment->relationLoaded('reports')
            ? $comment->reports
            : collect();

        return [
            /*
             * Identificación y estado actual del comentario.
             */
            'id' => $comment->id,
            'status' => $comment->status,
            'moderation_source' => $comment->moderation_source,
            'moderation_reason' => $comment->moderation_reason,
            'body' => (string) $comment->body,
            'parent_id' => $comment->parent_id,
            'type' => $comment->parent_id === null
                ? 'comment'
                : 'reply',

            /*
             * Autor actual o información histórica cuando la cuenta ya no existe.
             *
             * review_requested_at conserva cuándo se abrió la revisión más reciente;
             * review_pending indica si esa solicitud todavía requiere una decisión.
             */
            'author' => $this->authorData(
                $comment,
                $user,
            ),

            /*
             * Indica si la cuenta puede derivarse al administrador.
             *
             * La derivación pertenece únicamente a usuarios registrados. Las
             * intervenciones internas de TRAMA no se tratan como cuentas públicas.
             */
            'can_refer_to_admin' => $user instanceof User
                && $user->role === 'reader'
                && $user->is_active
                && ! $user->hasPendingModerationReview()
                && $comment->author_context === null,

            /*
             * Información de la noticia asociada al comentario.
             */
            'article' => $article
                ? [
                    'id' => $article->id,
                    'title' => $article->title,
                    'slug' => $article->slug,
                ]
                : null,

            /*
             * Cuando se modera una respuesta se incluye el comentario principal
             * para que el editor pueda interpretar el contexto sin salir del modal.
             */
            'parent' => $parent
                ? [
                    'id' => $parent->id,
                    'body' => (string) $parent->body,
                    'status' => $parent->status,
                    'author' => $this->authorData(
                        $parent,
                        $parent->relationLoaded('user')
                            ? $parent->user
                            : null,
                    ),
                    'created_at' => $parent->created_at?->toIso8601String(),
                ]
                : null,

            /*
             * Solamente se cargan reportes abiertos porque son los que todavía
             * requieren una decisión dentro del modal.
             */
            'reports' => $reports
                ->map(
                    function (CommentReport $report): array {
                        $reporter = $report->relationLoaded('user')
                            ? $report->user
                            : null;

                        return [
                            'id' => $report->id,
                            'reason' => $report->reason,
                            'reason_label' => CommentReport::REASON_LABELS[$report->reason]
                                ?? 'Motivo no disponible',
                            'reporter' => $reporter
                                ? [
                                    'id' => $reporter->id,
                                    'name' => $reporter->name,
                                    'email' => $reporter->email,
                                ]
                                : null,
                            'created_at' => $report->created_at?->toIso8601String(),
                        ];
                    },
                )
                ->values(),

            /*
             * Cantidades utilizadas por el modal para decidir qué acciones mostrar.
             */
            'open_reports_count' => (int) (
                $comment->open_reports_count
                ?? $reports->count()
            ),
            'replies_count' => (int) (
                $comment->replies_count
                ?? 0
            ),

            /*
             * Fechas del comentario en formato ISO 8601.
             */
            'created_at' => $comment->created_at?->toIso8601String(),
            'updated_at' => $comment->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Devuelve la identidad visible de un autor usando primero la cuenta actual
     * y luego la copia histórica conservada dentro del comentario.
     *
     * @return array<string, mixed>
     */
    private function authorData(
        Comment $comment,
        ?User $user,
    ): array {
        return [
            'id' => $comment->user_id,
            'name' => $comment->user_id === null
                ? 'Usuario eliminado'
                : ($user?->name
                    ?? $comment->author_name
                    ?? 'Cuenta eliminada'),
            'email' => $comment->user_id === null
                ? null
                : ($user?->email
                    ?? $comment->author_email),
            'context' => $comment->author_context,
            'context_label' => $this->authorContextLabel(
                $comment->author_context,
            ),
            'review_requested_at' => $user?->moderation_review_requested_at?->toIso8601String(),
            'review_reason' => $user?->moderation_review_reason,
            'review_reason_label' => $this->reviewReasonLabel($user?->moderation_review_reason),
            'review_note' => $user?->moderation_review_note,
            'review_pending' => $user instanceof User && $user->hasPendingModerationReview(),
            'review_resolved_at' => $user?->moderation_review_resolved_at?->toIso8601String(),
            'review_resolution' => $user?->moderation_review_resolution,
            'review_resolved_by' => $user?->relationLoaded('moderationReviewResolvedBy')
                && $user->moderationReviewResolvedBy
                    ? [
                        'id' => $user->moderationReviewResolvedBy->id,
                        'name' => $user->moderationReviewResolvedBy->name,
                    ]
                    : null,
            'account_blocked' => $user instanceof User && ! $user->is_active,
            'blocked_since' => $user?->disabled_at?->toIso8601String(),
            'blocked_reason' => $user?->blocked_reason,
            'blocked_reason_label' => $this->blockedReasonLabel($user?->blocked_reason),
        ];
    }

    /**
     * Traduce el motivo que el editor adjuntó a una solicitud administrativa.
     */
    private function reviewReasonLabel(?string $reason): ?string
    {
        return match ($reason) {
            'spam' => 'Spam',
            'abuse' => 'Insultos o acoso',
            'inappropriate' => 'Contenido inapropiado',
            'repeated' => 'Conducta repetida',
            'other' => 'Otro',
            default => null,
        };
    }

    /**
     * Traduce el motivo administrativo del bloqueo sin exponer observaciones internas.
     */
    private function blockedReasonLabel(?string $reason): ?string
    {
        return match ($reason) {
            'moderation' => 'Moderación reiterada',
            'abuse' => 'Conducta abusiva',
            'spam' => 'Spam',
            'security' => 'Seguridad',
            'terms' => 'Incumplimiento de términos',
            'other' => 'Otro motivo',
            default => null,
        };
    }

    /**
     * Traduce el contexto interno del autor a una etiqueta comprensible.
     */
    private function authorContextLabel(?string $context): ?string
    {
        return match ($context) {
            Comment::AUTHOR_CONTEXT_ARTICLE_AUTHOR => 'Autor de la nota',
            Comment::AUTHOR_CONTEXT_TRAMA_TEAM => 'Equipo TRAMA',
            default => null,
        };
    }
}
