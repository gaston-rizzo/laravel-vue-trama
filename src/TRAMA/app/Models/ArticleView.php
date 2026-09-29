<?php

/* ============================================================================
 * MODEL: ArticleView.php
 * ============================================================================
 *
 * Representa una vista registrada sobre una noticia.
 *
 * Registra una visita por artículo usando identificadores técnicos convertidos
 * a hash, como la IP y el navegador. Esos datos no se guardan como texto
 * visible: se transforman en valores irreversibles para contar lecturas,
 * evitar duplicados diarios y construir rankings sin exponer datos personales.
 * ============================================================================ */

namespace App\Models;

use App\Models\Concerns\UsesTramaClock;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleView extends Model
{
    use UsesTramaClock;
    /**
     * Campos que se pueden escribir al registrar una vista.
     *
     * article_id identifica la noticia vista, ip_hash y user_agent_hash guardan
     * identificadores técnicos anonimizados, y viewed_on guarda el día de la
     * visita para métricas diarias.
     *
     * @var list<string>
     */
    protected $fillable = [
        'article_id',
        'ip_hash',
        'user_agent_hash',
        'viewed_on',
    ];

    /**
     * Indica cómo Laravel debe convertir la fecha de visualización.
     *
     * viewed_on se usa como fecha simple, sin hora, porque el objetivo es contar
     * visitas por día.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Día en que se registró la visita.
            'viewed_on' => 'date',
        ];
    }

    /**
     * Define la relación con la noticia visualizada.
     *
     * article_views.article_id apunta al id de articles. Laravel usa esta
     * relación para saber a qué noticia pertenece cada visita registrada.
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
