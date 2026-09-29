<?php

/* ============================================================================
 * MODEL: CommentReport.php
 * ============================================================================
 *
 * Representa un reporte realizado por un lector sobre un comentario publicado.
 *
 * Guarda quién realizó el reporte, el motivo elegido y su estado de revisión.
 * Un mismo usuario solamente puede reportar una vez cada comentario.
 * ============================================================================ */

namespace App\Models;

use App\Models\Concerns\UsesTramaClock;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommentReport extends Model
{
    use UsesTramaClock;
    /**
     * Estados internos del reporte.
     *
     * open: todavía requiere revisión.
     * dismissed: el editor revisó el caso y decidió mantener el comentario.
     * resolved: el editor tomó una acción de moderación sobre el comentario.
     */
    public const STATUS_OPEN = 'open';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_RESOLVED = 'resolved';

    /**
     * Motivos disponibles para reportar un comentario.
     */
    public const REASON_PERSONAL_ATTACK = 'personal_attack';
    public const REASON_HATE = 'hate';
    public const REASON_THREAT = 'threat';
    public const REASON_SPAM = 'spam';
    public const REASON_PERSONAL_INFORMATION = 'personal_information';

    /**
     * Relaciona cada valor interno con el texto que verá el lector.
     *
     * @var array<string, string>
     */
    public const REASON_LABELS = [
        self::REASON_PERSONAL_ATTACK => 'Insultos o ataques personales',
        self::REASON_HATE => 'Discriminación u odio',
        self::REASON_THREAT => 'Amenazas o violencia',
        self::REASON_SPAM => 'Spam o publicidad',
        self::REASON_PERSONAL_INFORMATION => 'Información personal',        
    ];

    /**
     * Campos que pueden asignarse al crear o revisar un reporte.
     *
     * @var list<string>
     */
    protected $fillable = [
        'comment_id',
        'user_id',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    /**
     * Convierte reviewed_at en una fecha de Laravel.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Comentario que fue reportado.
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    /**
     * Usuario que realizó el reporte.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Editor que revisó el reporte.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Devuelve los motivos en el formato que necesita Vue
     * para construir las opciones del modal de reporte.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function reasonOptions(): array
    {
        return collect(self::REASON_LABELS)
            ->map(
                fn (string $label, string $value) => [
                    'value' => $value,
                    'label' => $label,
                ]
            )
            ->values()
            ->all();
    }
}