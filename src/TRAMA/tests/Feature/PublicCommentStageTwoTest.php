<?php

/* ============================================================================
 * TEST: PublicCommentStageTwoTest.php
 * ============================================================================
 *
 * Comprueba las reglas públicas incorporadas durante la Etapa 2 del sistema
 * de comentarios de TRAMA.
 *
 * Las pruebas verifican:
 *
 * - creación de reportes;
 * - bloqueo de autoreportes;
 * - bloqueo de reportes duplicados;
 * - permisos según rol;
 * - comentarios que pueden o no pueden reportarse;
 * - límite de reportes;
 * - carga del estado "Reportado" en comentarios y respuestas;
 * - límites para comentar, responder, editar, eliminar y dar Me gusta;
 * - manejo amigable de errores inesperados;
 * - respuesta global 503 cuando MySQL no está disponible.
 *
 * Todas las pruebas usan una base de datos de testing mediante RefreshDatabase.
 * ============================================================================ */


namespace Tests\Feature;


use App\Jobs\ModerateComment;
use App\Models\Article;
use App\Models\Category;
use App\Models\Comment;
use App\Models\User;
use App\Support\TramaClock;


use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;


use PDOException;
use RuntimeException;


use Tests\TestCase;


class PublicCommentStageTwoTest extends TestCase
{
    use RefreshDatabase;


    /**
     * Número usado para generar slugs distintos en las noticias creadas
     * durante una misma prueba.
     */
    private int $articleSequence = 0;


    /**
     * Limpia el cache antes de cada prueba para que los contadores de rate limit
     * de una prueba anterior no afecten a la siguiente.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        Queue::fake([
            ModerateComment::class,
        ]);
    }


    /**
     * Un lector activo puede reportar un comentario aprobado de otra persona.
     *
     * El reporte debe guardarse sin cambiar el estado público del comentario.
     */
    public function test_reader_can_report_approved_comment_from_another_user(): void
    {
        $reporter = $this->reader();
        $author = $this->reader();
        $journalist = $this->internalUser('journalist');

        $article = $this->publishedArticle($journalist);

        $comment = $this->comment(
            article: $article,
            user: $author,
            status: 'approved'
        );

        $response = $this->actingAs($reporter)->postJson(
            route('comments.reports.store', $comment),
            [
                'reason' => 'spam',
            ]
        );

        $response->assertCreated();

        $this->assertDatabaseHas('comment_reports', [
            'comment_id' => $comment->id,
            'user_id' => $reporter->id,
            'reason' => 'spam',
            'status' => 'open',
        ]);

        $this->assertDatabaseHas('comments', [
            'id' => $comment->id,
            'status' => 'approved',
        ]);
    }


    /**
     * Un usuario no puede reportar su propio comentario.
     */
    public function test_reader_cannot_report_own_comment(): void
    {
        $reader = $this->reader();
        $journalist = $this->internalUser('journalist');

        $article = $this->publishedArticle($journalist);

        $comment = $this->comment(
            article: $article,
            user: $reader,
            status: 'approved'
        );

        $response = $this->actingAs($reader)->postJson(
            route('comments.reports.store', $comment),
            [
                'reason' => 'spam',
            ]
        );

        $response->assertForbidden();

        $this->assertDatabaseMissing('comment_reports', [
            'comment_id' => $comment->id,
            'user_id' => $reader->id,
        ]);
    }


    /**
     * Una misma cuenta solamente puede reportar una vez el mismo comentario.
     */
    public function test_reader_cannot_report_same_comment_twice(): void
    {
        $reporter = $this->reader();
        $author = $this->reader();
        $journalist = $this->internalUser('journalist');

        $article = $this->publishedArticle($journalist);

        $comment = $this->comment(
            article: $article,
            user: $author,
            status: 'approved'
        );

        $firstResponse = $this->actingAs($reporter)->postJson(
            route('comments.reports.store', $comment),
            [
                'reason' => 'spam',
            ]
        );

        $firstResponse->assertCreated();

        $secondResponse = $this->actingAs($reporter)->postJson(
            route('comments.reports.store', $comment),
            [
                'reason' => 'hate',
            ]
        );

        $secondResponse
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['operation']);

