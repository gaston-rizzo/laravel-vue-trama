<?php

/* ============================================================================
 * COMMAND: PublishScheduledArticles.php
 * ============================================================================
 *
 * Hace visibles en el sitio público las noticias que fueron programadas.
 *
 * Una noticia con estado "scheduled" queda guardada en el panel editorial
 * interno de TRAMA, donde periodistas, editores y administradores gestionan
 * el contenido, pero todavía no aparece en la portada, las secciones ni las
 * búsquedas del sitio público.
 *
 * Cuando la fecha y hora guardadas en scheduled_at son alcanzadas por el reloj
 * editorial, este comando cambia el estado a "published", registra la fecha y
 * hora de publicación y habilita la noticia para mostrarse en el sitio público.
 *
 * IMPORTANTE:
 * Este comando no utiliza la fecha ni la hora reales para decidir qué noticias
 * publicar. Consulta TramaClock::now(), el mismo reloj editorial persistente que
 * utiliza el portal web.
 *
 * La primera sesión parte de:
 *
 *      TRAMA_REFERENCE_DATE = 2026-07-19
 *      TRAMA_REFERENCE_TIME = 15:30:00
 *
 * Después el reloj avanza mientras existe actividad y se pausa tras una
 * inactividad prolongada. Si una sesión anterior terminó en 16:35:17, una nueva
 * sesión continúa en 16:35:18 aunque la hora real del día siguiente sea 10:32.
 *
 * De esta manera una noticia programada para las 16:40 se publica cuando el reloj
 * editorial llega a 16:40, no cuando el reloj real del sistema marca esa hora.
 *
 * Cada noticia se actualiza dentro de una transacción para evitar que dos
 * ejecuciones del scheduler publiquen el mismo registro al mismo tiempo.
 * También se guarda una revisión para que el panel conserve el historial
 * del cambio automático.
 * ============================================================================ */

namespace App\Console\Commands;

use App\Support\TramaClock;

use App\Models\Article;
use App\Models\User;
use App\Services\Categories\CategoryEditorialOrderService;
use App\Services\Editorial\ArticleRevisionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublishScheduledArticles extends Command
{
    /**
     * Define el nombre con el que este comando se ejecuta desde Artisan.
     *
     * Ejecución manual:
     *
     *      php artisan articles:publish-scheduled
     *
     * Al ejecutarse, el comando busca las noticias con estado "scheduled"
     * cuya fecha y hora programadas ya hayan llegado, y las publica.
     *
     * También puede ser ejecutado automáticamente por el scheduler de Laravel.
     *
     * @var string
     */
    protected $signature = 'articles:publish-scheduled';

    /**
     * Explicación breve que aparece al listar los comandos.
     *
     * @var string
     */
    protected $description = 'Publica noticias programadas cuya fecha y hora ya se cumplieron.';

    /**
     * Busca noticias programadas cuya fecha de publicación ya llegó y las muestra en el sitio.
     */
    public function handle(
        ArticleRevisionService $revisions,
        CategoryEditorialOrderService $categoryOrder,
    ): int
    {
        // Contador usado para informar cuántas noticias fueron publicadas por esta ejecución.
        $published = 0;
        // Fecha y hora actuales del reloj editorial persistente.
        // El scheduler consulta este valor sin prolongar por sí solo la sesión.
        $editorialNow = TramaClock::now();

        // Se buscan solo noticias programadas cuya fecha ya llegó.
        Article::query()
            // Solo entran noticias en estado programado.
            ->where('status', 'scheduled')
            // Una noticia programada debe tener fecha de programación.
            ->whereNotNull('scheduled_at')
            // La fecha ya debe haberse cumplido.
            ->where('scheduled_at', '<=', $editorialNow)
            // Orden estable para que el procesamiento sea predecible.
            ->orderBy('id')
            // Solo hace falta el id para luego releer cada noticia con bloqueo.
            ->select('id')
            ->chunkById(100, function ($articles) use ($revisions, $categoryOrder, $editorialNow, &$published): void {
                // El procesamiento por lotes evita cargar demasiados registros en memoria.
                foreach ($articles as $articleReference) {
                    try {
                        $wasPublished = DB::transaction(function () use ($articleReference, $revisions, $categoryOrder, $editorialNow): bool {
                        // Se vuelve a leer la noticia con bloqueo antes de publicarla.
                        $article = Article::query()
                            // Las etiquetas se necesitan para el snapshot de revisión.
                            ->with('tags:id,name,slug')
                            // Busca exactamente la noticia del lote actual.
                            ->whereKey($articleReference->id)
                            // Impide que dos procesos publiquen la misma noticia al mismo tiempo.
                            ->lockForUpdate()
                            ->first();

                        // Otra ejecución pudo haber procesado la noticia mientras
                        // el lote esperaba el bloqueo. En ese caso no se repite.
                        if (
                            ! $article
                            || $article->status !== 'scheduled'
                            || $article->scheduled_at === null
                            || $article->scheduled_at->gt($editorialNow)
                        ) {
                            return false;
                        }

                        // La primera publicación de una categoría puede ocupar un nuevo
                        // cupo público. Si ya existen doce, la noticia sigue programada.
                        $categoryOrder->ensureCanPublishInCategory((int) $article->category_id);

                        // El snapshot guarda cómo estaba la noticia antes del cambio.
                        $previousSnapshot = $article->revisionSnapshot();
                        $scheduledBy = $this->resolveSchedulingUser($article);

                        // Al publicar, published_at queda fijo y scheduled_at se limpia.
                        $article->update([
                            // Cambia el estado para que el portal público la vea.
                            'status' => 'published',
                            // Guarda la fecha y hora exactas en las que se realizó la publicación automática.
                            'published_at' => $editorialNow,
                            // Limpia la fecha programada porque ya fue consumida.
                            'scheduled_at' => null,
                        ]);

                        // La publicación automática también queda visible en el historial.
                        $revisions->record(
                            $article,
                            $scheduledBy,
                            'published_automatically',
                            'scheduled',
                            $previousSnapshot,
                        );

                            return true;
                        }, attempts: 3);
                    } catch (ValidationException $exception) {
                        // El límite de categorías públicas no debe abortar todo el lote.
                        // La noticia queda programada para poder reintentarse después.
                        $wasPublished = false;
                        $message = $exception->errors()['operation'][0]
                            ?? 'No se pudo publicar la noticia programada.';
                        $this->warn("Noticia #{$articleReference->id}: {$message}");
                    }

                    if ($wasPublished) {
                        $published++;
                    }
                }
            });

        // El mensaje de consola permite monitorear el resultado del scheduler.
        $this->info($published === 1
            ? 'Se publicó 1 noticia programada.'
            : "Se publicaron {$published} noticias programadas.");

        return self::SUCCESS;
    }

    /**
     * Obtiene el usuario que programó la noticia para atribuir la revisión.
     *
     * Si una instalación antigua no tiene una revisión de programación, se usa
     * el autor como respaldo para cumplir la clave foránea obligatoria.
     */
    private function resolveSchedulingUser(Article $article): User
    {
        // Se atribuye la publicación automática al último usuario que programó o editó.
        $userId = $article->revisions()
            // scheduled es el caso ideal; updated sirve como respaldo de historial.
            ->whereIn('action', ['scheduled', 'updated'])
            // La revisión más reciente es la mejor atribución disponible.
            ->latest('id')
            // Solo se necesita el id de usuario, no la revisión completa.
            ->value('user_id');

        // Si no hay revisión histórica, se usa el autor para cumplir la clave obligatoria.
        return User::query()->find($userId) ?? $article->author()->firstOrFail();
    }

}
