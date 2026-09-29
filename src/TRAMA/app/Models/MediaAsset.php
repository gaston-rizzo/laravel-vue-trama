<?php

/* ============================================================================
 * MODEL: MediaAsset.php
 * ============================================================================
 *
 * Representa un archivo visual registrado por TRAMA.
 *
 * Guarda imágenes subidas o asociadas a una noticia, junto con su uso, ruta,
 * texto alternativo, crédito, origen, licencia y dimensiones. Esa información
 * permite mostrar portadas con metadatos editoriales claros.
 * ============================================================================ */

namespace App\Models;

use App\Models\Concerns\UsesTramaClock;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaAsset extends Model
{
    use UsesTramaClock;
    /**
     * Campos que pueden escribirse al registrar un archivo visual.
     *
     * article_id puede quedar en null si el archivo no pertenece todavía a una
     * noticia concreta. uploaded_by guarda quién lo cargó. disk y path indican
     * dónde está el archivo. usage describe para qué se usa, por ejemplo portada.
     *
     * @var list<string>
     */
    protected $fillable = [
        'article_id',
        'uploaded_by',
        'disk',
        'usage',
        'path',
        'alt_text',
        'credit',
        'source_url',
        'license',
        'width',
        'height',
    ];

    /**
     * Define la relación con la noticia asociada al archivo.
     *
     * media_assets.article_id apunta al id de articles. Puede quedar null si el
     * archivo se registró de forma general y no para una noticia específica.
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * Define la relación con el usuario que subió o registró el archivo.
     *
     * uploaded_by apunta al id de users. Sirve para auditar quién cargó una
     * portada o imagen dentro del panel editorial.
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
