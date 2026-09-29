<?php

/* ============================================================================
 * MODEL: Tag.php
 * ============================================================================
 *
 * Representa una etiqueta temática reutilizable entre noticias.
 *
 * Las etiquetas ayudan a clasificar noticias por temas más específicos que la
 * categoría principal. Pueden desactivarse o fusionarse: cuando una etiqueta se
 * fusiona, se conserva como referencia histórica y apunta a la etiqueta elegida
 * como principal.
 * ============================================================================ */

namespace App\Models;

use App\Models\Concerns\UsesTramaClock;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tag extends Model
{
    use UsesTramaClock;
    /**
     * Campos que pueden escribirse desde el panel editorial.
     *
     * merged_into_id queda en null para etiquetas normales. Cuando una etiqueta
     * se fusiona con otra, guarda el id de la etiqueta principal.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_active',
        'merged_into_id',
    ];

    /**
     * Indica cómo Laravel debe convertir los campos de estado.
     *
     * is_active llega como booleano real para saber si la etiqueta se puede
     * asignar a nuevas noticias.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Define si la etiqueta está disponible para nuevos artículos.
            'is_active' => 'boolean',
        ];
    }

    /**
     * Define la relación de muchas noticias para esta etiqueta.
     *
     * Laravel conecta tags con articles mediante la tabla pivote article_tag.
     * Una etiqueta puede aparecer en muchas noticias y una noticia puede tener
     * varias etiquetas.
     */
    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class);
    }

    /**
     * Define la relación con la etiqueta principal de una fusión.
     *
     * tags.merged_into_id apunta al id de otra etiqueta. Si vale null, esta
     * etiqueta no fue fusionada dentro de otra.
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    /**
     * Define la relación con etiquetas secundarias absorbidas por esta etiqueta.
     *
     * Laravel busca otras filas de tags cuyo merged_into_id sea el id de esta
     * etiqueta. Sirve para consultar qué nombres antiguos quedaron asociados a
     * una etiqueta principal.
     */
    public function mergedTags(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_id');
    }

}
