<?php

/* ============================================================================
 * CONTROLLER: UserController.php
 * ============================================================================
 *
 * Administra cuentas internas y usuarios registrados de TRAMA.
 *
 * Permite listar empleados y usuarios registrados, crear y editar cuentas internas,
 * bloquear accesos, enviar enlaces de recuperación y eliminar únicamente
 * cuentas sin noticias, comentarios, revisiones ni devoluciones asociadas.
 * ============================================================================ */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEmployeeRequest;
use App\Http\Requests\Admin\ToggleUserStatusRequest;
use App\Http\Requests\Admin\UserIndexRequest;
use App\Http\Requests\Admin\UpdateEmployeeRequest;
use App\Models\Comment;
use App\Models\User;
use App\Models\UserModerationReview;
use App\Services\Images\SafeImageProcessor;
use App\Support\OperationFailureHandler;
use App\Support\TramaBridge;
use App\Support\TramaClock;
use App\Support\TramaLog;

use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use Inertia\Response;
use Throwable;

class UserController extends Controller
{
    public function __construct(
        private readonly OperationFailureHandler $operationFailureHandler,
        private readonly SafeImageProcessor $imageProcessor,
    ) {
    }
    /**
     * Muestra empleados internos con métricas de actividad editorial.
     */
    public function employees(UserIndexRequest $request): Response
    {
        // Reutiliza el mismo listado, pero limitado a cuentas del equipo interno.
        return $this->renderIndex($request, 'employees');
    }

    /**
     * Muestra usuarios registrados y su actividad.
     */
    public function readers(UserIndexRequest $request): Response
    {
        // Reutiliza el mismo listado, pero limitado a usuarios registrados.
        return $this->renderIndex($request, 'readers');
    }

    /**
     * Crea una cuenta interna con correo @trama.test y reloj editorial.
     *
     * Si existe una foto, el procesador compartido la normaliza a 600 × 600 WebP
     * y utiliza el nombre del empleado como archivo, por ejemplo carlos-perez.webp.
     */
    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        $createdUserId = null;
        $createdAvatarPath = null;
        $creationCommitted = false;

        try {
            $data = $request->validated();
            $avatar = $request->file('avatar_file');
            $editorialNow = TramaClock::now();

            DB::transaction(function () use (
                $data,
                $avatar,
                $editorialNow,
                &$createdUserId,
                &$createdAvatarPath,
            ): void {
                // El nombre completo es único dentro del equipo interno.
                $this->ensureUniqueEmployeeName($data['name']);

                // El nombre también define la ruta del avatar administrado.
                $this->ensureEmployeeAvatarPathAvailable($data['name']);

                $employee = new User();
                $employee->forceFill([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                    'role' => $data['role'],
                    'job_title' => $data['job_title'],
                    'bio' => $data['bio'],
                    'avatar' => null,
                    'is_active' => $data['is_active'] ?? true,
                    'email_verified_at' => $editorialNow,
                    // Las altas demo deben pertenecer a la cronología editorial.
                    'created_at' => $editorialNow,
                    'updated_at' => $editorialNow,
                ]);
                $employee->save();
                $createdUserId = $employee->id;

                if ($avatar) {
                    $createdAvatarPath = $this->storeEmployeeAvatar(
                        $avatar->getPathname(),
                        $data['name'],
                    );

                    $employee->forceFill([
                        'avatar' => $createdAvatarPath,
                        'updated_at' => $editorialNow,
                    ])->save();
                }
            }, attempts: 3);

            // Solo después de confirmar la transacción se considera persistida la alta.
            // Esta bandera evita consultar nuevamente la base dentro del catch si la
            // propia conexión fue la causa del error.
            $creationCommitted = true;
        } catch (Throwable $exception) {
            // Si la transacción se revirtió después de generar el archivo, elimina
            // únicamente el avatar nuevo creado durante este intento.
            if ($createdUserId !== null && $createdAvatarPath !== null && ! $creationCommitted) {
                $this->deleteManagedEmployeeAvatar($createdAvatarPath);
            }

            $this->operationFailureHandler->fail(
                $exception,
                'admin_users',
                'create_employee',
                'No pudimos crear el empleado. Intentá nuevamente.',
                ['user_id' => $request->user()?->id],
            );
        }

