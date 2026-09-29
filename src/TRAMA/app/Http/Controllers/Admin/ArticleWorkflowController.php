<?php

/* ============================================================================
 * CONTROLLER: ArticleWorkflowController.php
 * ============================================================================
 *
 * Controla acciones editoriales que no son una edición común.
 *
 * Una noticia no solo se crea y se edita: también puede ser devuelta con una
 * observación, reenviada después de corregirse o guardada automáticamente
 * mientras alguien escribe. Estas acciones tienen reglas propias, por eso viven
 * separadas del controller principal de noticias.
 * ============================================================================ */

namespace App\Http\Controllers\Admin;

use App\Support\TramaClock;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AutosaveArticleRequest;
use App\Http\Requests\Admin\RequestArticleChangesRequest;
use App\Http\Requests\Admin\UpdateArticleHomepageRequest;
use App\Models\Article;
use App\Services\Editorial\ArticleWorkflowService;
use App\Services\HtmlSanitizer;
use App\Support\OperationFailureHandler;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

use Throwable;

class ArticleWorkflowController extends Controller
{
    public function __construct(
        private readonly OperationFailureHandler $operationFailures,
    ) {
    }

    /**
     * Devuelve una noticia al autor para que corrija puntos indicados por el editor.
     */
    public function requestChanges(
        RequestArticleChangesRequest $request,
        Article $article,
        ArticleWorkflowService $workflow,
    ): RedirectResponse {
        try {
            // Guarda las observaciones y devuelve la noticia al periodista autor.
            $workflow->requestChanges(
                $article,
                $request->user(),
                $request->string('message')->toString()
            );
        } catch (Throwable $exception) {
            $this->operationFailures->fail(
                exception: $exception,
                area: 'articles',
                operation: 'request_changes',
                userMessage: 'No pudimos devolver la noticia para corrección. No se realizaron cambios. Intentá nuevamente.',
                context: [
                    'controller' => class_basename(self::class),
                    'method' => __FUNCTION__,
                    'article_id' => $article->id,
                    'user_id' => $request->user()?->id,
                    'current_status' => $article->status,
                ],
            );
        }

        /*
        * La noticia deja de estar disponible para el editor después
        * de pasar a "Devuelta para corrección".
        *
        * Por eso no se utiliza back(): volver a la pantalla de edición
        * intentaría consultar una noticia que ya regresó al periodista.
        */
        return redirect()
            ->route('admin.articles.index')
            ->with(
                'status',
                'La noticia fue devuelta para corrección.'
            );
    }

    /**
     * Cancela la fecha programada y devuelve la noticia a un estado privado editable.
     */
    public function cancelSchedule(
        Request $request,
        Article $article,
        ArticleWorkflowService $workflow,
    ): RedirectResponse {
        try {
            // El servicio decide si la noticia vuelve a borrador o a revisión según
            // quién la escribió originalmente.
            $workflow->cancelSchedule(
                    $article, 
                    $request->user()
            );        
        } catch (Throwable $exception) {
            $this->operationFailures->fail(
                exception: $exception,
                area: 'articles',
                operation: 'cancel_schedule',
                userMessage: 'No se pudo cancelar la programación. No se realizaron cambios. Intentá nuevamente.',
                context: [
                    'controller' => class_basename(self::class),
                    'method' => __FUNCTION__,
                    'article_id' => $article->id,
                    'user_id' => $request->user()?->id,
                    'current_status' => $article->status,
                ],
            );
        }

        return back()->with('status', 'La programación fue cancelada.');
    }

    /**
     * Actualiza únicamente las marcas Urgente y Destacada de la portada.
     *
     * La noticia conserva su estado, contenido, categoría y publicación.
     */
    public function updateHomepage(
        UpdateArticleHomepageRequest $request,
        Article $article,
        ArticleWorkflowService $workflow,
    ): RedirectResponse {
        try {
            $workflow->updateHomepageFlags(
                $article,
                [
                    'is_breaking' => $request->boolean('is_breaking'),
                    'is_featured' => $request->boolean('is_featured'),
                ],
                $request->user(),
            );
        } catch (Throwable $exception) {
            $this->operationFailures->fail(
                exception: $exception,
                area: 'articles',
                operation: 'update_homepage',
                userMessage: 'No pudimos actualizar la configuración de portada. No se realizaron cambios. Intentá nuevamente.',
                context: [
                    'controller' => class_basename(self::class),
                    'method' => __FUNCTION__,
                    'article_id' => $article->id,
                    'user_id' => $request->user()?->id,
                    'current_status' => $article->status,
                ],
            );
        }

        return back()->with(
            'status',
            'La configuración de portada fue actualizada.'
        );
    }

    /**
     * Guarda automáticamente el avance de una noticia editable.
     *
     * El frontend envía la petición quince segundos después del último cambio.
     * Si el usuario continúa escribiendo durante ese intervalo, la espera se
     * reinicia para evitar una petición por cada modificación.
     *
     * Actualiza el contenido y recalcula datos derivados, como el tiempo estimado
     * de lectura, pero no cambia el estado editorial ni crea una nueva revisión
     * dentro del historial.
     */
    public function autosave(
        AutosaveArticleRequest $request,
        Article $article,
        ArticleWorkflowService $workflow,
        HtmlSanitizer $sanitizer,
    ): JsonResponse {
        try {            
           /*
            * El autosave recibe pocos campos y recalcula tiempo de lectura con el cuerpo actual.
            * Se recalcula porque el tiempo de lectura depende directamente del cuerpo de la noticia.
            * Durante el autoguardado, el usuario puede seguir agregando o quitando texto. Si solo 
            * se guarda body pero no se actualiza reading_time, quedaría desfasado.
            *
            * strip_tags() elimina las etiquetas HTML para contar solamente el texto
            * visible. str_word_count() obtiene la cantidad de palabras y el resultado
            * se divide por una velocidad estimada de 220 palabras por minuto.
            *
            * ceil() redondea hacia arriba para no mostrar tiempos parciales y max()
            * establece un mínimo de 2 minutos.
            *
            * Ejemplos:
            * - 660 palabras: 660 / 220 = 3 minutos.
            * - 300 palabras: 300 / 220 = 1,36 → 2 minutos.
            * - 100 palabras: el cálculo da 1 minuto, pero se conserva el mínimo de 2.
            */
            $validated = $request->validated();
            $validated['reading_time'] = max(
                2,
                (int) ceil(str_word_count(strip_tags($validated['body'])) / 220)
            );

            // El servicio valida permisos y guarda sin alterar el historial editorial principal.
            $saved = $workflow->autosave($article, $validated, $request->user());

            // La respuesta le permite a Vue mostrar hora de guardado y contador actualizado.
            return response()->json([
                'saved_at' => TramaClock::now()->toISOString(),
                'article_updated_at' => $saved->updated_at?->toISOString(),
                'word_count' => str_word_count(strip_tags($saved->body)),
                'visible_characters' => $sanitizer->textLength($saved->body),
            ]);
        } catch (Throwable $exception) {
            $this->operationFailures->fail(
                exception: $exception,
                area: 'articles',
                operation: 'autosave_article',
                userMessage: 'No se pudo autoguardar la noticia. No se realizaron cambios. Intentá nuevamente.',
                context: [
                    'controller' => class_basename(self::class),
                    'method' => __FUNCTION__,
                    'article_id' => $article->id,
                    'user_id' => $request->user()?->id,
                    'current_status' => $article->status,
                ],
            );
        }
    }
}
