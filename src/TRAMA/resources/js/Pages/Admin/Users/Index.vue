<script setup>
/* ============================================================================
 * PAGE: Admin/Users/Index.vue
 * ============================================================================
 *
 * Administración de empleados y usuarios registrados.
 *
 * La misma pantalla adapta columnas, filtros y acciones al tipo de cuenta.
 *
 * Empleados:
 * 
 *      - Pueden crearse y editarse;
 *      - Pueden cambiar de rol, cargo, biografía y foto;
 *      - Conservan límites breves y consistentes para los datos de perfil.
 *
 * Usuarios registrados:
 * 
 *      - El administrador no modifica nombre ni correo;
 *      - Administra estado, bloqueos, recuperación de acceso e historial visible;
 *      - Las cuentas derivadas desde Moderación se identifican en la grilla.
 *
 * Búsqueda, filtros, ordenamiento y paginación se resuelven en Laravel. TanStack
 * representa únicamente la página recibida desde el servidor.
 * ============================================================================ */

import { computed, h, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { Eye, KeyRound, Pencil, Power, Search, ShieldAlert, Trash2, UserPlus, X } from '@lucide/vue';
import { FlexRender, rowSortingFeature, tableFeatures, useTable } from '@tanstack/vue-table';
import AdminPagination from '@/Components/Admin/AdminPagination.vue';
import ConfirmActionModal from '@/Components/Shared/ConfirmActionModal.vue';

/* ============================================================================
 * PROPS Y ESTADO GENERAL
 * ============================================================================ */

// Propiedades enviadas por Laravel/Inertia para configurar esta pantalla.
const props = defineProps({
    // Indica qué variante de la pantalla se está mostrando:
    // empleados internos o usuarios registrados.
    type: String,
    // Filtros actualmente aplicados en Laravel:
    // búsqueda, estado, revisión, orden y cantidad por página.
    filters: Object,
    // Contiene los usuarios de la página actual y los datos de paginación.
    users: Object,
    // Lista de roles disponibles para crear o editar empleados.
    roleOptions: Array,
    // Mensaje mostrado cuando Laravel no pudo cargar correctamente el listado.
    loadError: {
        type: String,
        default: '',
    },
});

// Página actual de Inertia; permite identificar la propia cuenta administrativa
// para impedir acciones de bloqueo desde la misma sesión.
const page = usePage();

// Identificador de la cuenta autenticada compartida globalmente por Laravel.
const currentUserId = computed(() => Number(page.props.auth?.user?.id || 0));

// Límites usados por la validación preventiva del modal de empleados.
// Laravel repite estos límites en backend: el frontend solamente evita envíos
// evidentemente inválidos y mantiene deshabilitados los botones cuando corresponde.
const EMPLOYEE_NAME_MIN = 3;
const EMPLOYEE_NAME_MAX = 40;
const EMPLOYEE_EMAIL_LOCAL_MAX = 39;
const EMPLOYEE_JOB_TITLE_MAX = 35;
const EMPLOYEE_BIO_MIN = 30;
const EMPLOYEE_BIO_MAX = 300;
const EMPLOYEE_PASSWORD_MIN = 8;
const EMPLOYEE_PASSWORD_MAX = 20;

// La fuente debe alcanzar al menos 600 × 600. Después TRAMA recorta y guarda
// siempre un WebP final de 600 × 600 sin ampliar fotografías pequeñas.
const EMPLOYEE_AVATAR_MIN_WIDTH = 600;
const EMPLOYEE_AVATAR_MIN_HEIGHT = 600;
const BLOCK_NOTE_MIN = 10;
const BLOCK_NOTE_MAX = 200;

// Cantidad máxima de caracteres del nombre que se muestran
// dentro del título del modal de eliminación de una cuenta.
// Esto no modifica el nombre real del usuario o empleado.
const DELETE_ACCOUNT_NAME_PREVIEW_LENGTH = 23;

/*
 * Acorta solamente la representación visual del nombre.
 *
 * Ejemplo:
 * "María Fernández"
 * se muestra completo.
 *
 * Si supera el límite visual:
 * "María Fernández Rodríguez González"
 * pasa a mostrarse como:
 * "María Fernández Rodríg…"
 */
function deleteAccountDisplayName(name) {
    const characters = Array.from(
        String(name ?? '').trim()
    );

    if (characters.length <= DELETE_ACCOUNT_NAME_PREVIEW_LENGTH) {
        return characters.join('');
    }

    return `${characters
        .slice(0, DELETE_ACCOUNT_NAME_PREVIEW_LENGTH)
        .join('')}…`;
}

// Usuario abierto dentro del modal de edición/detalle.
const selected = ref(null);

// Controla el modal de creación de empleados.
const createOpen = ref(false);
const createSubmitting = ref(false);
const editSubmitting = ref(false);
const blockSubmitting = ref(false);

// Acción que espera confirmación: activar, bloquear, restablecer o eliminar.
const confirmation = ref(null);
const confirmationProcessing = ref(false);
const confirmationError = ref('');

// Modal específico para bloquear lectores con motivo administrativo.
const blockTarget = ref(null);

// Errores de acciones que no pertenecen a un formulario abierto.
const operationError = ref('');

/* ============================================================================
 * FILTROS Y PAGINACIÓN
 * ============================================================================ */

// Estado local de filtros. Se inicializa con la consulta que Laravel devolvió para
// que la interface siempre represente exactamente el listado actualmente visible.
const filter = reactive({
    q: props.filters?.q || '',
    status: props.filters?.status || '',
    review: props.filters?.review || '',
    sort: props.filters?.sort || 'created_at',
    direction: props.filters?.direction || 'desc',
    per_page: Number(props.filters?.per_page || 25),
});

/* ============================================================================
 * FORMULARIOS DE EMPLEADOS
 * ============================================================================ */

// Formulario de alta de empleados. useForm administra valores, errores de
// validación, estado processing y serialización de archivos para Inertia.
const createForm = useForm({
    name: '',
    email_local: '',
    role: 'journalist',
    job_title: '',
    bio: '',
    password: '',
    password_confirmation: '',
    // Los empleados nuevos se crean activos. El estado se administra después desde la grilla.
    is_active: true,
    avatar_file: null,
});

// Formulario de edición. No incluye contraseña porque el acceso se restablece
// mediante una acción independiente y no desde la edición del perfil.
const editForm = useForm({
    name: '',
    email_local: '',
    role: 'journalist',
    job_title: '',
    bio: '',
    avatar_file: null,
});

// Guarda una fotografía del estado original del empleado abierto. Se utiliza
// para mantener Guardar cambios deshabilitado hasta detectar una modificación.
const editInitial = ref(null);

// Formulario específico para documentar el bloqueo de un lector.
const blockForm = useForm({
    blocked_reason: '',
    blocked_note: '',
});

// Traducción de los códigos persistidos de bloqueo a etiquetas administrativas
// comprensibles dentro del detalle del usuario.
const blockReasonLabels = {
    moderation: 'Revisión de moderación',
    abuse: 'Abuso o acoso',
    spam: 'Spam',
    security: 'Seguridad de la cuenta',
    terms: 'Incumplimiento de términos',
    other: 'Otro',
};

// Relaciona los motivos enviados desde Moderación con el motivo administrativo
// que se utilizará al bloquear una cuenta desde este panel.
const reviewToBlockReason = {
    spam: 'spam',
    abuse: 'abuse',
    inappropriate: 'terms',
    repeated: 'moderation',
    other: 'other',
};

// TanStack recibe únicamente la capacidad de ordenamiento por filas; paginación
// y filtrado continúan siendo responsabilidades del servidor.
const features = tableFeatures({ rowSortingFeature });

// Filas de la página actual. Si Laravel no devolvió data, se utiliza un array vacío.
const userRows = computed(() => props.users?.data || []);

// Estado local del ordenamiento inicializado desde los filtros del servidor para
// mantener sincronizados encabezado, flecha y query string.
const sorting = ref([{
    id: props.filters?.sort || 'created_at',
    desc: (props.filters?.direction || 'desc') === 'desc',
}]);

// Define si la pantalla está administrando empleados internos o lectores.
const isEmployees = computed(() => props.type === 'employees');

// Título visible y texto utilizado por la paginación reutilizable.
const pageTitle = computed(() => isEmployees.value ? 'Empleados' : 'Usuarios registrados');
const paginationItemLabel = computed(() => isEmployees.value ? 'empleados' : 'usuarios');

// Texto escrito actualmente y búsqueda que Laravel tiene aplicada.
const normalizedSearch = computed(() => filter.q.trim());
const appliedSearch = computed(() => String(props.filters?.q || '').trim());

// Buscar se habilita al escribir una búsqueda nueva de al menos dos caracteres
// o al vaciar una búsqueda que ya estaba aplicada.
const canApplySearch = computed(() => {
    if (normalizedSearch.value === '' && appliedSearch.value !== '') {
        return true;
    }

    return normalizedSearch.value.length >= 2
        && normalizedSearch.value !== appliedSearch.value;
});

// Limpiar afecta únicamente los filtros visibles; conserva orden y cantidad.
const hasActiveFilters = computed(() => Boolean(
    normalizedSearch.value
    || filter.status
    || (!isEmployees.value && filter.review)
));

const createEmployeeBusy = computed(() => createForm.processing || createSubmitting.value);
const editEmployeeBusy = computed(() => editForm.processing || editSubmitting.value);
const readerBlockBusy = computed(() => blockForm.processing || blockSubmitting.value);

// Roles disponibles al crear o editar empleados; reader no pertenece al equipo.
const employeeRoles = computed(() => (
    (props.roleOptions || []).filter((role) => role.value !== 'reader')
));

// Contadores visibles exclusivamente para Biografía.
const createBioLength = computed(() => String(createForm.bio || '').length);
const editBioLength = computed(() => String(editForm.bio || '').length);
const blockNoteLength = computed(() => String(blockForm.blocked_note || '').trim().length);

// El identificador admite solo caracteres seguros antes de @trama.test.
const emailLocalPattern = /^[A-Za-z0-9](?:[A-Za-z0-9._-]{0,37}[A-Za-z0-9])?$/;

// En alta también se exige una contraseña inicial válida y confirmada.
// El botón Crear empleado solamente se habilita cuando los datos del perfil son
// válidos y las dos contraseñas coinciden con el mínimo requerido.
const canSubmitCreateEmployee = computed(() => (
    validEmployeeIdentity(createForm)
    && createForm.password.length >= EMPLOYEE_PASSWORD_MIN
    && createForm.password.length <= EMPLOYEE_PASSWORD_MAX
    && createForm.password_confirmation.length <= EMPLOYEE_PASSWORD_MAX
    && createForm.password_confirmation === createForm.password
));

// Compara los campos actuales con los datos originales del modal.
// Detecta cambios reales respecto de la fotografía tomada al abrir el modal.
// Así Guardar cambios comienza deshabilitado y no se envían PATCH innecesarios.
const employeeEditChanged = computed(() => {
    if (!isEmployees.value || !selected.value || !editInitial.value) {
        return false;
    }

    // La selección de un nuevo archivo cuenta como modificación aunque el resto
    // de los campos conserve exactamente el valor original.
    return editForm.name !== editInitial.value.name
        || editForm.email_local !== editInitial.value.email_local
        || editForm.role !== editInitial.value.role
        || editForm.job_title !== editInitial.value.job_title
        || editForm.bio !== editInitial.value.bio
        || editForm.avatar_file !== null;
});

// Guardar cambios requiere datos válidos y al menos una modificación real.
const canSubmitEditEmployee = computed(() => (
    validEmployeeIdentity(editForm)
    && employeeEditChanged.value
));

const canSubmitReaderBlock = computed(() => (
    Boolean(blockForm.blocked_reason)
    && blockNoteLength.value >= BLOCK_NOTE_MIN
    && blockNoteLength.value <= BLOCK_NOTE_MAX
    && !readerBlockBusy.value
));

// Columnas exclusivas del panel de Empleados. La tabla recibe la página ya
// paginada por Laravel y delega solamente la representación a TanStack.
const employeeColumns = [
    {
        id: 'name',
        accessorKey: 'name',
        header: 'Empleado',
        enableSorting: true,
        cell: (info) => {
            // row.original conserva el objeto completo entregado por Laravel aunque
            // la columna utilice solamente name como accessor para ordenar.
            const user = info.row.original;

            // Avatar, nombre, correo y cargo se agrupan en una única celda visual.
            return h('div', { class: 'admin-person-cell' }, [
                h('img', {
                    // Laravel ya entrega un fallback seguro si el archivo propio falta.
                    src: user.avatar_url || '/images/brand/avatar-default.webp',
                    alt: user.name,
                }),
                h('span', {}, [
                    h('strong', user.name),
                    h('small', user.email),
                    user.job_title ? h('small', user.job_title) : null,
                    user.has_custom_avatar && !user.avatar_exists
                        ? h(
                            'small',
                            { class: 'employee-avatar-missing-label' },
                            'Foto no encontrada',
                        )
                        : null,
                ].filter(Boolean)),
            ]);
        },
    },
    {
        id: 'role',
        accessorKey: 'role',
        header: 'Rol',
        enableSorting: false,
        // El backend entrega el valor técnico; la interface muestra su etiqueta.
        cell: (info) => roleLabel(info.getValue()),
    },
    {
        id: 'articles_count',
        accessorKey: 'articles_count',
        header: 'Noticias',
        enableSorting: true,
        // Se fuerza un valor numérico visible incluso si el contador llega nulo.
        cell: (info) => h('strong', String(info.getValue() ?? 0)),
    },
    {
        id: 'last_login_at',
        accessorKey: 'last_login_at',
        header: 'Último acceso',
        enableSorting: true,
        /*
         * Las fechas conservan su alineación habitual. Cuando todavía no existe
         * un acceso, "Nunca" se centra para diferenciarlo de una fecha real.
         */
        cell: (info) => formatDate(info.getValue()),
    },
    {
        id: 'status',
        accessorFn: (user) => user.is_active ? 'active' : 'blocked',
        header: 'Estado',
        enableSorting: false,
        cell: (info) => {
            // El estado real se obtiene del objeto de la fila para elegir tanto el
            // texto como la clase visual del badge.
            const user = info.row.original;

            return h(
                'span',
                {
                    class: [
                        'status-badge',
                        user.is_active ? 'status-published' : 'status-archived',
                    ],
                },
                user.is_active ? 'ACTIVA' : 'BLOQUEADA',
            );
        },
    },
    {
        id: 'created_at',
        accessorKey: 'created_at',
        header: 'Alta',
        enableSorting: true,
        cell: (info) => formatDateOnly(info.getValue()),
    },
    {
        id: 'actions',
        header: 'Acciones',
        enableSorting: false,
        cell: (info) => accountActionCell(info.row.original),
    },
];

// Columnas del panel de Usuarios registrados. No se muestra avatar ni rol porque
// su identidad no se administra como parte del equipo editorial.
const readerColumns = [
    {
        id: 'name',
        accessorKey: 'name',
        header: 'Usuario / correo',
        enableSorting: true,
        cell: (info) => {
            // Los lectores muestran identidad textual sin avatar administrativo.
            const user = info.row.original;

            return h(
                'div',
                { class: 'admin-person-cell admin-person-cell--no-avatar' },
                [
                    h('span', {}, [
                        h('strong', user.name),
                        h('small', user.email),
                    ]),
                ],
            );
        },
    },
    {
        id: 'comments_count',
        accessorKey: 'comments_count',
        header: 'Comentarios',
        enableSorting: true,
        // Se fuerza un valor numérico visible incluso si el contador llega nulo.
        cell: (info) => h('strong', String(info.getValue() ?? 0)),
    },
    {
        id: 'last_login_at',
        accessorKey: 'last_login_at',
        header: 'Último acceso',
        enableSorting: true,
        // Las fechas se presentan con el mismo formato regional en toda la grilla.
        cell: (info) => formatDate(info.getValue()),
    },
    {
        id: 'created_at',
        accessorKey: 'created_at',
        header: 'Alta',
        enableSorting: true,
        // Las fechas se presentan con el mismo formato regional en toda la grilla.
        cell: (info) => formatDateOnly(info.getValue()),
    },
    {
        id: 'status',
        accessorFn: (user) => user.is_active ? 'active' : 'blocked',
        header: 'Estado',
        enableSorting: false,
        cell: (info) => {
            // El estado real se obtiene del objeto de la fila para elegir tanto el
            // texto como la clase visual del badge.
            const user = info.row.original;

            return h(
                'span',
                {
                    class: [
                        'status-badge',
                        user.is_active ? 'status-published' : 'status-archived',
                    ],
                },
                user.is_active ? 'ACTIVA' : 'BLOQUEADA',
            );
        },
    },
    {
        id: 'review',
        accessorFn: (user) => user.moderation_review_requested_at || '',
        header: 'Revisión',
        enableSorting: false,
        cell: (info) => {
            // La columna separa una solicitud todavía pendiente de una revisión
            // ya resuelta. El estado de la cuenta continúa en su propia columna.
            const user = info.row.original;

            if (hasPendingModerationReview(user)) {
                return h(
                    'span',
                    {
                        class: 'user-review-requested-badge',
                        title: `Revisión pendiente: ${moderationReviewReasonLabel(user)} · ${formatDate(user.moderation_review_requested_at)}`,
                    },
                    [
                        h(ShieldAlert, { size: 13, 'aria-hidden': 'true' }),
                        'REVISIÓN PENDIENTE',
                    ],
                );
            }

            if (hasResolvedModerationReview(user)) {
                return h(
                    'span',
                    {
                        class: 'user-review-resolved-badge',
                        title: `Revisión resuelta: ${moderationReviewResolutionLabel(user)}`,
                    },
                    'REVISADA',
                );
            }

            // Un guion mantiene la estructura de la columna sin inventar estado.
            return h('span', { class: 'admin-muted-value' }, '—');
        },
    },
    {
        id: 'actions',
        header: 'Acciones',
        enableSorting: false,
        cell: (info) => accountActionCell(info.row.original),
    },
];

// Selecciona las columnas correspondientes según se administren
// empleados internos o usuarios registrados.
const columns = isEmployees.value
    ? employeeColumns
    : readerColumns;

// Instancia TanStack utilizada por FlexRender en el template. El modo manual
// impide que la librería reordene localmente una página incompleta de resultados.
const table = useTable({
    key: isEmployees.value ? 'admin-employees-table' : 'admin-readers-table',
    features,
    columns,
    data: userRows,
    getRowId: (row) => String(row.id),
    manualSorting: true,
    enableMultiSort: false,
    enableSortingRemoval: false,
    state: {
        // Getter reactivo: TanStack siempre consulta el ordenamiento vigente.
        get sorting() {
            return sorting.value;
        },
    },
    onSortingChange: changeSorting,
});

// Construye la consulta sin enviar parámetros vacíos.
function buildQuery(overrides = {}) {
    // Parte de los filtros actualmente aplicados y permite reemplazar únicamente
    // los valores afectados por la acción que originó la nueva navegación.
    const query = {
        q: appliedSearch.value || undefined,
        status: filter.status || undefined,
        review: !isEmployees.value ? (filter.review || undefined) : undefined,
        sort: filter.sort || 'created_at',
        direction: filter.direction || 'desc',
        per_page: Number(filter.per_page || 25),
        ...overrides,
    };

    // Los parámetros vacíos se eliminan para mantener una URL limpia y evitar
    // que Laravel interprete cadenas vacías como filtros intencionales.
    return Object.fromEntries(
        Object.entries(query).filter(([, value]) => (
            value !== undefined
            && value !== null
            && value !== ''
        )),
    );
}

// Navega hacia la misma grilla conservando estado y posición de scroll.
function visitList(overrides = {}) {
    // La misma vista Vue sirve a dos rutas. Se elige el endpoint correspondiente
    // sin duplicar toda la lógica de navegación y filtros.
    const routeName = isEmployees.value
        ? 'admin.users.employees'
        : 'admin.users.readers';

    // preserveState evita reconstruir controles innecesariamente; replace impide
    // llenar el historial del navegador con cada cambio de filtro.
    const scrollX = window.scrollX;
    const scrollY = window.scrollY;

    router.get(
        route(routeName),
        buildQuery(overrides),
        {
            preserveState: true,
            replace: true,
            preserveScroll: true,
            // Restaura la posición exacta después de que Vue reemplaza las filas.
            onSuccess: async () => {
                await nextTick();
                const root = document.documentElement;
                const previousBehavior = root.style.scrollBehavior;
                root.style.scrollBehavior = 'auto';
                window.scrollTo({ left: scrollX, top: scrollY, behavior: 'auto' });
                root.style.scrollBehavior = previousBehavior;
            },
        },
    );
}

// La búsqueda textual se aplica únicamente al presionar Buscar.
function applySearch() {
    // No se consulta si el texto todavía no cumple el mínimo o coincide exactamente
    // con la búsqueda que ya se encuentra aplicada en Laravel.
    if (!canApplySearch.value) {
        return;
    }

    // Cualquier búsqueda nueva vuelve a la primera página para no conservar un
    // número de página que podría no existir en el nuevo conjunto de resultados.
    visitList({
        q: normalizedSearch.value || undefined,
        page: undefined,
    });
}

// Los combos se aplican inmediatamente, igual que en Etiquetas.
function changeStatus() {
    // El cambio de estado se aplica en el momento y también reinicia la paginación.
    visitList({
        status: filter.status || undefined,
        page: undefined,
    });
}

// Aplica el filtro de revisión únicamente en Usuarios registrados; Empleados no
// posee ese criterio y por eso una llamada accidental se ignora.
function changeReview() {
    if (isEmployees.value) {
        return;
    }

    // Como cambia el universo de resultados, la consulta vuelve a página uno.
    visitList({
        review: filter.review || undefined,
        page: undefined,
    });
}

// Limpia búsqueda, estado y revisión sin alterar orden ni filas por página.
function resetFilters() {
    // Si no existe ningún filtro visible activo no se genera una navegación inútil.
    if (!hasActiveFilters.value) {
        return;
    }

    // Primero se limpia el estado visual de los controles para que la interface
    // responda inmediatamente al click del usuario.
    filter.q = '';
    filter.status = '';
    filter.review = '';

    // undefined hace que buildQuery descarte estos parámetros de la URL.
    visitList({
        q: undefined,
        status: undefined,
        review: undefined,
        page: undefined,
    });
}

// El selector 25/50/100 vive únicamente en la barra inferior de paginación.
function changePerPage(value) {
    // AdminPagination entrega el nuevo valor; se fuerza Number para que el estado
    // conserve el mismo tipo que Laravel espera al construir la consulta.
    filter.per_page = Number(value);

    visitList({
        per_page: filter.per_page,
        page: undefined,
    });
}

// Comprueba los campos comunes de alta y edición antes de permitir el envío.
// Esta validación no reemplaza a Laravel: sirve para controlar el estado del botón.
function validEmployeeIdentity(form) {
    // Se validan valores normalizados para que espacios al inicio/final no cuenten
    // como contenido real ni permitan habilitar un formulario incompleto.
    const nameLength = String(form.name || '').trim().length;
    const emailLocal = String(form.email_local || '').trim();
    const jobTitleLength = String(form.job_title || '').trim().length;
    const bioLength = String(form.bio || '').trim().length;

    // La biografía pertenece a los perfiles editoriales. Para Administrador
    // el campo permanece visible pero deshabilitado y no participa de la validez.
    const validBiography = form.role === 'admin'
        || (
            bioLength >= EMPLOYEE_BIO_MIN
            && bioLength <= EMPLOYEE_BIO_MAX
        );

    return nameLength >= EMPLOYEE_NAME_MIN
        && nameLength <= EMPLOYEE_NAME_MAX
        && emailLocal.length > 0
        && emailLocal.length <= EMPLOYEE_EMAIL_LOCAL_MAX
        && emailLocalPattern.test(emailLocal)
        && Boolean(form.role)
        && jobTitleLength > 0
        && jobTitleLength <= EMPLOYEE_JOB_TITLE_MAX
        && validBiography;
}

/*
 * Reduce un campo breve a una sola línea antes de enviarlo.
 * Laravel repite la limpieza para que no dependa del navegador.
 */
function normalizeSingleLine(value) {
    // Cualquier secuencia de espacios, tabs o saltos se convierte en un único
    // espacio para impedir nombres/cargos partidos en varias líneas.
    return String(value || '')
        .replace(/\s+/g, ' ')
        .trim();
}

/*
 * La biografía administrativa es un único párrafo breve. Saltos de línea,
 * tabulaciones y espacios repetidos se convierten en un solo espacio.
 */
function normalizeBiography(value) {
    return normalizeSingleLine(value);
}

// Actualiza la biografía en tiempo real para que pegar texto con saltos tampoco
// deje párrafos manuales dentro del perfil.
function updateBiography(form, value) {
    form.bio = normalizeBiography(value);
}

/*
 * Al seleccionar Administrador la biografía deja de participar del formulario.
 * Se elimina cualquier error previo sin borrar todavía el texto local, por si el
 * usuario vuelve a elegir un rol editorial antes de guardar.
 */
function handleEmployeeRoleChange(form) {
    if (form.role === 'admin') {
        form.clearErrors('bio');
    }
}

// Construye el contador dinámico: mientras falta contenido informa exactamente
// cuántos caracteres restan; al alcanzar el mínimo queda solo actual/máximo.
function employeeBioCounter(length) {
    if (length < EMPLOYEE_BIO_MIN) {
        const remaining = EMPLOYEE_BIO_MIN - length;
        const verb = remaining === 1 ? 'falta' : 'faltan';
        const unit = remaining === 1 ? 'carácter' : 'caracteres';
        return `${length}/${EMPLOYEE_BIO_MAX} · ${verb} ${remaining} ${unit}`;
    }

    return `${length}/${EMPLOYEE_BIO_MAX}`;
}

// Construye el contador de la observación de bloqueo.
// Mientras no se alcanza el mínimo requerido, informa cuántos caracteres faltan;
// después muestra únicamente la cantidad actual respecto del máximo permitido.
function blockNoteCounter() {
    const length = blockNoteLength.value;

    if (length < BLOCK_NOTE_MIN) {
        const remaining = BLOCK_NOTE_MIN - length;
        const verb = remaining === 1 ? 'falta' : 'faltan';
        const unit = remaining === 1 ? 'carácter' : 'caracteres';
        return `${length}/${BLOCK_NOTE_MAX} · ${verb} ${remaining} ${unit}`;
    }

    return `${length}/${BLOCK_NOTE_MAX}`;
}

// Mantiene la observación interna legible sin permitir bloques enormes de
// renglones vacíos. Un Enter se conserva, dos también y tres o más se reducen a dos.
function normalizeBlockNote(value, maxLength) {
    return String(value || '')
        .replace(/[\r\n]+/g, ' ')
        .replace(/[\t ]{2,}/g, ' ')
        .slice(0, maxLength);
}

function handleBlockNoteInput(event) {
    const normalized = normalizeBlockNote(
        event?.target?.value ?? blockForm.blocked_note,
        BLOCK_NOTE_MAX,
    );

    blockForm.blocked_note = normalized;
    blockForm.clearErrors('blocked_note');

    if (event?.target && event.target.value !== normalized) {
        event.target.value = normalized;
    }
}

// Aplica la misma normalización de presentación a los campos editables antes
// de enviarlos. El backend vuelve a normalizar para no confiar en el navegador.
function normalizeEmployeeForm(form) {
    // Nombre, correo local, cargo y biografía se normalizan antes de enviar.
    form.name = normalizeSingleLine(form.name);
    form.email_local = String(form.email_local || '').trim().toLowerCase();
    form.job_title = normalizeSingleLine(form.job_title);
    form.bio = normalizeBiography(form.bio);
}

/*
 * Valida en el navegador que la fotografía tenga al menos 600 × 600 px.
 *
 * Esta comprobación mejora la respuesta inmediata del modal, pero no reemplaza
 * la regla dimensions del Form Request: cualquier petición construida a mano
 * vuelve a ser validada por Laravel antes de guardar el archivo.
 */
function handleEmployeeAvatarChange(event, form) {
    const input = event.currentTarget;
    const file = input?.files?.[0] || null;

    // Cada selección nueva elimina únicamente el error anterior de la fotografía.
    form.clearErrors('avatar_file');

    // Si el usuario canceló el selector se elimina cualquier archivo pendiente.
    if (!file) {
        form.avatar_file = null;
        return;
    }

    // Se conserva inmediatamente el archivo. Si el usuario enviara el formulario
    // antes de terminar esta lectura, Laravel vuelve a validar las dimensiones y
    // nunca permitirá persistir una imagen que supere el límite.
    form.avatar_file = file;

    // URL.createObjectURL permite leer las dimensiones sin subir el archivo ni
    // convertirlo a base64. La URL temporal se libera apenas termina la lectura.
    const objectUrl = URL.createObjectURL(file);
    const image = new Image();

    image.onload = () => {
        const belowMinimum = image.naturalWidth < EMPLOYEE_AVATAR_MIN_WIDTH
            || image.naturalHeight < EMPLOYEE_AVATAR_MIN_HEIGHT;

        URL.revokeObjectURL(objectUrl);

        if (belowMinimum) {
            // Se limpia también el input nativo para que un archivo inválido no
            // permanezca seleccionado mientras el formulario muestra el error.
            input.value = '';
            form.avatar_file = null;
            form.setError(
                'avatar_file',
                `La foto debe medir al menos ${EMPLOYEE_AVATAR_MIN_WIDTH} × ${EMPLOYEE_AVATAR_MIN_HEIGHT} píxeles.`,
            );
        }

        // Una fuente suficientemente grande pasa a formar parte del FormData.
        form.avatar_file = file;
    };

    image.onerror = () => {
        URL.revokeObjectURL(objectUrl);

        // El tipo y la integridad real de la imagen se validan nuevamente en
        // Laravel. Se conserva el archivo para recibir el mensaje específico.
    };

    image.src = objectUrl;
}

// Agrega un fallback solamente cuando Inertia no recibió ningún error concreto.
// Garantiza un mensaje amigable dentro del modal si la petición falla sin que
// Laravel/Inertia haya entregado un error específico de validación u operación.
function ensureFormOperationError(form, errors, fallback) {
    // Si backend ya explicó el problema, se respeta ese mensaje y no se lo
    // reemplaza por un texto genérico.
    if (errors?.operation || Object.keys(errors || {}).length > 0) {
        return;
    }

    form.setError('operation', fallback);
}

// Abre el alta con los errores de un intento anterior completamente limpios.
function openCreateModal() {
    // Se conservan los valores escritos si el modal fue cerrado manualmente, pero
    // no se arrastran mensajes de validación de una apertura anterior.
    createSubmitting.value = false;
    createForm.clearErrors();
    createOpen.value = true;
}

// Cierra el alta únicamente si no existe una petición en curso, evitando que
// el usuario interrumpa visualmente una operación que todavía se está enviando.
function closeCreateModal() {
    // Mientras se está creando el empleado, cerrar el modal podría hacer creer al
    // usuario que la operación fue cancelada aunque la petición siga ejecutándose.
    if (createEmployeeBusy.value) {
        return;
    }

    // El formulario conserva sus valores mientras el modal está abierto; al
    // cerrarlo manualmente solo se eliminan los mensajes de error visibles.
    createOpen.value = false;
    createForm.clearErrors();
}

function closeCreateEmployeeAfterSuccess() {
    createSubmitting.value = false;
    createOpen.value = false;
    createForm.reset();
    createForm.role = 'journalist';
    createForm.is_active = true;
}

// Crea un empleado y reinicia el formulario si Laravel confirma la operación.
// Crea un empleado interno después de normalizar y validar preventivamente
// los datos. Los errores inesperados permanecen visibles dentro del propio modal.
function createEmployee() {
    // Se normaliza antes de consultar canSubmitCreateEmployee para que el estado
    // final enviado sea exactamente el mismo que se usa para decidir el envío.
    normalizeEmployeeForm(createForm);

    // Evita envíos inválidos y dobles clics mientras Inertia procesa la petición.
    if (!canSubmitCreateEmployee.value || createEmployeeBusy.value) {
        return;
    }

    // Un nuevo intento no debe seguir mostrando errores generales anteriores.
    operationError.value = '';
    createForm.clearErrors('operation');

    createForm.post(route('admin.users.store'), {
        preserveScroll: true,
        forceFormData: true,
        onStart: () => { createSubmitting.value = true; },
        onSuccess: () => {
            closeCreateEmployeeAfterSuccess();
        },
        onError: (errors) => {
            createSubmitting.value = false;
            // Conserva errores de validación concretos y agrega fallback solamente
            // si la respuesta inesperada no trajo un mensaje utilizable.
            ensureFormOperationError(
                createForm,
                errors,
                'No pudimos crear el empleado. Intentá nuevamente.',
            );
        },
        onFinish: () => {
            if (createForm.wasSuccessful) {
                closeCreateEmployeeAfterSuccess();
            }

            createSubmitting.value = false;
        },
    });
}

// Abre edición para empleados y detalle de solo lectura para usuarios públicos.
// Abre el modal asociado a una fila. En Empleados carga un formulario editable;
// en Usuarios registrados solamente abre el detalle de identidad de solo lectura.
function openEdit(user) {
    editSubmitting.value = false;
    // selected también alimenta los títulos y datos visibles del modal.
    selected.value = user;
    operationError.value = '';

    // Los lectores no copian datos al formulario porque administración no puede
    // modificar nombre ni correo de una cuenta pública.
    if (!isEmployees.value) {
        editInitial.value = null;
        return;
    }

    // Para empleados se copian los valores actuales de la fila al formulario.
    editForm.name = user.name;
    editForm.email_local = String(user.email || '').replace(/@trama\.test$/i, '');
    editForm.role = user.role;
    editForm.job_title = user.job_title || '';
    editForm.bio = user.bio || '';
    editForm.avatar_file = null;
    editForm.clearErrors();

    /*
     * Si existe una ruta de avatar en la base pero el archivo desapareció, el
     * modal lo avisa apenas se abre. El administrador puede reparar la cuenta
     * seleccionando una nueva foto desde este mismo formulario.
     */
    if (user.has_custom_avatar && !user.avatar_exists) {
        editForm.setError(
            'avatar_file',
            'El archivo actual de la foto no existe. Subí nuevamente la imagen para restaurarla.',
        );
    }

    // Esta fotografía inicial permite saber si el usuario realmente modificó
    // algún campo y controla el estado de Guardar cambios.
    editInitial.value = {
        name: editForm.name,
        email_local: editForm.email_local,
        role: editForm.role,
        job_title: editForm.job_title,
        bio: editForm.bio,
    };
}

// Cierra tanto edición de empleado como detalle de lector. Durante un guardado
// no se permite cerrar para evitar estados visuales ambiguos.
function closeSelectedModal() {
    // La edición permanece visible mientras existe una petición activa; en modo
    // lector editForm.processing será falso porque el modal es de solo lectura.
    if (editEmployeeBusy.value) {
        return;
    }

    // Se descartan selección y fotografía inicial para que una apertura futura
    // vuelva a calcular correctamente si existen cambios.
    selected.value = null;
    editInitial.value = null;
    editForm.clearErrors();
}

function closeEditEmployeeAfterSuccess() {
    editSubmitting.value = false;
    selected.value = null;
    editInitial.value = null;
}

// Solo los empleados internos admiten una operación de edición.
// Solo los empleados internos admiten una operación de edición.
// Los usuarios registrados nunca pasan por este flujo de actualización.
function updateUser() {
    // Protege el método ante una llamada fuera del panel de empleados, sin fila
    // seleccionada o mientras ya existe otra actualización en curso.
    if (!isEmployees.value || !selected.value || editEmployeeBusy.value) {
        return;
    }

    // Antes de comparar y enviar, se eliminan espacios/saltos innecesarios para
    // trabajar con el mismo valor que finalmente persistirá Laravel.
    normalizeEmployeeForm(editForm);

    // No se guarda si algún dato es inválido o si el empleado no cambió nada.
    if (!canSubmitEditEmployee.value) {
        return;
    }

    // Cada nuevo intento comienza sin el error general del intento anterior.
    editForm.clearErrors('operation');

    editForm
        // Se usa POST multipart para poder adjuntar avatar, pero Laravel recibe
        // _method=patch y mantiene la semántica de actualización parcial.
        .transform((data) => ({ ...data, _method: 'patch' }))
        .post(route('admin.users.update', selected.value.id), {
            preserveScroll: true,
            forceFormData: true,
            onStart: () => { editSubmitting.value = true; },
            onSuccess: () => {
                closeEditEmployeeAfterSuccess();
            },
            onError: (errors) => {
                editSubmitting.value = false;
                // Los errores de campo siguen debajo de sus inputs; un fallo inesperado
                // se transforma en un mensaje general dentro del mismo modal.
                ensureFormOperationError(
                    editForm,
                    errors,
                    'No pudimos guardar los cambios del empleado. Intentá nuevamente.',
                );
            },
            onFinish: () => {
                if (editForm.wasSuccessful) {
                    closeEditEmployeeAfterSuccess();
                    return;
                }

                editSubmitting.value = false;
            },
        });
}

/* ============================================================================
 * ACCIONES DE CUENTA Y MODALES
 * ============================================================================ */

// Elimina el mensaje general del modal reutilizable de confirmación.
function clearConfirmationError() {
    confirmationError.value = '';
}

// Indica si una fila corresponde a la cuenta que está usando el panel.
function isCurrentAccount(user) {
    return Number(user?.id || 0) === currentUserId.value;
}

// Abre la confirmación de activación o bloqueo.
// Prepara la activación o el bloqueo de la cuenta seleccionada. Los lectores
// bloqueados requieren un modal adicional para registrar el motivo administrativo.
function openToggleConfirmation(user) {
    // La propia cuenta no puede bloquearse desde la sesión que está administrando.
    if (isCurrentAccount(user)) {
        return;
    }

    // La acción se deduce del estado actual: una cuenta activa se bloqueará y
    // una cuenta bloqueada se activará.
    const willActivate = !user.is_active;

    clearConfirmationError();

    // Los lectores requieren motivo administrativo al bloquearse.
    if (!isEmployees.value && !willActivate) {
        blockSubmitting.value = false;
        blockTarget.value = user;
        blockForm.reset();
        blockForm.clearErrors();
        blockForm.blocked_reason = hasPendingModerationReview(user)
            ? reviewSuggestedBlockReason(user)
            : '';
        blockForm.blocked_note = hasPendingModerationReview(user)
            ? String(user.moderation_review_note || '').slice(0, BLOCK_NOTE_MAX)
            : '';
        return;
    }

    // El mismo componente de confirmación recibe textos distintos según la acción.
    confirmation.value = {
        type: 'toggle',
        user,
        eyebrow: willActivate ? 'Activar cuenta' : 'Bloquear cuenta',
        title: willActivate
            ? `¿Activar la cuenta de ${user.name}?`
            : `¿Bloquear la cuenta de ${user.name}?`,
        message: willActivate
            ? 'La persona podrá volver a iniciar sesión y utilizar las funciones correspondientes a su cuenta.'
            : 'La cuenta perderá el acceso. Cualquier sesión abierta será expulsada en su siguiente petición.',
        help: '',
        confirmLabel: willActivate ? 'Activar cuenta' : 'Bloquear cuenta',
        processingLabel: willActivate ? 'Activando...' : 'Bloqueando...',
        confirmButtonClass: 'user-toggle-confirm-button',
        danger: false,
    };
}

// Bloquea un lector con motivo y observación interna sin borrar su historial.
// Envía el bloqueo documentado de un lector. La cuenta conserva todo su
// contenido histórico y únicamente pierde acceso mientras permanezca bloqueada.
function blockReader() {
    // Sin lector objetivo o con una petición ya activa no hay operación válida.
    if (!blockTarget.value || !canSubmitReaderBlock.value) {
        return;
    }

    // Se limpia el error general para que el nuevo intento muestre únicamente
    // el resultado de la petición actual.
    blockForm.clearErrors('operation');

    blockForm.patch(route('admin.users.toggle-active', blockTarget.value.id), {
        preserveScroll: true,
        onStart: () => { blockSubmitting.value = true; },
        onSuccess: () => {
            closeBlockAfterSuccess();
        },
        onError: (errors) => {
            blockSubmitting.value = false;
            // Conserva errores de validación concretos y agrega fallback solamente
            // si la respuesta inesperada no trajo un mensaje utilizable.
            ensureFormOperationError(
                blockForm,
                errors,
                'No pudimos bloquear la cuenta. Intentá nuevamente.',
            );
        },
        onFinish: () => {
            if (blockForm.wasSuccessful) {
                closeBlockAfterSuccess();
                return;
            }

            blockSubmitting.value = false;
        },
    });
}

function closeBlockAfterSuccess() {
    blockSubmitting.value = false;
    blockTarget.value = null;
    blockForm.reset();
}

// Cancela el modal de bloqueo mientras no exista una petición en curso.
function closeBlockModal() {
    // El cierre queda bloqueado durante el PATCH para que el resultado de la acción
    // siempre se muestre en el mismo contexto donde fue iniciada.
    if (readerBlockBusy.value) {
        return;
    }

    blockTarget.value = null;
    blockForm.reset();
    blockForm.clearErrors();
}

// Permite al administrador cerrar una derivación después de revisar la cuenta
// cuando considera que no corresponde aplicar un bloqueo.
function openResolveReviewConfirmation(user) {
    if (!hasPendingModerationReview(user) || !user.is_active) {
        return;
    }

    clearConfirmationError();

    confirmation.value = {
        type: 'resolve-review',
        user,
        eyebrow: 'Revisión administrativa',
        title: `¿Cerrar la revisión de ${user.name}?`,
        message: 'La cuenta continuará activa y esta solicitud dejará de figurar como pendiente.',
        help: 'La revisión quedará registrada como resuelta sin aplicar un bloqueo. Moderación podrá volver a derivar la cuenta si surge un nuevo problema.',
        confirmLabel: 'Cerrar revisión',
        processingLabel: 'Cerrando...',
        danger: false,
    };
}

// Confirma el envío de un enlace de recuperación de contraseña.
// Configura la confirmación para enviar recuperación de contraseña y cerrar
// sesiones existentes del usuario seleccionado.
function openResetAccessConfirmation(user) {
    // Un nuevo modal no debe heredar el mensaje de error de una acción anterior.
    clearConfirmationError();

    // El mismo componente de confirmación recibe textos distintos según la acción.
    confirmation.value = {
        type: 'reset-access',
        user,
        eyebrow: 'Restablecer acceso',
        title: `¿Enviar un enlace a ${user.email}?`,
        message: 'La persona recibirá un correo para crear una nueva contraseña.',
        help: 'También se cerrarán sus sesiones actuales para impedir que la contraseña anterior continúe utilizándose.',
        confirmLabel: 'Enviar enlace',
        processingLabel: 'Enviando...',
        danger: false,
    };
}

// Confirma la eliminación de cuentas sin contenido histórico.
// Abre la eliminación solamente para cuentas sin relaciones históricas. El
// backend vuelve a comprobar esta condición antes de borrar definitivamente.
function openDeleteConfirmation(user) {
    // La cuenta que está utilizando actualmente el panel no puede eliminarse
    // desde su propia sesión administrativa.
    if (isCurrentAccount(user)) {
        return;
    }

    // La UI evita ofrecer una operación que destruiría referencias históricas.
    if (user.has_historical_content) {
        return;
    }

    clearConfirmationError();

    // El mismo componente de confirmación recibe textos distintos según la acción.
    confirmation.value = {
        type: 'delete',
        user,
        eyebrow: 'Eliminar cuenta',
        title: `¿Eliminar a ${deleteAccountDisplayName(user.name)}?`,
        message: 'La cuenta se eliminará definitivamente y esta acción no se puede deshacer.',
        help: user.role === 'reader'
            ? 'Sus comentarios publicados quedarán visibles como Usuario eliminado; la cuenta y el acceso se borrarán definitivamente.'
            : 'La eliminación está disponible porque la cuenta no tiene noticias, comentarios, revisiones ni devoluciones asociadas.',
        confirmLabel: 'Eliminar definitivamente',
        processingLabel: 'Eliminando...',
        danger: true,
    };
}

// Cierra el modal reutilizable únicamente cuando ninguna acción está procesando.
function closeConfirmation() {
    // No se permite cerrar mientras una acción destructiva o de acceso está en
    // tránsito, evitando dobles estados entre UI y backend.
    if (confirmationProcessing.value) {
        return;
    }

    confirmation.value = null;
    clearConfirmationError();
}

// Ejecuta la acción y mantiene cualquier error amigable dentro del modal.
// Ejecuta la acción preparada en confirmation y conserva cualquier error
// amigable dentro del modal hasta que el usuario vuelva a intentar o cancele.
function confirmUserAction() {
    // Evita ejecutar sin una acción definida y bloquea dobles confirmaciones.
    if (!confirmation.value || confirmationProcessing.value) {
        return;
    }

    // Se conserva una referencia local porque confirmation puede cambiar después
    // de una respuesta exitosa; processing bloquea botones durante la petición.
    const action = confirmation.value;
    confirmationProcessing.value = true;
    clearConfirmationError();

    // Todas las acciones comparten callbacks para cierre, error amigable y limpieza
    // del estado de procesamiento, independientemente del verbo HTTP utilizado.
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            // La confirmación se descarta únicamente cuando backend completó la acción.
            confirmation.value = null;
            clearConfirmationError();
        },
        onError: (errors) => {
            // Prioriza el mensaje de operación, luego un error asociado a user y,
            // si ninguno existe, muestra un fallback sin detalles técnicos.
            confirmationError.value = errors?.operation
                || errors?.review
                || errors?.user
                || 'No pudimos completar la operación. Intentá nuevamente.';
        },
        onFinish: () => {
            // onFinish se ejecuta tanto en éxito como en error y libera los controles.
            confirmationProcessing.value = false;
        },
    };

    // Cada tipo de confirmación se traduce en su endpoint correspondiente.
    if (action.type === 'toggle') {
        router.patch(route('admin.users.toggle-active', action.user.id), {}, options);
        return;
    }

    if (action.type === 'resolve-review') {
        router.patch(
            route('admin.users.moderation-review.resolve', action.user.id),
            {},
            {
                ...options,
                onSuccess: () => {
                    selected.value = null;
                    confirmation.value = null;
                    clearConfirmationError();
                },
            },
        );
        return;
    }

    if (action.type === 'reset-access') {
        router.post(route('admin.users.reset-access', action.user.id), {}, options);
        return;
    }

    if (action.type === 'delete') {
        router.delete(route('admin.users.destroy', action.user.id), options);
    }
}

