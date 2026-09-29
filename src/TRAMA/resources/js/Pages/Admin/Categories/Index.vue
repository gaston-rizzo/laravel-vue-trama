<script setup>
/* ============================================================================
 * PAGE: Admin/Categories/Index.vue
 * ============================================================================
 *
 * Administración de categorías editoriales.
 *
 * Laravel resuelve búsqueda, estado, ordenamiento y paginación. La pantalla
 * permite crear, editar, activar, desactivar y eliminar categorías sin uso.
 *
 * Una categoría ACTIVA queda disponible para trabajar editorialmente, pero no
 * aparece en el portal hasta tener al menos una noticia publicada. En ese momento
 * pasa a mostrarse como PUBLICADA y participa del orden editorial. Una categoría
 * INACTIVA queda retirada del flujo nuevo y del sitio público sin borrar historial.
 * ============================================================================ */

import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, h, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { route } from 'ziggy-js';
import { FolderPlus, Image as ImageIcon, Pencil, Power, Search, Trash2, X } from '@lucide/vue';
import { FlexRender, rowSortingFeature, tableFeatures, useTable } from '@tanstack/vue-table';
import AdminPagination from '@/Components/Admin/AdminPagination.vue';
import ConfirmActionModal from '@/Components/Shared/ConfirmActionModal.vue';

// Props de administración de categorías.
const props = defineProps({
    // Página actual y metadatos de paginación enviados por Laravel.
    categories: {
        type: Object,
        default: () => ({
            data: [],
            pagination: {
                links: [],
            },
        }),
    },

    // Búsqueda, estado, orden y cantidad de registros actualmente aplicados.
    filters: {
        type: Object,
        default: () => ({}),
    },

    // Cantidad actual de categorías publicadas en el portal.
    publishedCount: {
        type: Number,
        default: 0,
    },

    // Máximo de categorías públicas permitido por TRAMA.
    publishedLimit: {
        type: Number,
        default: 12,
    },

    // Cantidad de categorías que participan actualmente del orden público.
    editorialPositionCount: {
        type: Number,
        default: 0,
    },

    // Mensaje mostrado cuando Laravel no pudo cargar la grilla.
    loadError: {
        type: String,
        default: '',
    },
});

/* ============================================================================
 * LÍMITES Y FILTROS
 * ============================================================================ */

// Límites compartidos con CategoryRequest.php.
const CATEGORY_NAME_MIN = 3;
const CATEGORY_NAME_MAX = 30;
const CATEGORY_DESCRIPTION_MIN = 20;
const CATEGORY_DESCRIPTION_MAX = 120;

// Cantidad máxima de caracteres del nombre que se muestran
// dentro del título del modal de eliminación.
// Esto no modifica el nombre real de la categoría.
const DELETE_CATEGORY_NAME_PREVIEW_LENGTH = 23;

/*
 * Acorta solamente la representación visual del nombre.
 *
 * Ejemplo:
 * "Economía internacional"
 * pasa a mostrarse como:
 * "Economía internacional"
 *
 * Si supera el límite visual:
 * "Política económica internacional"
 * pasa a mostrarse como:
 * "Política económica inte…"
 */
function deleteCategoryDisplayName(name) {
    const characters = Array.from(
        String(name ?? '').trim()
    );

    if (characters.length <= DELETE_CATEGORY_NAME_PREVIEW_LENGTH) {
        return characters.join('');
    }

    return `${characters
        .slice(0, DELETE_CATEGORY_NAME_PREVIEW_LENGTH)
        .join('')}…`;
}

const CATEGORY_IMAGE_MAX_BYTES = 5 * 1024 * 1024;
const CATEGORY_IMAGE_MIN_WIDTH = 1600;
const CATEGORY_IMAGE_MIN_HEIGHT = 900;
const ACCEPTED_CATEGORY_IMAGE_TYPES = new Set([
    'image/jpeg',
    'image/png',
    'image/webp',
]);

// Estado local de los filtros de la grilla.
const filter = reactive({
    q: props.filters?.q || '',
    status: props.filters?.status || '',
    sort: props.filters?.sort || 'sort_order',
    direction: props.filters?.direction || 'asc',
    per_page: Number(props.filters?.per_page || 25),
});

// Texto escrito y búsqueda que Laravel ya tiene aplicada.
const normalizedSearch = computed(() => String(filter.q || '').trim());
const appliedSearch = computed(() => String(props.filters?.q || '').trim());

// Buscar se habilita al escribir al menos dos caracteres nuevos o al vaciar
// una búsqueda que ya se encontraba aplicada.
const canApplySearch = computed(() => {
    if (normalizedSearch.value === '' && appliedSearch.value !== '') {
        return true;
    }

    return normalizedSearch.value.length >= 2
        && normalizedSearch.value !== appliedSearch.value;
});

// Limpiar afecta búsqueda y estado, pero conserva orden y cantidad por página.
const hasActiveFilters = computed(() => Boolean(
    normalizedSearch.value || filter.status
));

// Texto visible del contador de secciones que ya participan del portal público.
const publishedCounter = computed(() =>
    `${props.publishedCount} de ${props.publishedLimit} categorías publicadas`
);

