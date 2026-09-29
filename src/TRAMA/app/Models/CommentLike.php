<?php

/* ============================================================================
 * MODEL: CommentLike.php
 * ============================================================================
 *
 * Representa el "Me gusta" que un usuario dio a un comentario.
 *
 * La base de datos impide que el mismo usuario vote dos veces el mismo
 * comentario. Si el usuario vuelve a pulsar el botón, el registro se elimina y
 * el voto queda cancelado.
 * ============================================================================ */

namespace App\Models;

use App\Models\Concerns\UsesTramaClock;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommentLike extends Model
{
    use UsesTramaClock;
    /**
     * Campos que se pueden escribir al crear un voto positivo.
     *
     * comment_id identifica el comentario votado y user_id identifica al usuario
     * que dio "Me gusta".
     *
     * @var list<string>
     */
    protected $fillable = [
        'comment_id',
        'user_id',
    ];

    /**
     * Define la relación con el comentario votado.
     *
     * comment_likes.comment_id apunta al id de comments.
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    /**
     * Define la relación con el usuario que dio "Me gusta".
     *
     * comment_likes.user_id apunta al id de users. Esta relación permite saber
     * quién votó y aplicar la restricción de un voto por usuario y comentario.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
