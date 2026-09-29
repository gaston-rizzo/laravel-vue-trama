<?php

/* ============================================================================
 * MODEL: Comment.php
 * ============================================================================
 *
 * Representa un comentario escrito por un usuario en una noticia.
 *
 * TRAMA usa moderación previa automática: los comentarios nuevos de usuarios registrados
 * nacen en "processing" y permanecen ocultos mientras Node los analiza. Después
 * pasan a "approved", "pending" (revisión humana) o "rejected". Un comentario puede ser
 * principal o respuesta directa de otro comentario principal. No se permiten
 * respuestas dentro de respuestas para mantener la conversación ordenada.
 * ============================================================================ */

namespace App\Models;

use App\Models\Concerns\UsesTramaClock;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comment extends Model
{
    use UsesTramaClock;
    /**
     * Contextos especiales utilizados para identificar públicamente
     * comentarios escritos por integrantes de TRAMA.
     */
    public const AUTHOR_CONTEXT_ARTICLE_AUTHOR = 'article_author';

    public const AUTHOR_CONTEXT_TRAMA_TEAM = 'trama_team';

    /**
     * Campos que pueden asignarse al crear o editar comentarios.
     *
     * article_id identifica la noticia, parent_id identifica si es respuesta,
     * user_id identifica al usuario autenticado y status guarda el estado de
     * moderación: processing, pending, approved o rejected.
     *
     * @var list<string>
     */
    protected $fillable = [
        'article_id',
        'parent_id',
        'user_id',
        'author_name',
        'author_email',
        'author_context',
        'body',
        'status',
        'moderation_revision',
        'moderation_source',
        'moderation_reason',
    ];

    /**
     * Convierte moderation_revision a entero al leer el comentario desde MySQL.
     *
     * moderation_revision aumenta cuando el lector edita un comentario que debe
     * volver a analizarse. El Job compara este número con la revisión para la que
     * fue creado y descarta resultados viejos.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'moderation_revision' => 'integer',
        ];
    }


    /**
     * Define la relación con la noticia comentada.
     *
     * El campo comments.article_id apunta al id de articles. Laravel usa esta
     * relación para saber en qué noticia debe mostrarse o moderarse el comentario.
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * Define la relación con el comentario principal al que responde.
     *
     * Si parent_id tiene valor, esta fila es una respuesta. Si parent_id es null,
     * esta fila es un comentario principal de la noticia.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Define la relación con las respuestas directas de este comentario.
     *
     * Laravel busca otras filas de comments cuyo parent_id sea el id de este
     * comentario. La relación no permite anidación infinita por sí sola; el
     * controlador valida que solo se responda a comentarios principales.
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Define la relación con el usuario autenticado que escribió el comentario.
     *
     * user_id apunta a users.id. author_name y author_email quedan guardados en
     * el comentario como copia histórica, pero esta relación permite mostrar los
     * datos actuales de la cuenta cuando existen.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Define la relación con los "Me gusta" recibidos por el comentario.
     *
     * Cada fila relacionada representa un voto positivo de un usuario. La base
     * de datos impide duplicados para que una misma cuenta no vote dos veces el
     * mismo comentario.
     */
    public function likes(): HasMany
    {
        return $this->hasMany(CommentLike::class);
    }

    /**
     * Define los reportes realizados sobre este comentario.
     *
     * Cada reporte pertenece a un usuario distinto porque la base impide
     * que una misma cuenta reporte dos veces el mismo comentario.
     */
    public function reports(): HasMany
    {
        return $this->hasMany(CommentReport::class);
    }
}