        $this->assertSame(
            1,
            $comment->reports()
                ->where('user_id', $reporter->id)
                ->count()
        );
    }


    /**
     * Periodistas, editores y administradores no reportan comentarios
     * como si fueran usuarios comunes del portal.
     */
    public function test_internal_roles_cannot_report_comments(): void
    {
        $commentAuthor = $this->reader();
        $journalist = $this->internalUser('journalist');

        $article = $this->publishedArticle($journalist);

        $comment = $this->comment(
            article: $article,
            user: $commentAuthor,
            status: 'approved'
        );

        foreach (['journalist', 'editor', 'admin'] as $role) {
            $internalUser = $this->internalUser($role);

            $response = $this->actingAs($internalUser)->postJson(
                route('comments.reports.store', $comment),
                [
                    'reason' => 'spam',
                ]
            );

            $response->assertForbidden();

            $this->assertDatabaseMissing('comment_reports', [
                'comment_id' => $comment->id,
                'user_id' => $internalUser->id,
            ]);
        }
    }


    /**
     * Un lector desactivado tampoco puede reportar comentarios.
     */
    public function test_inactive_reader_cannot_report_comments(): void
    {
        $inactiveReader = $this->reader(active: false);
        $commentAuthor = $this->reader();
        $journalist = $this->internalUser('journalist');

        $article = $this->publishedArticle($journalist);

        $comment = $this->comment(
            article: $article,
            user: $commentAuthor,
            status: 'approved'
        );

        $response = $this->actingAs($inactiveReader)->postJson(
            route('comments.reports.store', $comment),
            [
                'reason' => 'spam',
            ]
        );

        $response->assertForbidden();

        $this->assertDatabaseMissing('comment_reports', [
            'comment_id' => $comment->id,
            'user_id' => $inactiveReader->id,
        ]);
    }


    /**
     * Los comentarios pendientes o rechazados no pueden reportarse.
     */
    public function test_pending_and_rejected_comments_cannot_be_reported(): void
    {
        $reporter = $this->reader();
        $commentAuthor = $this->reader();
        $journalist = $this->internalUser('journalist');

        $article = $this->publishedArticle($journalist);

        foreach (['pending', 'rejected'] as $status) {
            $comment = $this->comment(
                article: $article,
                user: $commentAuthor,
                status: $status,
                body: 'Comentario '.$status.' utilizado para comprobar reportes.'
            );

            $response = $this->actingAs($reporter)->postJson(
                route('comments.reports.store', $comment),
                [
                    'reason' => 'spam',
                ]
            );

            $response->assertNotFound();

            $this->assertDatabaseMissing('comment_reports', [
                'comment_id' => $comment->id,
                'user_id' => $reporter->id,
            ]);
        }
    }


    /**
     * Solamente se aceptan motivos de reporte definidos por TRAMA.
     */
    public function test_invalid_report_reason_is_rejected(): void
    {
        $reporter = $this->reader();
        $commentAuthor = $this->reader();
        $journalist = $this->internalUser('journalist');

        $article = $this->publishedArticle($journalist);

        $comment = $this->comment(
            article: $article,
            user: $commentAuthor,
            status: 'approved'
        );

        $response = $this->actingAs($reporter)->postJson(
            route('comments.reports.store', $comment),
            [
                'reason' => 'motivo_inventado',
            ]
        );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $this->assertDatabaseMissing('comment_reports', [
            'comment_id' => $comment->id,
            'user_id' => $reporter->id,
        ]);
    }


    /**
     * Un lector puede realizar como máximo diez reportes dentro de diez minutos.
     *
     * El intento número once debe rechazarse sin crear otra fila.
     */
    public function test_report_limit_is_ten_reports_every_ten_minutes(): void
    {
        $reporter = $this->reader();
        $commentAuthor = $this->reader();
        $journalist = $this->internalUser('journalist');

        $article = $this->publishedArticle($journalist);

        $comments = collect();

        for ($i = 1; $i <= 11; $i++) {
            $comments->push(
                $this->comment(
                    article: $article,
                    user: $commentAuthor,
                    status: 'approved',
                    body: 'Comentario reportable número '.$i.' con texto suficiente.'
                )
            );
        }

        foreach ($comments->take(10) as $comment) {
            $response = $this->actingAs($reporter)->postJson(
                route('comments.reports.store', $comment),
                [
                    'reason' => 'spam',
                ]
            );

            $response->assertCreated();
        }

        $blockedComment = $comments->last();

        $blockedResponse = $this->actingAs($reporter)->postJson(
            route('comments.reports.store', $blockedComment),
            [
                'reason' => 'spam',
            ]
        );

        $blockedResponse
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['operation']);

        $this->assertSame(
            10,
            $reporter->commentReports()->count()
        );

        $this->assertDatabaseMissing('comment_reports', [
            'comment_id' => $blockedComment->id,
            'user_id' => $reporter->id,
        ]);
    }


    /**
     * Cuando un comentario ya fue reportado, el endpoint usado por
     * "Ver más comentarios" debe devolverlo como reportado.
     */
    public function test_loaded_main_comment_knows_when_current_reader_already_reported_it(): void
    {
        $reporter = $this->reader();
        $commentAuthor = $this->reader();
        $journalist = $this->internalUser('journalist');

        $article = $this->publishedArticle($journalist);

        $comment = $this->comment(
            article: $article,
            user: $commentAuthor,
            status: 'approved'
        );

        $this->actingAs($reporter)
            ->postJson(
                route('comments.reports.store', $comment),
                ['reason' => 'spam']
            )
            ->assertCreated();

        $response = $this->actingAs($reporter)->getJson(
            route('comments.index', $article).'?offset=0&sort=recent'
        );

        $response
            ->assertOk()
            ->assertJsonPath('comments.0.id', $comment->id)
            ->assertJsonPath('comments.0.reported_by_current_user', true)
            ->assertJsonPath('comments.0.can_report', false);
    }


    /**
     * Una respuesta cargada desde "Ver más respuestas" también debe recordar
     * que el usuario actual ya la reportó.
     */
    public function test_loaded_reply_knows_when_current_reader_already_reported_it(): void
    {
        $reporter = $this->reader();
        $commentAuthor = $this->reader();
        $journalist = $this->internalUser('journalist');

        $article = $this->publishedArticle($journalist);

        $parent = $this->comment(
            article: $article,
            user: $commentAuthor,
            status: 'approved',
            body: 'Comentario principal aprobado para recibir respuestas.'
        );

        $reply = $this->comment(
            article: $article,
            user: $commentAuthor,
            status: 'approved',
            parent: $parent,
            body: 'Respuesta aprobada que será reportada por otro lector.'
        );

        $this->actingAs($reporter)
            ->postJson(
                route('comments.reports.store', $reply),
                ['reason' => 'spam']
            )
            ->assertCreated();

        $response = $this->actingAs($reporter)->getJson(
            route('comments.replies', $parent).'?offset=0'
        );

        $response
            ->assertOk()
            ->assertJsonPath('replies.0.id', $reply->id)
            ->assertJsonPath('replies.0.reported_by_current_user', true)
            ->assertJsonPath('replies.0.can_report', false);
    }


    /**
     * Crear comentarios y responder utilizan el mismo límite:
     * cinco acciones cada diez minutos.
     */
    public function test_comments_and_replies_share_five_actions_per_ten_minutes_limit(): void
    {
        $reader = $this->reader();
        $otherReader = $this->reader();
        $journalist = $this->internalUser('journalist');

        $articles = collect();

        for ($i = 1; $i <= 6; $i++) {
            $articles->push(
                $this->publishedArticle($journalist)
            );
        }

        // Primeras cuatro acciones: cuatro comentarios principales.
        for ($i = 0; $i < 4; $i++) {
            $response = $this->actingAs($reader)->post(
                route('comments.store', $articles[$i]),
                [
                    'body' => 'Comentario público número '.($i + 1).' con texto suficiente.',
                ]
            );

            $response
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }

        // Quinta acción: una respuesta.
        $parent = $this->comment(
            article: $articles[4],
            user: $otherReader,
            status: 'approved',
            body: 'Comentario aprobado que recibe la quinta acción como respuesta.'
        );

        $fifthResponse = $this->actingAs($reader)->post(
            route('comments.store', $articles[4]),
            [
                'body' => 'Esta es la quinta acción y corresponde a una respuesta.',
                'parent_id' => $parent->id,
            ]
        );

        $fifthResponse
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // Sexta acción: debe quedar bloqueada por el rate limit.
        $sixthResponse = $this->actingAs($reader)->post(
            route('comments.store', $articles[5]),
            [
                'body' => 'Este comentario no debe guardarse porque supera el límite.',
            ]
        );

        $sixthResponse->assertSessionHasErrors([
            'body' => 'Alcanzaste el límite de comentarios. Podrás volver a comentar en 10 minutos.',
        ]);

        $this->assertSame(
            5,
            Comment::query()
                ->where('user_id', $reader->id)
                ->count()
        );
    }


    /**
     * Editar y eliminar comparten el límite de veinte acciones por minuto.
     *
     * Se realizan diecinueve ediciones y una eliminación. La acción número
     * veintiuno debe quedar bloqueada.
     */
    public function test_edit_and_delete_share_twenty_actions_per_minute_limit(): void
    {
        $reader = $this->reader();
        $journalist = $this->internalUser('journalist');

        /*
         * Una edición de pending ahora cambia inmediatamente la fila a processing,
         * por lo que no sería válido editar diecinueve veces el MISMO comentario.
         *
         * Para probar exclusivamente el rate limit se preparan veinte comentarios
         * pending diferentes y se realiza como máximo una acción sobre cada uno.
         */
        $editableComments = collect();

        for ($i = 1; $i <= 20; $i++) {
            $article = $this->publishedArticle($journalist);

            $editableComments->push(
                $this->comment(
                    article: $article,
                    user: $reader,
                    status: 'pending',
                    body: 'Comentario pendiente número '.$i.' preparado para probar modificaciones.'
                )
            );
        }

        // Primeras diecinueve acciones: una edición sobre diecinueve filas distintas.
        foreach ($editableComments->take(19)->values() as $index => $comment) {
            $response = $this->actingAs($reader)->patch(
                route('comments.update', $comment),
                [
                    'body' => 'Edición permitida número '.($index + 1).' con contenido suficiente.',
                ]
            );

            $response
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            $this->assertDatabaseHas('comments', [
                'id' => $comment->id,
                'status' => 'processing',
            ]);
        }

        // Acción número 20: eliminación del comentario que todavía seguía pending.
        $deletableComment = $editableComments->get(19);

        $deleteResponse = $this->actingAs($reader)->delete(
            route('comments.destroy', $deletableComment)
        );

        $deleteResponse
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('comments', [
            'id' => $deletableComment->id,
        ]);

        /*
         * Se prepara una fila adicional fuera de las veinte acciones anteriores.
         * El intento número 21 debe ser frenado por el rate limit antes de editarla.
         */
        $blockedArticle = $this->publishedArticle($journalist);

        $blockedComment = $this->comment(
            article: $blockedArticle,
            user: $reader,
            status: 'pending',
            body: 'Comentario pendiente reservado para comprobar el intento bloqueado.'
        );

        $blockedResponse = $this->actingAs($reader)->patch(
            route('comments.update', $blockedComment),
            [
                'body' => 'Esta edición debe quedar bloqueada por el límite.',
            ]
        );

        $blockedResponse->assertSessionHasErrors([
            'operation' => 'Hiciste demasiadas modificaciones en poco tiempo. Esperá un minuto antes de volver a intentarlo.',
        ]);

        $this->assertDatabaseHas('comments', [
            'id' => $blockedComment->id,
            'status' => 'pending',
            'body' => 'Comentario pendiente reservado para comprobar el intento bloqueado.',
        ]);
    }


    /**
     * Me gusta permite treinta acciones por minuto.
     *
     * Como se alterna entre poner y quitar el voto, después de treinta acciones
     * no debe quedar ningún voto guardado. La acción treinta y uno se rechaza.
     */
    public function test_like_limit_is_thirty_actions_per_minute(): void
    {
        $reader = $this->reader();
        $commentAuthor = $this->reader();
        $journalist = $this->internalUser('journalist');

        $article = $this->publishedArticle($journalist);

        $comment = $this->comment(
            article: $article,
            user: $commentAuthor,
            status: 'approved'
        );

        for ($i = 1; $i <= 30; $i++) {
            $response = $this->actingAs($reader)->post(
                route('comments.like', $comment)
            );

            $response
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(
            0,
            $comment->likes()
                ->where('user_id', $reader->id)
                ->count()
        );

        $blockedResponse = $this->actingAs($reader)->post(
            route('comments.like', $comment)
        );

        $blockedResponse->assertSessionHasErrors([
            'operation' => 'Hiciste demasiadas acciones de Me gusta en poco tiempo. Esperá un minuto antes de volver a intentarlo.',
        ]);

        $this->assertSame(
            0,
            $comment->likes()
                ->where('user_id', $reader->id)
                ->count()
        );
    }


    /**
     * Periodistas, editores y administradores tampoco pueden utilizar Me gusta
     * como usuarios públicos comunes.
     */
    public function test_internal_roles_cannot_like_public_comments(): void
    {
        $commentAuthor = $this->reader();
        $journalist = $this->internalUser('journalist');

        $article = $this->publishedArticle($journalist);

        $comment = $this->comment(
            article: $article,
            user: $commentAuthor,
            status: 'approved'
        );

        foreach (['journalist', 'editor', 'admin'] as $role) {
            $internalUser = $this->internalUser($role);

            $response = $this->actingAs($internalUser)->post(
                route('comments.like', $comment)
            );

            $response->assertForbidden();

            $this->assertSame(
                0,
                $comment->likes()
                    ->where('user_id', $internalUser->id)
                    ->count()
            );
        }
    }


    /**
     * Un error inesperado al crear un reporte debe convertirse en un error
     * amigable y no debe exponer el detalle técnico al usuario.
     */
    public function test_unexpected_report_error_returns_friendly_message(): void
    {
        $reporter = $this->reader();
        $commentAuthor = $this->reader();
        $journalist = $this->internalUser('journalist');

        $article = $this->publishedArticle($journalist);

        $comment = $this->comment(
            article: $article,
            user: $commentAuthor,
            status: 'approved'
        );

        $reportModelClass = get_class(
            $comment->reports()->getRelated()
        );

        $eventName = 'eloquent.creating: '.$reportModelClass;

        Event::listen($eventName, function (): void {
            throw new RuntimeException(
                'ERROR_TECNICO_REPORTE_ETAPA_2'
            );
        });

        try {
            $response = $this->actingAs($reporter)->postJson(
                route('comments.reports.store', $comment),
                [
                    'reason' => 'spam',
                ]
            );
        } finally {
            $this->removeTemporaryModelEvent($eventName);
        }

        $response
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.operation.0',
                'No pudimos enviar el reporte en este momento. Intentá nuevamente.'
            )
            ->assertJsonMissing([
                'ERROR_TECNICO_REPORTE_ETAPA_2',
            ]);

        $this->assertDatabaseMissing('comment_reports', [
            'comment_id' => $comment->id,
            'user_id' => $reporter->id,
        ]);
    }


    /**
     * Un error inesperado al crear un comentario debe devolver un mensaje
     * seguro en lugar de mostrar la excepción interna.
     */
    public function test_unexpected_comment_creation_error_returns_friendly_message(): void
    {
        $reader = $this->reader();
        $journalist = $this->internalUser('journalist');

        $article = $this->publishedArticle($journalist);

        $eventName = 'eloquent.creating: '.Comment::class;

        Event::listen($eventName, function (): void {
            throw new RuntimeException(
                'ERROR_TECNICO_CREAR_COMENTARIO_ETAPA_2'
            );
        });

        try {
            $response = $this->actingAs($reader)->post(
                route('comments.store', $article),
                [
                    'body' => 'Comentario válido que provocará un error controlado.',
                ]
            );
        } finally {
            $this->removeTemporaryModelEvent($eventName);
        }

        $response->assertSessionHasErrors([
            'operation' => 'No pudimos enviar el comentario en este momento. Intentá nuevamente.',
        ]);

        $this->assertDatabaseMissing('comments', [
            'article_id' => $article->id,
            'user_id' => $reader->id,
        ]);
    }


    /**
     * Un error inesperado al crear una respuesta utiliza el mensaje específico
     * de respuestas y no el mensaje de comentarios principales.
     */
    public function test_unexpected_reply_creation_error_returns_friendly_message(): void
    {
        $reader = $this->reader();
        $commentAuthor = $this->reader();
        $journalist = $this->internalUser('journalist');

        $article = $this->publishedArticle($journalist);

        $parent = $this->comment(
            article: $article,
            user: $commentAuthor,
            status: 'approved'
        );

        $eventName = 'eloquent.creating: '.Comment::class;

        Event::listen($eventName, function (): void {
            throw new RuntimeException(
                'ERROR_TECNICO_CREAR_RESPUESTA_ETAPA_2'
            );
        });

        try {
            $response = $this->actingAs($reader)->post(
                route('comments.store', $article),
                [
                    'body' => 'Respuesta válida que provocará un error controlado.',
                    'parent_id' => $parent->id,
                ]
            );
        } finally {
            $this->removeTemporaryModelEvent($eventName);
        }

        $response->assertSessionHasErrors([
            'operation' => 'No pudimos enviar la respuesta en este momento. Intentá nuevamente.',
        ]);

        $this->assertDatabaseMissing('comments', [
            'article_id' => $article->id,
            'user_id' => $reader->id,
            'parent_id' => $parent->id,
        ]);
    }


    /**
     * Un error inesperado durante una edición deja el comentario intacto
     * y devuelve un mensaje seguro.
     */
    public function test_unexpected_edit_error_returns_friendly_message_and_keeps_comment(): void
    {
        $reader = $this->reader();
        $journalist = $this->internalUser('journalist');

        $article = $this->publishedArticle($journalist);

        $comment = $this->comment(
            article: $article,
            user: $reader,
            status: 'pending',
            body: 'Texto original antes de provocar el error.'
        );

        $eventName = 'eloquent.updating: '.Comment::class;

        Event::listen($eventName, function (): void {
            throw new RuntimeException(
                'ERROR_TECNICO_EDITAR_COMENTARIO_ETAPA_2'
            );
        });

        try {
            $response = $this->actingAs($reader)->patch(
                route('comments.update', $comment),
                [
                    'body' => 'Texto nuevo que no debe quedar guardado.',
                ]
            );
        } finally {
            $this->removeTemporaryModelEvent($eventName);
        }

        $response->assertSessionHasErrors([
            'operation' => 'No pudimos guardar los cambios del comentario en este momento. Intentá nuevamente.',
        ]);

        $this->assertDatabaseHas('comments', [
            'id' => $comment->id,
            'body' => 'Texto original antes de provocar el error.',
        ]);
    }


    /**
     * Un error inesperado durante la eliminación mantiene el comentario
     * en la base y devuelve un mensaje amigable.
     */
    public function test_unexpected_delete_error_returns_friendly_message_and_keeps_comment(): void
    {
        $reader = $this->reader();
        $journalist = $this->internalUser('journalist');

        $article = $this->publishedArticle($journalist);

        $comment = $this->comment(
            article: $article,
            user: $reader,
            status: 'approved'
        );

        $eventName = 'eloquent.deleting: '.Comment::class;

        Event::listen($eventName, function (): void {
            throw new RuntimeException(
                'ERROR_TECNICO_ELIMINAR_COMENTARIO_ETAPA_2'
            );
        });

        try {
            $response = $this->actingAs($reader)->delete(
                route('comments.destroy', $comment)
            );
        } finally {
            $this->removeTemporaryModelEvent($eventName);
        }

        $response->assertSessionHasErrors([
            'operation' => 'No pudimos eliminar el comentario en este momento. Intentá nuevamente.',
        ]);

        $this->assertDatabaseHas('comments', [
            'id' => $comment->id,
        ]);
    }


    /**
     * Un error inesperado al crear un Me gusta debe devolverse como mensaje
     * seguro y no debe crear el voto.
     *
     * El modelo relacionado se obtiene desde la propia relación para no depender
     * del nombre concreto de la clase que representa comment_likes.
     */
    public function test_unexpected_like_error_returns_friendly_message_and_does_not_create_like(): void
    {
        $reader = $this->reader();
        $commentAuthor = $this->reader();
        $journalist = $this->internalUser('journalist');

        $article = $this->publishedArticle($journalist);

        $comment = $this->comment(
            article: $article,
            user: $commentAuthor,
            status: 'approved'
        );

        $likeModelClass = get_class(
            $comment->likes()->getRelated()
        );

        $eventName = 'eloquent.creating: '.$likeModelClass;

        Event::listen($eventName, function (): void {
            throw new RuntimeException(
                'ERROR_TECNICO_ME_GUSTA_ETAPA_2'
            );
        });

        try {
            $response = $this->actingAs($reader)->post(
                route('comments.like', $comment)
            );
        } finally {
            $this->removeTemporaryModelEvent($eventName);
        }

        $response->assertSessionHasErrors([
            'operation' => 'No pudimos actualizar tu Me gusta en este momento. Intentá nuevamente.',
        ]);

        $this->assertSame(
            0,
            $comment->likes()
                ->where('user_id', $reader->id)
                ->count()
        );
    }


    /**
     * Simula una caída de conexión MySQL sin apagar realmente el servidor.
     *
     * La excepción SQLSTATE 2002 debe ser procesada por el manejador global
     * y producir una respuesta 503 específica de base de datos no disponible.
     */
    public function test_database_connection_failure_is_rendered_as_global_503(): void
    {
        $previous = new PDOException(
            'SQLSTATE[HY000] [2002] Connection refused',
            2002
        );

        $exception = new QueryException(
            'mysql',
            'select * from comments',
            [],
            $previous
        );

        $request = Request::create(
            '/prueba-automatica-base-caida',
            'GET'
        );

        $request->headers->set(
            'Accept',
            'application/json'
        );

        $response = $this->app
            ->make(ExceptionHandler::class)
            ->render($request, $exception);

        $this->assertSame(
            503,
            $response->getStatusCode()
        );

        $payload = json_decode(
            $response->getContent(),
            true
        );

        $this->assertSame(
            false,
            $payload['success'] ?? null
        );

        $this->assertSame(
            'DATABASE_UNAVAILABLE',
            $payload['code'] ?? null
        );

        $this->assertSame(
            'La página no está disponible en este momento.',
            $payload['message'] ?? null
        );
    }

    /**
     * Cada usuario puede escribir una sola respuesta dentro del mismo
     * comentario principal, independientemente de su rol o del estado
     * con el que quede guardada esa respuesta.
     */
    public function test_each_user_can_reply_only_once_to_same_main_comment(): void
    {
        $reader = $this->reader();
        $commentAuthor = $this->reader();
        $journalist = $this->internalUser('journalist');
        $editor = $this->internalUser('editor');

        // El periodista es autor de esta noticia para que tenga permiso de responder.
        $article = $this->publishedArticle($journalist);

        $parent = $this->comment(
            article: $article,
            user: $commentAuthor,
            status: 'approved',
            body: 'Comentario principal utilizado para comprobar una única respuesta por usuario.'
        );

        foreach ([$reader, $journalist, $editor] as $user) {
            // Primera respuesta: permitida.
            $firstResponse = $this->actingAs($user)->post(
                route('comments.store', $article),
                [
                    'body' => 'Primera respuesta válida escrita por '.$user->role.'.',
                    'parent_id' => $parent->id,
                ]
            );

            $firstResponse
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            // Segunda respuesta al mismo comentario principal: bloqueada.
            $secondResponse = $this->actingAs($user)->post(
                route('comments.store', $article),
                [
                    'body' => 'Segunda respuesta que no debe ser permitida.',
                    'parent_id' => $parent->id,
                ]
            );

            $secondResponse->assertSessionHasErrors([
                'body' => 'Ya respondiste este comentario.',
            ]);

            // Debe existir exactamente una respuesta de esa cuenta.
            $this->assertSame(
                1,
                Comment::query()
                    ->where('parent_id', $parent->id)
                    ->where('user_id', $user->id)
                    ->count()
            );
        }
    }

    /**
     * Crea un lector activo o desactivado según lo pedido por la prueba.
     */
    private function reader(bool $active = true): User
    {
        return User::factory()->create([
            'role' => 'reader',
            'is_active' => $active,
            'email_verified_at' => now(),
        ]);
    }


    /**
     * Crea una cuenta interna activa para probar permisos por rol.
     */
    private function internalUser(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }


    /**
     * Crea una noticia publicada y visible para las pruebas públicas.
     */
    private function publishedArticle(
        User $author,
        array $attributes = [],
    ): Article {
        $this->articleSequence++;

        $category = $this->defaultCategory();

        return Article::query()->create(array_merge([
            'author_id' => $author->id,
            'category_id' => $category->id,
            'title' => 'Noticia pública de prueba '.$this->articleSequence,
            'slug' => 'noticia-publica-prueba-'.$this->articleSequence,
            'subtitle' => 'Bajada utilizada por las pruebas automáticas.',
            'excerpt' => 'Resumen utilizado para comprobar el sistema público de comentarios.',
            'body' => '<p>Contenido suficiente para una noticia pública utilizada durante las pruebas.</p>',
            'status' => 'published',
            'views' => 0,
            'published_at' => TramaClock::now()->subMinute(),
            'scheduled_at' => null,
        ], $attributes));
    }


    /**
     * Devuelve una categoría válida para las noticias creadas durante el test.
     */
    private function defaultCategory(): Category
    {
        return Category::query()->firstOrCreate(
            [
                'slug' => 'general-tests-etapa-2',
            ],
            [
                'name' => 'General Tests Etapa 2',
                'description' => 'Categoría utilizada solamente por las pruebas automáticas.',
                'accent_color' => '#D6A23A',
                'sort_order' => 0,
                'is_active' => true,
            ]
        );
    }


    /**
     * Crea directamente un comentario o una respuesta con el estado necesario
     * para preparar cada escenario del test.
     *
     * Las acciones que queremos comprobar después sí pasan por las rutas reales.
     */
    private function comment(
        Article $article,
        User $user,
        string $status = 'approved',
        ?Comment $parent = null,
        string $body = 'Comentario utilizado por las pruebas automáticas de TRAMA.',
    ): Comment {
        return $article->comments()->create([
            'parent_id' => $parent?->id,
            'user_id' => $user->id,
            'author_name' => $user->name,
            'author_email' => $user->email,
            'author_context' => null,
            'body' => $body,
            'status' => $status,
        ]);
    }


    /**
     * Retira un listener temporal utilizado para provocar una RuntimeException.
     *
     * Después se limpian los modelos iniciados para que Laravel pueda registrar
     * nuevamente sus eventos normales en las siguientes operaciones del test.
     */
    private function removeTemporaryModelEvent(string $eventName): void
    {
        Event::forget($eventName);

        Model::clearBootedModels();
    }
}