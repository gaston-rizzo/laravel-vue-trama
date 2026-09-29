<?php

/* ============================================================================
 * CONTROLLER: ArticleRevisionController.php
 * ============================================================================
 *
 * Permite recuperar una versión anterior de una noticia.
 *
 * Cada revisión guarda una copia del contenido que tenía la noticia en un
 * momento determinado. Este controller recibe la revisión elegida, verifica que
 * pertenezca a la noticia correcta y le pide al servicio editorial que copie ese
 * contenido como borrador nuevo. No borra el historial anterior.
 * ============================================================================ */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\ArticleRevision;
use App\Services\Editorial\ArticleWorkflowService;
use App\Support\OperationFailureHandler;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

use Throwable;

class ArticleRevisionController extends Controller
{
    public function __construct(
        private readonly OperationFailureHandler $operationFailures,
    ) {
    }

    /**
     * Copia el contenido de una revisión antigua y lo deja como borrador editable.
     */
    public function restore(
        Request $request,
        Article $article,
        ArticleRevision $revision,
        ArticleWorkflowService $workflow,
    ): RedirectResponse 
    {    
        // Obtiene el usuario que intenta restaurar la versión.
        $user = $request->user();

        // Solo el editor puede restaurar una versión anterior.
        abort_unless(
            $user?->canReviewArticles(),
            403,
            'Solo un editor puede restaurar versiones.'
        );

        // Impide que el editor acceda o restaure borradores
        // pertenecientes a otro integrante de la redacción.
        abort_if(
            $article->status === 'draft'
            && $article->author_id !== $user->id,
            403,
            'No tenés permisos para restaurar versiones de este borrador.'
        );

        // Evita restaurar una revisión que pertenece a otra noticia.
        abort_unless($revision->article_id === $article->id, 404);

        try {
            // El servicio registra la restauración como una revisión nueva.
            $workflow->restore(
                $article,
                $revision,
                $request->user()
            );
        } catch (Throwable $exception) {
            $this->operationFailures->fail(
                exception: $exception,
                area: 'articles',
                operation: 'restore_revision',
                userMessage: 'No se pudo restaurar la versión. No se realizaron cambios. Intentá nuevamente.',
                context: [
                    'controller' => class_basename(self::class),
                    'method' => __FUNCTION__,
                    'article_id' => $article->id,
                    'revision_id' => $revision->id,
                    'user_id' => $request->user()?->id,
                    'current_status' => $article->status,
                ],
            );
        }

        return redirect()
            ->route('admin.articles.edit', $article)
            ->with('status', 'La versión fue restaurada como borrador.');
    }
}
