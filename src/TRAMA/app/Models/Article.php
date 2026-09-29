<?php

/* ============================================================================
 * MODEL: Article.php
 * ============================================================================
 *
 * Representa una noticia completa dentro de TRAMA.
 *
 * Este modelo contiene el contenido publicado o en preparación: título, bajada,
 * cuerpo, portada, categoría, etiquetas, autor, estado editorial, vistas,
 * fecha de publicación, fecha programada, comentarios y revisiones históricas.
 *
 * Usa SoftDeletes para permitir borrado lógico si Laravel necesita retirar una
 * fila sin eliminarla físicamente de la base. En TRAMA, archivar una noticia se
 * maneja con el campo status; el borrado lógico queda como protección adicional.
 * ============================================================================ */

namespace App\Models;

use App\Models\Concerns\UsesTramaClock;

use App\Support\TramaClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\File;

class Article extends Model
{
    use UsesTramaClock;
    use SoftDeletes;

    /**
     * Estados admitidos por el flujo editorial de una noticia.
     *
     * draft: borrador interno.
     * review: enviada para revisión.
     * needs_changes: devuelta con correcciones pendientes.
     * scheduled: programada para publicarse más adelante.
     * published: visible en el sitio público.
     * archived: retirada del sitio público sin borrar su historial.
     *
     * @var list<string>
     */
    public const STATUSES = [
        'draft',
        'review',
        'needs_changes',
        'scheduled',
        'published',
        'archived',
    ];

    /**
     * Estados que todavía permiten edición por parte del periodista autor.
     *
     * Un periodista puede trabajar sobre borradores y noticias devueltas. Si la
     * noticia está en revisión, publicada, programada o archivada, la edición
     * queda reservada al equipo con permisos de aprobación.
     *
     * @var list<string>
     */
    public const JOURNALIST_EDITABLE_STATUSES = ['draft', 'needs_changes'];

    /**
     * Campos que pueden asignarse de forma masiva.
     *
     * Laravel usa esta lista cuando se crea o actualiza una noticia con arrays
     * provenientes de formularios, servicios o seeders. Los campos que no estén
     * en esta lista no se escriben mediante create(), update() o fill().
     *
     * @var list<string>
     */
    protected $fillable = [
        'author_id',
        'category_id',
        'title',
        'slug',
        'subtitle',
        'excerpt',
        'body',
        'cover_image',
        'cover_alt',
        'image_credit',
        'image_source_url',
        'image_license',
        'status',
        'is_breaking',
        'is_featured',        
        'reading_time',
        'views',
        'published_at',
        'scheduled_at',
    ];

    /**
     * Indica cómo Laravel debe convertir campos al leerlos desde la base.
     *
     * Las marcas booleanas llegan como true/false en PHP y las fechas llegan
     * como objetos de fecha de Laravel. Eso evita comparar strings manualmente
     * al decidir si una noticia es urgente, destacada o programada.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Identificador numérico de la categoría seleccionada.
            'category_id' => 'integer',
            // Marca la noticia para la cinta de urgentes.
            'is_breaking' => 'boolean',
            // Marca la noticia como destacada para portada.
            'is_featured' => 'boolean',                        
            // Fecha real en la que la noticia queda publicada.
            'published_at' => 'datetime',
            // Fecha futura elegida para publicación programada.
            'scheduled_at' => 'datetime',
        ];
    }


    /**
     * Limita una consulta a noticias que realmente pueden verse en el portal.
     *
     * Además del estado y la fecha de publicación, exige que la categoría siga
     * PUBLICADA. Si la categoría se desactiva, la noticia conserva su historial
     * y su estado published, pero deja de ser accesible desde el sitio público.
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', TramaClock::now())
            ->whereHas(
                'category',
                fn (Builder $category) => $category->publiclyVisible()
            );
    }

    /**
     * Indica si la base conserva una ruta de portada para esta noticia.
     *
     * Una portada vacía es válida mientras el estado editorial permita trabajar
     * sin imagen; no se considera por sí sola un problema de integridad.
     */
    public function hasCoverImage(): bool
    {
        return is_string($this->cover_image)
            && trim($this->cover_image) !== '';
    }

    /**
     * Comprueba que la portada registrada siga existiendo físicamente.
     *
     * La URL debe pertenecer exactamente a la carpeta pública configurada para
     * portadas y contener un único nombre de archivo. No se buscan variantes ni
     * se intenta adivinar un posible renombre realizado manualmente.
     */
    public function coverImageExists(): bool
    {
        if (! $this->hasCoverImage()) {
            return false;
        }

        $coverUrl = '/'.trim(
            str_replace('\\', '/', (string) config('trama.articles.cover_url')),
            '/'
        );
        $currentCover = '/'.ltrim(
            str_replace('\\', '/', trim((string) $this->cover_image)),
            '/'
        );
        $filename = basename($currentCover);

        // Exige /images/articles/archivo.ext sin subdirectorios ni otra ruta.
        if (
            $coverUrl === '/'
            || $filename === ''
            || $currentCover !== $coverUrl.'/'.$filename
        ) {
            return false;
        }

        $directory = (string) config('trama.articles.cover_path');

        if ($directory === '') {
            return false;
        }

        return File::exists(
            $directory.DIRECTORY_SEPARATOR.$filename
        );
    }