/* ============================================================================
 * ESTADO DE MODALES Y FORMULARIOS
 * ============================================================================ */

// Categoría seleccionada para edición y fotografía de sus valores originales.
const editing = ref(null);
const editInitial = ref(null);

// Control del modal de alta.
const createOpen = ref(false);
const createSubmitting = ref(false);
const editSubmitting = ref(false);

// Previsualizaciones de la imagen elegida en alta y edición.
const createImagePreview = ref('');
const editImagePreview = ref('');

// Confirmación de eliminación.
const deleteTarget = ref(null);
const deleteProcessing = ref(false);
const deleteError = ref('');

// Error general de acciones ejecutadas desde la grilla.
const operationError = ref('');

// Las categorías nuevas se crean activas en backend; el modal no expone ese estado.
const createForm = useForm({
    name: '',
    description: '',
    accent_color: '#D6A23A',
    cover_image_file: null,
});

// La edición modifica datos públicos. El estado activo se administra desde la grilla.
const editForm = useForm({
    name: '',
    description: '',
    accent_color: '#D6A23A',
    sort_order: null,
    cover_image_file: null,
});

// Estados de procesamiento utilizados para bloquear cierres y dobles envíos.
const createCategoryBusy = computed(() =>
    createForm.processing || createSubmitting.value
);
const editCategoryBusy = computed(() =>
    editForm.processing || editSubmitting.value
);

// Contadores de la descripción. Se usa texto real sin espacios sobrantes.
const createDescriptionLength = computed(() =>
    String(createForm.description || '').trim().length
);
const editDescriptionLength = computed(() =>
    String(editForm.description || '').trim().length
);

// El orden editorial existe únicamente para categorías visibles en el portal.
const editingHasEditorialOrder = computed(() => Boolean(
    editing.value?.is_publicly_visible
));

// Posiciones disponibles para recolocar una categoría pública sin duplicados.
const editorialPositionOptions = computed(() => Array.from(
    { length: Number(props.editorialPositionCount || 0) },
    (_, index) => index + 1,
));

// Detecta cambios reales respecto de la categoría abierta.
const editCategoryChanged = computed(() => {
    if (!editing.value || !editInitial.value) {
        return false;
    }

    const currentPosition = editForm.sort_order === null
        ? null
        : Number(editForm.sort_order);

    return editForm.cover_image_file !== null
        || editForm.name !== editInitial.value.name
        || editForm.description !== editInitial.value.description
        || editForm.accent_color !== editInitial.value.accent_color
        || currentPosition !== editInitial.value.sort_order;
});

// El alta requiere todos los datos válidos y una imagen nueva.
const canSubmitCreateCategory = computed(() => (
    validCategoryData(createForm, Boolean(createForm.cover_image_file))
    && !createCategoryBusy.value
));

// En edición sirve la imagen existente salvo que la categoría histórica no tenga una.
const canSubmitEditCategory = computed(() => {
    const hasCover = Boolean(
        editForm.cover_image_file
        || editing.value?.cover_image_url
    );

    const validPosition = !editingHasEditorialOrder.value
        || editorialPositionOptions.value.includes(Number(editForm.sort_order));

    return validCategoryData(editForm, hasCover)
        && validPosition
        && editCategoryChanged.value
        && !editCategoryBusy.value;
});

/* ============================================================================
 * TABLA TANSTACK
 * ============================================================================
 *
 * TanStack representa únicamente la página recibida. Laravel mantiene el
 * ordenamiento real sobre todo el conjunto antes de paginar.
 * ============================================================================ */

const features = tableFeatures({
    rowSortingFeature,
});

// Las cuatro columnas de consulta admiten ordenamiento servidor.
// Orden editorial organiza la vista, pero la posición se modifica solo desde Editar.
const sortableColumnIds = new Set([
    'sort_order',
    'name',
    'published_articles_count',
    'status',
]);
const initialSort = props.filters?.sort || 'sort_order';
const sorting = ref(
    sortableColumnIds.has(initialSort)
        ? [{
            id: initialSort,
            desc: (props.filters?.direction || 'asc') === 'desc',
        }]
        : []
);

// Filas de la página actual.
const categoryRows = computed(() => props.categories?.data || []);

