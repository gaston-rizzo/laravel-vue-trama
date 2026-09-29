<?php

/* ============================================================================
 * JOB: ModerateComment.php
 * ============================================================================
 *
 * TRAMA — MODERACIÓN AUTOMÁTICA DE COMENTARIOS
 * ---------------------------------------------------------------------------
 *
 * OBJETIVO
 * ---------------------------------------------------------------------------
 *
 * Este Job procesa en segundo plano un comentario enviado por un usuario
 * registrado.
 *
 * Su función es recuperar ese comentario desde la base de datos, enviarlo
 * al proceso Node.js de moderación mediante "Process::run()" y ejecutar:
 *
 *     scripts/comments/moderate-comment.mjs
 *
 * Ese script coordina los cinco detectores de TRAMA:
 *
 *     idioma -> links -> amenazas -> toxicidad -> spam
 *
 * Node devuelve una única decisión en formato JSON y este Job utiliza ese
 * resultado para actualizar el comentario como:
 *
 *     "approved" -> se publica;
 *     "pending"  -> pasa a moderación humana;
 *     "rejected" -> queda rechazado automáticamente.
 *
 * Laravel ya guardó previamente ese comentario en la tabla "comments" de la
 * base de datos con:
 *
 *     status = "processing"
 *
 * Se guarda primero porque la petición web debe terminar rápido y el comentario
 * tiene que quedar persistido, identificado y recuperable mientras el análisis
 * automático se ejecuta desde la Queue.
 *
 * El Job NO llama uno por uno a los cinco detectores y NO usa un servidor HTTP
 * local. Sigue el mismo patrón que TRAMA utiliza para procesos Node de imágenes:
 *
 *     ModerateComment
 *          |
 *          v
 *     Process::run()
 *          |
 *          v
 *     node scripts/comments/moderate-comment.mjs
 *          |
 *          +--> idioma
 *          +--> links
 *          +--> amenazas
 *          +--> toxicidad
 *          +--> spam
 *          |
 *          v
 *     JSON por stdout
 *          |
 *          v
 *     ModerateComment valida el contrato y actualiza la misma fila
 *
 * FLUJO GENERAL
 * ---------------------------------------------------------------------------
 *
 *     1. Usuario registrado envía un comentario
 *              |
 *              v
 *     2. Laravel guarda el comentario en la tabla "comments" de la base de datos
 *        con status = "processing" (oculto), porque el análisis automático se
 *        ejecuta después de responder al usuario y necesitamos que el comentario
 *        ya quede persistido, identificado y recuperable aunque falle la cola,
 *        Node o alguno de los clasificadores.
 *              |
 *              v
 *     3. Laravel responde inmediatamente al navegador:
 *
 *        "Comentario recibido.
 *         Estamos revisándolo antes de publicarlo."
 *
 *              |
 *              v
 *     4. ModerateComment queda guardado como Job en la cola "moderation".
 *        La fila real del comentario continúa en "comments"; la cola solamente
 *        contiene el trabajo que debe realizarse sobre esa fila.
 *              |
 *              v
 *     5. El worker de Laravel toma el Job:
 *
 *        php artisan queue:work --queue=moderation,default --tries=3 --timeout=70
 *
 *              |
 *              v
 *     6. El Job vuelve a buscar el comentario por ID y comprueba:
 *
 *        status = "processing"
 *        moderation_revision = revisión esperada
 *
 *              |
 *              v
 *     7. El Job ejecuta directamente:
 *
 *        node scripts/comments/moderate-comment.mjs <id> <revision> <texto>
 *
 *        La ruta del ejecutable Node se obtiene de:
 *
 *        config('trama.node_binary')
 *
 *        exactamente igual que en los procesos Node de imágenes de TRAMA.
 *              |
 *              v
 *     8. moderate-comment.mjs importa y ejecuta los detectores en este orden:
 *
 *        idioma -> links -> amenazas -> toxicidad -> spam
 *
 *        El pipeline no siempre ejecuta los cinco detectores completos.
 *
 *        Si una etapa produce una decisión terminal, Node corta el análisis
 *        en ese punto y no ejecuta los detectores siguientes.
 *
 *        Ejemplos:
 *
 *        - si links detecta un enlace prohibido:
 *
 *              links -> rejected
 *
 *          Node termina ahí y no ejecuta amenazas, toxicidad ni spam.
 *
 *        - si amenazas devuelve riesgo HIGH:
 *
 *              amenazas -> rejected
 *
 *          Node termina ahí y no ejecuta toxicidad ni spam.
 *
 *        - si una etapa solamente recomienda moderación humana:
 *
 *              pending provisional
 *
 *          Node puede continuar con los detectores siguientes, porque alguno
 *          de ellos todavía podría encontrar una causa más grave que convierta
 *          la decisión final en "rejected".
 *
 *        Si ninguna etapa produce un rechazo automático ni requiere
 *        moderación humana, el pipeline llega hasta spam y la decisión
 *        final será "approved". 
 *              |
 *              v
 *     9. El script devuelve un único JSON por stdout.
 *              |
 *              v
 *    10. Laravel valida ese JSON y actualiza ESA MISMA fila de "comments":
 *
 *        approved -> se publica
 *        pending  -> pasa a moderación humana
 *        rejected -> rechazo automático conservado como historial
 *                    moderation_source = "automatic"
 *
 * IMPORTANTE
 * ---------------------------------------------------------------------------
 * "processing" es un estado técnico y transitorio:
 *
 * - no es público;
 * - no aparece en el panel de moderación humana;
 * - el usuario no puede editarlo mientras está siendo analizado;
 * - no equivale a "pending";
 * - un fallo técnico nunca lo convierte en "approved".
 *
 * "pending" significa exclusivamente que el análisis automático terminó y el
 * comentario necesita una decisión humana.
 *
 * Los rechazos automáticos y editoriales comparten status = "rejected", pero se
 * diferencian con moderation_source:
 *
 *     automatic -> rechazado por los filtros
 *     editorial -> rechazado por un editor
 * ============================================================================ */

