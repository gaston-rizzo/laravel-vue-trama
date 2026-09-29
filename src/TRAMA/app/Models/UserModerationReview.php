<?php

/* ============================================================================
 * MODEL: UserModerationReview.php
 * ============================================================================
 *
 * Conserva el historial de cada revisión administrativa solicitada por
 * Moderación sobre una cuenta pública.
 *
 * users mantiene un resumen de la revisión más reciente para que las grillas
 * puedan filtrar y mostrar su estado sin joins complejos. Esta tabla preserva
 * cada ciclo completo aunque una cuenta vuelva a derivarse en el futuro.
 * ============================================================================ */

namespace App\Models;

use App\Models\Concerns\UsesTramaClock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserModerationReview extends Model
{
    use UsesTramaClock;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'requested_at',
        'reason',
        'note',
        'requested_by_id',
        'comment_id',
        'comment_excerpt',
        'article_title',
        'resolved_at',
        'resolution',
        'resolved_by_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** Cuenta pública revisada. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Editor que originó la solicitud. */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    /** Administrador que cerró la solicitud. */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    /** Comentario utilizado como evidencia al derivar la cuenta. */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }
}