// Columnas visibles de la grilla.
const columns = [
    {
        id: 'sort_order',
        accessorKey: 'sort_order',
        header: 'Orden',
        enableSorting: true,
        cell: (info) => {
            const value = info.getValue();

            return h(
                'strong',
                {
                    class: 'category-order-cell',
                    title: value === null
                        ? 'Sin orden editorial: todavía no participa del portal.'
                        : `Orden editorial #${value}`,
                },
                value === null ? '—' : `#${value}`,
            );
        },
    },
    {
        id: 'name',
        accessorKey: 'name',
        header: 'Categoría',
        enableSorting: true,
        cell: (info) => {
            const category = info.row.original;

            const visual = category.cover_image_url
                ? h('img', {
                    src: category.cover_image_url,
                    alt: category.name,
                    class: 'category-admin-thumb',
                })
                : h('span', {
                    class: 'category-color',
                    style: { backgroundColor: category.accent_color },
                    'aria-hidden': 'true',
                });

            return h('div', { class: 'category-name-cell admin-category-name-cell' }, [
                visual,
                h('div', { class: 'admin-category-name-copy' }, [
                    h(
                        'strong',
                        {
                            class: 'admin-category-name-text',
                            title: category.name,
                        },
                        category.name,
                    ),
                    h(
                        'small',
                        {
                            class: 'admin-category-slug-text',
                            title: `/seccion/${category.slug}`,
                        },
                        `/seccion/${category.slug}`,
                    ),
                ]),
            ]);
        },
    },
    {
        id: 'description',
        accessorFn: (category) => category.description || 'Sin descripción',
        header: 'Descripción',
        enableSorting: false,
        cell: (info) => {
            const description = String(info.getValue() || 'Sin descripción');

            return h(
                'span',
                {
                    class: 'admin-category-description-text',
                    title: description,
                },
                description,
            );
        },
    },
    {
        id: 'published_articles_count',
        accessorKey: 'published_articles_count',
        header: 'Publicadas',
        enableSorting: true,
        cell: (info) => h('strong', String(info.getValue() ?? 0)),
    },
    {
        id: 'status',
        accessorKey: 'status',
        header: 'Estado',
        enableSorting: true,
        cell: (info) => {
            const category = info.row.original;
            const labels = {
                published: 'Publicada',
                active: 'Activa',
                inactive: 'Inactiva',
            };
            const titles = {
                published: 'Publicada: está activa, tiene noticias publicadas y aparece en el portal.',
                active: 'Activa: disponible para uso editorial, pero todavía no aparece en el portal.',
                inactive: 'Inactiva: no se ofrece para contenido nuevo y no aparece en el portal.',
            };
            const classes = {
                published: 'status-published',
                active: 'status-review',
                inactive: 'status-archived',
            };

            return h(
                'span',
                {
                    class: [
                        'status-badge',
                        classes[category.status] || 'status-archived',
                    ],
                    title: titles[category.status] || titles.inactive,
                },
                labels[category.status] || 'Inactiva',
            );
        },
    },
    {
        id: 'actions',
        header: 'Acciones',
        enableSorting: false,
        cell: (info) => categoryActionCell(info.row.original),
    },
];

// Instancia TanStack en modo manual para impedir ordenamientos parciales en navegador.
const table = useTable({
    key: 'admin-categories-table',
    features,
    columns,
    data: categoryRows,
    getRowId: (row) => String(row.id),
    manualSorting: true,
    enableMultiSort: false,
    enableSortingRemoval: false,
    state: {
        get sorting() {
            return sorting.value;
        },
    },
    onSortingChange: changeSorting,
});

/* ============================================================================
 * FILTROS, ORDEN Y PAGINACIÓN
 * ============================================================================ */

// Construye la consulta sin enviar parámetros vacíos.
function buildQuery(overrides = {}) {
    const query = {
        q: appliedSearch.value || undefined,
        status: filter.status || undefined,
        sort: filter.sort || 'sort_order',
        direction: filter.direction || 'asc',
        per_page: Number(filter.per_page || 25),
        ...overrides,
    };

    return Object.fromEntries(
        Object.entries(query).filter(([, value]) => (
            value !== undefined
            && value !== null
            && value !== ''
        )),
    );
}

