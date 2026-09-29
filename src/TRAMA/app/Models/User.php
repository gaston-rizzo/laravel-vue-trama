<?php

/* ============================================================================
 * MODEL: User.php
 * ============================================================================
 *
 * Representa una cuenta de TRAMA.
 *
 * Una cuenta puede ser pública, para comentar y leer con sesión, o interna,
 * para trabajar en el panel editorial. El modelo también conserva relaciones
 * históricas con noticias, revisiones, devoluciones y comentarios para impedir
 * borrar cuentas que todavía tienen actividad registrada.
 * ============================================================================ */

namespace App\Models;

use App\Models\Concerns\UsesTramaClock;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\File;
use Illuminate\Notifications\Notifiable;
use App\Notifications\TramaResetPassword;
use App\Notifications\TramaVerifyEmail;

class User extends Authenticatable implements MustVerifyEmail
{
    use UsesTramaClock;
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Roles fijos admitidos por el proyecto.
     *
     * admin: gestiona usuarios, categorías, etiquetas, publicidades
     * y la configuración general del CMS.
     * editor: revisa, devuelve, programa y publica noticias.
     * journalist: escribe noticias y las envía a revisión.
     * reader: usuario público que puede comentar.
     *
     * @var list<string>
     */
    public const ROLES = ['admin', 'editor', 'journalist', 'reader'];

    /**
     * Roles que permiten ingresar al panel editorial.
     *
     * reader queda fuera porque representa cuentas públicas del portal, no
     * cuentas internas de administración.
     *
     * @var list<string>
     */
    public const EDITORIAL_ROLES = ['admin', 'editor', 'journalist'];

    /**
     * Campos que pueden asignarse de forma masiva.
     *
     * Laravel permite escribir estos campos con create(), update() o fill().
     * password se puede asignar porque el cast "hashed" lo convierte en hash
     * antes de guardarlo.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'email_verified_at',
        'password',
        'role',
        'job_title',
        'bio',
        'avatar',
        'is_active',
        'last_login_at',
        'disabled_at',
        'blocked_reason',
        'blocked_note',
        'password_reset_required_at',
        'moderation_review_requested_at',
        'moderation_review_reason',
        'moderation_review_note',
        'moderation_review_requested_by_id',
        'moderation_review_comment_id',
        'moderation_review_comment_excerpt',
        'moderation_review_article_title',
        'moderation_review_resolved_at',
        'moderation_review_resolution',
        'moderation_review_resolved_by_id',
    ];

    /**
     * Atributos que nunca deben exponerse al serializar el modelo.
     *
     * password contiene el hash de la contraseña y remember_token permite
     * mantener sesiones recordadas; ambos deben quedar fuera de respuestas JSON.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Indica cómo Laravel debe convertir campos al leerlos o escribirlos.
     *
     * Las fechas pasan a objetos de fecha, is_active pasa a booleano y password
     * se guarda automáticamente como hash. Así no se almacena la contraseña en
     * texto plano.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Momento en que el usuario confirmó su correo.
            'email_verified_at' => 'datetime',
            // Laravel aplica hash automático antes de guardar la contraseña.
            'password' => 'hashed',
            // Indica si la cuenta puede iniciar sesión.
            'is_active' => 'boolean',
            // Último inicio de sesión registrado.
            'last_login_at' => 'datetime',
            // Fecha en la que la cuenta fue desactivada, si corresponde.
            'disabled_at' => 'datetime',
            // Marca administrativa que obliga a restablecer acceso.
            'password_reset_required_at' => 'datetime',
            // Momento en que un editor derivó manualmente la cuenta para revisión.
            'moderation_review_requested_at' => 'datetime',
            // Momento en que administración resolvió la revisión más reciente.
            'moderation_review_resolved_at' => 'datetime',
        ];
    }

    /**
     * Indica si la cuenta tiene una ruta de avatar propia guardada en la base.
     *
     * Un valor null o vacío es normal: en ese caso TRAMA utiliza el avatar
     * genérico y no considera que exista un problema de integridad.
     */
    public function hasCustomAvatar(): bool
    {
        return is_string($this->avatar)
            && trim($this->avatar) !== '';
    }

    /**
     * Comprueba que el avatar propio siga existiendo físicamente.
     *
     * La ruta almacenada debe apuntar directamente a /images/team y coincidir
     * exactamente con un archivo real. No se buscan nombres parecidos ni se
     * intenta seguir renombres realizados manualmente fuera de TRAMA.
     */
    public function avatarFileExists(): bool
    {
        if (! $this->hasCustomAvatar()) {
            return false;
        }

        $avatar = '/'.ltrim(
            str_replace('\\', '/', trim((string) $this->avatar)),
            '/'
        );

        // Los avatares administrados y demo viven directamente en /images/team.
        if (! preg_match('#^/images/team/[A-Za-z0-9._-]+$#', $avatar)) {
            return false;
        }

        return File::exists(
            public_path(ltrim($avatar, '/'))
        );
    }

