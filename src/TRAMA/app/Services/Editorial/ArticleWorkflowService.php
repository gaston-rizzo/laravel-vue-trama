<?php

/* ============================================================================
 * SERVICE: ArticleWorkflowService.php
 * ============================================================================
 *
 * Ejecuta las operaciones transaccionales del flujo editorial de TRAMA.
 *
 * Mantiene sincronizados noticia, etiquetas, observaciones y revisiones. Cada
 * operación crítica bloquea la fila principal para impedir transiciones
 * simultáneas o restauraciones parciales.
 * ============================================================================ */

namespace App\Services\Editorial;

use App\Support\TramaClock;

use App\Models\Article;
use App\Models\ArticleRevision;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Categories\CategoryEditorialOrderService;
use App\Support\TramaLog;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use RuntimeException;
use Throwable;

class ArticleWorkflowService
{
    public function __construct(
        // Servicio responsable de guardar la auditoría de cada cambio importante.
        private readonly ArticleRevisionService $revisions,

        // Servicio responsable de reconstruir el texto usado por el buscador editorial.
        private readonly ArticleSearchIndexer $searchIndexer,

        // Controla el orden y el máximo de categorías que pueden estar publicadas.
        private readonly CategoryEditorialOrderService $categoryOrder,
    ) {
    }

    /**
     * Crea una noticia, sincroniza etiquetas y registra la primera versión.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $tagIds
     */
    public function create(
        array $attributes,
        array $tagIds,
        User $user,
        ?array $coverAsset = null,
    ): Article {
        // La creación de noticia, etiquetas, imágenes internas, portada y
        // primera revisión debe confirmarse como una sola unidad. Si falla una
        // parte, Laravel deshace todo lo escrito en base de datos.
        return DB::transaction(function () use ($attributes, $tagIds, $user, $coverAsset): Article {
            // Publicar la primera noticia de una categoría puede convertirla en una
            // nueva sección pública. Antes de escribir se verifica el límite global.
            if (($attributes['status'] ?? null) === 'published') {
                $this->categoryOrder->ensureCanPublishInCategory(
                    (int) ($attributes['category_id'] ?? 0),
                );
            }

            // Se crea la noticia con los datos ya validados y se fuerza author_id
            // al usuario autenticado para que no pueda venir manipulado desde Vue.
            $article = Article::query()->create([
                ...$attributes,
                'author_id' => $user->id,
            ]);

        // Sincroniza exactamente las etiquetas elegidas en el formulario.
        $article->tags()->sync($tagIds);

        /*
        * Construye el documento de búsqueda después de sincronizar las etiquetas,
        * para que search_text contenga los nombres definitivos asociados a la noticia.
        */
        $this->searchIndexer->index($article);

        // Vincula imágenes internas que fueron subidas antes de guardar la noticia.
        $this->syncInlineMedia($article, $user, (string) ($attributes['body'] ?? ''));

            if ($coverAsset !== null) {
                // Registra la portada subida como archivo visual auditable.
                $article->mediaAssets()->create($coverAsset);
            }

            // Guarda la primera revisión para que el historial arranque desde la creación.
            $this->revisions->record(
                $article,
                $user,
                $this->actionForCreation($article->status),
            );

            // Devuelve la noticia con relaciones listas para el controlador.
            return $article->load(['author', 'category', 'tags']);
        }, attempts: 3);
    }

