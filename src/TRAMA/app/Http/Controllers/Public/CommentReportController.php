<?php

/* ============================================================================
 * CONTROLLER: CommentReportController.php
 * ============================================================================
 *
 * Recibe reportes realizados por lectores sobre comentarios publicados.
 *
 * Valida quién puede reportar, impide autoreportes y reportes duplicados,
 * limita intentos abusivos y registra errores inesperados sin mostrar
 * información técnica al usuario.
 * ============================================================================ */

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\CommentReport;
use App\Support\OperationFailureHandler;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

use Throwable;

class CommentReportController extends Controller
{
    /**
     * Cantidad máxima de reportes permitidos por usuario
     * dentro del período definido debajo.
     */
    private const USER_REPORT_LIMIT = 10;

    /**
     * Diez minutos expresados en segundos.
     */
    private const USER_REPORT_LIMIT_SECONDS = 600;

    public function __construct(
        private readonly OperationFailureHandler $operationFailureHandler,
    ) {
    }

    /**
     * Guarda un reporte sobre un comentario público.
     */
    public function store(Request $request, Comment $comment): JsonResponse
    {
        try {
            $user = $request->user();

            /*
             * Solamente una cuenta pública activa puede utilizar
             * la herramienta de reportes.
             */
            abort_unless(
                $user?->canReportPublicComments(),
                403,
                'No tenés permisos para reportar comentarios.'
            );

            /*
             * Un usuario nunca puede reportar su propio comentario.
             */
            abort_if(
                (int) $comment->user_id === (int) $user->id,
                403,
                'No podés reportar tu propio comentario.'
            );

            /*
            * Las intervenciones públicas del equipo interno de TRAMA no se reportan
            * mediante el sistema destinado a comentarios de lectores.
            *
            * Incluye respuestas identificadas como "Autor de la nota" y "Equipo TRAMA".
            */
            abort_if(
                in_array(
                    $comment->author_context,
                    [
                        Comment::AUTHOR_CONTEXT_ARTICLE_AUTHOR,
                        Comment::AUTHOR_CONTEXT_TRAMA_TEAM,
                    ],
                    true
                ),
                403,
                'No podés reportar una intervención del equipo de TRAMA.'
            );

            /*
             * Solo se pueden reportar comentarios publicados y visibles.
             *
             * Si es una respuesta, su comentario principal también debe seguir
             * aprobado. La noticia además debe continuar publicada en el portal.
             */
            abort_unless(
                $comment->status === 'approved'
                && (
                    $comment->parent_id === null
                    || $comment->parent()
                        ->where('status', 'approved')
                        ->exists()
                )
                && $comment->article()
                    ->publiclyVisible()
                    ->exists(),
                404
            );

            /*
             * Solo acepta los motivos definidos oficialmente por TRAMA.
             */
            $validated = $request->validate([
                'reason' => [
                    'required',
                    'string',
                    Rule::in(array_keys(CommentReport::REASON_LABELS)),
                ],
            ], [
                'reason.required' => 'Seleccioná un motivo para enviar el reporte.',
                'reason.in' => 'El motivo seleccionado no es válido.',
            ]);

            /*
             * Da un mensaje claro antes de llegar a la restricción UNIQUE
             * de la base de datos.
             */
            $existingReport = $comment->reports()
                ->where('user_id', $user->id)
                ->first();

            if ($existingReport?->status === CommentReport::STATUS_OPEN) {
                throw ValidationException::withMessages([
                    'operation' => 'Ya reportaste este comentario.',
                ]);
            }

            if ($existingReport instanceof CommentReport) {
                throw ValidationException::withMessages([
                    'operation' => 'Tu reporte sobre este comentario ya fue revisado.',
                ]);
            }

            $rateLimitKey = $this->reportRateLimitKey($request);

            /*
             * Evita que una cuenta genere cantidades excesivas de reportes
             * o reintentos durante un período corto.
             */
            if (
                RateLimiter::tooManyAttempts(
                    $rateLimitKey,
                    self::USER_REPORT_LIMIT
                )
            ) {
                throw ValidationException::withMessages([
                    'operation' => 'Hiciste demasiados reportes en poco tiempo. Esperá unos minutos antes de volver a intentarlo.',
                ]);
            }

            /*
             * El intento se registra antes de escribir el reporte.
             *
             * Así una cuenta no puede golpear repetidamente la base de datos
             * cuando una operación está fallando y el navegador ofrece reintentar.
             */
            RateLimiter::hit(
                $rateLimitKey,
                self::USER_REPORT_LIMIT_SECONDS
            );

            /*
            * Reportar y dar Me gusta son acciones incompatibles para una misma cuenta.
            *
            * Si el lector había marcado previamente Me gusta, se elimina ese voto
            * antes de guardar el reporte. Ambas operaciones se ejecutan dentro de la
            * misma transacción para no dejar un estado intermedio inconsistente.
            */
            $likesCount = DB::transaction(function () use ($comment, $user, $validated): int {
                $comment->likes()
                    ->where('user_id', $user->id)
                    ->delete();

                $comment->reports()->create([
                    'user_id' => $user->id,
                    'reason' => $validated['reason'],
                    'status' => CommentReport::STATUS_OPEN,
                ]);

                return $comment->likes()->count();
            });

            /*
            * Vue actualiza únicamente el comentario afectado sin recargar la noticia.
            */
            return response()->json([
                'message' => 'Gracias por avisarnos. Recibimos tu reporte y será revisado por el equipo de TRAMA.',
                'likes_count' => $likesCount,
            ], 201);
        } catch (Throwable $exception) {
            $this->operationFailureHandler->fail(
                $exception,
                'public_comments',
                'report_comment',
                'No pudimos enviar el reporte en este momento. Intentá nuevamente.',
                [
                    'comment_id' => $comment->id,
                    'user_id' => $request->user()?->id,
                ]
            );
        }
    }

    /**
     * Clave independiente utilizada para limitar reportes por usuario.
     */
    private function reportRateLimitKey(Request $request): string
    {
        return 'comment-reports:user:'.$request->user()->id;
    }
}