// Navega por la misma grilla conservando exactamente la posición de scroll.
function visitList(overrides = {}) {
    const scrollX = window.scrollX;
    const scrollY = window.scrollY;

    router.get(
        route('admin.categories.index'),
        buildQuery(overrides),
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
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

// Aplica la búsqueda únicamente al presionar Buscar.
function applySearch() {
    if (!canApplySearch.value) {
        return;
    }

    visitList({
        q: normalizedSearch.value || undefined,
        page: undefined,
    });
}

// El estado se aplica inmediatamente al cambiar el combo.
function changeStatus() {
    visitList({
        status: filter.status || undefined,
        page: undefined,
    });
}

// Limpia búsqueda y estado sin modificar ordenamiento ni cantidad por página.
function resetFilters() {
    if (!hasActiveFilters.value) {
        return;
    }

    filter.q = '';
    filter.status = '';

    visitList({
        q: undefined,
        status: undefined,
        page: undefined,
    });
}

// AdminPagination entrega la nueva cantidad elegida en la barra inferior.
function changePerPage(value) {
    filter.per_page = Number(value);

    visitList({
        per_page: filter.per_page,
        page: undefined,
    });
}

// Convierte el cambio visual de TanStack a sort/direction comprendidos por Laravel.
function changeSorting(updater) {
    const nextSorting = typeof updater === 'function'
        ? updater(sorting.value)
        : updater;

    const selected = nextSorting[0] || {
        id: 'name',
        desc: false,
    };

    sorting.value = [selected];
    filter.sort = selected.id;
    filter.direction = selected.desc ? 'desc' : 'asc';

    visitList({
        sort: filter.sort,
        direction: filter.direction,
        page: undefined,
    });
}

/* ============================================================================
 * VALIDACIÓN PREVENTIVA DE FORMULARIOS
 * ============================================================================ */

// Comprueba los datos obligatorios antes de habilitar los botones de guardado.
function validCategoryData(form, hasCover) {
    const nameLength = String(form.name || '').trim().length;
    const descriptionLength = String(form.description || '').trim().length;

    return nameLength >= CATEGORY_NAME_MIN
        && nameLength <= CATEGORY_NAME_MAX
        && descriptionLength >= CATEGORY_DESCRIPTION_MIN
        && descriptionLength <= CATEGORY_DESCRIPTION_MAX
        && /^#[0-9A-Fa-f]{6}$/.test(String(form.accent_color || ''))
        && hasCover;
}

// Reduce un campo breve a una única línea antes de enviarlo.
function normalizeSingleLine(value) {
    return String(value || '')
        .replace(/\s+/g, ' ')
        .trim();
}

// La descripción puede escribirse normalmente, pero nunca conserva saltos manuales.
function normalizeDescriptionInput(value) {
    return String(value || '')
        .replace(/\s+/g, ' ');
}

// Actualiza la descripción mientras se escribe o pega contenido con saltos.
function updateDescription(form, value) {
    form.description = normalizeDescriptionInput(value);
}

// Normaliza los campos textuales inmediatamente antes de guardar.
function normalizeCategoryForm(form) {
    form.name = normalizeSingleLine(form.name);
    form.description = normalizeSingleLine(form.description);
}

// Construye el contador dinámico usado por Crear y Editar.
function descriptionCounter(length) {
    if (length < CATEGORY_DESCRIPTION_MIN) {
        const remaining = CATEGORY_DESCRIPTION_MIN - length;
        const verb = remaining === 1 ? 'falta' : 'faltan';
        const unit = remaining === 1 ? 'carácter' : 'caracteres';

        return `${length}/${CATEGORY_DESCRIPTION_MAX} · ${verb} ${remaining} ${unit}`;
    }

    return `${length}/${CATEGORY_DESCRIPTION_MAX}`;
}

// Lee las dimensiones de un archivo sin subirlo al servidor.
function getImageDimensions(file) {
    return new Promise((resolve, reject) => {
        const temporaryUrl = URL.createObjectURL(file);
        const image = new Image();

        image.onload = () => {
            const dimensions = {
                width: image.naturalWidth,
                height: image.naturalHeight,
            };

            URL.revokeObjectURL(temporaryUrl);
            resolve(dimensions);
        };

        image.onerror = () => {
            URL.revokeObjectURL(temporaryUrl);
            reject(new Error('No se pudieron leer las dimensiones de la imagen.'));
        };

        image.src = temporaryUrl;
    });
}

// Libera únicamente URLs temporales creadas por el navegador.
function releaseImagePreview(preview) {
    if (String(preview.value || '').startsWith('blob:')) {
        URL.revokeObjectURL(preview.value);
    }

    preview.value = '';
}

// Valida tipo, peso y dimensiones antes de enviar la imagen a Laravel.
// El filtro sexual/gore se ejecuta nuevamente en backend mediante SafeImageProcessor.
async function handleCategoryImageChange(event, form, preview, fallbackPreview = '') {
    const input = event.currentTarget;
    const file = input?.files?.[0] || null;

    form.clearErrors('cover_image_file');

    if (!file) {
        return;
    }

    if (!ACCEPTED_CATEGORY_IMAGE_TYPES.has(file.type)) {
        input.value = '';
        form.cover_image_file = null;
        releaseImagePreview(preview);
        preview.value = fallbackPreview;
        form.setError(
            'cover_image_file',
            'La imagen debe ser JPG, JPEG, PNG o WEBP.',
        );
        return;
    }

    if (file.size > CATEGORY_IMAGE_MAX_BYTES) {
        input.value = '';
        form.cover_image_file = null;
        releaseImagePreview(preview);
        preview.value = fallbackPreview;
        form.setError(
            'cover_image_file',
            'La imagen no puede superar los 5 MB.',
        );
        return;
    }

    try {
        const dimensions = await getImageDimensions(file);

        if (
            dimensions.width < CATEGORY_IMAGE_MIN_WIDTH
            || dimensions.height < CATEGORY_IMAGE_MIN_HEIGHT
        ) {
            input.value = '';
            form.cover_image_file = null;
            releaseImagePreview(preview);
            preview.value = fallbackPreview;
            form.setError(
                'cover_image_file',
                `La imagen debe medir al menos ${CATEGORY_IMAGE_MIN_WIDTH} × ${CATEGORY_IMAGE_MIN_HEIGHT} píxeles. La imagen seleccionada mide ${dimensions.width} × ${dimensions.height} píxeles.`,
            );
            return;
        }
    } catch {
        input.value = '';
        form.cover_image_file = null;
        releaseImagePreview(preview);
        preview.value = fallbackPreview;
        form.setError(
            'cover_image_file',
            'No se pudieron leer las dimensiones de la imagen.',
        );
        return;
    }

    releaseImagePreview(preview);
    form.cover_image_file = file;
    preview.value = URL.createObjectURL(file);
}

// Los wrappers conservan las referencias reales porque el template desempaqueta refs.
function handleCreateCategoryImageChange(event) {
    handleCategoryImageChange(event, createForm, createImagePreview);
}

function handleEditCategoryImageChange(event) {
    handleCategoryImageChange(
        event,
        editForm,
        editImagePreview,
        editing.value?.cover_image_url || '',
    );
}

/* ============================================================================
 * ALTA Y EDICIÓN
 * ============================================================================ */

// Abre el alta. Crear categorías activas no consume el límite de secciones públicas.
function openCreateModal() {
    createSubmitting.value = false;
    createForm.clearErrors();
    createOpen.value = true;
}

// Cierra el alta mientras no exista una petición en curso.
function closeCreateModal() {
    if (createCategoryBusy.value) {
        return;
    }

    createOpen.value = false;
    releaseImagePreview(createImagePreview);
    createForm.reset();
    createForm.accent_color = '#D6A23A';
    createForm.clearErrors();
}

// Cierra el alta después de guardar y restablece el formulario completo.
function closeCreateCategoryAfterSuccess() {
    createSubmitting.value = false;
    createOpen.value = false;
    releaseImagePreview(createImagePreview);
    createForm.reset();
    createForm.accent_color = '#D6A23A';
}

// Agrega un mensaje general solo cuando backend no entregó un error concreto.
function ensureFormOperationError(form, errors, fallback) {
    if (errors?.operation || Object.keys(errors || {}).length > 0) {
        return;
    }

    form.setError('operation', fallback);
}

// Crea una categoría después de normalizar los datos preventivamente.
function storeCategory() {
    normalizeCategoryForm(createForm);

    if (!canSubmitCreateCategory.value) {
        return;
    }

    operationError.value = '';
    createForm.clearErrors('operation');

    createForm.post(route('admin.categories.store'), {
        preserveScroll: true,
        forceFormData: true,
        onStart: () => { createSubmitting.value = true; },
        onSuccess: closeCreateCategoryAfterSuccess,
        onError: (errors) => {
            createSubmitting.value = false;
            ensureFormOperationError(
                createForm,
                errors,
                'No pudimos crear la categoría. Intentá nuevamente.',
            );
        },
        onFinish: () => {
            if (createForm.wasSuccessful) {
                closeCreateCategoryAfterSuccess();
                return;
            }

            createSubmitting.value = false;
        },
    });
}

// Copia la fila seleccionada al formulario de edición.
function openEdit(category) {
    editSubmitting.value = false;
    editing.value = category;
    editForm.name = category.name;
    editForm.description = category.description || '';
    editForm.accent_color = category.accent_color;
    editForm.sort_order = category.sort_order ?? null;
    editForm.cover_image_file = null;
    editForm.clearErrors();

    releaseImagePreview(editImagePreview);
    editImagePreview.value = category.cover_image_url || '';

    editInitial.value = {
        name: editForm.name,
        description: editForm.description,
        accent_color: editForm.accent_color,
        sort_order: editForm.sort_order === null
            ? null
            : Number(editForm.sort_order),
    };
}

// Cierra edición y elimina cualquier preview temporal elegida durante la sesión.
function closeEditModal() {
    if (editCategoryBusy.value) {
        return;
    }

    editing.value = null;
    editInitial.value = null;
    editForm.clearErrors();
    releaseImagePreview(editImagePreview);
}

// Cierra edición después de guardar correctamente.
function closeEditCategoryAfterSuccess() {
    editSubmitting.value = false;
    editing.value = null;
    editInitial.value = null;
    releaseImagePreview(editImagePreview);
}

// Actualiza la categoría seleccionada sin modificar su estado activo.
function updateCategory() {
    if (!editing.value || editCategoryBusy.value) {
        return;
    }

    normalizeCategoryForm(editForm);

    if (!canSubmitEditCategory.value) {
        return;
    }

    operationError.value = '';
    editForm.clearErrors('operation');

    editForm
        .transform((data) => ({ ...data, _method: 'patch' }))
        .post(route('admin.categories.update', editing.value.id), {
            preserveScroll: true,
            forceFormData: true,
            onStart: () => { editSubmitting.value = true; },
            onSuccess: closeEditCategoryAfterSuccess,
            onError: (errors) => {
                editSubmitting.value = false;
                ensureFormOperationError(
                    editForm,
                    errors,
                    'No pudimos guardar los cambios de la categoría. Intentá nuevamente.',
                );
            },
            onFinish: () => {
                if (editForm.wasSuccessful) {
                    closeEditCategoryAfterSuccess();
                    return;
                }

                editSubmitting.value = false;
            },
        });
}

/* ============================================================================
 * ACCIONES DE GRILLA
 * ============================================================================ */

// Activa o desactiva la disponibilidad editorial de una categoría.
function toggleCategory(category) {
    operationError.value = '';

    router.patch(
        route('admin.categories.toggle-active', category.id),
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                operationError.value = errors?.operation
                    || 'No pudimos cambiar el estado de la categoría. Intentá nuevamente.';
            },
        },
    );
}