    /**
     * Devuelve una URL de avatar segura para cualquier superficie de TRAMA.
     *
     * Si la cuenta no tiene foto propia o la ruta guardada apunta a un archivo
     * que fue eliminado/renombrado manualmente, se utiliza el avatar genérico.
     * La ruta original permanece intacta en la base para que Administración
     * pueda detectar el problema y permitir repararlo con una nueva imagen.
     */
    public function avatarUrl(): string
    {
        if (! $this->avatarFileExists()) {
            return '/images/brand/avatar-default.webp';
        }

        return '/'.ltrim(
            str_replace('\\', '/', trim((string) $this->avatar)),
            '/'
        );
    }

    /**
     * Define la relación con las noticias escritas por el usuario.
     *
     * articles.author_id apunta a users.id. Esta relación mantiene la autoría
     * histórica aunque el usuario cambie de nombre, rol o estado.
     */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class, 'author_id');
    }

    /**
     * Define la relación con revisiones editoriales generadas por el usuario.
     *
     * Sirve para auditar quién creó, modificó, publicó, archivó o restauró una
     * noticia dentro del flujo editorial.
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(ArticleRevision::class);
    }

    /**
     * Define la relación con devoluciones editoriales hechas por el usuario.
     *
     * returned_by apunta a users.id. Se usa para saber qué editor
     * pidió cambios sobre una noticia.
     */
    public function returnedFeedback(): HasMany
    {
        return $this->hasMany(ArticleReviewFeedback::class, 'returned_by');
    }

    /**
     * Define la relación con comentarios escritos por el usuario en el portal.
     *
     * Incluye comentarios principales y respuestas. Los comentarios no se borran
     * junto con la cuenta automáticamente porque forman parte del historial.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * Define los reportes de comentarios realizados por el usuario.
     */
    public function commentReports(): HasMany
    {
        return $this->hasMany(CommentReport::class);
    }

    /**
     * Editor que solicitó revisión administrativa del lector.
     */
    public function moderationReviewRequestedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'moderation_review_requested_by_id');
    }

    /**
     * Comentario que originó la revisión administrativa del lector.
     */
    public function moderationReviewComment(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'moderation_review_comment_id');
    }

    /**
     * Administrador que resolvió la revisión más reciente del lector.
     */
    public function moderationReviewResolvedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'moderation_review_resolved_by_id');
    }

    /**
     * Historial completo de derivaciones administrativas de la cuenta.
     */
    public function moderationReviews(): HasMany
    {
        return $this->hasMany(UserModerationReview::class);
    }

    /**
     * Indica si la revisión administrativa más reciente continúa pendiente.
     */
    public function hasPendingModerationReview(): bool
    {
        return $this->moderation_review_requested_at !== null
            && $this->moderation_review_resolved_at === null;
    }

    /**
     * Indica si la cuenta puede ingresar al panel editorial.
     *
     * Para entrar al panel se necesitan dos condiciones: cuenta activa y rol
     * interno. Una cuenta reader activa puede iniciar sesión en el portal, pero
     * no puede administrar noticias.
     */
    public function canAccessEditorial(): bool
    {
        // La cuenta debe estar habilitada y su rol debe pertenecer al equipo interno.
        return $this->is_active && in_array($this->role, self::EDITORIAL_ROLES, true);
    }

    /**
     * Indica si la cuenta puede crear un comentario principal
     * dentro de una noticia pública.
     *
     * Los comentarios principales pertenecen a la participación de lectores.
     * Las cuentas internas de TRAMA no publican opiniones como usuarios comunes.
     */
    public function canCreatePublicMainComment(): bool
    {
        return $this->is_active
            && $this->role === 'reader';
    }

    /**
     * Indica si la cuenta puede responder comentarios dentro de una noticia.
     *
     * - reader puede responder normalmente.
     * - journalist solamente puede responder en noticias escritas por él.
     * - editor puede intervenir en cualquier noticia como Equipo TRAMA.
     * - admin no participa públicamente.
     */
    public function canReplyToPublicComments(Article $article): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->role === 'reader') {
            return true;
        }

        if ($this->role === 'journalist') {
            return (int) $article->author_id === (int) $this->id;
        }

        return $this->role === 'editor';
    }

    /**
     * Indica si la cuenta puede utilizar Me gusta en comentarios públicos.
     *
     * El voto pertenece a la participación normal de los lectores. Las cuentas
     * internas de TRAMA no intervienen mediante reacciones anónimas.
     */
    public function canLikePublicComments(): bool
    {
        return $this->is_active
            && $this->role === 'reader';
    }

    /**
     * Indica si la cuenta puede reportar comentarios publicados.
     *
     * Reportar pertenece a la participación de lectores registrados.
     * Periodistas, editores y administradores no utilizan esta acción
     * como si fueran usuarios comunes del portal.
     */
    public function canReportPublicComments(): bool
    {
        return $this->is_active
            && $this->role === 'reader';
    }

    /**
     * Devuelve el contexto con el que una respuesta interna debe quedar guardada.
     *
     * Los lectores no necesitan contexto especial. El periodista queda identificado
     * como autor únicamente dentro de sus propias noticias y el editor interviene
     * públicamente en representación de Equipo TRAMA.
     *
     * Una cuenta interna desactivada no conserva identidad pública para participar.
     */
    public function publicCommentContextFor(Article $article): ?string
    {
        if (! $this->is_active) {
            return null;
        }

        if (
            $this->role === 'journalist'
            && (int) $article->author_id === (int) $this->id
        ) {
            return Comment::AUTHOR_CONTEXT_ARTICLE_AUTHOR;
        }

        if ($this->role === 'editor') {
            return Comment::AUTHOR_CONTEXT_TRAMA_TEAM;
        }

        return null;
    }

    /**
     * Indica si el usuario tiene rol administrador.
     *
     * El administrador puede gestionar cuentas internas, usuarios registrados y
     * configuraciones sensibles del panel.
     */
    public function isAdmin(): bool
    {
        // La comparación se hace contra el valor fijo usado en la base de datos.
        return $this->role === 'admin';
    }

    /**
     * Indica si el usuario puede acceder al módulo de noticias.
     *
     * Periodistas y editores forman parte de la redacción.
     * El administrador no participa del flujo editorial.
     */
    public function canAccessArticles(): bool
    {
        return $this->is_active
            && in_array($this->role, ['journalist', 'editor'], true);
    }

    /**
     * Indica si el usuario puede tomar decisiones editoriales.
     *
     * Solo el editor puede revisar, devolver, programar,
     * publicar o archivar noticias.
     */
    public function canReviewArticles(): bool
    {
        return $this->is_active
            && $this->role === 'editor';
    }

    /**
     * Indica si el usuario puede moderar comentarios.
     *
     * La moderación forma parte de las responsabilidades del editor.
     */
    public function canModerateComments(): bool
    {
        return $this->is_active
            && $this->role === 'editor';
    }

    /**
     * Indica si el usuario puede administrar la estructura del CMS.
     *
     * Solo el administrador gestiona empleados, usuarios,
     * categorías, etiquetas, publicidades y configuración.
     */
    public function canManageSystem(): bool
    {
        return $this->is_active
            && $this->role === 'admin';
    }

    /**
     * Alias de compatibilidad para el nombre anterior del permiso editorial.
     *
     * Las decisiones editoriales corresponden exclusivamente al editor y la
     * implementación delega en canReviewArticles(), que es el permiso vigente.
     * Se conserva el alias para no romper integraciones que pudieran invocarlo.
     */
    public function canApproveArticles(): bool
    {
        return $this->canReviewArticles();
    }

    /**
     * Indica si la cuenta conserva historial que impide eliminarla.
     *
     * Si el usuario tiene noticias, revisiones, comentarios o devoluciones
     * asociadas, se debe desactivar la cuenta en lugar de borrarla para no perder
     * trazabilidad editorial.
     */
    public function hasHistoricalContent(): bool
    {
        // exists consulta rápido si hay al menos una fila relacionada.
        return $this->articles()->exists()
            || $this->revisions()->exists()
            || $this->comments()->exists()
            || $this->returnedFeedback()->exists()
            || $this->moderationReviews()->exists();
    }

    /**
     * Envía el correo personalizado para verificar la cuenta.
     *
     * Laravel llama este método durante el flujo de registro. En vez de usar el
     * mail genérico del framework, TRAMA envía una notificación propia con
     * diseño, textos y enlace de verificación adaptados al sitio.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new TramaVerifyEmail());
    }

    /**
     * Envía el correo personalizado para restablecer la contraseña.
     *
     * Laravel genera el token de recuperación y lo pasa a este método. TRAMA usa
     * una notificación propia para enviar el enlace con el formato visual del
     * portal.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new TramaResetPassword($token));
    }
}