    /**
     * Actualiza una noticia y registra exactamente qué campos cambiaron.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $tagIds
     */
    public function update(
        Article $article,
        array $attributes,
        array $tagIds,
        User $user,
        string $action = 'updated',
        ?array $coverAsset = null,
        bool $removeCoverAsset = false,
    ): Article {
        // La actualización usa transacción porque puede tocar datos de la noticia,
        // etiquetas, imágenes, devoluciones abiertas y una nueva revisión.
        return DB::transaction(function () use ($article, $attributes, $tagIds, $user, $action, $coverAsset, $removeCoverAsset): Article {
            // Se bloquea la fila mientras dura la actualización para impedir que
            // otra acción editorial modifique la misma noticia al mismo tiempo.
            $locked = Article::query()
                ->with('tags:id,name,slug')
                ->whereKey($article->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Se guarda el estado y una copia completa antes de cambiar datos.
            // Esa copia permite comparar y restaurar luego.
            $previousStatus = $locked->status;
            $previousSnapshot = $locked->revisionSnapshot();

            // Si esta actualización publica la primera noticia de una sección,
            // valida el cupo antes de hacer visible el cambio.
            $targetStatus = (string) ($attributes['status'] ?? $locked->status);
            $targetCategoryId = (int) ($attributes['category_id'] ?? $locked->category_id);

            if ($targetStatus === 'published') {
                $this->categoryOrder->ensureCanPublishInCategory($targetCategoryId);
            }

            // Aplica campos validados al registro principal.
            $locked->update($attributes);

            // Reemplaza las etiquetas actuales por las enviadas desde el formulario.
            $locked->tags()->sync($tagIds);

           /*
            * Reconstruye el documento de búsqueda después de actualizar el contenido,
            * la categoría y las etiquetas definitivas de la noticia.
            */
            $this->searchIndexer->index($locked);

            // Asocia imágenes internas nuevas que aparecen dentro del cuerpo HTML.
            $this->syncInlineMedia(
                $locked,
                $user,
                (string) ($attributes['body'] ?? $locked->body)
            );

            if ($previousStatus === 'needs_changes' && $locked->status !== 'needs_changes') {
                // Si el periodista reenvía una noticia corregida, significa que terminó
                // de responder las observaciones pendientes del editor.
                //
                // En el mismo guardado se actualiza el contenido, la noticia vuelve a revisión
                // y todas las observaciones abiertas se marcan como resueltas.
                //
                // Si alguna de esas operaciones falla, no se guarda ningún cambio.
                $locked->reviewFeedback()
                    ->whereNull('resolved_at')
                    ->update([
                        'resolved_at' => TramaClock::now(),
                        'resolved_by' => $user->id,
                    ]);
            }

            if ($coverAsset !== null) {
                // Las portadas anteriores se conservan como assets históricos.
                // Esto permite que una revisión antigua pueda restaurarlas sin
                // apuntar a un archivo que ya fue eliminado.
                $locked->mediaAssets()->create($coverAsset);
            }

            if ($removeCoverAsset) {
                // Quitar la portada del estado actual no elimina archivos ni
                // registros históricos vinculados a revisiones anteriores.
                $locked->forceFill(['cover_image' => null, 'cover_alt' => null])->save();
            }

            // Recarga etiquetas porque revisionSnapshot necesita ids y nombres actualizados.
            $locked->load('tags:id,name,slug');

            // Registra la acción con el snapshot previo para que el historial muestre
            // qué cambió respecto de la versión anterior.
            $this->revisions->record(
                $locked,
                $user,
                $action === 'updated'
                    ? $this->actionForTransition($previousStatus, $locked->status)
                    : $action,
                $previousStatus,
                $previousSnapshot,
            );

            // refresh trae valores finales desde la base y load prepara relaciones para Vue.
            return $locked->refresh()->load(['author', 'category', 'tags']);
        }, attempts: 3);
    }

    /**
     * Devuelve una noticia en revisión y conserva la observación obligatoria.
     */
    public function requestChanges(Article $article, User $reviewer, string $message): Article
    {
        // Cambio de estado y observación se guardan en la misma transacción.
        return DB::transaction(function () use ($article, $reviewer, $message): Article {
            // Bloquea la noticia antes de validar el estado para evitar que sea
            // publicada o archivada por otra acción mientras se está devolviendo.
            $locked = Article::query()
                ->with('tags:id,name,slug')
                ->whereKey($article->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== 'review') {
                // Solo una noticia enviada a revisión puede volver al autor con cambios.
                throw ValidationException::withMessages([
                    'message' => 'Solo se puede devolver una noticia que está en revisión.',
                ]);
            }

            // Guarda evidencia del estado anterior antes de marcarla como devuelta.
            $previousSnapshot = $locked->revisionSnapshot();
            $previousStatus = $locked->status;

            // Al devolver una noticia se limpian fechas y marcas de portada,
            // porque todavía no debe aparecer como publicada ni programada.
            $locked->update([
                'status' => 'needs_changes',
                'published_at' => null,
                'scheduled_at' => null,
                'is_breaking' => false,
                'is_featured' => false,                
            ]);

            // La observación y el cambio de estado deben confirmarse juntos. Una
            // noticia nunca puede quedar devuelta sin una explicación asociada.
            $locked->reviewFeedback()->create([
                'returned_by' => $reviewer->id,
                'message' => $message,
            ]);

            $this->revisions->record(
                $locked,
                $reviewer,
                'changes_requested',
                $previousStatus,
                $previousSnapshot,
            );

            return $locked->refresh();
        }, attempts: 3);
    }

    /**
     * Cancela la programación de una noticia sin eliminarla ni archivarla.
     *
     * Si la noticia pertenece al editor que realiza la acción, vuelve a borrador.
     * Si pertenece a un periodista, vuelve a revisión editorial.
     */
    public function cancelSchedule(Article $article, User $editor): Article
    {
        return DB::transaction(function () use ($article, $editor): Article {
           /*
            * Bloquea la noticia para impedir que el scheduler u otra petición
            * la modifiquen mientras se cancela la programación.
            */
            $locked = Article::query()
                ->with([
                    'author',
                    'tags:id,name,slug',
                ])
                ->whereKey($article->id)
                ->lockForUpdate()
                ->firstOrFail();

           /*
            * Solamente un editor puede cancelar una programación.
            */
            if (! $editor->canReviewArticles()) {
                abort(
                    403,
                    'No tenés permisos para cancelar esta programación.'
                );
            }

           /*
            * La acción solamente corresponde a una noticia
            * que todavía se encuentra programada.
            */
            if (
                $locked->status !== 'scheduled'
                || $locked->scheduled_at === null
            ) {
                throw ValidationException::withMessages([
                    'scheduled_at' => 'Solo se puede cancelar una noticia que está programada.',
                ]);
            }

           /*
            * Una programación puede cancelarse cuando:
            *
            * - la noticia pertenece al editor conectado;
            * - la noticia pertenece a un periodista.
            *
            * Cualquier otra autoría indica un dato inconsistente
            * y se bloquea antes de modificar la noticia.
            */
            $isOwnArticle = $locked->author_id === $editor->id;
            $isJournalistArticle = $locked->author?->role === 'journalist';

            if (! $isOwnArticle && ! $isJournalistArticle) {
                abort(
                    403,
                    'La noticia tiene una autoría incompatible con la cancelación de programación.'
                );
            }

           /*
            * La noticia propia del editor vuelve a borrador.
            *
            * La noticia de un periodista vuelve a revisión porque
            * ya había ingresado al control editorial.
            */
            $targetStatus = $isOwnArticle
                ? 'draft'
                : 'review';

           /*
            * Guarda el estado anterior para registrar correctamente
            * la transición dentro del historial.
            */
            $previousSnapshot = $locked->revisionSnapshot();
            $previousStatus = $locked->status;

           /*
            * Cancela la fecha programada y devuelve la noticia
            * al estado privado correspondiente.
            */
            $locked->update([
                'status' => $targetStatus,
                'scheduled_at' => null,
                'published_at' => null,
            ]);

           /*
            * Registra la cancelación en el historial editorial.
            */
            $this->revisions->record(
                $locked,
                $editor,
                'schedule_cancelled',
                $previousStatus,
                $previousSnapshot,
            );

            return $locked
                ->refresh()
                ->load([
                    'author',
                    'category',
                    'tags',
                ]);
        }, attempts: 3);
    }

    /**
     * Restaura una versión como borrador y genera una revisión nueva.
     */
    public function restore(Article $article, ArticleRevision $revision, User $user): Article
    {
        // Restaurar toca la noticia, etiquetas y crea una nueva revisión, por eso
        // todo ocurre dentro de una transacción.
        return DB::transaction(function () use ($article, $revision, $user): Article {
            // Bloquea la noticia actual para impedir cambios simultáneos.
            $locked = Article::query()
                ->with('tags:id,name,slug')
                ->whereKey($article->id)
                ->lockForUpdate()
                ->firstOrFail();

            // También bloquea la revisión elegida para asegurar que pertenece a
            // la misma noticia que se está restaurando.
            $lockedRevision = ArticleRevision::query()
                ->whereKey($revision->id)
                ->where('article_id', $locked->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Snapshot guardado en la revisión histórica seleccionada.
            $snapshot = $lockedRevision->snapshot ?? [];
            // Snapshot del estado actual, usado para auditar la restauración.
            $previousSnapshot = $locked->revisionSnapshot();
            $previousStatus = $locked->status;

            // Solo se restauran campos editoriales. No se restauran métricas,
            // ids, autoría ni fechas de publicación directa.
            $restorable = Arr::only($snapshot, [
                'title',
                'subtitle',
                'excerpt',
                'body',
                'category_id',
                'cover_image',
                'cover_alt',
                'is_breaking',
                'is_featured',                
            ]);

            // Toda restauración vuelve a borrador. Así una versión histórica no
            // reemplaza directamente contenido que ya está publicado.
            $locked->update([
                ...$restorable,
                'status' => 'draft',
                'published_at' => null,
                'scheduled_at' => null,
            ]);

            // Restaura las etiquetas que tenía la versión histórica.
            $locked->tags()->sync(array_map('intval', $snapshot['tag_ids'] ?? []));

           /*
            * Reconstruye el documento de búsqueda con el contenido, la categoría y las
            * etiquetas recuperadas desde la revisión histórica.
            */
            $this->searchIndexer->index($locked);

            // Carga etiquetas restauradas para guardar una revisión completa.
            $locked->load('tags:id,name,slug');

            $this->revisions->record(
                $locked,
                $user,
                'restored_as_draft',
                $previousStatus,
                $previousSnapshot,
                $lockedRevision,
            );

            return $locked->refresh();
        }, attempts: 3);
    }

    /**
     * Actualiza únicamente las marcas editoriales utilizadas por la portada.
     *
     * La noticia conserva su estado y todo su contenido. Esta operación permite
     * retirar una destacada o una urgente sin despublicarla y registra el cambio
     * dentro del historial de revisiones.
     *
     * @param  array{is_breaking: bool, is_featured: bool}  $attributes
     */
    public function updateHomepageFlags(
        Article $article,
        array $attributes,
        User $user,
    ): Article {
        return DB::transaction(function () use ($article, $attributes, $user): Article {
            // Bloquea la noticia para evitar dos cambios de portada simultáneos.
            $locked = Article::query()
                ->with('tags:id,name,slug')
                ->whereKey($article->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Solo las noticias publicadas o programadas pueden administrarse
            // mediante esta acción independiente de portada.
            if (! in_array($locked->status, ['published', 'scheduled'], true)) {
                throw ValidationException::withMessages([
                    'operation' => 'Solo se puede gestionar la portada de una noticia publicada o programada.',
                ]);
            }

            $targetBreaking = (bool) ($attributes['is_breaking'] ?? false);
            $targetFeatured = (bool) ($attributes['is_featured'] ?? false);

            // Una petición repetida no genera una revisión innecesaria.
            if (
                $targetBreaking === (bool) $locked->is_breaking
                && $targetFeatured === (bool) $locked->is_featured
            ) {
                return $locked->refresh();
            }

            // Vuelve a comprobar el límite dentro de la transacción para evitar
            // que dos peticiones concurrentes creen una octava destacada.
            if ($targetFeatured) {
                $featuredCount = Article::query()
                    ->where('is_featured', true)
                    ->whereIn('status', ['published', 'scheduled'])
                    ->whereKeyNot($locked->id)
                    ->lockForUpdate()
                    ->get(['id'])
                    ->count();

                if ($featuredCount >= 7) {
                    throw ValidationException::withMessages([
                        'is_featured' => 'La portada ya tiene 7 noticias destacadas. Quitá una antes de marcar otra.',
                    ]);
                }
            }

            $previousSnapshot = $locked->revisionSnapshot();
            $previousStatus = $locked->status;

            $locked->update([
                'is_breaking' => $targetBreaking,
                'is_featured' => $targetFeatured,
            ]);

            // Registra la configuración de portada como una decisión editorial
            // independiente, sin modificar el estado de publicación.
            $this->revisions->record(
                $locked,
                $user,
                'homepage_updated',
                $previousStatus,
                $previousSnapshot,
            );

            return $locked->refresh();
        }, attempts: 3);
    }

    /**
     * Archiva una noticia y elimina sus banderas de portada en una sola operación.
     */
    public function archive(Article $article, User $user): Article
    {
        // Archivar y registrar historial debe ser una única operación.
        return DB::transaction(function () use ($article, $user): Article {
            // Bloquea la noticia para que no se publique o edite al mismo tiempo.
            $locked = Article::query()
                ->with('tags:id,name,slug')
                ->whereKey($article->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Guarda cómo estaba antes de retirarla del sitio público.
            $previousSnapshot = $locked->revisionSnapshot();
            $previousStatus = $locked->status;

            // Archivar retira la noticia de portada, programación y publicación.
            $locked->update([
                'status' => 'archived',
                'published_at' => null,
                'scheduled_at' => null,
                'is_breaking' => false,
                'is_featured' => false,                
            ]);

            $this->revisions->record(
                $locked,
                $user,
                'archived',
                $previousStatus,
                $previousSnapshot,
            );

            return $locked->refresh();
        }, attempts: 3);
    }

    /**
     * Elimina definitivamente una noticia privada perteneciente a su autor.
     *
     * Solamente admite borradores y noticias devueltas para corrección.
     * La eliminación física de la noticia provoca también el borrado en cascada
     * de revisiones, observaciones editoriales y relaciones dependientes.
     *
     * Los registros de media_assets se eliminan manualmente antes de borrar
     * la noticia mediante $locked->mediaAssets()->delete().
     *
     * Esto es necesario porque la clave foránea usa nullOnDelete(): si se borrara
     * primero la noticia, esos registros no desaparecerían y article_id quedaría
     * en null.
     * 
     * Los archivos físicos se eliminan después, utilizando las rutas
     * obtenidas antes de borrar estos registros.
     *     
     * @return string Estado que tenía la noticia antes de ser eliminada.
     */
    public function deletePermanently(Article $article, User $user): string
    {
        $result = DB::transaction(function () use ($article, $user): array {
           /*
            * Bloquea la noticia para impedir que sea enviada a revisión,
            * publicada o modificada mientras se procesa su eliminación.
            */
            $locked = Article::query()
                ->with('mediaAssets:id,article_id,path')
                ->whereKey($article->id)
                ->lockForUpdate()
                ->firstOrFail();

           /*
            * Solamente el autor puede eliminar definitivamente
            * su propio borrador o descartar su propia noticia devuelta.
            */
            abort_unless(
                $locked->author_id === $user->id,
                403,
                'No tenés permisos para eliminar esta noticia.'
            );

           /*
            * Una noticia deja de poder eliminarse directamente cuando
            * entra en revisión, se programa, se publica o se archiva.
            */
            if (! in_array($locked->status, ['draft', 'needs_changes'], true)) {
                abort(
                    409,
                    'Solo se pueden eliminar borradores o noticias devueltas para corrección.'
                );
            }

           /*
            * Conserva las rutas antes de borrar los registros de media_assets.
            *
            * Incluye todas las portadas históricas y las imágenes internas
            * que continúen asociadas a la noticia.
            */
            $imagePaths = $locked->mediaAssets
                ->pluck('path')
                ->filter(
                    fn ($path): bool =>
                        is_string($path)
                        && $path !== ''
                );

           /*
            * Agrega la portada actual como respaldo cuando, por datos antiguos,
            * no exista su correspondiente registro en media_assets.
            *
            * No se agrega si otra noticia utiliza actualmente el mismo archivo.
            */
            if (filled($locked->cover_image)) {
                $coverUsedByAnotherArticle = Article::withTrashed()
                    ->where('id', '!=', $locked->id)
                    ->where('cover_image', $locked->cover_image)
                    ->exists();

                if (! $coverUsedByAnotherArticle) {
                    $imagePaths->push($locked->cover_image);
                }
            }

            $imagePaths = $imagePaths
                ->unique()
                ->values()
                ->all();

            // Conserva el estado para construir el mensaje final del panel.
            $deletedStatus = $locked->status;

           /*
            * media_assets.article_id utiliza nullOnDelete().
            *
            * Si no se eliminan estos registros antes de borrar la noticia,
            * quedarían en la base con article_id igual a null.
            */
            $locked->mediaAssets()->delete();

           /*
            * forceDelete() omite SoftDeletes y elimina físicamente la fila.
            *
            * Las claves foráneas eliminan automáticamente:
            * - revisiones y versiones históricas;
            * - observaciones editoriales;
            * - relaciones con etiquetas;
            * - demás registros dependientes de la noticia.
            */
            $locked->forceDelete();

            return [
                'status' => $deletedStatus,
                'image_paths' => $imagePaths,
            ];
        }, attempts: 3);

       /*
        * La noticia ya fue eliminada de la base.
        * Los archivos vinculados a la noticia se eliminan después de 
        * confirmar la transacción.
        *
        * Así nunca se borran imágenes mientras la noticia todavía
        * permanece guardada en la base de datos.                
        *        
        * Un fallo posterior al borrar un archivo físico se registra para limpieza,
        * pero no convierte una eliminación confirmada en un falso error para el usuario.
        */
        foreach ($result['image_paths'] as $path) {
            try {
                $this->deleteArticleImage($path);
            } catch (Throwable $exception) {
                TramaLog::error(
                    'No se pudo eliminar un archivo perteneciente a una noticia eliminada.',
                    [
                        'service' => self::class,
                        'method' => __FUNCTION__,
                        'deleted_article_id' => $article->id,
                        'path' => $path,
                        'exception_class' => $exception::class,
                        'exception_message' => $exception->getMessage(),
                    ]
                );
            }
        }

        return $result['status'];
    }

    /**
     * Elimina una imagen asociada a una noticia.
     *
     * Solo admite rutas de portadas o imágenes internas gestionadas por TRAMA.
     * Cualquier otra ruta se ignora para evitar borrar archivos ajenos.
     */
    private function deleteArticleImage(string $path): void
    {
        $coverUrl = rtrim(
            (string) config('trama.articles.cover_url'),
            '/'
        ).'/';

        if (Str::startsWith($path, $coverUrl)) {
           /*
            * Las portadas pueden estar configuradas fuera de public/,
            * por eso se utiliza trama.articles.cover_path.
            */
            $filePath =
                (string) config('trama.articles.cover_path')
                .DIRECTORY_SEPARATOR
                .basename($path);
        } elseif (
            Str::startsWith(
                $path,
                '/images/uploads/articles/content/'
            )
        ) {
            // Las imágenes internas se guardan físicamente dentro de public/.
            $filePath = public_path(ltrim($path, '/'));
        } else {
            // No se elimina ninguna ruta que no pertenezca al sistema de noticias.
            return;
        }

        if (
            File::isFile($filePath)
            && ! File::delete($filePath)
        ) {
            throw new RuntimeException(
                'El archivo físico no pudo eliminarse.'
            );
        }
    }

    /**
     * Guarda cambios temporales sin crear una revisión por cada pulsación.
     *
     * La revisión definitiva se registra cuando el usuario presiona Guardar o
     * ejecuta una transición editorial.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function autosave(Article $article, array $attributes, User $user): Article
    {
        // El guardado automático debe evitar pisarse con cambios manuales.
        return DB::transaction(function () use ($article, $attributes, $user): Article {
            // Bloquea la noticia mientras se aplica el autosave.
            $locked = Article::query()
                ->whereKey($article->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Comprueba si la noticia pertenece al usuario que está editando.
            $isOwner = $locked->author_id === $user->id;

            // El editor puede trabajar con noticias ajenas únicamente
            // después de que hayan salido del estado borrador.
            $isAccessibleToEditor =
                $user->canReviewArticles()
                && $locked->status !== 'draft';

            // Impide autoguardar noticias ajenas que todavía
            // no ingresaron al flujo editorial.
            if (! $isOwner && ! $isAccessibleToEditor) {
                abort(403, 'No tenés permisos para guardar esta noticia.');
            }

            if (! in_array($locked->status, Article::JOURNALIST_EDITABLE_STATUSES, true)) {
                // No se guarda automáticamente contenido que ya salió del estado editable.
                throw ValidationException::withMessages([
                    'article' => 'La noticia ya no admite guardado automático.',
                ]);
            }

            // Aplica únicamente los campos permitidos por AutosaveArticleRequest.
            $locked->update($attributes);

            return $locked->refresh();
        }, attempts: 3);
    }

    /**
     * Vincula a la noticia las imágenes internas subidas antes del primer guardado.
     *
     * El editor permite subir imágenes mientras una noticia nueva todavía no tiene
     * ID. Al guardar, se detectan las rutas presentes en el HTML y se asignan solo
     * los assets del mismo usuario que todavía no pertenecen a otra noticia.
     */
    private function syncInlineMedia(Article $article, User $user, string $body): void
    {
        // Busca imágenes insertadas dentro del HTML del cuerpo.
        preg_match_all(
            '/<img[^>]+src=["\']([^"\']+)["\']/i',
            $body,
            $matches,
        );

        // Conserva solo rutas internas de imágenes subidas por el editor de TRAMA.
        $paths = collect($matches[1] ?? [])
            ->filter(fn (string $path): bool => str_starts_with($path, '/images/uploads/articles/content/'))
            ->unique()
            ->values();

        if ($paths->isEmpty()) {
            // Si el cuerpo no usa imágenes internas, no hay assets para vincular.
            return;
        }

        // Solo se vinculan archivos subidos por el mismo usuario y que todavía
        // no pertenecen a ninguna noticia.
        MediaAsset::query()
            ->where('uploaded_by', $user->id)
            ->whereNull('article_id')
            ->whereIn('path', $paths)
            ->update(['article_id' => $article->id]);
    }

   /**
     * Define qué acción debe registrarse en el historial
     * cuando se crea una noticia por primera vez.
     *
     * La acción depende del estado inicial elegido al crearla.
     *
     * Ejemplos:
     *
     * - Se crea como borrador:
     *   status = draft
     *   registra "created".
     *
     * - Se crea y se envía directamente a revisión:
     *   status = review
     *   registra "created_and_submitted".
     *
     * - Un editor la crea y la programa:
     *   status = scheduled
     *   registra "created_and_scheduled".
     *
     * - Un editor la crea y la publica inmediatamente:
     *   status = published
     *   registra "created_and_published".
     */
    private function actionForCreation(string $status): string
    {
        // El estado inicial define cómo se etiqueta la primera revisión.
        return match ($status) {
            'review' => 'created_and_submitted',
            'scheduled' => 'created_and_scheduled',
            'published' => 'created_and_published',
            default => 'created',
        };
    }

    /**
     * Define qué acción debe guardarse en el historial según
     * el estado anterior y el nuevo estado de la noticia.
     *
     * Ejemplos:
     *
     * - draft → review:
     *   primer envío a revisión, registra "submitted_for_review".
     *
     * - needs_changes → review:
     *   reenvío después de corregir, registra "resubmitted".
     *
     * - review → scheduled:
     *   registra "scheduled".
     *
     * - scheduled → published:
     *   registra "published".
     *
     * - scheduled → scheduled:
     *   el estado no cambió, registra una edición normal: "updated".
     */
    private function actionForTransition(string $from, string $to): string
    {
       /*
        * Una noticia corregida que vuelve desde needs_changes
        * debe diferenciarse del primer envío editorial.
        */
        if ($from === 'needs_changes' && $to === 'review') {
            return 'resubmitted';
        }

        if ($from === $to) {
            // Si el estado no cambió, la revisión representa una edición normal.
            return 'updated';
        }

        // Si el estado cambió, se guarda una acción legible para el historial.
        return match ($to) {
            'draft' => 'returned_to_draft',
            'review' => 'submitted_for_review',
            'needs_changes' => 'corrections_saved',
            'scheduled' => 'scheduled',
            'published' => 'published',
            'archived' => 'archived',
            default => 'status_changed',
        };
    }
}