// Construye la celda de acciones sin propagar sus clicks a la fila.
function categoryActionCell(category) {
    return h('div', { class: 'row-actions' }, [
        h(
            'button',
            {
                type: 'button',
                title: 'Editar categoría',
                'aria-label': 'Editar categoría',
                onClick: (event) => {
                    event.stopPropagation();
                    openEdit(category);
                },
            },
            [h(Pencil, { size: 16 })],
        ),
        h(
            'button',
            {
                type: 'button',
                title: category.is_active
                    ? 'Desactivar categoría'
                    : 'Activar categoría',
                'aria-label': category.is_active
                    ? 'Desactivar categoría'
                    : 'Activar categoría',
                onClick: (event) => {
                    event.stopPropagation();
                    toggleCategory(category);
                },
            },
            [h(Power, { size: 16 })],
        ),
        h(
            'button',
            {
                type: 'button',
                title: category.articles_count > 0
                    ? 'No se puede eliminar porque tiene noticias asociadas'
                    : 'Eliminar categoría',
                'aria-label': 'Eliminar categoría',
                disabled: category.articles_count > 0,
                onClick: (event) => {
                    event.stopPropagation();
                    openDeleteConfirmation(category);
                },
            },
            [h(Trash2, { size: 16 })],
        ),
    ]);
}