        return back()->with('status', 'Empleado creado correctamente.');
    }

    /**
     * Actualiza identidad, rol y perfil de un empleado interno.
     *
     * Los usuarios registrados del portal no pasan por esta operación: el
     * administrador gestiona su estado, bloqueos e historial sin modificar su
     * nombre ni su correo.
     */
    public function update(UpdateEmployeeRequest $request, User $user): RedirectResponse
    {
        /*
         * UpdateEmployeeRequest ya rechaza cuentas reader. Esta segunda barrera deja
         * la regla explícita también dentro del controlador.
         */
        abort_if(
            $user->role === 'reader',
            403,
            'La identidad de los usuarios registrados no se modifica desde administración.'
        );

        $data = $request->validated();

        // Nadie puede quitarse a sí mismo el rol de administrador.
        if ($request->user()->is($user) && $data['role'] !== 'admin') {
            throw ValidationException::withMessages([
                'role' => 'No podés quitarte tu propio rol de administrador.',
            ]);
        }

        $avatarToDelete = null;

        try {
            DB::transaction(function () use ($request, $user, $data, &$avatarToDelete): void {
                // Bloquea la cuenta para que rol/estado no cambien desde otra
                // petición mientras se confirma la edición.
                $locked = User::query()
                    ->whereKey($user->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                 * Si se baja un administrador activo, debe permanecer al menos
                 * otro administrador activo dentro de TRAMA.
                 */
                if (
                    $locked->role === 'admin'
                    && $data['role'] !== 'admin'
                    && $locked->is_active
                ) {
                    $this->ensureAnotherActiveAdministrator($locked);
                }

                $updateData = $data;
                $avatar = $request->file('avatar_file');
                unset($updateData['avatar_file'], $updateData['email_local']);

                $previousAvatar = $locked->avatar;
                $nameChanged = $locked->name !== $data['name'];

                /*
                 * Si la base conserva una foto propia pero el archivo físico fue
                 * eliminado o renombrado manualmente, no permitimos guardar otros
                 * cambios como si el perfil siguiera íntegro. Una foto nueva sí
                 * puede continuar porque repara el empleado en esta misma operación.
                 */
                if (
                    ! $avatar
                    && $locked->hasCustomAvatar()
                    && ! $locked->avatarFileExists()
                ) {
                    throw ValidationException::withMessages([
                        'avatar_file' => 'El archivo actual de la foto no existe. Subí nuevamente la imagen para restaurarla.',
                    ]);
                }

                // La validación se repite dentro de la transacción para impedir
                // que dos empleados internos terminen con el mismo nombre completo.
                $this->ensureUniqueEmployeeName($data['name'], $locked->id);

                // El nombre visible define también el nombre del archivo WebP.
                $this->ensureEmployeeAvatarPathAvailable($data['name'], $locked->id);

                if ($avatar) {
                    // Si además cambia la foto, procesa directamente el archivo nuevo
                    // con el nombre definitivo y luego elimina la ruta anterior.
                    $updateData['avatar'] = $this->storeEmployeeAvatar(
                        $avatar->getPathname(),
                        $data['name'],
                    );
                } elseif ($nameChanged && filled($previousAvatar)) {
                    // Sin foto nueva, el avatar actual se reescribe con el nuevo nombre.
                    $updateData['avatar'] = $this->renameEmployeeAvatar(
                        (string) $previousAvatar,
                        $data['name'],
                    );
                }

                if (
                    array_key_exists('avatar', $updateData)
                    && $previousAvatar !== $updateData['avatar']
                ) {
                    // El archivo anterior se elimina recién después del commit para
                    // no romper la ruta guardada si la transacción se revierte.
                    $avatarToDelete = $previousAvatar;
                }

                // updated_at también sigue el reloj editorial del proyecto demo.
                $updateData['updated_at'] = TramaClock::now();

                // Guarda únicamente los campos validados para empleados internos.
                $locked->forceFill($updateData)->save();
            }, attempts: 3);

            $this->deleteEmployeeAvatarFile($avatarToDelete);
        } catch (Throwable $exception) {
            $this->operationFailureHandler->fail(
                $exception,
                'admin_users',
                'update_employee',
                'No pudimos guardar los cambios del empleado. Intentá nuevamente.',
                [
                    'managed_user_id' => $user->id,
                    'user_id' => $request->user()?->id,
                ],
            );
        }

        return back()->with('status', 'Empleado actualizado.');
    }

    /**
     * Activa o desactiva una cuenta. Al bloquearla, el acceso queda denegado de
     * inmediato y cada sesión abierta es expulsada en su siguiente petición.
     */
    public function toggleActive(ToggleUserStatusRequest $request, User $user): RedirectResponse
    {
        // Evita que un usuario bloquee su propia sesión administrativa.
        if ($request->user()->is($user)) {
            throw ValidationException::withMessages([
                'user' => 'No podés desactivar tu propia cuenta.',
            ]);
        }

        try {
            $isActive = DB::transaction(function () use ($request, $user): bool {
                // Bloquea la cuenta antes de invertir el estado.
                $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                // Calcula el estado final que se va a guardar.
                $willBeActive = ! $locked->is_active;

                // No se permite desactivar al último administrador activo.
                if (! $willBeActive && $locked->role === 'admin') {
                    $this->ensureAnotherActiveAdministrator($locked);
                }

                $statusData = [
                    // Nuevo estado de habilitación.
                    'is_active' => $willBeActive,
                    // disabled_at registra el bloqueo con la fecha editorial de TRAMA.
                    'disabled_at' => $willBeActive ? null : TramaClock::now(),
                    // Al bloquear se borra remember_token para invalidar "recordarme".
                    'remember_token' => $willBeActive ? $locked->remember_token : null,
                    'updated_at' => TramaClock::now(),
                ];

                if ($locked->role === 'reader') {
                    // Solo los lectores documentan motivo y observación de bloqueo.
                    $statusData['blocked_reason'] = $willBeActive
                        ? null
                        : $request->validated('blocked_reason');
                    $statusData['blocked_note'] = $willBeActive
                        ? null
                        : $request->validated('blocked_note');
                } else {
                    // En empleados el estado vive exclusivamente en is_active/disabled_at.
                    $statusData['blocked_reason'] = null;
                    $statusData['blocked_note'] = null;
                }

                $locked->forceFill($statusData)->save();

                /*
                 * Si el bloqueo responde a una revisión que todavía estaba
                 * pendiente, la misma decisión administrativa debe cerrar también
                 * esa solicitud. Estado de cuenta y estado de revisión nunca
                 * quedan contradictorios.
                 */
                if (
                    ! $willBeActive
                    && $locked->role === 'reader'
                    && $locked->hasPendingModerationReview()
                ) {
                    $this->resolvePendingModerationReview(
                        $locked,
                        $request->user()?->id,
                        'blocked',
                    );
                }

                if (! $willBeActive) {
                    // La cuenta bloqueada no debe poder seguir utilizando sesiones abiertas.
                    /*
                     * No se borran de inmediato las filas de sessions. Cada sesión
                     * abierta será expulsada en su siguiente petición por
                     * EnsureActiveAccount y recibirá el mensaje de cuenta bloqueada.
                     * El flag is_active hace efectivo el bloqueo del lado servidor 
                     * desde este mismo instante.
                     *
                     * Esto también cubre sesiones abiertas en otros dispositivos:
                     * todas conservan la identidad necesaria para que el middleware
                     * detecte el bloqueo, invalide esa sesión concreta y muestre el
                     * mismo mensaje al llegar al login.
                     */
                }

                return $willBeActive;
            }, attempts: 3);
        } catch (Throwable $exception) {
            $this->operationFailureHandler->fail(
                $exception,
                'admin_users',
                'toggle_user_status',
                'No pudimos cambiar el estado de la cuenta. Intentá nuevamente.',
                ['managed_user_id' => $user->id, 'user_id' => $request->user()?->id],
            );
        }

        return back()->with('status', $isActive ? 'Cuenta activada.' : 'Cuenta bloqueada.');
    }

    /**
     * Cierra una revisión administrativa sin aplicar un bloqueo.
     *
     * La cuenta continúa activa, la solicitud deja de figurar como pendiente y
     * el historial queda preservado para auditoría. Una revisión resuelta no
     * impide que Moderación vuelva a derivar la cuenta en el futuro.
     */
    public function resolveModerationReview(Request $request, User $user): RedirectResponse
    {
        abort_unless(
            $request->user()?->isAdmin(),
            403,
            'No tenés permisos para resolver revisiones administrativas.'
        );

        try {
            DB::transaction(function () use ($request, $user): void {
                $locked = User::query()
                    ->whereKey($user->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->role !== 'reader') {
                    throw ValidationException::withMessages([
                        'user' => 'Esta cuenta no pertenece al flujo de revisión de usuarios registrados.',
                    ]);
                }

                if (! $locked->hasPendingModerationReview()) {
                    throw ValidationException::withMessages([
                        'review' => 'La revisión administrativa ya no está pendiente.',
                    ]);
                }

                if (! $locked->is_active) {
                    throw ValidationException::withMessages([
                        'review' => 'La cuenta ya está bloqueada y la revisión no puede cerrarse sin acción.',
                    ]);
                }

                $this->resolvePendingModerationReview(
                    $locked,
                    $request->user()?->id,
                    'no_action',
                );
            }, attempts: 3);
        } catch (Throwable $exception) {
            $this->operationFailureHandler->fail(
                $exception,
                'admin_users',
                'resolve_moderation_review',
                'No pudimos cerrar la revisión administrativa. Intentá nuevamente.',
                [
                    'managed_user_id' => $user->id,
                    'user_id' => $request->user()?->id,
                ],
            );
        }

        return back()->with('status', 'Revisión administrativa cerrada sin aplicar un bloqueo.');
    }

    /**
     * Envía un enlace de recuperación y deja registrado el pedido administrativo.
     */
    public function resetAccess(User $user): RedirectResponse
    {
        // Una cuenta bloqueada no debe recibir un enlace para recuperar acceso.
        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'user' => 'Activá la cuenta antes de restablecer su acceso.',
            ]);
        }

        $tokenCreated = false;

        try {
            /*
             * La creación del token también queda dentro del bloque protegido.
             * Así, si el broker falla de forma inesperada, el modal recibe un
             * mensaje amigable en lugar de exponer una excepción técnica.
             */
            $token = Password::createToken($user);
            $tokenCreated = true;

            DB::transaction(function () use ($user): void {
                $locked = User::query()
                    ->whereKey($user->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // La marca de recuperación y la revocación de sesiones deben
                // confirmarse juntas para que una sesión vieja no siga activa.
                $locked->forceFill([
                    'password_reset_required_at' => TramaClock::now(),
                    'remember_token' => null,
                ])->save();

                DB::table('sessions')
                    ->where('user_id', $locked->id)
                    ->delete();
            }, attempts: 3);

            // El correo se envía al final, después de confirmar el estado interno.
            $user->sendPasswordResetNotification($token);
        } catch (Throwable $exception) {
            /*
             * Si ya existía un token y el envío falla, se intenta dejar la cuenta
             * nuevamente utilizable con su contraseña actual.
             *
             * La limpieza es best-effort: si la base completa está caída, la
             * excepción original debe continuar hacia el manejo global 503.
             */
            if ($tokenCreated) {
                try {
                    Password::deleteToken($user);

                    User::query()
                        ->whereKey($user->id)
                        ->update(['password_reset_required_at' => null]);
                } catch (Throwable $cleanupException) {
                    if (
                        $cleanupException instanceof QueryException
                        && str_contains(
                            $cleanupException->getMessage(),
                            'SQLSTATE[HY000] [2002]'
                        )
                    ) {
                        throw $cleanupException;
                    }

                    TramaLog::error('No se pudo revertir completamente un restablecimiento de acceso fallido.', [
                        'controller' => 'UserController',
                        'method' => 'resetAccess',
                        'managed_user_id' => $user->id,
                        'exception_class' => $cleanupException::class,
                        'exception_message' => $cleanupException->getMessage(),
                    ]);
                }
            }

            $this->operationFailureHandler->fail(
                $exception,
                'admin_users',
                'reset_user_access',
                'No pudimos enviar el enlace para restablecer el acceso. Intentá nuevamente.',
                ['managed_user_id' => $user->id],
            );
        }

        return back()->with('status', 'Se envió el enlace para restablecer el acceso.');
    }

    /**
     * Elimina únicamente cuentas sin autoría, revisiones, devoluciones ni comentarios.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        // La propia cuenta se desactiva desde otro administrador, no se elimina desde sí misma.
        if ($request->user()->is($user)) {
            throw ValidationException::withMessages([
                'user' => 'No podés eliminar tu propia cuenta.',
            ]);
        }

        try {
            $deletedAvatar = DB::transaction(function () use ($user): ?string {
                // Bloquea la cuenta para validar y borrar dentro del mismo estado.
                $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

                if ($locked->role === 'admin' && $locked->is_active) {
                    $this->ensureAnotherActiveAdministrator($locked);
                }

                // La comprobación se realiza dentro de la misma transacción que la
                // eliminación para reducir el riesgo de una relación creada entre
                // la validación y el borrado.
                if ($this->deletionBlockedByHistoricalContent($locked)) {
                    throw ValidationException::withMessages([
                        'user' => 'La cuenta conserva contenido histórico y debe desactivarse en lugar de eliminarse.',
                    ]);
                }

                // Se conserva la ruta para retirar después del commit únicamente
                // los avatares administrados, nunca las fotos demo compartidas.
                $avatar = $locked->avatar;

                // Borra sesiones porque la cuenta dejará de existir.
                DB::table('sessions')->where('user_id', $locked->id)->delete();
                $this->detachReaderCommentsBeforeDelete($locked);

                $locked->delete();

                return $avatar;
            }, attempts: 3);

            $this->deleteManagedEmployeeAvatar($deletedAvatar);
        } catch (Throwable $exception) {
            $this->operationFailureHandler->fail(
                $exception,
                'admin_users',
                'delete_user',
                'No pudimos eliminar la cuenta. Intentá nuevamente.',
                ['managed_user_id' => $user->id, 'user_id' => $request->user()?->id],
            );
        }

        return back()->with('status', 'Cuenta eliminada.');
    }

    /**
     * Prepara la grilla común de empleados o usuarios registrados.
     */
    private function renderIndex(UserIndexRequest $request, string $type): Response
    {
        $filters = [
            'q' => '', 'status' => '', 'review' => '', 'sort' => 'created_at',
            'direction' => 'desc', 'per_page' => 25, ...$request->validated(),
        ];
        $filters['q'] = (string) ($filters['q'] ?? '');
        $filters['status'] = (string) ($filters['status'] ?? '');
        $filters['review'] = (string) ($filters['review'] ?? '');
        $filters['per_page'] = (int) $filters['per_page'];
        $hasExplicitSort = $request->query->has('sort');
        $payload = [
            'data' => [],
            'pagination' => [
                'current_page' => 1, 'last_page' => 1, 'per_page' => $filters['per_page'],
                'total' => 0, 'from' => null, 'to' => null, 'links' => [],
            ],
        ];
        $loadError = '';

        try {
            // La consulta base sirve para empleados internos y usuarios registrados.
            $query = User::query()
                ->with([
                    'moderationReviewRequestedBy:id,name,email',
                    'moderationReviewResolvedBy:id,name,email',
                    'moderationReviewComment:id,article_id,body,created_at',
                    'moderationReviewComment.article:id,title,slug',
                ])
                ->withCount([
                    'articles',
                    'comments',
                    'revisions',
                    'returnedFeedback',
                    'moderationReviews',
                ])
                // En la grilla de empleados entran administradores, editores y periodistas.
                ->when($type === 'employees', fn ($builder) => $builder->whereIn('role', User::EDITORIAL_ROLES))
                // En la grilla de usuarios registrados entran únicamente cuentas reader.
                ->when($type === 'readers', fn ($builder) => $builder->where('role', 'reader'))
                ->when($filters['q'], function ($builder, string $term): void {
                    $builder->where(function ($inner) use ($term): void {
                        // Busca por nombre visible o correo.
                        $inner->where('name', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%");
                    });
                })
                // Filtro de cuentas habilitadas.
                ->when($filters['status'] === 'active', fn ($builder) => $builder->where('is_active', true))
                // Filtro de cuentas bloqueadas.
                ->when($filters['status'] === 'blocked', fn ($builder) => $builder->where('is_active', false))
                ->when(
                    $type === 'readers' && $filters['review'] === 'requested',
                    fn ($builder) => $builder
                        ->whereNotNull('moderation_review_requested_at')
                        ->whereNull('moderation_review_resolved_at')
                )
                ->when(
                    $type === 'readers' && $filters['review'] === 'resolved',
                    fn ($builder) => $builder
                        ->whereNotNull('moderation_review_requested_at')
                        ->whereNotNull('moderation_review_resolved_at')
                )
                ->when(
                    $type === 'readers' && $filters['review'] === 'none',
                    fn ($builder) => $builder->whereNull('moderation_review_requested_at')
                );

            /*
             * En usuarios registrados, las cuentas derivadas por un editor se
             * muestran primero para que la solicitud administrativa no quede
             * perdida entre páginas del listado.
             */
            if ($type === 'readers' && ! $hasExplicitSort) {
                $query
                    ->orderByRaw(
                        'CASE WHEN moderation_review_requested_at IS NOT NULL AND moderation_review_resolved_at IS NULL THEN 0 ELSE 1 END ASC'
                    )
                    ->orderByRaw(
                        'COALESCE(moderation_review_resolved_at, moderation_review_requested_at) DESC'
                    );
            }

            $sort = $filters['sort'];
            if ($type === 'employees' && $sort === 'comments_count') {
                $sort = 'articles_count';
            }
            if ($type === 'readers' && $sort === 'articles_count') {
                $sort = 'comments_count';
            }
            // El criterio elegido define el orden principal; id descendente actúa
            // solamente como desempate estable entre valores iguales.
            $query->orderBy($sort, $filters['direction'])->orderByDesc('id');

            $users = $query->paginate($filters['per_page'])->withQueryString();
            $payload = [
                'data' => $users->getCollection()->map(fn (User $user) => [
                    // Datos de identidad y estado para la fila de la grilla.
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'job_title' => $user->job_title,
                    'bio' => $user->bio,
                    // avatar_url siempre es segura: si el archivo propio falta, el
                    // modelo devuelve el avatar genérico para evitar imágenes rotas.
                    'avatar_url' => $user->avatarUrl(),
                    // Estas banderas permiten que Administración diferencie entre
                    // una cuenta sin foto propia y una ruta cuyo archivo desapareció.
                    'has_custom_avatar' => $user->hasCustomAvatar(),
                    'avatar_exists' => $user->avatarFileExists(),
                    'is_active' => (bool) $user->is_active,
                    'disabled_at' => $user->disabled_at?->toISOString(),
                    'blocked_reason' => $user->blocked_reason,
                    'blocked_note' => $user->blocked_note,
                    'last_login_at' => $user->last_login_at?->toISOString(),
                    'created_at' => $user->created_at?->toISOString(),
                    // Fecha de la derivación manual realizada desde moderación.
                    'moderation_review_requested_at' => $user->moderation_review_requested_at?->toISOString(),
                    'moderation_review_reason' => $user->moderation_review_reason,
                    'moderation_review_note' => $user->moderation_review_note,
                    'moderation_review_reason_label' => $this->moderationReviewReasonLabel(
                        $user->moderation_review_reason,
                    ),
                    'moderation_review_requested_by' => $user->moderationReviewRequestedBy
                        ? [
                            'id' => $user->moderationReviewRequestedBy->id,
                            'name' => $user->moderationReviewRequestedBy->name,
                            'email' => $user->moderationReviewRequestedBy->email,
                        ]
                        : null,
                    'moderation_review_resolved_at' => $user->moderation_review_resolved_at?->toISOString(),
                    'moderation_review_resolution' => $user->moderation_review_resolution,
                    'moderation_review_resolved_by' => $user->moderationReviewResolvedBy
                        ? [
                            'id' => $user->moderationReviewResolvedBy->id,
                            'name' => $user->moderationReviewResolvedBy->name,
                            'email' => $user->moderationReviewResolvedBy->email,
                        ]
                        : null,
                    /*
                     * La evidencia conserva una copia textual independiente de la
                     * FK. Si el comentario original se elimina más adelante,
                     * nullOnDelete limpia el id pero el administrador continúa
                     * viendo el fragmento y la noticia que originaron la revisión.
                     */
                    'moderation_review_comment' => (
                        $user->moderation_review_comment_id
                        || $user->moderation_review_comment_excerpt
                        || $user->moderation_review_article_title
                    )
                        ? [
                            'id' => $user->moderation_review_comment_id,
                            'body' => $user->moderation_review_comment_excerpt
                                ?: (string) $user->moderationReviewComment?->body,
                            'article_title' => $user->moderation_review_article_title
                                ?: $user->moderationReviewComment?->article?->title,
                            'created_at' => $user->moderationReviewComment?->created_at?->toISOString(),
                        ]
                        : null,
                    'articles_count' => (int) $user->articles_count,
                    'comments_count' => (int) $user->comments_count,
                    // Si conserva historial, el panel debe desactivar en vez de borrar.
                    'has_historical_content' => $user->role === 'reader'
                        ? (
                            $user->articles_count
                            + $user->revisions_count
                            + $user->returned_feedback_count
                        ) > 0
                        : (
                            $user->articles_count
                            + $user->comments_count
                            + $user->revisions_count
                            + $user->returned_feedback_count
                            + $user->moderation_reviews_count
                        ) > 0,
                ])->values()->all(),
                'pagination' => [
                    'current_page' => $users->currentPage(), 'last_page' => $users->lastPage(),
                    'per_page' => $users->perPage(), 'total' => $users->total(),
                    'from' => $users->firstItem(), 'to' => $users->lastItem(),
                    'links' => $users->linkCollection()->toArray(),
                ],
            ];
        } catch (Throwable $exception) {
            if ($exception instanceof QueryException
                && str_contains($exception->getMessage(), 'SQLSTATE[HY000] [2002]')) {
                throw $exception;
            }

            TramaLog::error('No se pudo cargar la administración de usuarios.', [
                'controller' => 'UserController', 'method' => 'renderIndex', 'status' => 500,
                'user_id' => $request->user()?->id, 'type' => $type, 'filters' => $filters,
                'exception_class' => $exception::class, 'exception_message' => $exception->getMessage(),
                'exception_file' => $exception->getFile(), 'exception_line' => $exception->getLine(),
            ]);
            $loadError = $type === 'employees'
                ? 'No pudimos cargar los empleados en este momento. Intentá nuevamente.'
                : 'No pudimos cargar los usuarios registrados en este momento. Intentá nuevamente.';
        }

        // Se envían conteos para que Vue pueda indicar si una cuenta tiene historial.
        return TramaBridge::render('Admin/Users/Index', [
            'type' => $type,
            'filters' => $filters,
            'users' => $payload,
            'loadError' => $loadError,
            'roleOptions' => [
                ['value' => 'admin', 'label' => 'Administrador'],
                ['value' => 'editor', 'label' => 'Editor'],
                ['value' => 'journalist', 'label' => 'Periodista'],
                ['value' => 'reader', 'label' => 'Usuario registrado'],
            ],
        ]);
    }

    /**
     * Resuelve la revisión pendiente más reciente y conserva el ciclo completo
     * dentro del historial user_moderation_reviews.
     *
     * Este método se invoca siempre dentro de una transacción que ya bloqueó la
     * fila de users. Si por compatibilidad con datos anteriores todavía no existe
     * una fila histórica, la crea a partir del resumen conservado en users.
     */
    private function resolvePendingModerationReview(
        User $user,
        ?int $resolvedById,
        string $resolution,
    ): void {
        if (! $user->hasPendingModerationReview()) {
            return;
        }

        $resolvedAt = TramaClock::now();

        $review = UserModerationReview::query()
            ->where('user_id', $user->id)
            ->whereNull('resolved_at')
            ->latest('requested_at')
            ->lockForUpdate()
            ->first();

        if (! $review) {
            $review = UserModerationReview::query()->create([
                'user_id' => $user->id,
                'requested_at' => $user->moderation_review_requested_at,
                'reason' => $user->moderation_review_reason ?: 'other',
                'note' => $user->moderation_review_note ?: 'Revisión administrativa sin observación histórica disponible.',
                'requested_by_id' => $user->moderation_review_requested_by_id,
                'comment_id' => $user->moderation_review_comment_id,
                'comment_excerpt' => $user->moderation_review_comment_excerpt,
                'article_title' => $user->moderation_review_article_title,
            ]);
        }

        $review->forceFill([
            'resolved_at' => $resolvedAt,
            'resolution' => $resolution,
            'resolved_by_id' => $resolvedById,
        ])->save();

        $user->forceFill([
            'moderation_review_resolved_at' => $resolvedAt,
            'moderation_review_resolution' => $resolution,
            'moderation_review_resolved_by_id' => $resolvedById,
        ])->save();
    }

    /**
     * Procesa un avatar administrado a 600 × 600 WebP usando el nombre visible.
     *
     * Ejemplo: "Carlos Pérez" se guarda como /images/team/carlos-perez.webp.
     */
    private function storeEmployeeAvatar(string $sourcePath, string $employeeName): string
    {
        $directory = public_path('images/team');
        File::ensureDirectoryExists($directory);

        $publicPath = $this->employeeAvatarPublicPath($employeeName);
        $destination = public_path(ltrim($publicPath, '/'));

        try {
            $this->imageProcessor->process(
                sourcePath: $sourcePath,
                destinationPath: $destination,
                width: 600,
                height: 600,
                quality: 82,
                exactSourceDimensions: false,
                overwrite: true,
            );
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages([
                'avatar_file' => $exception->getMessage(),
            ]);
        }

        return $publicPath;
    }

    /**
     * Reescribe el avatar actual con el slug del nuevo nombre del empleado.
     * También migra imágenes históricas JPG o employee-{id}.webp al formato final.
     */
    private function renameEmployeeAvatar(string $currentAvatarPath, string $employeeName): string
    {
        $normalizedCurrent = '/'.ltrim(str_replace('\\', '/', trim($currentAvatarPath)), '/');
        $targetPath = $this->employeeAvatarPublicPath($employeeName);

        if ($normalizedCurrent === $targetPath) {
            return $targetPath;
        }

        if (! preg_match('#^/images/team/[A-Za-z0-9._-]+$#', $normalizedCurrent)) {
            throw ValidationException::withMessages([
                'avatar_file' => 'No se pudo administrar la foto actual del empleado. Intentá nuevamente.',
            ]);
        }

        $sourcePath = public_path(ltrim($normalizedCurrent, '/'));

        if (! File::exists($sourcePath)) {
            throw ValidationException::withMessages([
                'avatar_file' => 'No se encontró la foto actual del empleado para renombrarla.',
            ]);
        }

        // Procesar nuevamente permite convertir también avatares históricos JPG a WebP.
        return $this->storeEmployeeAvatar($sourcePath, $employeeName);
    }

    /** Impide nombres completos duplicados entre administradores, editores y periodistas. */
    private function ensureUniqueEmployeeName(string $name, ?int $ignoreId = null): void
    {
        $query = User::query()
            ->whereIn('role', User::EDITORIAL_ROLES)
            ->whereRaw('LOWER(name) = LOWER(?)', [$name])
            ->lockForUpdate();

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'Ya existe un empleado con este nombre.',
            ]);
        }
    }

    /** Evita que dos empleados terminen apuntando al mismo archivo de avatar. */
    private function ensureEmployeeAvatarPathAvailable(string $employeeName, ?int $ignoreId = null): void
    {
        $targetPath = $this->employeeAvatarPublicPath($employeeName);
        $targetSlug = Str::slug($employeeName) ?: 'empleado';
        $query = User::query()
            ->whereIn('role', User::EDITORIAL_ROLES)
            ->lockForUpdate();

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        $collision = $query
            ->get(['id', 'name', 'avatar'])
            ->contains(function (User $employee) use ($targetPath, $targetSlug): bool {
                $employeeSlug = Str::slug((string) $employee->name) ?: 'empleado';

                return $employeeSlug === $targetSlug
                    || $employee->avatar === $targetPath;
            });

        if ($collision) {
            throw ValidationException::withMessages([
                'name' => 'Ese nombre genera el mismo archivo de imagen que otro empleado.',
            ]);
        }
    }

    /** Devuelve la ruta pública determinista basada en el nombre del empleado. */
    private function employeeAvatarPublicPath(string $employeeName): string
    {
        $baseName = Str::slug($employeeName) ?: 'empleado';

        return '/images/team/'.$baseName.'.webp';
    }

    /**
     * Elimina un avatar anterior después de haber generado correctamente su reemplazo.
     * Solo acepta archivos directos de /images/team para impedir rutas arbitrarias.
     */
    private function deleteEmployeeAvatarFile(?string $avatarPath): void
    {
        if (! is_string($avatarPath) || trim($avatarPath) === '') {
            return;
        }

        $normalized = '/'.ltrim(str_replace('\\', '/', trim($avatarPath)), '/');

        if (! preg_match('#^/images/team/[A-Za-z0-9._-]+$#', $normalized)) {
            return;
        }

        $absolutePath = public_path(ltrim($normalized, '/'));

        if (File::exists($absolutePath)) {
            File::delete($absolutePath);
        }
    }

    /**
     * Elimina avatares WebP generados por Administración al borrar o revertir altas.
     * Las imágenes JPG demo originales no se eliminan desde esta operación general.
     */
    private function deleteManagedEmployeeAvatar(?string $avatarPath): void
    {
        if (! is_string($avatarPath) || ! preg_match('#^/images/team/(?:employee-\d+|[a-z0-9-]+)\.webp$#', $avatarPath)) {
            return;
        }

        $this->deleteEmployeeAvatarFile($avatarPath);
    }

    private function moderationReviewReasonLabel(?string $reason): ?string
    {
        return match ($reason) {
            'spam' => 'Spam',
            'abuse' => 'Insultos o acoso',
            'inappropriate' => 'Contenido inapropiado',
            'repeated' => 'Conducta repetida',
            'other' => 'Otro',
            default => null,
        };
    }

    /**
     * Determina si borrar la cuenta destruiria trazabilidad editorial.
     */
    private function deletionBlockedByHistoricalContent(User $user): bool
    {
        if ($user->role === 'reader') {
            return $user->articles()->exists()
                || $user->revisions()->exists()
                || $user->returnedFeedback()->exists();
        }

        return $user->hasHistoricalContent();
    }

    /**
     * Conserva comentarios de lectores eliminados sin exponer nombre ni email.
     */
    private function detachReaderCommentsBeforeDelete(User $user): void
    {
        if ($user->role !== 'reader') {
            return;
        }

        Comment::query()
            ->where('user_id', $user->id)
            ->update([
                'user_id' => null,
                'author_name' => 'Usuario eliminado',
                'author_email' => null,
                'updated_at' => TramaClock::now(),
            ]);
    }

    /**
     * Impide que el sistema quede sin una cuenta administrativa activa.
     */
    private function ensureAnotherActiveAdministrator(User $excluded): void
    {
        // El lock evita carreras donde dos admins se desactivan al mismo tiempo.
        $exists = User::query()
            ->select('id')
            ->where('role', 'admin')
            ->where('is_active', true)
            ->where('id', '!=', $excluded->id)
            ->lockForUpdate()
            ->first() !== null;

        if (! $exists) {
            throw ValidationException::withMessages([
                'user' => 'Debe permanecer al menos un administrador activo.',
            ]);
        }
    }
}
