<?php

/* ============================================================================
 * MODEL: ArticleReviewFeedback.php
 * ============================================================================
 *
 * Guarda los pedidos de corrección hechos sobre una noticia.
 *
 * Cada registro representa un mensaje escrito por un editor o administrador
 * cuando una noticia necesita cambios antes de publicarse. Guarda la noticia
 * afectada, quién pidió la corrección, qué mensaje recibió el autor, cuándo se
 * resolvió el pedido y qué usuario lo marcó como resuelto.
 * ============================================================================ */

namespace App\Models;

use App\Models\Concerns\UsesTramaClock;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleReviewFeedback extends Model
{
    use UsesTramaClock;
    /**
     * Nombre real de la tabla asociada al modelo.
     *
     * Laravel intentaría usar article_review_feedback pluralizado de otra forma,
     * por eso se indica la tabla manualmente.
     *
     * @var string
     */
    protected $table = 'article_review_feedback';

    /**
     * Campos que puede escribir el servicio de revisión editorial.
     *
     * article_id identifica la noticia que necesita cambios, returned_by
     * identifica quién pidió la corrección, message contiene el texto enviado
     * al autor, resolved_at indica cuándo se resolvió el pedido y resolved_by
     * guarda quién lo marcó como resuelto.
     *
     * @var list<string>
     */
    protected $fillable = [
        'article_id',
        'returned_by',
        'message',
        'resolved_at',
        'resolved_by',
    ];

    /**
     * Indica cómo Laravel debe convertir la fecha de resolución.
     *
     * resolved_at llega como objeto de fecha cuando la observación fue resuelta.
     * Si sigue pendiente, el valor queda en null.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Fecha y hora en que el pedido de corrección quedó resuelto.
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * Define la relación con la noticia sobre la que se pidieron cambios.
     *
     * article_review_feedback.article_id apunta al id de articles.
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * Define la relación con el usuario que pidió la corrección.
 *
     * returned_by apunta al id del editor o administrador que escribió el
     * mensaje de corrección para el autor.
     */
    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /**
     * Define la relación con el usuario que resolvió la observación.
     *
     * resolved_by apunta al id del usuario que reenvió la noticia corregida o
     * cerró el pedido. Puede quedar en null mientras la corrección sigue
     * pendiente.
     */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