namespace App\Jobs;

use App\Models\Comment;
use App\Support\TramaLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

class ModerateComment implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Archivo Node que coordina los cinco detectores.
     *
     * La ruta queda declarada explícitamente acá para que al leer el Job sea
     * evidente qué proceso se ejecuta. No existe URL, host ni puerto.
     */
    private const SCRIPT_PATH =
        'scripts/comments/moderate-comment.mjs';

    /**
     * Tiempo máximo permitido al subproceso Node.
     *
     * Debe quedar por debajo del timeout total del Job.
     */
    private const PROCESS_TIMEOUT_SECONDS = 60;

    /**
     * Cantidad máxima de ejecuciones del Job.
     *
     * Primer intento + dos reintentos.
     */
    public int $tries = 3;

    /**
     * Tiempo máximo total permitido por Laravel para una ejecución del Job.
     */
    public int $timeout = 70;

    /**
     * Un timeout se considera un fallo real y participa de los reintentos.
     */
    public bool $failOnTimeout = true;

    /**
     * El Job siempre se crea para una versión exacta del comentario.
     *
     * Ejemplo:
     *
     *     new ModerateComment(
     *         commentId: 85,
     *         moderationRevision: 3,
     *     );
     *
     * Sólo podrá aplicar el resultado si la fila continúa:
     *
     *     id = 85
     *     status = processing
     *     moderation_revision = 3
     */
    public function __construct(
        public readonly int $commentId,
        public readonly int $moderationRevision,
    ) {
        /*
         * La moderación usa una cola propia para poder separarla de otros Jobs.
         */
        $this->onQueue('moderation');

        /*
         * Si el Job se despacha dentro de una transacción, no queda disponible
         * hasta que la fila del comentario haya sido confirmada en MySQL.
         */
        $this->afterCommit();
    }

    /**
     * Pausas entre reintentos ante fallos transitorios de Node/modelos.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15, 30];
    }

    /**
     * Punto de entrada obligatorio de Laravel Queue.
     *
     * Este método NO se invoca manualmente desde CommentController.
     *
     * El flujo es:
     *
     *     ModerateComment::dispatch(...)
     *              |
     *              v
     *     Laravel guarda el Job en la cola "moderation"
     *              |
     *              v
     *     queue:work toma el Job
     *              |
     *              v
     *     Laravel invoca automáticamente handle()
     *              |
     *              v
     *     handle() delega en processAutomaticModeration()
     *
     * Se conserva el nombre handle() porque Laravel lo utiliza como método
     * convencional de ejecución de los Jobs. La lógica real se mantiene en
     * processAutomaticModeration(), cuyo nombre describe claramente qué hace.
     */
    public function handle(): void
    {
        $this->processAutomaticModeration();
    }

    /**
     * Procesa realmente la moderación automática del comentario.
     *
     * Recupera la fila de "comments", comprueba que el Job siga vigente,
     * ejecuta Node.js con "scripts/comments/moderate-comment.mjs", valida
     * el JSON devuelto y aplica la decisión final sobre la misma fila.
     */
    private function processAutomaticModeration(): void
    {
        /*
         * La Queue guarda el trabajo, no el comentario como entidad principal.
         * Por eso al comenzar se recupera nuevamente la fila real de "comments".
         */
        $comment = Comment::query()
            ->find($this->commentId);

        if (! $comment) {
            // La fila fue eliminada mientras el Job esperaba. No queda nada que hacer.
            return;
        }

        /*
         * Primera protección contra Jobs viejos o estados ya resueltos.
         * Si no puede aplicarse el resultado, ni siquiera arrancamos Node.
         */
        if (! $this->canStillBeAutomaticallyModerated($comment)) {
            return;
        }

        /*
         * TRAMA obtiene la ruta absoluta de node.exe/node desde config/trama.php.
         * Es la misma configuración utilizada por los procesos de imágenes.
         */
        $nodeBinary = trim(
            (string) config('trama.node_binary')
        );

        /*
         * base_path() convierte la ruta relativa del coordinador a la ruta
         * absoluta dentro del proyecto.
         */
        $scriptPath = base_path(
            self::SCRIPT_PATH
        );

        if ($nodeBinary === '') {
            /*
             * config("trama.node_binary") obtiene su valor desde config/trama.php.
             * Esa configuración, a su vez, toma la ruta de Node.js desde la
             * variable TRAMA_NODE_BINARY del archivo .env.
             *
             * Si el valor llegó vacío, registramos directamente el nombre de la
             * variable del .env que debe revisarse.
             */
            $this->failModeration(
                'No se pudo iniciar la moderación porque falta configurar Node.js.',
                __FUNCTION__,
                [
                    'env_key' => 'TRAMA_NODE_BINARY',
                ]
            );
        }

        if (! is_file($scriptPath)) {
            $this->failModeration(
                'No se encontró el script Node de moderación de comentarios.',
                __FUNCTION__,
                [
                    'expected_path' => $scriptPath,
                    'relative_path' => self::SCRIPT_PATH,
                    'node_binary' => $nodeBinary,
                ]
            );
        }

        /*
         * ACÁ se invoca realmente el archivo .mjs.
         *
         * El comando equivalente es:
         *
         *     node scripts/comments/moderate-comment.mjs 85 3 "texto..."
         *
         * Process::run() recibe un array: cada argumento viaja por separado.
         * No construimos manualmente una cadena de shell con el texto del usuario.
         *
         * El proceso Node importa internamente los cinco módulos de detección y
         * deja en stdout únicamente el JSON consolidado.
         */
        try {
            $process = Process::path(base_path())
                ->timeout(self::PROCESS_TIMEOUT_SECONDS)
                /*
                 * Igual que en el clasificador de imágenes, Windows necesita
                 * heredar correctamente SystemRoot y PATH para que node.exe y sus
                 * dependencias puedan inicializarse.
                 */
                ->env([
                    'SystemRoot' => getenv('SystemRoot'),
                    'PATH' => getenv('PATH'),
                ])
                ->run([
                    $nodeBinary,
                    $scriptPath,
                    (string) $comment->id,
                    (string) $this->moderationRevision,
                    (string) $comment->body,
                ]);
        } catch (Throwable $exception) {
            $this->failModeration(
                'No se pudo ejecutar el proceso Node de moderación de comentarios.',
                __FUNCTION__,
                [
                    'script_path' => $scriptPath,
                    'node_binary' => $nodeBinary,
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                ],
                $exception
            );
        }

        /*
         * Código distinto de cero = fallo técnico.
         *
         * No interpretamos eso como comentario rechazado ni aprobado. Se lanza
         * una excepción para que Laravel reintente el Job.
         */
        if ($process->failed()) {
            $this->failModeration(
                'El proceso Node de moderación de comentarios terminó con un error.',
                __FUNCTION__,
                [
                    'script_path' => $scriptPath,
                    'node_binary' => $nodeBinary,
                    'exit_code' => $process->exitCode(),
                    'error_output' => trim($process->errorOutput()),
                ]
            );
        }

        /*
         * stdout debe contener un único objeto JSON. Es el mismo patrón utilizado
         * por la clasificación Node de imágenes del proyecto.
         */
        try {
            $result = json_decode(
                trim($process->output()),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            $this->failModeration(
                'El proceso Node de moderación devolvió una respuesta JSON inválida.',
                __FUNCTION__,
                [
                    'script_path' => $scriptPath,
                    'process_output' => Str::limit(trim($process->output()), 1000),
                    'error_output' => Str::limit(trim($process->errorOutput()), 1000),
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                ],
                $exception
            );
        }

        /*
         * Antes de tocar MySQL se comprueba estrictamente que Node respondió por
         * este mismo comentario/revisión y con una decisión conocida.
         */
        $this->validateResponseContract(
            $result,
            $scriptPath,
            $process->output(),
            $process->errorOutput(),
        );

        /*
         * saveModerationResult() vuelve a bloquear y comprobar la fila. La primera
         * comprobación ocurrió ANTES de arrancar Node; esta segunda ocurre DESPUÉS.
         */
        $this->saveModerationResult($result);
    }

    /**
     * Si los tres intentos fallan, el comentario pasa a revisión humana.
     *
     * Regla de seguridad:
     *
     *     fallo técnico != comentario limpio
     *
     * Por eso jamás se publica automáticamente si falla Node, un modelo ONNX,
     * el JSON devuelto o cualquier paso necesario para completar el análisis.
     */
    public function failed(Throwable $exception): void
    {
        /*
         * La Queue agotó los tres intentos del Job.
         * Registramos el fallo definitivo en el mismo TramaLog utilizado por
         * los procesos Node de imágenes y luego enviamos el comentario a
         * moderación humana con status = "pending".
         */
        $this->logModerationError(
            'La moderación automática de un comentario agotó todos los reintentos.',
            __FUNCTION__,
            [
                'script_path' => self::SCRIPT_PATH,
                'node_binary' => (string) config('trama.node_binary'),
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]
        );

        try {
            DB::transaction(
                function (): void {
                    $comment = Comment::query()
                        ->whereKey($this->commentId)
                        ->lockForUpdate()
                        ->first();

                    if (! $comment) {
                        return;
                    }

                    /*
                     * Un Job viejo tampoco puede registrar un error sobre una
                     * versión nueva o sobre un estado ya resuelto.
                     */
                    if (! $this->canStillBeAutomaticallyModerated($comment)) {
                        return;
                    }

                    /*
                     * La información técnica detallada del fallo ya quedó guardada
                     * en TramaLog. En la tabla comments sólo conservamos el estado,
                     * el origen de la transición y una razón corta para que el panel
                     * sepa que el comentario llegó a revisión humana por un error
                     * del proceso automático.
                     */
                    $comment->update([
                        'status' => 'pending',
                        'moderation_source' => 'automatic',
                        'moderation_reason' => 'automation_error',
                    ]);
                },
                attempts: 3,
            );
        } catch (Throwable $secondaryException) {
            /*
             * Incluso pudo fallar la escritura que debía cambiar el comentario
             * de "processing" a "pending". Ese segundo error también queda
             * registrado en el log técnico propio de TRAMA.
             */
            $this->logModerationError(
                'No se pudo enviar a moderación humana un comentario cuyo análisis automático falló.',
                __FUNCTION__,
                [
                    'original_exception_class' => $exception::class,
                    'original_exception' => $exception->getMessage(),
                    'secondary_exception_class' => $secondaryException::class,
                    'secondary_exception' => $secondaryException->getMessage(),
                ]
            );
        }
    }

    /**
     * Determina si ESTE Job todavía puede aplicar una decisión automática.
     */
    private function canStillBeAutomaticallyModerated(
        Comment $comment,
    ): bool {
        /*
         * Sólo "processing" representa análisis automático en curso.
         */
        if ($comment->status !== 'processing') {
            return false;
        }

        /*
         * La revisión evita aplicar el resultado de un Job anterior sobre una
         * edición posterior del comentario.
         */
        if (
            (int) $comment->moderation_revision
            !== $this->moderationRevision
        ) {
            return false;
        }

        /*
         * Los mensajes internos del autor de la noticia/Equipo TRAMA mantienen
         * la política existente y no atraviesan este pipeline de usuarios.
         */
        return ! in_array(
            $comment->author_context,
            [
                Comment::AUTHOR_CONTEXT_ARTICLE_AUTHOR,
                Comment::AUTHOR_CONTEXT_TRAMA_TEAM,
            ],
            true,
        );
    }

    /**
     * Comprueba el contrato mínimo devuelto por "moderate-comment.mjs".
     *
     * Si la estructura no es válida, utiliza failModeration() para registrar
     * el problema en TramaLog y lanzar la excepción que hará fallar este intento
     * del Job.
     *
     * @param mixed $result
     */
    private function validateResponseContract(
        mixed $result,
        string $scriptPath,
        string $processOutput,
        string $errorOutput,
    ): void {
        $context = [
            'script_path' => $scriptPath,
            'process_output' => Str::limit(trim($processOutput), 1500),
            'error_output' => Str::limit(trim($errorOutput), 1000),
        ];

        if (! is_array($result)) {
            $this->failModeration(
                'El proceso Node no devolvió un objeto JSON válido.',
                __FUNCTION__,
                $context
            );
        }

        if (($result['ok'] ?? false) !== true) {
            $this->failModeration(
                'El proceso Node devolvió un resultado no exitoso.',
                __FUNCTION__,
                $context
            );
        }

        if (
            (int) ($result['comment_id'] ?? 0)
            !== $this->commentId
        ) {
            $this->failModeration(
                'Node devolvió un comment_id inesperado.',
                __FUNCTION__,
                $context
            );
        }

        if (
            (int) ($result['revision'] ?? 0)
            !== $this->moderationRevision
        ) {
            $this->failModeration(
                'Node devolvió una moderation_revision inesperada.',
                __FUNCTION__,
                $context
            );
        }

        if (! in_array(
            $result['decision'] ?? null,
            [
                'approved',
                'pending',
                'rejected',
            ],
            true,
        )) {
            $this->failModeration(
                'Node devolvió una decisión de moderación inválida.',
                __FUNCTION__,
                $context
            );
        }

        if (! is_string($result['reason'] ?? null)) {
            $this->failModeration(
                'Node no devolvió una razón de moderación válida.',
                __FUNCTION__,
                $context
            );
        }
    }

    /**
     * Guarda en la base de datos la decisión final devuelta por Node para
     * este comentario.
     *
     * Antes de actualizar la fila, vuelve a buscar el comentario dentro de una
     * transacción y lo bloquea con "lockForUpdate()" para impedir que otro proceso
     * lo modifique al mismo tiempo.
     *
     * Después comprueba nuevamente que:
     *
     *     - El comentario todavía tenga status = "processing";
     *     - Moderation_revision coincida con la revisión analizada por este Job;
     *     - El resultado siga correspondiendo a la versión actual del comentario.
     *
     * Si alguna de esas condiciones dejó de cumplirse, no guarda el resultado
     * devuelto por Node y la fila permanece como esté en ese momento.
     *
     * Si todo sigue siendo válido, actualiza la misma fila de la tabla "comments"
     * con la decisión devuelta por Node:
     *
     *     "approved" -> guarda el comentario como aprobado;
     *     "pending"  -> lo envía a moderación humana;
     *     "rejected" -> lo guarda como rechazado automáticamente.
     *
     * También guarda:
     *
     *     moderation_source = "automatic"
     *
     * para indicar que la decisión provino del análisis automático. También
     * guarda moderation_reason con el motivo principal devuelto por Node.
     *
     * @param array<string, mixed> $result Resultado validado devuelto por
     *        "scripts/comments/moderate-comment.mjs".
     */
    private function saveModerationResult(array $result): void
    {
        DB::transaction(
            function () use ($result): void {
                /*
                 * Segunda barrera contra carreras: Node pudo tardar varios
                 * segundos y durante ese lapso la fila podría haber cambiado.
                 */
                $comment = Comment::query()
                    ->whereKey($this->commentId)
                    ->lockForUpdate()
                    ->first();

                if (! $comment) {
                    return;
                }

                if (! $this->canStillBeAutomaticallyModerated($comment)) {
                    return;
                }

                $decision =
                    (string) $result['decision'];

                $reason =
                    Str::limit(
                        (string) $result['reason'],
                        80,
                        '',
                    );

                /*
                 * Toda decisión de este Job es automática.
                 *
                 * Los rechazos realizados luego desde el panel utilizan
                 * moderation_source = "editorial".
                 */
                $comment->update([
                    'status' =>
                        $decision,
                    'moderation_source' =>
                        'automatic',
                    'moderation_reason' =>
                        $reason,
                ]);
            },
            attempts: 3,
        );
    }

    /**
     * Registra un error técnico de moderación y hace fallar el intento actual
     * del Job.
     *
     * Cumple el mismo objetivo que "failClassification()" en
     * ArticleImageSafetyClassifier:
     *
     * 1. registra el error mediante TramaLog::error();
     * 2. agrega siempre el Job, comment_id y moderation_revision;
     * 3. lanza una RuntimeException para que Laravel Queue aplique los reintentos.
     *
     * @param string $logMessage Mensaje técnico almacenado en TramaLog.
     * @param string $method Método donde se detectó el problema.
     * @param array<string, mixed> $context Datos adicionales para diagnosticarlo.
     */
    private function failModeration(
        string $logMessage,
        string $method,
        array $context = [],
        ?Throwable $previous = null,
    ): never {
        $this->logModerationError(
            $logMessage,
            $method,
            $context
        );

        throw new RuntimeException(
            'No se pudo completar la moderación automática del comentario.',
            previous: $previous
        );
    }

    /**
     * Guarda en TramaLog los errores técnicos que puedan ocurrir
     * mientras este Job procesa la moderación automática del comentario.
     *
     * "failed()" también necesita registrar errores, pero no debe lanzar una
     * nueva excepción después de que Laravel agotó los reintentos. Por eso la
     * escritura del log queda separada de failModeration().
     *
     * @param string $logMessage Mensaje técnico almacenado en TramaLog.
     * @param string $method Método donde se detectó el problema.
     * @param array<string, mixed> $context Datos adicionales para diagnosticarlo.
     */
    private function logModerationError(
        string $logMessage,
        string $method,
        array $context = [],
    ): void {
        TramaLog::error(
            $logMessage,
            [
                'job' => self::class,
                'method' => $method,
                'comment_id' => $this->commentId,
                'moderation_revision' => $this->moderationRevision,
                ...$context,
            ]
        );
    }
}