// Abre la confirmación únicamente para categorías sin noticias asociadas.
function openDeleteConfirmation(category) {
    if (category.articles_count > 0) {
        return;
    }

    deleteError.value = '';
    deleteTarget.value = category;
}

// Cierra la confirmación mientras no haya una eliminación en curso.
function closeDeleteConfirmation() {
    if (deleteProcessing.value) {
        return;
    }

    deleteTarget.value = null;
    deleteError.value = '';
}

// Elimina definitivamente la categoría seleccionada.
function confirmDeleteCategory() {
    if (!deleteTarget.value || deleteProcessing.value) {
        return;
    }

    deleteProcessing.value = true;
    deleteError.value = '';

    router.delete(
        route('admin.categories.destroy', deleteTarget.value.id),
        {
            preserveScroll: true,
            onSuccess: () => {
                deleteTarget.value = null;
                deleteError.value = '';
            },
            onError: (errors) => {
                deleteError.value = errors?.operation
                    || errors?.category
                    || 'No pudimos eliminar la categoría. Intentá nuevamente.';
            },
            onFinish: () => {
                deleteProcessing.value = false;
            },
        },
    );
}

/* ============================================================================
 * ESCAPE Y CICLO DE VIDA
 * ============================================================================ */

// Escape cierra únicamente el modal superior que no se encuentre procesando.
function handleEscape(event) {
    if (event.key !== 'Escape' || deleteTarget.value) {
        return;
    }

    if (editing.value) {
        if (!editCategoryBusy.value) {
            event.preventDefault();
            closeEditModal();
        }
        return;
    }

    if (createOpen.value && !createCategoryBusy.value) {
        event.preventDefault();
        closeCreateModal();
    }
}

// Registra el listener global de Escape mientras la pantalla está montada.
onMounted(() => {
    document.addEventListener('keydown', handleEscape);
});

// Elimina listeners y URLs temporales antes de abandonar la vista.
onBeforeUnmount(() => {
    document.removeEventListener('keydown', handleEscape);
    releaseImagePreview(createImagePreview);
    releaseImagePreview(editImagePreview);
});
</script>

