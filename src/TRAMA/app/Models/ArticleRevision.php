<?php

/* ============================================================================
 * MODEL: ArticleRevision.php
 * ============================================================================
 *
 * Conserva una versión histórica de una noticia.
 *
 * Cada registro funciona como auditoría del flujo editorial. Guarda qué acción
 * se realizó, quién la hizo, de qué estado venía la noticia, a qué estado pasó,
 * una copia completa del contenido en ese momento y los campos modificados.
 * ============================================================================ */

namespace App\Models;

use App\Models\Concerns\UsesTramaClock;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleRevision extends Model
{
    use UsesTramaClock;
    /**
     * Campos que puede escribir el servicio de revisiones.
     *
     * snapshot guarda una copia del contenido de la noticia, changed_fields
     * guarda qué campos cambiaron y restored_from_revision_id apunta a la
     * revisión usada como origen cuando se restaura una versión anterior.
     *
     * @var list<string>
     */
    protected $fillable = [
        'article_id',
        'user_id',
        'action',
        'status_from',
        'status_to',
        'snapshot',
        'changed_fields',
        'restored_from_revision_id',
    ];

    /**
     * Indica cómo Laravel debe convertir los campos JSON.
     *
     * snapshot y changed_fields se guardan como JSON en la base y se reciben
     * como arrays en PHP para leerlos sin decodificar manualmente.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Copia completa de la noticia en el momento de la revisión.
            'snapshot' => 'array',
            // Lista de campos modificados en esa acción.
            'changed_fields' => 'array',
        ];
    }

    /**
     * Define la relación con la noticia auditada.
     *
     * article_revisions.article_id apunta al id de articles.
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * Define la relación con el usuario que generó la revisión.
     *
     * user_id apunta al usuario que creó, modificó, publicó, programó, archivó
     * o restauró la noticia.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Define la relación con la revisión usada como origen de una restauración.
     *
     * Cuando se restaura contenido anterior, restored_from_revision_id guarda el
     * id de la revisión original. Si esta revisión no viene de una restauración,
     * el campo queda en null.
     */
    public function restoredFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'restored_from_revision_id');
    }
}