    /**
     * Devuelve una portada segura para el portal público.
     *
     * Si la base apunta a un archivo eliminado/renombrado, el valor original se
     * conserva para que Administración pueda repararlo, pero el visitante recibe
     * la imagen predeterminada y nunca una URL rota.
     */
    public function publicCoverImageUrl(): string
    {
        if (! $this->coverImageExists()) {
            return '/images/brand/noticia-default.webp';
        }

        return '/'.ltrim(
            str_replace('\\', '/', trim((string) $this->cover_image)),
            '/'
        );
    }

    /**
     * Define la relación con el usuario autor de la noticia.
     *
     * El campo articles.author_id apunta al id de users. Laravel usa esta
     * relación para cargar el nombre, avatar, cargo y biografía del autor.
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Define la relación con la categoría principal de la noticia.
     *
     * El campo articles.category_id apunta al id de categories. Esta relación
     * permite mostrar la sección pública, el color de acento y la navegación.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Define la relación de muchas etiquetas para una noticia.
     *
     * Laravel conecta articles con tags mediante la tabla pivote article_tag.
     * Una noticia puede tener varias etiquetas y una etiqueta puede aparecer en
     * muchas noticias.
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /**
     * Define la relación con imágenes y archivos vinculados a la noticia.
     *
     * Sirve para registrar portadas subidas desde el panel, guardar metadatos
     * de uso, dimensiones, crédito, licencia y origen del archivo.
     */
    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    /**
     * Define la relación con las versiones históricas de la noticia.
     *
     * Cada vez que el flujo editorial crea, actualiza, publica, programa,
     * archiva o restaura una noticia, se guarda una revisión para poder auditar
     * qué cambió y quién lo hizo.
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(ArticleRevision::class);
    }

    /**
     * Define la relación con las devoluciones editoriales recibidas.
     *
     * Cuando un editor devuelve una noticia, el motivo queda guardado en
     * article_review_feedback y asociado a esta noticia.
     */
    public function reviewFeedback(): HasMany
    {
        return $this->hasMany(ArticleReviewFeedback::class);
    }

    /**
     * Define la relación con los comentarios enviados por usuarios.
     *
     * Incluye comentarios principales y respuestas. La visibilidad pública no
     * se decide acá: los controladores aplican filtros explícitos por estado y
     * usuario para que se entienda qué comentarios se muestran.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * Devuelve una copia completa de los campos editoriales de la noticia.
     *
     * El resultado se guarda dentro de una revisión histórica. Permite comparar
     * versiones, restaurar contenido anterior y conservar evidencia de cómo se
     * veía la noticia en un momento concreto del flujo editorial.
     *
     * @return array<string, mixed>
     */
    public function revisionSnapshot(): array
    {
        // Carga etiquetas solo si todavía no estaban cargadas para evitar
        // consultas repetidas cuando el servicio ya trajo la relación.
        $this->loadMissing('tags:id,name,slug');

        return [
            // Datos de identidad pública de la noticia.
            'title' => $this->title,
            'slug' => $this->slug,
            'subtitle' => $this->subtitle,
            'excerpt' => $this->excerpt,
            // Cuerpo HTML ya sanitizado que se puede restaurar más adelante.
            'body' => $this->body,
            // Categoría y portada vigentes al momento de capturar la revisión.
            'category_id' => $this->category_id,
            'cover_image' => $this->cover_image,
            'cover_alt' => $this->cover_alt,
            // Estado editorial y marcas de portada.
            'status' => $this->status,
            'is_breaking' => (bool) $this->is_breaking,
            'is_featured' => (bool) $this->is_featured,            
            // Fechas serializadas para guardar el snapshot como JSON estable.
            'published_at' => $this->published_at?->toISOString(),
            'scheduled_at' => $this->scheduled_at?->toISOString(),
            // Etiquetas en dos formatos: ids para restaurar relaciones y nombres para lectura humana.
            'tag_ids' => $this->tags->pluck('id')->values()->all(),
            'tag_names' => $this->tags->pluck('name')->values()->all(),
            // Momento exacto en que se tomó la copia histórica.
            'captured_at' => TramaClock::now()->toISOString(),
        ];
    }
}