<template>
    <Head title="Categorías" />

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

    <!-- Barra sticky con búsqueda, estado, contador y alta. -->
    <section class="admin-toolbar editorial-toolbar categories-toolbar">
        <form
            class="admin-filter-form compact editorial-search-form categories-search-form"
            @submit.prevent="applySearch"
        >
            <Search :size="18" aria-hidden="true" />

            <input
                v-model="filter.q"
                class="editorial-search-input"
                type="search"
                name="q"
                minlength="2"
                maxlength="120"
                placeholder="Buscar categoría..."
                aria-label="Buscar categoría"
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
            class="editorial-filter-control categories-status-filter"
            name="status"
            aria-label="Filtrar categorías por estado"
            @change="changeStatus"
        >
            <option value="">Todos los estados</option>
            <option value="published">Publicadas</option>
            <option value="active">Activas</option>
            <option value="inactive">Inactivas</option>
        </select>

        <button
            type="button"
            class="editorial-filter-clear categories-filter-clear"
            :disabled="!hasActiveFilters"
            @click="resetFilters"
        >
            Limpiar
        </button>

        <div class="admin-toolbar-summary categories-active-counter">
            {{ publishedCounter }}
        </div>

        <button
            type="button"
            class="admin-primary-action categories-create-button"
            title="Crear una nueva categoría"
            @click="openCreateModal"
        >
            <FolderPlus :size="18" />
            Nueva categoría
        </button>
    </section>

    <!-- La tabla recibe únicamente la página actual; Laravel ordena y pagina. -->
    <section
        v-if="!loadError"
        class="editorial-table-panel categories-table-panel"
    >
        <div class="editorial-table-scroll">
            <table class="editorial-table admin-tanstack-table admin-categories-table">
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
                                numeric: header.column.id === 'published_articles_count',
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
                        class="editorial-table-row admin-category-table-row"
                        tabindex="0"
                        @click="openEdit(row.original)"
                        @keydown.enter.self.prevent="openEdit(row.original)"
                    >
                        <td
                            v-for="cell in row.getAllCells()"
                            :key="cell.id"
                            :class="{
                                numeric: cell.column.id === 'published_articles_count',
                                'admin-numeric-value-centered': cell.column.id === 'published_articles_count',
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
                            No se encontraron categorías.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Misma paginación fija utilizada por las demás grillas administrativas. -->
    <AdminPagination
        v-if="!loadError && categories?.pagination"
        :pagination="categories.pagination"
        :per-page="filter.per_page"
        :per-page-options="[25, 50, 100]"
        item-label="categorías"
        @update:per-page="changePerPage"
    />

    <!-- Confirma la eliminación definitiva de una categoría sin noticias. -->
    <ConfirmActionModal
        :open="deleteTarget !== null"
        eyebrow="Eliminar categoría"
        :title="deleteTarget
            ? `¿Eliminar la categoría ${deleteCategoryDisplayName(deleteTarget.name)}?`
            : ''"
        message="La categoría se eliminará definitivamente y esta acción no se puede deshacer."
        help="Esta opción solo está disponible cuando la categoría no tiene noticias asociadas."
        :error="deleteError"
        confirm-label="Eliminar categoría"
        processing-label="Eliminando..."
        :processing="deleteProcessing"
        danger
        @confirm="confirmDeleteCategory"
        @cancel="closeDeleteConfirmation"
    />

    <!-- Modal de alta. La categoría se crea activa automáticamente. -->
    <div
        v-if="createOpen"
        class="admin-modal-backdrop"
    >
        <form
            class="admin-modal wide admin-modal-refined category-admin-modal"
            @submit.prevent="storeCategory"
        >
            <header>
                <div>
                    <p class="eyebrow">Contenido</p>
                    <h2>Nueva categoría</h2>
                    <p class="admin-modal-subtitle">
                        Creá una sección completa para utilizarla en nuevas noticias.
                    </p>
                </div>

                <button
                    type="button"
                    class="admin-modal-close"
                    :disabled="createCategoryBusy"
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

            <!-- Reutiliza el mismo recuadro de carga del alta de empleados. -->
            <label class="admin-avatar-upload category-cover-upload">
                <span class="category-cover-upload-preview">
                    <img
                        v-if="createImagePreview"
                        :src="createImagePreview"
                        :alt="createForm.name || 'Vista previa de la categoría'"
                    />
                    <ImageIcon
                        v-else
                        :size="28"
                        aria-hidden="true"
                    />
                </span>

                <span>
                    <strong>Imagen de portada</strong>
                    <small>
                        JPG, JPEG, PNG o WEBP · hasta 5 MB · mínimo 1600 × 900 px; TRAMA valida, recorta y guarda automáticamente como WebP.
                    </small>
                    <input
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        required
                        :disabled="createCategoryBusy"
                        @change="handleCreateCategoryImageChange"
                    />
                    <span
                        v-if="createForm.errors.cover_image_file"
                        class="form-error"
                    >
                        {{ createForm.errors.cover_image_file }}
                    </span>
                </span>
            </label>

            <!-- Una categoría nueva todavía no tiene posición editorial pública. -->
            <div class="category-main-fields category-main-fields--single">
                <label>
                    Nombre
                    <input
                        v-model="createForm.name"
                        required
                        :minlength="CATEGORY_NAME_MIN"
                        :maxlength="CATEGORY_NAME_MAX"
                        :disabled="createCategoryBusy"
                        @blur="createForm.name = normalizeSingleLine(createForm.name)"
                    />
                    <span
                        v-if="createForm.errors.name"
                        class="form-error"
                    >
                        {{ createForm.errors.name }}
                    </span>
                </label>
            </div>

            <label class="category-description-field">
                Descripción
                <textarea
                    :value="createForm.description"
                    rows="3"
                    required
                    :minlength="CATEGORY_DESCRIPTION_MIN"
                    :maxlength="CATEGORY_DESCRIPTION_MAX"
                    :disabled="createCategoryBusy"
                    @keydown.enter.prevent
                    @input="updateDescription(createForm, $event.target.value)"
                />
                <span class="category-description-meta">
                    <small>Un único párrafo, sin saltos de línea.</small>
                    <small class="category-description-counter">
                        {{ descriptionCounter(createDescriptionLength) }}
                    </small>
                </span>
                <span
                    v-if="createForm.errors.description"
                    class="form-error"
                >
                    {{ createForm.errors.description }}
                </span>
            </label>

            <div class="category-bottom-fields">
                <label class="category-color-field">
                    Color
                    <input
                        v-model="createForm.accent_color"
                        type="color"
                        required
                        :disabled="createCategoryBusy"
                    />
                    <span
                        v-if="createForm.errors.accent_color"
                        class="form-error"
                    >
                        {{ createForm.errors.accent_color }}
                    </span>
                </label>

                <p class="admin-limit-note category-create-state-note">
                    La categoría se crea ACTIVA para uso editorial. Al publicar su primera noticia pasa a PUBLICADA y recibe orden editorial. {{ publishedCounter }}.
                </p>
            </div>

            <footer class="admin-modal-footer">
                <button
                    type="button"
                    class="admin-secondary-button"
                    :disabled="createCategoryBusy"
                    @click="closeCreateModal"
                >
                    Cancelar
                </button>

                <button
                    type="submit"
                    class="admin-gold-button"
                    :disabled="!canSubmitCreateCategory"
                >
                    {{ createCategoryBusy ? 'Creando...' : 'Crear categoría' }}
                </button>
            </footer>
        </form>
    </div>

    <!-- Modal de edición. El estado activo se modifica únicamente desde la grilla. -->
    <div
        v-if="editing"
        class="admin-modal-backdrop"
    >
        <form
            class="admin-modal wide admin-modal-refined category-admin-modal"
            @submit.prevent="updateCategory"
        >
            <header>
                <div>
                    <p class="eyebrow">Contenido</p>
                    <h2>Editar categoría</h2>
                    <p class="admin-modal-subtitle">
                        Actualizá los datos públicos de la sección.
                    </p>
                </div>

                <button
                    type="button"
                    class="admin-modal-close"
                    :disabled="editCategoryBusy"
                    @click="closeEditModal"
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

            <!-- Conserva el mismo recuadro de carga usado en Nueva categoría. -->
            <label class="admin-avatar-upload category-cover-upload">
                <span class="category-cover-upload-preview">
                    <img
                        v-if="editImagePreview"
                        :src="editImagePreview"
                        :alt="editForm.name || editing.name"
                    />
                    <ImageIcon
                        v-else
                        :size="28"
                        aria-hidden="true"
                    />
                </span>

                <span>
                    <strong>Cambiar imagen de portada</strong>
                    <small>
                        Si no elegís un archivo se conserva la imagen actual. JPG, JPEG, PNG o WEBP · hasta 5 MB · mínimo 1600 × 900 px.
                    </small>
                    <input
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        :disabled="editCategoryBusy"
                        @change="handleEditCategoryImageChange"
                    />
                    <span
                        v-if="editForm.errors.cover_image_file"
                        class="form-error"
                    >
                        {{ editForm.errors.cover_image_file }}
                    </span>
                </span>
            </label>

            <div
                class="form-two-columns category-main-fields"
                :class="{ 'category-main-fields--single': !editingHasEditorialOrder }"
            >
                <label>
                    Nombre
                    <input
                        v-model="editForm.name"
                        required
                        :minlength="CATEGORY_NAME_MIN"
                        :maxlength="CATEGORY_NAME_MAX"
                        :disabled="editCategoryBusy"
                        @blur="editForm.name = normalizeSingleLine(editForm.name)"
                    />
                    <span
                        v-if="editForm.errors.name"
                        class="form-error"
                    >
                        {{ editForm.errors.name }}
                    </span>
                </label>

                <!-- La posición solo existe mientras la categoría participa del portal. -->
                <label v-if="editingHasEditorialOrder">
                    Orden editorial
                    <select
                        v-model.number="editForm.sort_order"
                        required
                        :disabled="editCategoryBusy"
                    >
                        <option
                            v-for="position in editorialPositionOptions"
                            :key="position"
                            :value="position"
                        >
                            {{ position }}
                        </option>
                    </select>
                    <span
                        v-if="editForm.errors.sort_order"
                        class="form-error"
                    >
                        {{ editForm.errors.sort_order }}
                    </span>
                </label>
            </div>

            <p
                v-if="!editingHasEditorialOrder"
                class="admin-limit-note category-editorial-order-note"
            >
                El orden editorial se habilita únicamente cuando la categoría está PUBLICADA.
            </p>

            <label class="category-description-field">
                Descripción
                <textarea
                    :value="editForm.description"
                    rows="3"
                    required
                    :minlength="CATEGORY_DESCRIPTION_MIN"
                    :maxlength="CATEGORY_DESCRIPTION_MAX"
                    :disabled="editCategoryBusy"
                    @keydown.enter.prevent
                    @input="updateDescription(editForm, $event.target.value)"
                />
                <span class="category-description-meta">
                    <small>Un único párrafo, sin saltos de línea.</small>
                    <small class="category-description-counter">
                        {{ descriptionCounter(editDescriptionLength) }}
                    </small>
                </span>
                <span
                    v-if="editForm.errors.description"
                    class="form-error"
                >
                    {{ editForm.errors.description }}
                </span>
            </label>

            <div class="category-bottom-fields category-bottom-fields--edit">
                <label class="category-color-field">
                    Color
                    <input
                        v-model="editForm.accent_color"
                        type="color"
                        required
                        :disabled="editCategoryBusy"
                    />
                    <span
                        v-if="editForm.errors.accent_color"
                        class="form-error"
                    >
                        {{ editForm.errors.accent_color }}
                    </span>
                </label>
            </div>

            <footer class="admin-modal-footer">
                <button
                    type="button"
                    class="admin-secondary-button"
                    :disabled="editCategoryBusy"
                    @click="closeEditModal"
                >
                    Cancelar
                </button>

                <button
                    type="submit"
                    class="admin-gold-button"
                    :disabled="!canSubmitEditCategory"
                >
                    {{ editCategoryBusy ? 'Guardando...' : 'Guardar cambios' }}
                </button>
            </footer>
        </form>
    </div>
</template>

<style src="../../../../css/admin/categories.css"></style>