// Escape cierra/cancela el modal superior que corresponda. Nunca ejecuta una
// acción importante y se ignora cuando una petición ya está en procesamiento.
function handleAdminUsersEscape(event) {
    if (event.key !== 'Escape') return;

    // ConfirmActionModal resuelve su propio Escape. No se cierra el modal padre
    // que pueda quedar detrás de esa confirmación.
    if (confirmation.value) {
        return;
    }

    if (blockTarget.value) {
        if (!readerBlockBusy.value) closeBlockModal();
        return;
    }

    if (createOpen.value) {
        if (!createEmployeeBusy.value) closeCreateModal();
        return;
    }

    if (selected.value) {
        if (!editEmployeeBusy.value) closeSelectedModal();
    }
}

/* ============================================================================
 * FORMATO Y TABLA TANSTACK
 * ============================================================================ */

// Convierte fechas de Laravel al formato legible utilizado por el panel. Cuando
// nunca hubo acceso se evita mostrar una fecha inexistente.
function formatDate(value) {
    if (!value) {
        return 'Nunca';
    }

    // Las grillas usan el formato compacto común: 08/08/2026, 19:05.
    return new Intl.DateTimeFormat('es-AR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).format(new Date(value));
}

// El detalle de una cuenta conserva el formato largo más humano del modal.
function formatDateOnly(value) {
    if (!value) {
        return 'Nunca';
    }

    return new Intl.DateTimeFormat('es-AR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(new Date(value));
}

function formatDetailDate(value) {
    if (!value) {
        return 'Nunca';
    }

    return new Intl.DateTimeFormat('es-AR', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}

// Resuelve la etiqueta de un motivo y conserva un fallback para datos antiguos
// o valores que todavía no formen parte del mapa conocido.
function blockReasonLabel(reason) {
    // Los registros antiguos pueden no tener motivo; en ese caso se muestra un
    // texto neutro en lugar de dejar la interface vacía.
    return blockReasonLabels[reason] || reason || 'Sin motivo registrado';
}

function moderationReviewReasonLabel(user) {
    return user?.moderation_review_reason_label
        || {
            spam: 'Spam',
            abuse: 'Insultos o acoso',
            inappropriate: 'Contenido inapropiado',
            repeated: 'Conducta repetida',
            other: 'Otro',
        }[user?.moderation_review_reason]
        || 'Sin motivo registrado';
}

function reviewerLabel(user) {
    return user?.moderation_review_requested_by?.name || 'Moderación';
}

// Una derivación está pendiente solamente mientras todavía no tenga resolución.
function hasPendingModerationReview(user) {
    return Boolean(
        user?.moderation_review_requested_at
        && !user?.moderation_review_resolved_at
    );
}

// Una revisión resuelta conserva su contexto histórico sin seguir reclamando acción.
function hasResolvedModerationReview(user) {
    return Boolean(
        user?.moderation_review_requested_at
        && user?.moderation_review_resolved_at
    );
}

function moderationReviewResolutionLabel(user) {
    return user?.moderation_review_resolution === 'blocked'
        ? 'Bloqueo aplicado'
        : 'Sin acción sobre la cuenta';
}

function reviewResolverLabel(user) {
    return user?.moderation_review_resolved_by?.name || 'Administración';
}

function reviewSuggestedBlockReason(user) {
    return reviewToBlockReason[user?.moderation_review_reason] || 'moderation';
}

// Convierte el valor técnico del rol en el texto recibido desde Laravel.
function roleLabel(role) {
    // Si la opción ya no existe se conserva el valor original para no ocultar el
    // rol almacenado en datos históricos.
    return (props.roleOptions || []).find((option) => option.value === role)?.label || role;
}

// Acciones de cada fila. Los lectores usan un ojo porque su identidad es de solo
// lectura; los empleados conservan el lápiz de edición.
//
// Construye con h() la celda de acciones de TanStack. h() permite renderizar
// componentes Vue y callbacks desde la definición JavaScript de las columnas.
function accountActionCell(user) {
    // La primera acción cambia según el tipo de panel: edición real para empleados
    // y consulta de solo lectura para usuarios registrados.
    const detailIcon = isEmployees.value ? Pencil : Eye;
    const detailTitle = isEmployees.value ? 'Editar empleado' : 'Ver detalle';

    // Todos los botones detienen su lógica en las funciones específicas; TanStack
    // se limita a renderizar la celda sin modificar directamente los datos.
    return h('div', { class: 'row-actions' }, [
        // Abre el formulario de edición cuando se administra un empleado o
        // el detalle de solo lectura cuando se consulta un usuario registrado.
        h(
            'button',
            {
                type: 'button',
                title: detailTitle,
                onClick: (event) => {
                    // El botón tiene una acción propia y no debe volver a disparar
                    // el click general de la fila.
                    event.stopPropagation();
                    openEdit(user);
                },
            },
            [h(detailIcon, { size: 16 })],
        ),

        // Permite activar o bloquear la cuenta.
        //
        // La cuenta que está utilizando actualmente el panel no puede bloquearse
        // a sí misma, por lo que el botón queda deshabilitado en su propia fila.
        h(
            'button',
            {
                type: 'button',
                title: isCurrentAccount(user)
                    ? 'No podés bloquear tu propia cuenta'
                    : user.is_active
                        ? 'Bloquear cuenta'
                        : 'Activar cuenta',
                disabled: isCurrentAccount(user),
                onClick: (event) => {
                    // Evita que la acción del botón también active el evento
                    // general asociado a la fila.
                    event.stopPropagation();
                    openToggleConfirmation(user);
                },
            },
            [h(Power, { size: 16 })],
        ),

        // Abre la confirmación para restablecer las credenciales de acceso.
        //
        // Una cuenta bloqueada no puede recibir un nuevo acceso hasta que vuelva
        // a estar activa.
        h(
            'button',
            {
                type: 'button',
                title: 'Restablecer acceso',
                disabled: !user.is_active,
                onClick: (event) => {
                    // Mantiene esta operación independiente del click de la fila.
                    event.stopPropagation();
                    openResetAccessConfirmation(user);
                },
            },
            [h(KeyRound, { size: 16 })],
        ),

        // Permite eliminar una cuenta cuando las reglas del sistema lo permiten.
        //
        // El usuario autenticado nunca puede eliminar su propia cuenta y tampoco
        // se permite eliminar una cuenta que conserva contenido histórico asociado.
        h(
            'button',
            {
                type: 'button',
                title: isCurrentAccount(user)
                    ? 'No podés eliminar tu propia cuenta'
                    : user.has_historical_content
                        ? 'No se puede eliminar porque conserva historial editorial'
                    : 'Eliminar',
                disabled: isCurrentAccount(user) || user.has_historical_content,
                onClick: (event) => {
                    // Evita propagar el click antes de abrir la confirmación
                    // específica de eliminación.
                    event.stopPropagation();
                    openDeleteConfirmation(user);
                },
            },
            [h(Trash2, { size: 16 })],
        ),
    ]);
}

// Recibe el cambio propuesto por TanStack, lo reduce a una sola columna y vuelve
// a pedir la página a Laravel con el nuevo sort/direction.
function changeSorting(updater) {
    // TanStack puede entregar directamente el array o una función actualizadora.
    const nextSorting = typeof updater === 'function'
        ? updater(sorting.value)
        : updater;

    // Si por alguna razón no queda columna seleccionada se recupera el orden
    // predeterminado por fecha de alta descendente.
    const selectedSorting = nextSorting[0] || {
        id: 'created_at',
        desc: true,
    };

    // Se refleja primero en el estado local y luego se sincroniza con Laravel.
    sorting.value = [selectedSorting];
    filter.sort = selectedSorting.id;
    filter.direction = selectedSorting.desc ? 'desc' : 'asc';

    // El servidor vuelve a ordenar el conjunto completo; ordenar solamente las
    // filas visibles produciría resultados incorrectos con paginación.
    visitList({
        sort: filter.sort,
        direction: filter.direction,
        page: undefined,
    });
}

// Al montar la pantalla, registra el listener global que permite cerrar
// el modal activo mediante la tecla Escape cuando la acción lo permite.
onMounted(() => {
    document.addEventListener('keydown', handleAdminUsersEscape);
});

// Antes de desmontar la pantalla, elimina el listener global para evitar
// que el manejador de Escape permanezca activo fuera de esta vista.
onBeforeUnmount(() => {
    document.removeEventListener('keydown', handleAdminUsersEscape);
});
</script>

<template>
    <Head :title="pageTitle" />

    <div
        v-if="loadError"
        class="operation-error"
        role="alert"
    >
        <strong>No se pudo cargar el listado</strong>
        <span>{{ loadError }}</span>
    </div>

    <div
        v-if="operationError"
        class="operation-error"
        role="alert"
    >
        <strong>No se pudo completar la operación</strong>
        <span>{{ operationError }}</span>
    </div>

    <!-- Barra sticky de una sola línea, con el mismo patrón de Etiquetas. -->
    <section class="admin-toolbar editorial-toolbar users-list-toolbar">
        <form
            class="admin-filter-form compact editorial-search-form users-search-form"
            @submit.prevent="applySearch"
        >
            <Search :size="18" aria-hidden="true" />

            <input
                v-model="filter.q"
                class="editorial-search-input"
                type="search"
                name="q"
                minlength="2"
                maxlength="160"
                placeholder="Buscar por nombre o correo..."
                aria-label="Buscar por nombre o correo"
            />

            <button
                type="submit"
                :disabled="!canApplySearch"
            >
                Buscar
            </button>
        </form>

        <select
            v-model="filter.status"
            class="editorial-filter-control users-status-filter"
            aria-label="Filtrar por estado"
            @change="changeStatus"
        >
            <option value="">Todos los estados</option>
            <option value="active">Activos</option>
            <option value="blocked">Bloqueados</option>
        </select>

        <select
            v-if="!isEmployees"
            v-model="filter.review"
            class="editorial-filter-control users-review-filter"
            aria-label="Filtrar por revisión"
            @change="changeReview"
        >
            <option value="">Todas las revisiones</option>
            <option value="requested">Pendientes</option>
            <option value="resolved">Resueltas</option>
            <option value="none">Sin revisión</option>
        </select>

        <button
            type="button"
            class="editorial-filter-clear"
            :disabled="!hasActiveFilters"
            @click="resetFilters"
        >
            Limpiar
        </button>

        <button
            v-if="isEmployees"
            type="button"
            class="admin-primary-action users-create-button"
            @click="openCreateModal"
        >
            <UserPlus :size="18" />
            Nuevo empleado
        </button>
    </section>

    <section
        v-if="!loadError"
        class="editorial-table-panel"
    >
        <div class="editorial-table-scroll">
            <table
                class="editorial-table admin-tanstack-table"
                :class="isEmployees ? 'admin-employees-table' : 'admin-readers-table'"
            >
                <thead>
                    <tr
                        v-for="headerGroup in table.getHeaderGroups()"
                        :key="headerGroup.id"
                    >
                        <th
                            v-for="header in headerGroup.headers"
                            :key="header.id"
                            :class="{
                                sortable: header.column.getCanSort(),
                                numeric: ['articles_count', 'comments_count'].includes(header.column.id),
                                'comments-column': header.column.id === 'comments_count',
                                actions: header.column.id === 'actions',
                            }"
                        >
                            <button
                                v-if="header.column.getCanSort()"
                                type="button"
                                class="editorial-sort-button"
                                @click="header.column.getToggleSortingHandler()?.($event)"
                            >
                                <FlexRender :header="header" />
                                <span class="sort-indicator" aria-hidden="true">
                                    {{ header.column.getIsSorted() === 'asc' ? '▲' : header.column.getIsSorted() === 'desc' ? '▼' : '↕' }}
                                </span>
                            </button>

                            <FlexRender
                                v-else-if="!header.isPlaceholder"
                                :header="header"
                            />
                        </th>
                    </tr>
                </thead>

                <tbody>
                    <tr
                        v-for="row in table.getRowModel().rows"
                        :key="row.id"
                        class="editorial-table-row"
                        :class="{ 'editorial-table-row--account-clickable': true }"
                        @click="openEdit(row.original)"
                    >
                        <td
                            v-for="cell in row.getAllCells()"
                            :key="cell.id"
                            :class="{
                                numeric: ['articles_count', 'comments_count'].includes(cell.column.id),
                                'comments-column': cell.column.id === 'comments_count',
                                'admin-numeric-value-centered': cell.column.id === 'articles_count',
                                actions: cell.column.id === 'actions',
                            }"
                        >
                            <FlexRender :cell="cell" />
                        </td>
                    </tr>

                    <tr v-if="!table.getRowModel().rows.length">
                        <td
                            :colspan="columns.length"
                            class="empty-state editorial-empty-state"
                        >
                            No se encontraron cuentas.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- La cantidad 25/50/100 se administra únicamente desde esta barra inferior. -->
    <AdminPagination
        v-if="!loadError && users?.pagination"
        :pagination="users.pagination"
        :per-page="filter.per_page"
        :per-page-options="[25, 50, 100]"
        :item-label="paginationItemLabel"
        @update:per-page="changePerPage"
    />

    <!-- Bloqueo de usuario registrado. El click sobre el fondo no cierra la modal. -->
    <div
        v-if="blockTarget"
        class="admin-modal-backdrop"
    >
        <form
            class="admin-modal admin-modal-refined reader-block-modal"
            @submit.prevent="blockReader"
        >
            <header>
                <div>
                    <p class="eyebrow">Seguridad</p>
                    <h2>Bloquear usuario</h2>
                    <p class="admin-modal-subtitle">
                        {{ blockTarget.name }} · {{ blockTarget.email }}
                    </p>
                </div>

                <button
                    type="button"
                    class="admin-modal-close"
                    :disabled="readerBlockBusy"
                    @click="closeBlockModal"
                >
                    <X />
                </button>
            </header>

            <div class="admin-info-box">
                <strong>La cuenta dejará de poder participar.</strong>
                <p>
                    No podrá iniciar sesión, comentar, responder, dar Me gusta ni
                    reportar mientras permanezca bloqueada.
                </p>
                <small>
                    El contenido histórico aprobado no será eliminado ni mostrará
                    públicamente que la cuenta está bloqueada.
                </small>
            </div>

            <div
                v-if="hasPendingModerationReview(blockTarget)"
                class="admin-info-box admin-review-context-box"
            >
                <strong>Contexto de moderación</strong>
                <p>
                    {{ reviewerLabel(blockTarget) }} solicitó revisar esta cuenta el
                    {{ formatDetailDate(blockTarget.moderation_review_requested_at) }}.
                </p>
                <p>
                    Motivo: <b>{{ moderationReviewReasonLabel(blockTarget) }}</b>
                </p>
                <p v-if="blockTarget.moderation_review_note">
                    Observación: {{ blockTarget.moderation_review_note }}
                </p>
                <div
                    v-if="blockTarget.moderation_review_comment?.body"
                    class="admin-review-evidence"
                >
                    <small>Comentario usado como evidencia</small>
                    <p>{{ blockTarget.moderation_review_comment.body }}</p>
                    <small v-if="blockTarget.moderation_review_comment.article_title">
                        Noticia: {{ blockTarget.moderation_review_comment.article_title }}
                    </small>
                </div>
            </div>

            <p
                v-if="blockForm.errors.operation"
                class="operation-error"
                role="alert"
            >
                {{ blockForm.errors.operation }}
            </p>

            <label>
                Motivo
                <select
                    v-model="blockForm.blocked_reason"
                    required
                    :disabled="blockForm.processing"
                >
                    <option value="">Seleccionar motivo</option>
                    <option value="moderation">Revisión de moderación</option>
                    <option value="abuse">Abuso o acoso</option>
                    <option value="spam">Spam</option>
                    <option value="security">Seguridad de la cuenta</option>
                    <option value="terms">Incumplimiento de términos</option>
                    <option value="other">Otro</option>
                </select>
                <span
                    v-if="blockForm.errors.blocked_reason"
                    class="form-error"
                >
                    {{ blockForm.errors.blocked_reason }}
                </span>
            </label>

            <label>
                Observación interna
                <textarea
                    v-model="blockForm.blocked_note"
                    rows="3"
                    :maxlength="BLOCK_NOTE_MAX"
                    :disabled="blockForm.processing"
                    placeholder="Contexto para administración y moderación"
                    @keydown.enter.prevent
                    @input="handleBlockNoteInput"
                />
                <small class="admin-character-counter">
                    {{ blockNoteCounter() }}
                </small>
                <span
                    v-if="blockForm.errors.blocked_note"
                    class="form-error"
                >
                    {{ blockForm.errors.blocked_note }}
                </span>
            </label>

            <footer class="admin-modal-footer">
                <button
                    type="button"
                    class="admin-secondary-button"
                    :disabled="readerBlockBusy"
                    @click="closeBlockModal"
                >
                    Cancelar
                </button>

                <button
                    type="submit"
                    class="admin-gold-button"
                    :disabled="!canSubmitReaderBlock"
                >
                    {{ readerBlockBusy ? 'Bloqueando...' : 'Bloquear cuenta' }}
                </button>
            </footer>
        </form>
    </div>

    <!-- Alta de empleado. El click sobre el fondo no cierra la modal. -->
    <div
        v-if="createOpen"
        class="admin-modal-backdrop"
    >
        <form
            class="admin-modal wide admin-modal-refined employee-create-modal"
            @submit.prevent="createEmployee"
        >
            <header>
                <div>
                    <p class="eyebrow">Acceso interno</p>
                    <h2>Nuevo empleado</h2>
                    <p class="admin-modal-subtitle">
                        Creá una cuenta para el equipo de TRAMA.
                    </p>
                </div>

                <button
                    type="button"
                    class="admin-modal-close"
                    :disabled="createEmployeeBusy"
                    @click="closeCreateModal"
                >
                    <X />
                </button>
            </header>

            <p
                v-if="createForm.errors.operation"
                class="operation-error"
                role="alert"
            >
                {{ createForm.errors.operation }}
            </p>

            <label class="admin-avatar-upload">
                <span class="admin-avatar-placeholder">
                    <img
                        :src="'/images/brand/avatar-default.webp'"
                        alt="Avatar por defecto"
                    />
                </span>

                <span>
                    <strong>Foto del empleado</strong>
                    <small>JPG, PNG o WEBP · hasta 4 MB · mínimo 600 × 600 px; TRAMA recorta y optimiza automáticamente.</small>
                    <input
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        :disabled="createForm.processing"
                        @change="handleEmployeeAvatarChange($event, createForm)"
                    />
                    <span
                        v-if="createForm.errors.avatar_file"
                        class="form-error"
                    >
                        {{ createForm.errors.avatar_file }}
                    </span>
                </span>
            </label>

            <div class="form-two-columns">
                <label>
                    Nombre
                    <input
                        v-model="createForm.name"
                        required
                        autocomplete="off"
                        :minlength="EMPLOYEE_NAME_MIN"
                        :maxlength="EMPLOYEE_NAME_MAX"
                        :disabled="createForm.processing"
                    />
                    <span v-if="createForm.errors.name" class="form-error">
                        {{ createForm.errors.name }}
                    </span>
                </label>

                <label>
                    Correo
                    <span class="employee-email-field">
                        <input
                            v-model.trim="createForm.email_local"
                            type="text"
                            required
                            autocomplete="off"
                            :maxlength="EMPLOYEE_EMAIL_LOCAL_MAX"
                            :disabled="createForm.processing"
                            placeholder="valentina.fernandez"
                        />
                        <span class="employee-email-field__domain">@trama.test</span>
                    </span>
                    <span v-if="createForm.errors.email_local || createForm.errors.email" class="form-error">
                        {{ createForm.errors.email_local || createForm.errors.email }}
                    </span>
                </label>
            </div>

            <div class="form-two-columns">
                <label>
                    Rol
                    <select
                        v-model="createForm.role"
                        required
                        :disabled="createForm.processing"
                        @change="handleEmployeeRoleChange(createForm)"
                    >
                        <option
                            v-for="role in employeeRoles"
                            :key="role.value"
                            :value="role.value"
                        >
                            {{ role.label }}
                        </option>
                    </select>
                    <span v-if="createForm.errors.role" class="form-error">
                        {{ createForm.errors.role }}
                    </span>
                </label>

                <label>
                    Cargo
                    <input
                        v-model="createForm.job_title"
                        required
                        autocomplete="off"
                        :maxlength="EMPLOYEE_JOB_TITLE_MAX"
                        :disabled="createForm.processing"
                    />
                    <span v-if="createForm.errors.job_title" class="form-error">
                        {{ createForm.errors.job_title }}
                    </span>
                </label>
            </div>

            <label class="employee-bio-field">
                <span>Biografía</span>
                <textarea
                    :value="createForm.bio"
                    class="employee-bio-textarea"
                    rows="5"
                    :required="createForm.role !== 'admin'"
                    :maxlength="EMPLOYEE_BIO_MAX"
                    :disabled="createForm.processing || createForm.role === 'admin'"
                    @keydown.enter.prevent
                    @input="updateBiography(createForm, $event.target.value)"
                />
                <small
                    v-if="createForm.role !== 'admin'"
                    class="employee-bio-counter"
                >
                    {{ employeeBioCounter(createBioLength) }}
                </small>
                <small
                    v-else
                    class="employee-bio-disabled-note"
                >
                    La biografía se utiliza solo para perfiles editoriales.
                </small>
                <span v-if="createForm.errors.bio" class="form-error">
                    {{ createForm.errors.bio }}
                </span>
            </label>

            <div class="form-two-columns">
                <label>
                    Contraseña inicial
                    <input
                        v-model="createForm.password"
                        type="password"
                        required
                        autocomplete="new-password"
                        :minlength="EMPLOYEE_PASSWORD_MIN"
                        :maxlength="EMPLOYEE_PASSWORD_MAX"
                        :disabled="createForm.processing"
                    />
                    <span v-if="createForm.errors.password" class="form-error">
                        {{ createForm.errors.password }}
                    </span>
                </label>

                <label>
                    Confirmar contraseña
                    <input
                        v-model="createForm.password_confirmation"
                        type="password"
                        required
                        autocomplete="new-password"
                        :minlength="EMPLOYEE_PASSWORD_MIN"
                        :maxlength="EMPLOYEE_PASSWORD_MAX"
                        :disabled="createForm.processing"
                    />
                </label>
            </div>

            <footer class="admin-modal-footer">
                <button
                    type="button"
                    class="admin-secondary-button"
                    :disabled="createEmployeeBusy"
                    @click="closeCreateModal"
                >
                    Cancelar
                </button>

                <button
                    type="submit"
                    class="admin-gold-button employee-create-submit-button"
                    :disabled="createEmployeeBusy || !canSubmitCreateEmployee"
                >
                    {{ createEmployeeBusy ? 'Creando...' : 'Crear empleado' }}
                </button>
            </footer>
        </form>
    </div>

    <!-- Edición de empleado. No se cierra haciendo click fuera. -->
    <div
        v-if="selected && isEmployees"
        class="admin-modal-backdrop"
    >
        <form
            class="admin-modal wide admin-modal-refined"
            @submit.prevent="updateUser"
        >
            <header>
                <div>
                    <p class="eyebrow">Acceso interno</p>
                    <h2>Editar empleado</h2>
                    <p class="admin-modal-subtitle">{{ selected.email }}</p>
                </div>

                <button
                    type="button"
                    class="admin-modal-close"
                    :disabled="editEmployeeBusy"
                    @click="closeSelectedModal"
                >
                    <X />
                </button>
            </header>

            <p
                v-if="editForm.errors.operation"
                class="operation-error"
                role="alert"
            >
                {{ editForm.errors.operation }}
            </p>

            <label class="admin-avatar-upload">
                <span class="admin-avatar-placeholder">
                    <img
                        :src="selected.avatar_url || '/images/brand/avatar-default.webp'"
                        :alt="selected.name"
                    />
                </span>

                <span>
                    <strong>Foto del empleado</strong>
                    <small>Elegí un archivo solo si querés reemplazar la imagen actual · mínimo 600 × 600 px; TRAMA recorta y optimiza automáticamente.</small>
                    <input
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        :disabled="editForm.processing"
                        @change="handleEmployeeAvatarChange($event, editForm)"
                    />
                    <span v-if="editForm.errors.avatar_file" class="form-error">
                        {{ editForm.errors.avatar_file }}
                    </span>
                </span>
            </label>

            <div class="form-two-columns">
                <label>
                    Nombre
                    <input
                        v-model="editForm.name"
                        required
                        autocomplete="off"
                        :minlength="EMPLOYEE_NAME_MIN"
                        :maxlength="EMPLOYEE_NAME_MAX"
                        :disabled="editForm.processing"
                    />
                    <span v-if="editForm.errors.name" class="form-error">
                        {{ editForm.errors.name }}
                    </span>
                </label>

                <label>
                    Correo
                    <span class="employee-email-field">
                        <input
                            v-model.trim="editForm.email_local"
                            type="text"
                            required
                            autocomplete="off"
                            :maxlength="EMPLOYEE_EMAIL_LOCAL_MAX"
                            :disabled="editForm.processing"
                        />
                        <span class="employee-email-field__domain">@trama.test</span>
                    </span>
                    <span v-if="editForm.errors.email_local || editForm.errors.email" class="form-error">
                        {{ editForm.errors.email_local || editForm.errors.email }}
                    </span>
                </label>
            </div>

            <div class="form-two-columns">
                <label>
                    Rol
                    <select
                        v-model="editForm.role"
                        required
                        :disabled="editForm.processing"
                        @change="handleEmployeeRoleChange(editForm)"
                    >
                        <option
                            v-for="role in employeeRoles"
                            :key="role.value"
                            :value="role.value"
                        >
                            {{ role.label }}
                        </option>
                    </select>
                    <span v-if="editForm.errors.role" class="form-error">
                        {{ editForm.errors.role }}
                    </span>
                </label>

                <label>
                    Cargo
                    <input
                        v-model="editForm.job_title"
                        required
                        autocomplete="off"
                        :maxlength="EMPLOYEE_JOB_TITLE_MAX"
                        :disabled="editForm.processing"
                    />
                    <span v-if="editForm.errors.job_title" class="form-error">
                        {{ editForm.errors.job_title }}
                    </span>
                </label>
            </div>

            <label class="employee-bio-field">
                <span>Biografía</span>
                <textarea
                    :value="editForm.bio"
                    class="employee-bio-textarea"
                    rows="5"
                    :required="editForm.role !== 'admin'"
                    :maxlength="EMPLOYEE_BIO_MAX"
                    :disabled="editForm.processing || editForm.role === 'admin'"
                    @keydown.enter.prevent
                    @input="updateBiography(editForm, $event.target.value)"
                />
                <small
                    v-if="editForm.role !== 'admin'"
                    class="employee-bio-counter"
                >
                    {{ employeeBioCounter(editBioLength) }}
                </small>
                <small
                    v-else
                    class="employee-bio-disabled-note"
                >
                    La biografía se utiliza solo para perfiles editoriales.
                </small>
                <span v-if="editForm.errors.bio" class="form-error">
                    {{ editForm.errors.bio }}
                </span>
            </label>

            <footer class="admin-modal-footer">
                <button
                    type="button"
                    class="admin-secondary-button"
                    :disabled="editEmployeeBusy"
                    @click="closeSelectedModal"
                >
                    Cancelar
                </button>

                <button
                    type="submit"
                    class="admin-gold-button"
                    :disabled="editEmployeeBusy || !canSubmitEditEmployee"
                >
                    {{ editEmployeeBusy ? 'Guardando...' : 'Guardar cambios' }}
                </button>
            </footer>
        </form>
    </div>

    <!-- Usuarios registrados: detalle de solo lectura, sin edición de identidad. -->
    <div
        v-if="selected && !isEmployees"
        class="admin-modal-backdrop"
    >
        <section
            class="admin-modal wide admin-modal-refined"
            role="dialog"
            aria-modal="true"
            aria-labelledby="reader-detail-title"
        >
            <header>
                <div>
                    <p class="eyebrow">Cuenta registrada</p>
                    <h2 id="reader-detail-title">{{ selected.name }}</h2>
                    <p class="admin-modal-subtitle">{{ selected.email }}</p>
                </div>

                <button
                    type="button"
                    class="admin-modal-close"
                    @click="closeSelectedModal"
                >
                    <X />
                </button>
            </header>

            <section class="admin-account-activity">
                <h3>Actividad</h3>
                <div>
                    <span>
                        <small>Comentarios</small>
                        <strong>{{ selected.comments_count }}</strong>
                    </span>
                    <span>
                        <small>Último acceso</small>
                        <strong class="reader-detail-date">{{ formatDetailDate(selected.last_login_at) }}</strong>
                    </span>
                    <span>
                        <small>Estado</small>
                        <strong>{{ selected.is_active ? 'Activa' : 'Bloqueada' }}</strong>
                    </span>
                    <span>
                        <small>Registrado</small>
                        <strong class="reader-detail-date">{{ formatDetailDate(selected.created_at) }}</strong>
                    </span>
                </div>
            </section>

            <div
                v-if="selected.moderation_review_requested_at"
                class="admin-info-box admin-review-context-box"
            >
                <strong>
                    {{ hasPendingModerationReview(selected)
                        ? 'Revisión solicitada por Moderación'
                        : (selected.moderation_review_resolution === 'blocked'
                            ? 'Revisión administrativa resuelta'
                            : 'Revisión administrativa cerrada') }}
                </strong>
                <p>
                    {{ reviewerLabel(selected) }} solicitó revisar esta cuenta el
                    {{ formatDetailDate(selected.moderation_review_requested_at) }}.
                </p>
                <p>
                    Motivo: <b>{{ moderationReviewReasonLabel(selected) }}</b>
                </p>
                <p v-if="selected.moderation_review_note">
                    Observación: {{ selected.moderation_review_note }}
                </p>
                <p v-if="hasResolvedModerationReview(selected)">
                    Resultado: <b>{{ moderationReviewResolutionLabel(selected) }}</b>
                </p>
                <small v-if="hasResolvedModerationReview(selected)">
                    {{ reviewResolverLabel(selected) }} resolvió la revisión el
                    {{ formatDetailDate(selected.moderation_review_resolved_at) }}.
                </small>
                <div
                    v-if="selected.moderation_review_comment?.body"
                    class="admin-review-evidence"
                >
                    <small>Comentario usado como evidencia</small>
                    <p>{{ selected.moderation_review_comment.body }}</p>
                    <small v-if="selected.moderation_review_comment.article_title">
                        Noticia: {{ selected.moderation_review_comment.article_title }}
                    </small>
                </div>
            </div>

            <div
                v-if="!selected.is_active"
                class="admin-info-box admin-info-box--danger"
            >
                <strong>Cuenta bloqueada</strong>
                <p>Motivo: {{ blockReasonLabel(selected.blocked_reason) }}</p>
                <p v-if="selected.blocked_note">
                    Observación: {{ selected.blocked_note }}
                </p>
                <small>
                    Bloqueada desde {{ formatDetailDate(selected.disabled_at) }}.
                </small>
            </div>

            <footer class="admin-modal-footer">
                <button
                    type="button"
                    class="admin-secondary-button"
                    @click="closeSelectedModal"
                >
                    Cerrar
                </button>

                <button
                    v-if="hasPendingModerationReview(selected) && selected.is_active"
                    type="button"
                    class="admin-primary-action user-review-resolve-button"
                    @click="openResolveReviewConfirmation(selected)"
                >
                    Resolver revisión
                </button>
            </footer>
        </section>
    </div>
    <!-- Confirmación reutilizable para acceso, revisión administrativa y eliminación. -->
    <ConfirmActionModal
        :open="confirmation !== null"
        :eyebrow="confirmation?.eyebrow || 'Confirmación'"
        :title="confirmation?.title || ''"
        :message="confirmation?.message || ''"
        :help="confirmation?.help || ''"
        :error="confirmationError"
        :confirm-label="confirmation?.confirmLabel || 'Confirmar'"
        :processing-label="confirmation?.processingLabel || 'Procesando...'"
        :confirm-button-class="confirmation?.confirmButtonClass || ''"
        :processing="confirmationProcessing"
        :danger="confirmation?.danger || false"
        @confirm="confirmUserAction"
        @cancel="closeConfirmation"
    />


</template>

<style src="../../../../css/admin/users.css"></style>
