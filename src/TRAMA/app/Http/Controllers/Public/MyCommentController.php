<?php

/* ============================================================================
 * CONTROLLER: MyCommentController.php
 * ============================================================================
 *
 * Muestra la grilla personal de comentarios del usuario autenticado.
 *
 * La pantalla sirve para encontrar comentarios propios dentro de las noticias:
 * muestra fecha, fragmento, noticia, estado, Me gusta y respuestas. Los
 * comentarios eliminados no aparecen porque se borran del sistema. Los
 * rechazos automáticos tampoco se muestran en esta grilla personal; solo los
 * rechazos editoriales quedan disponibles para consulta.
 * ============================================================================ */

namespace App\Http\Controllers\Public;

use App\Support\TramaClock;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Support\TramaBridge;
use Illuminate\Http\Request;
use Inertia\Response;

class MyCommentController extends Controller
{
    /**
     * Devuelve la grilla paginada de comentarios escritos por el usuario actual.
     */
    public function __invoke(Request $request): Response
    {
        $comments = Comment::query()
            // Limita la grilla a comentarios creados por la cuenta que inició sesión.
            ->where('user_id', $request->user()->id)
            // Los eliminados no están en este conjunto. `processing` permite que el
            // usuario sepa que TRAMA recibió el comentario mientras Node lo analiza.
            // También se incluyen pendientes, publicados y rechazados editoriales.
            ->whereIn('status', ['processing', 'pending', 'approved', 'rejected'])
            // Solo se muestran rechazos revisados por moderación editorial. Los
            // automáticos y los históricos sin origen claro quedan fuera.
            ->where(function ($query): void {
                $query->where('status', '!=', 'rejected')
                    ->orWhere('moderation_source', 'editorial');
            })
            // Carga la noticia para mostrar el título y construir el enlace directo.
            ->with([
                'article:id,title,slug,status,published_at,category_id',
                'article.category:id,is_active',
            ])
            // Cuenta los Me gusta del comentario sin cargar cada voto individual.
            ->withCount('likes')
            // En comentarios principales cuenta respuestas existentes para la grilla.
            ->withCount('replies')
            // Primero se muestran los comentarios más recientes del usuario.
            ->latest()
            // La grilla muestra 10 comentarios por página y conserva filtros futuros.
            ->paginate(10)
            ->withQueryString();

        return TramaBridge::render('Public/MyComments', [
            'comments' => [
                'data' => $comments->getCollection()->map(fn (Comment $comment) => [
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'status' => $comment->status,
                    'type' => $comment->parent_id ? 'reply' : 'comment',
                    'likes_count' => (int) $comment->likes_count,
                    'replies_count' => (int) $comment->replies_count,
                    'created_at' => $comment->created_at?->toISOString(),
                    'article' => $comment->article?->only(['title', 'slug']),
                    'article_url' => $this->articleUrl($comment),                    
                ]),
                'links' => $comments->linkCollection(),
            ],
        ]);
    }

    /**
     * Construye el enlace que abre la noticia y apunta al comentario exacto.
     */
    private function articleUrl(Comment $comment): ?string
    {
        // No genera un enlace cuando el comentario fue rechazado
        // o la noticia no está publicada y visible.
        if (
            ! $comment->article
            || $comment->status === 'rejected'
            || $comment->article->status !== 'published'
            || $comment->article->published_at === null
            || $comment->article->published_at->gt(TramaClock::now())
            || $comment->article->category?->is_active !== true
        ) {
            return null;
        }

        // Se manda el ID como parámetro de consulta y no como #comentario-ID.
        // Así Vue puede localizar y centrar el comentario dentro de la noticia.
        return route('articles.show', [
            'slug' => $comment->article->slug,
            'comentario' => $comment->id,
        ]);
    }
}
