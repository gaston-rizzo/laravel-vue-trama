<script setup>
/* ============================================================================
 * PAGE: Admin/Tags/Index.vue
 * ============================================================================
 *
 * Administración de etiquetas temáticas.
 *
 * Permite crear, editar, activar, desactivar, fusionar y eliminar etiquetas sin
 * historial. La fusión conserva una etiqueta principal y reasigna las noticias
 * sin duplicar relaciones.
 * ============================================================================ */

import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, h, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { ChevronDown, GitMerge, Pencil, Power, Search, Tag as TagIcon, Trash2, X } from '@lucide/vue';
import { FlexRender, rowSortingFeature, tableFeatures, useTable } from '@tanstack/vue-table';
import ConfirmActionModal from '@/Components/Shared/ConfirmActionModal.vue';
import AdminPagination from '@/Components/Admin/AdminPagination.vue';

// Props de administración de etiquetas:
// tags contiene la página actual y su paginación; filters conserva búsqueda,
// estado, uso, orden y cantidad por página durante la navegación.
const props = defineProps({
    tags: Object,
    filters: Object,
    activeCount: { type: Number, default: 0 },
    activeLimit: { type: Number, default: 100 },
    mergeTargets: { type: Array, default: () => [] },
    loadError: { type: String, default: '' },
});

/*
 * Límites editoriales de las etiquetas.
 *
 * El nombre debe ser corto porque funciona como una referencia temática visible
 * en chips, filtros y grillas. La descripción permite explicar brevemente el
 * alcance de la etiqueta sin convertirse en un texto editorial extenso.
 */
const TAG_NAME_MIN = 2;
const TAG_NAME_MAX = 30;
const TAG_DESCRIPTION_MIN = 10;
const TAG_DESCRIPTION_MAX = 160;

// Cantidad máxima de caracteres del nombre que se muestran
// dentro del título del modal de eliminación.
// Esto no modifica el nombre real de la etiqueta.
const DELETE_TAG_NAME_PREVIEW_LENGTH = 23;

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
function deleteTagDisplayName(name) {
    const characters = Array.from(
        String(name ?? '').trim()
    );

    if (characters.length <= DELETE_TAG_NAME_PREVIEW_LENGTH) {
        return characters.join('');
    }

    return `${characters
        .slice(0, DELETE_TAG_NAME_PREVIEW_LENGTH)
        .join('')}…`;
}

// Filtros reactivos que Laravel aplica sobre la grilla paginada.
const filter = reactive({
    q: props.filters?.q || '',
    status: props.filters?.status || '',
    usage: props.filters?.usage || '',
    sort: props.filters?.sort || 'name',
    direction: props.filters?.direction || 'asc',
    per_page: Number(props.filters?.per_page || 25),
});
// Etiqueta seleccionada para editar.
const editing = ref(null);
// Etiqueta seleccionada para fusionar con otra.
const merging = ref(null);

// Controla si el modal de creación está abierto.
const createOpen = ref(false);

// Etiqueta seleccionada para confirmar su eliminación definitiva.
const deleteTarget = ref(null);
// Bloquea el modal mientras se procesa la eliminación.
const deleteProcessing = ref(false);
// Mensaje amigable mostrado cuando una operación administrativa falla.
const operationError = ref('');
const deleteError = ref('');

/*
 * Registra únicamente la funcionalidad de ordenamiento usada por esta tabla.
 */
const features = tableFeatures({
    rowSortingFeature,
});

/*
 * Estado inicial del ordenamiento recibido desde Laravel.
 */
const sorting = ref([{
    id: props.filters?.sort || 'name',
    desc: (props.filters?.direction || 'asc') === 'desc',
}]);

// Formulario para crear una etiqueta.
const createForm = useForm({ name: '', description: '', is_active: true });
const createSubmitting = ref(false);

// Formulario para editar una etiqueta existente.
const editForm = useForm({ name: '', description: '', is_active: true });
const editSubmitting = ref(false);

// Valores originales de la etiqueta abierta. Permiten mantener Guardar cambios
// deshabilitado hasta que exista una modificación real.
const editInitial = ref(null);

// Formulario que guarda la etiqueta destino de una fusión.
const mergeForm = useForm({ target_tag_id: '' });
const mergeSubmitting = ref(false);
const mergeSearch = ref('');
const mergeSuggestionsOpen = ref(false);
const mergeSearchControl = ref(null);

/* ============================================================================
 * TABLA TANSTACK
 * ============================================================================
 *
 * TanStack Table se utiliza únicamente para construir y representar la grilla.
 *
 * El filtrado, el ordenamiento y la paginación reales se resuelven en Laravel.
 * De esta manera el navegador trabaja solamente con las etiquetas de la página
 * actual y no necesita cargar todo el conjunto de registros.
 * ============================================================================ */

/*
 * Define las columnas visibles de la grilla de etiquetas.
 *
 * Cada columna indica:
 *
 * - qué dato utiliza;
 * - qué encabezado muestra;
 * - si permite ordenamiento;
 * - cómo debe renderizarse la celda cuando necesita una presentación especial.
 */
const columns = [
    /*
     * Columna principal de la etiqueta.
     *
     * Muestra el nombre y, debajo, el slug histórico. Cuando una etiqueta fue
     * fusionada, reemplaza el slug visible por el nombre de la etiqueta destino.
     */
    {
        id: 'name',
        accessorKey: 'name',
        header: 'Etiqueta',
        enableSorting: true,

        /*
         * Construye la celda principal mediante h() porque las columnas TanStack
         * se definen dentro del script y no directamente dentro del template.
         */
        cell: (info) => {
            const tag = info.row.original;

            return h(
                'div',
                {
                    class: 'admin-table-primary-cell',
                },
                [
                    /*
                     * Nombre visible de la etiqueta.
                     */
                    h(
                        'strong',
                        {
                            class: 'admin-tags-name-text',
                            title: tag.name,
                        },
                        tag.name,
                    ),

                    /*
                     * Slug histórico o referencia de fusión.
                     *
                     * También se limita visualmente para impedir que un slug
                     * heredado de datos antiguos ensanche la grilla.
                     */
                    h(
                        'small',
                        {
                            class: 'admin-tags-slug-text',
                            title: tag.merged_into
                                ? `Fusionada con ${tag.merged_into.name}`
                                : `#${tag.slug}`,
                        },
                        tag.merged_into
                            ? `Fusionada con ${tag.merged_into.name}`
                            : `#${tag.slug}`,
                    ),
                ],
            );
        },
    },

    /*
     * Descripción editorial de la etiqueta.
     */
    {
        id: 'description',
        accessorFn: (tag) => tag.description || 'Sin descripción',
        header: 'Descripción',
        enableSorting: false,

        /*
         * Mantiene la descripción en una sola línea y deja el texto completo en
         * title para poder consultarlo al pasar el puntero.
         */
        cell: (info) => {
            const description = String(info.getValue() || 'Sin descripción');

            return h(
                'span',
                {
                    class: 'admin-tags-description-text',
                    title: description,
                },
                description,
            );
        },
    },

    /*
     * Cantidad de noticias actualmente relacionadas con la etiqueta.
     */
    {
        id: 'articles_count',
        accessorKey: 'articles_count',
        header: 'Noticias',
        enableSorting: true,

        /*
         * Siempre muestra un número. Si Laravel no entrega el valor, utiliza 0.
         */
        cell: (info) => h(
            'strong',
            String(info.getValue() ?? 0),
        ),
    },

    /*
     * Estado administrativo de la etiqueta.
     *
     * Puede ser active, inactive o merged.
     */
    {
        id: 'status',

        /*
         * Normaliza el estado utilizado por TanStack.
         */
        accessorFn: (tag) => tag.merged_into
            ? 'merged'
            : (tag.is_active ? 'active' : 'inactive'),

        header: 'Estado',
        enableSorting: false,

        /*
         * Renderiza el estado mediante el mismo sistema visual de badges
         * utilizado por las otras grillas administrativas.
         */
        cell: (info) => {
            const tag = info.row.original;

            return h(
                'span',
                {
                    class: [
                        'status-badge',
                        tag.merged_into
                            ? 'status-merged'
                            : (tag.is_active ? 'status-published' : 'status-archived'),
                    ],
                },
                tag.merged_into
                    ? 'Fusionada'
                    : (tag.is_active ? 'Activa' : 'Inactiva'),
            );
        },
    },

    /*
     * Acciones disponibles sobre cada etiqueta.
     *
     * Cada botón detiene la propagación del click para impedir que la acción
     * también abra el modal general de edición de la fila.
     */
    {
        id: 'actions',
        header: 'Acciones',
        enableSorting: false,

        /*
         * Construye los botones correspondientes a la etiqueta actual.
         */
        cell: (info) => {
            const tag = info.row.original;

            /*
             * Una etiqueta fusionada queda conservada como historial y no puede
             * volver a editarse, fusionarse ni cambiarse de estado.
             */
            const merged = Boolean(tag.merged_into);

            return h(
                'div',
                {
                    class: 'row-actions',
                },
                [
                    /*
                     * Editar etiqueta.
                     */
                    h(
                        'button',
                        {
                            type: 'button',
                            disabled: merged,
                            title: 'Editar',
                            onClick: (event) => {
                                event.stopPropagation();
                                openEdit(tag);
                            },
                        },
                        [
                            h(Pencil, { size: 16 }),
                        ],
                    ),

                    /*
                     * Fusionar con otra etiqueta.
                     */
                    h(
                        'button',
                        {
                            type: 'button',
                            disabled: merged,
                            title: 'Fusionar con otra etiqueta',
                            onClick: (event) => {
                                event.stopPropagation();
                                openMerge(tag);
                            },
                        },
                        [
                            h(GitMerge, { size: 16 }),
                        ],
                    ),

                    /*
                     * Activar o desactivar.
                     */
                    h(
                        'button',
                        {
                            type: 'button',
                            disabled: merged,
                            title: 'Activar o desactivar',
                            onClick: (event) => {
                                event.stopPropagation();
                                toggleTag(tag);
                            },
                        },
                        [
                            h(Power, { size: 16 }),
                        ],
                    ),

                    /*
                     * Eliminar definitivamente.
                     *
                     * Sólo se habilita cuando la etiqueta no está fusionada y no
                     * tiene noticias relacionadas.
                     */
                    h(
                        'button',
                        {
                            type: 'button',
                            disabled: tag.articles_count > 0 || merged,
                            title: 'Eliminar',
                            onClick: (event) => {
                                event.stopPropagation();
                                openDeleteConfirmation(tag);
                            },
                        },
                        [
                            h(Trash2, { size: 16 }),
                        ],
                    ),
                ],
            );
        },
    },
];

// Filas correspondientes a la página actual entregada por Laravel.
const tagRows = computed(() => props.tags?.data || []);

/*
 * Crea la instancia de TanStack Table utilizada por la grilla de etiquetas.
 *
 * manualSorting mantiene el ordenamiento en el servidor para no ordenar sólo
 * las filas visibles de la página actual.
 */
const table = useTable({
    /*
     * Identificador estable de la tabla.
     */
    key: 'admin-tags-table',

    /*
     * Funcionalidades TanStack habilitadas.
     */
    features,

    /*
     * Definición de columnas declarada anteriormente.
     */
    columns,

    /*
     * Filas de la página actual entregadas por Laravel.
     */
    data: tagRows,

    /*
     * Usa el id real de la etiqueta como identificador estable de cada fila.
     */
    getRowId: (row) => String(row.id),

    /*
     * El ordenamiento real se ejecuta en Laravel.
     */
    manualSorting: true,

    /*
     * Sólo se permite una columna ordenada por vez.
     */
    enableMultiSort: false,

    /*
     * Impide dejar la grilla sin criterio de ordenamiento.
     */
    enableSortingRemoval: false,

    /*
     * Estado reactivo que TanStack utiliza para mostrar la dirección actual.
     */
    state: {
        get sorting() {
            return sorting.value;
        },
    },

    /*
     * Traduce cada cambio de encabezado a una nueva consulta al backend.
     */
    onSortingChange: changeSorting,
});

/* ============================================================================ */

/*
 * Cantidad real de etiquetas activas.
 *
 * El límite administrativo no representa la cantidad total existente, por eso
 * este contador muestra únicamente el dato real recibido desde Laravel.
 */
const activeCounter = computed(() => {
    const count = Number(props.activeCount || 0);

    return count === 1
        ? '1 etiqueta activa'
        : `${count} etiquetas activas`;
});

/*
 * Cantidades de caracteres utilizadas por los contadores visibles del modal de
 * creación.
 */
const createNameLength = computed(() => createForm.name.length);
const createDescriptionLength = computed(() => createForm.description.length);

/*
 * Cantidades de caracteres utilizadas por los contadores visibles del modal de
 * edición.
 */
const editNameLength = computed(() => editForm.name.length);
const editDescriptionLength = computed(() => editForm.description.length);

/*
 * Valida localmente los mínimos y máximos antes de habilitar la creación.
 *
 * Laravel debe conservar las mismas reglas para que la validación no dependa
 * exclusivamente del navegador.
 */
const canSubmitCreateTag = computed(() => {
    const nameLength = createForm.name.trim().length;
    const descriptionLength = createForm.description.trim().length;

    return nameLength >= TAG_NAME_MIN
        && nameLength <= TAG_NAME_MAX
        && descriptionLength >= TAG_DESCRIPTION_MIN
        && descriptionLength <= TAG_DESCRIPTION_MAX;
});

/*
 * Aplica las mismas reglas al formulario de edición.
 */
const canSubmitEditTag = computed(() => {
    const nameLength = editForm.name.trim().length;
    const descriptionLength = editForm.description.trim().length;

    return nameLength >= TAG_NAME_MIN
        && nameLength <= TAG_NAME_MAX
        && descriptionLength >= TAG_DESCRIPTION_MIN
        && descriptionLength <= TAG_DESCRIPTION_MAX;
});

const createTagBusy = computed(() => createForm.processing || createSubmitting.value);
const editTagBusy = computed(() => editForm.processing || editSubmitting.value);
const mergeTagBusy = computed(() => mergeForm.processing || mergeSubmitting.value);

// Detecta cambios de contenido respecto de la etiqueta abierta.
const editTagChanged = computed(() => {
    if (!editing.value || !editInitial.value) {
        return false;
    }

    return editForm.name !== editInitial.value.name
        || editForm.description !== editInitial.value.description;
});

const selectedMergeTarget = computed(() => props.mergeTargets.find(
    (tag) => Number(tag.id) === Number(mergeForm.target_tag_id),
) || null);

const mergeTagSuggestions = computed(() => {
    if (!merging.value) {
        return [];
    }

    const term = mergeSearch.value.trim().toLocaleLowerCase('es-AR');
    const hasSearchTerm = term.length >= 2;

    return props.mergeTargets
        .filter((tag) => Number(tag.id) !== Number(merging.value.id))
        .filter((tag) => !hasSearchTerm || tag.name.toLocaleLowerCase('es-AR').includes(term))
        .sort((left, right) => left.name.localeCompare(right.name, 'es-AR'))
        .slice(0, 10);
});

const canSubmitMergeTag = computed(() => Boolean(mergeForm.target_tag_id) && !mergeTagBusy.value);

// Texto escrito en el buscador. Se aplica únicamente al presionar Buscar.
const normalizedSearch = computed(() => filter.q.trim());

// Búsqueda que Laravel tiene aplicada actualmente.
const appliedSearch = computed(() => String(props.filters?.q || '').trim());

// Habilita Buscar cuando hay una búsqueda nueva válida o cuando se quiere quitar
// una búsqueda que ya estaba aplicada.
const canApplySearch = computed(() => {
    if (normalizedSearch.value === '' && appliedSearch.value !== '') {
        return true;
    }

    return normalizedSearch.value.length >= 2
        && normalizedSearch.value !== appliedSearch.value;
});

// Limpiar comienza deshabilitado y se habilita únicamente cuando existe texto
// escrito o algún filtro de estado/uso seleccionado.
const hasActiveFilters = computed(() => Boolean(
    normalizedSearch.value
    || filter.status
    || filter.usage
));

/*
 * Construye el texto dinámico de los contadores. Mientras el campo todavía no
 * alcanza su mínimo informa cuántos caracteres faltan; al cumplirlo conserva
 * solamente actual/máximo y evita el texto fijo "mínimo X".
 */
function tagCounterText(length, minimum, maximum) {
    if (length < minimum) {
        const remaining = minimum - length;
        const verb = remaining === 1 ? 'falta' : 'faltan';
        const unit = remaining === 1 ? 'carácter' : 'caracteres';

        return `${length}/${maximum} · ${verb} ${remaining} ${unit}`;
    }

    return `${length}/${maximum}`;
}

// Construye la consulta con el texto que Laravel ya tiene aplicado y con los
// combos visibles actuales. De esta manera escribir en el buscador no filtra
// accidentalmente al cambiar Estado o Uso.
function buildQuery(overrides = {}) {
    const query = {
        q: appliedSearch.value || undefined,
        status: filter.status || undefined,
        usage: filter.usage || undefined,
        sort: filter.sort || 'name',
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

// Navega por la grilla conservando exactamente la posición de scroll.
// preserveScroll cubre el comportamiento normal de Inertia y la restauración
// manual mantiene el mismo resultado que la grilla de Moderación en Chrome.
function visitList(overrides = {}) {
    const scrollX = window.scrollX;
    const scrollY = window.scrollY;

    router.get(
        route('admin.tags.index'),
        buildQuery(overrides),
        {
            preserveState: true,
            replace: true,
            preserveScroll: true,
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

// El botón Buscar controla exclusivamente la búsqueda textual.
function applySearch() {
    if (!canApplySearch.value) {
        return;
    }

    visitList({
        q: normalizedSearch.value || undefined,
        page: undefined,
    });
}

// Estado se aplica automáticamente al cambiar la opción del combo.
function changeStatus() {
    visitList({
        status: filter.status || undefined,
        page: undefined,
    });
}

// Uso se aplica automáticamente al cambiar la opción del combo.
function changeUsage() {
    visitList({
        usage: filter.usage || undefined,
        page: undefined,
    });
}

// Limpia solamente búsqueda, estado y uso. El orden y la cantidad por página
// permanecen como estaban.
function resetFilters() {
    if (!hasActiveFilters.value) {
        return;
    }

    filter.q = '';
    filter.status = '';
    filter.usage = '';

    visitList({
        q: undefined,
        status: undefined,
        usage: undefined,
        page: undefined,
    });
}

// Cambia la cantidad de filas conservando búsqueda y filtros aplicados.
function changePerPage(value) {
    filter.per_page = Number(value);

    visitList({
        per_page: filter.per_page,
        page: undefined,
    });
}

/*
 * Normaliza campos textuales que deben almacenarse en una sola línea.
 *
 * Convierte saltos de línea, tabulaciones y grupos de espacios consecutivos
 * en un único espacio y elimina espacios al principio y al final.
 *
 * Laravel repite esta normalización en TagRequest para que la regla no dependa
 * exclusivamente del navegador.
 */
function normalizeTagField(form, field) {
    form[field] = String(form[field] || '')
        .replace(/\s+/g, ' ')
        .trim();
}

// Agrega un mensaje general solamente cuando Inertia no recibió errores concretos.
function ensureFormOperationError(form, errors, fallback) {
    if (errors?.is_active) {
        form.setError('operation', errors.is_active);
        return;
    }

    if (errors?.operation || Object.keys(errors || {}).length > 0) {
        return;
    }

    form.setError('operation', fallback);
}

// Cierra el modal de creación después de guardar correctamente la etiqueta
// y restablece el formulario a sus valores iniciales.
function closeCreateTagAfterSuccess() {
    createSubmitting.value = false;
    createOpen.value = false;
    createForm.reset();
    createForm.is_active = true;
}

// Cierra el modal de edición después de guardar correctamente y elimina
// la referencia utilizada para comparar los valores originales.
function closeEditTagAfterSuccess() {
    editSubmitting.value = false;
    editing.value = null;
    editInitial.value = null;
}

// Cierra el modal de fusión después de completarse correctamente y limpia
// la búsqueda y el estado temporal del selector de etiqueta destino.
function closeMergeTagAfterSuccess() {
    mergeSubmitting.value = false;
    merging.value = null;
    mergeSearch.value = '';
    mergeSuggestionsOpen.value = false;
}

// Crea la etiqueta y cierra el modal si Laravel la acepta.
function storeTag() {
    /*
     * Normaliza antes de validar para que los contadores y mínimos se evalúen
     * sobre el mismo texto que finalmente recibirá Laravel.
     */
    normalizeTagField(createForm, 'name');
    normalizeTagField(createForm, 'description');

    if (createTagBusy.value || !canSubmitCreateTag.value) {
        return;
    }

    operationError.value = '';
    // createForm.post usa Inertia para enviar un POST HTTP normal a Laravel.
    // Laravel valida la etiqueta y devuelve errores o la lista actualizada como props.
    createForm.post(route('admin.tags.store'), {
        preserveScroll: true,
        onStart: () => { createSubmitting.value = true; },
        onSuccess: closeCreateTagAfterSuccess,
        onError: (errors) => {
            createSubmitting.value = false;
            ensureFormOperationError(
                createForm,
                errors,
                'No pudimos crear la etiqueta. Intentá nuevamente.',
            );
        },
        onFinish: () => {
            if (createForm.wasSuccessful) {
                closeCreateTagAfterSuccess();
            } else {
                createSubmitting.value = false;
            }
        },
    });
}

// Carga la etiqueta elegida en el formulario de edición.
function openEdit(tag) {
    if (tag.merged_into) {
        return;
    }

    editSubmitting.value = false;
    editing.value = tag;
    editForm.name = tag.name;
    editForm.description = tag.description || '';
    editForm.is_active = tag.is_active;
    editForm.clearErrors();

    editInitial.value = {
        name: editForm.name,
        description: editForm.description,
        is_active: Boolean(editForm.is_active),
    };
}

// Guarda cambios de nombre, descripción y estado.
function updateTag() {
    /*
     * Aplica la misma normalización utilizada durante la creación.
     */
    normalizeTagField(editForm, 'name');
    normalizeTagField(editForm, 'description');

    if (editTagBusy.value || !canSubmitEditTag.value || !editTagChanged.value) {
        return;
    }

    operationError.value = '';
    editForm.patch(route('admin.tags.update', editing.value.id), {
        preserveScroll: true,
        onStart: () => { editSubmitting.value = true; },
        onSuccess: closeEditTagAfterSuccess,
        onError: (errors) => {
            editSubmitting.value = false;
            ensureFormOperationError(
                editForm,
                errors,
                'No pudimos guardar los cambios de la etiqueta. Intentá nuevamente.',
            );
        },
        onFinish: () => {
            if (editForm.wasSuccessful) {
                closeEditTagAfterSuccess();
            } else {
                editSubmitting.value = false;
            }
        },
    });
}

// Abre el modal para elegir la etiqueta principal de destino.
function openMerge(tag) {
    mergeSubmitting.value = false;
    merging.value = tag;
    mergeForm.target_tag_id = '';
    mergeSearch.value = '';
    mergeSuggestionsOpen.value = false;
    mergeForm.clearErrors();
}

// Cierra el modal de fusión cuando no existe una operación en curso y limpia
// la selección, la búsqueda y los errores del formulario.
function closeMergeModal() {
    if (mergeTagBusy.value) {
        return;
    }

    merging.value = null;
    mergeSearch.value = '';
    mergeSuggestionsOpen.value = false;
    mergeForm.clearErrors();
}

// Selecciona una etiqueta como destino de la fusión, refleja su nombre en el
// buscador y cierra la lista de sugerencias.
function selectMergeTarget(tag) {
    mergeForm.target_tag_id = tag.id;
    mergeSearch.value = tag.name;
    mergeSuggestionsOpen.value = false;
    mergeForm.clearErrors('target_tag_id');
}

// Quita la etiqueta destino actualmente seleccionada y vuelve a dejar abierta
// la búsqueda para elegir una nueva opción.
function removeMergeTarget() {
    mergeForm.target_tag_id = '';
    mergeSearch.value = '';
    mergeSuggestionsOpen.value = true;
    mergeForm.clearErrors('target_tag_id');
}

// Abre la lista de etiquetas disponibles para elegir el destino de la fusión.
function openMergeSuggestions() {
    mergeSuggestionsOpen.value = true;
}

function toggleMergeSuggestions() {
    mergeSuggestionsOpen.value = !mergeSuggestionsOpen.value;
}

// Mantiene sincronizada la búsqueda del selector de fusión. Si el usuario
// modifica el nombre de una selección previa, elimina su identificador asociado.
function handleMergeSearchInput() {
    if (selectedMergeTarget.value && mergeSearch.value !== selectedMergeTarget.value.name) {
        mergeForm.target_tag_id = '';
    }

    mergeSuggestionsOpen.value = true;
    mergeForm.clearErrors('target_tag_id');
}

// Cierra únicamente la lista de sugerencias sin modificar el resto del modal.
function closeMergeSuggestions() {
    mergeSuggestionsOpen.value = false;
}

// Cierra las sugerencias cuando se hace clic fuera del buscador de fusión,
// conservándolas abiertas mientras la interacción ocurre dentro del control.
function handleMergeSelectorPointerDown(event) {
    if (!mergeSuggestionsOpen.value || !mergeSearchControl.value) {
        return;
    }

    if (!mergeSearchControl.value.contains(event.target)) {
        closeMergeSuggestions();
    }
}

// Fusiona relaciones de noticias hacia la etiqueta elegida como principal.
function mergeTag() {
    if (!canSubmitMergeTag.value) {
        return;
    }

    operationError.value = '';
    // mergeForm.post usa Inertia para enviar un POST HTTP normal a Laravel.
    // Laravel mueve las relaciones de noticias a la etiqueta destino y refresca
    // los datos del panel mediante props, no mediante una respuesta JSON manual.
    mergeForm.post(route('admin.tags.merge', merging.value.id), {
        preserveScroll: true,
        onStart: () => { mergeSubmitting.value = true; },
        onSuccess: closeMergeTagAfterSuccess,
        onError: (errors) => {
            mergeSubmitting.value = false;
            ensureFormOperationError(
                mergeForm,
                errors,
                'No pudimos fusionar la etiqueta. Intentá nuevamente.',
            );
        },
        onFinish: () => {
            if (mergeForm.wasSuccessful) {
                closeMergeTagAfterSuccess();
            } else {
                mergeSubmitting.value = false;
            }
        },
    });
}

// Activa o desactiva una etiqueta disponible para nuevas noticias.
function toggleTag(tag) {
    operationError.value = '';
    router.patch(route('admin.tags.toggle-active', tag.id), {}, {
        preserveScroll: true,
        onError: (errors) => { operationError.value = errors?.operation || errors?.is_active || errors?.tag || 'No pudimos cambiar la etiqueta. Intentá nuevamente.'; },
    });
}

/* =============================================================================== 
   MODAL
   =============================================================================== */

// Abre el modal si la grilla no detecta uso directo ni una fusión hacia otra etiqueta.
// Laravel vuelve a validar el historial completo antes de borrar.
function openDeleteConfirmation(tag) {
    if (tag.articles_count > 0 || tag.merged_into) {
        return;
    }

    deleteError.value = '';
    deleteTarget.value = tag;
}

// Cierra el modal mientras no haya una eliminación en curso.
function closeDeleteConfirmation() {
    if (deleteProcessing.value) {
        return;
    }

    deleteTarget.value = null;
    deleteError.value = '';
}

// Elimina definitivamente la etiqueta seleccionada.
function confirmDeleteTag() {
    if (!deleteTarget.value || deleteProcessing.value) {
        return;
    }

    deleteProcessing.value = true;
    deleteError.value = '';

    router.delete(
        route('admin.tags.destroy', deleteTarget.value.id),
        {
            preserveScroll: true,

            // Cierra el modal cuando Laravel confirma la eliminación.
            onSuccess: () => {
                deleteTarget.value = null;
                deleteError.value = '';
            },

            onError: (errors) => {
                deleteError.value = errors?.operation
                    || errors?.tag
                    || 'No pudimos eliminar la etiqueta. Intentá nuevamente.';
            },

            // Vuelve a habilitar los controles aunque la petición falle.
            onFinish: () => {
                deleteProcessing.value = false;
            },
        }
    );
}

/*
 * Recibe el nuevo orden calculado por TanStack y lo transforma en los
 * parámetros sort y direction comprendidos por Laravel.
 */
function changeSorting(updater) {
    /*
     * TanStack puede entregar directamente el nuevo estado o una función que
     * calcula el estado siguiente a partir del estado actual.
     */
    const nextSorting = typeof updater === 'function'
        ? updater(sorting.value)
        : updater;

    /*
     * Si no llega una columna, vuelve al orden predeterminado por nombre.
     */
    const selected = nextSorting[0] || {
        id: 'name',
        desc: false,
    };

    /*
     * Mantiene sincronizado el estado local de TanStack.
     */
    sorting.value = [selected];

    /*
     * Convierte el estado de TanStack a los parámetros esperados por Laravel.
     */
    filter.sort = selected.id;
    filter.direction = selected.desc
        ? 'desc'
        : 'asc';

    /*
     * Al cambiar el orden vuelve a la primera página.
     */
    visitList({
        sort: filter.sort,
        direction: filter.direction,
        page: undefined,
    });
}

/*
 * Escape cancela únicamente el modal superior que esté abierto. Las
 * confirmaciones destructivas usan ConfirmActionModal, que gestiona su propio
 * Escape y por eso tienen prioridad. Ningún modal se cierra durante processing.
 */
function handleEscape(event) {
    if (event.key !== 'Escape') {
        return;
    }

    if (deleteTarget.value) {
        return;
    }

    if (merging.value) {
        if (!mergeTagBusy.value) {
            event.preventDefault();
            closeMergeModal();
        }
        return;
    }

    if (editing.value) {
        if (!editTagBusy.value) {
            event.preventDefault();
            editing.value = null;
            editInitial.value = null;
            editForm.clearErrors();
        }
        return;
    }

    if (createOpen.value && !createTagBusy.value) {
        event.preventDefault();
        createOpen.value = false;
        createForm.clearErrors();
    }
}

// Registra los listeners globales utilizados para Escape y para detectar clics
// fuera del selector de fusión mientras la pantalla permanece montada.
onMounted(() => {
    document.addEventListener('keydown', handleEscape);
    document.addEventListener('pointerdown', handleMergeSelectorPointerDown);
});

// Elimina los listeners globales antes de desmontar la pantalla para evitar
// mantener manejadores activos después de abandonar el listado.
onBeforeUnmount(() => {
    document.removeEventListener('keydown', handleEscape);
    document.removeEventListener('pointerdown', handleMergeSelectorPointerDown);
});

</script>

<template>
    <Head title="Etiquetas" />

    <div v-if="loadError" class="operation-error" role="alert"><strong>No se pudo cargar el listado</strong><span>{{ loadError }}</span></div>
    <div v-if="operationError" class="operation-error" role="alert"><strong>No se pudo completar la operación</strong><span>{{ operationError }}</span></div>

    <section class="admin-toolbar editorial-toolbar tags-toolbar-v2">
        <!--
            Igual que en Noticias y Moderación, el texto se aplica únicamente
            al presionar Buscar. Los combos filtran en el momento del cambio.
        -->
        <form
            class="admin-filter-form compact editorial-search-form tags-search-form"
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
                placeholder="Buscar etiqueta..."
                aria-label="Buscar etiqueta"
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
            class="editorial-filter-control tags-status-filter"
            name="status"
            aria-label="Filtrar etiquetas por estado"
            @change="changeStatus"
        >
            <option value="">Todos los estados</option>
            <option value="active">Activas</option>
            <option value="inactive">Inactivas</option>
            <option value="merged">Fusionadas</option>
        </select>

        <select
            v-model="filter.usage"
            class="editorial-filter-control tags-usage-filter"
            name="usage"
            aria-label="Filtrar etiquetas por uso"
            @change="changeUsage"
        >
            <option value="">Todas</option>
            <option value="with_articles">Con noticias</option>
            <option value="without_articles">Sin uso</option>
        </select>

        <button
            type="button"
            class="editorial-filter-clear"
            :disabled="!hasActiveFilters"
            @click="resetFilters"
        >
            Limpiar
        </button>

        <div class="admin-toolbar-summary tags-active-counter">
            {{ activeCounter }}
        </div>

        <button
            type="button"
            class="admin-primary-action tags-create-button"
            @click="createSubmitting = false; createOpen = true"
        >
            <TagIcon :size="18" />
            Nueva etiqueta
        </button>
    </section>

    <section v-if="!loadError" class="editorial-table-panel">
        <div class="editorial-table-scroll">
            <table class="editorial-table admin-tanstack-table admin-tags-table">
                <thead>
                    <tr v-for="headerGroup in table.getHeaderGroups()" :key="headerGroup.id">
                        <th v-for="header in headerGroup.headers" :key="header.id" :class="{ sortable: header.column.getCanSort(), numeric: header.column.id === 'articles_count', actions: header.column.id === 'actions' }">
                            <button v-if="header.column.getCanSort()" type="button" class="editorial-sort-button" @click="header.column.getToggleSortingHandler()?.($event)">
                                <FlexRender :header="header" />
                                <span class="sort-indicator" aria-hidden="true">{{ header.column.getIsSorted() === 'asc' ? '▲' : header.column.getIsSorted() === 'desc' ? '▼' : '↕' }}</span>
                            </button>
                            <FlexRender v-else-if="!header.isPlaceholder" :header="header" />
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in table.getRowModel().rows"
                        :key="row.id"
                        class="editorial-table-row admin-tags-table-row"
                        :class="{ 'admin-tags-table-row--locked': row.original.merged_into }"
                        :tabindex="row.original.merged_into ? undefined : 0"
                        @click="openEdit(row.original)"
                        @keydown.enter="openEdit(row.original)"
                    >
                        <td v-for="cell in row.getAllCells()" :key="cell.id" :class="{ numeric: cell.column.id === 'articles_count', 'admin-numeric-value-centered': cell.column.id === 'articles_count', actions: cell.column.id === 'actions' }">
                            <FlexRender :cell="cell" />
                        </td>
                    </tr>
                    <tr v-if="!table.getRowModel().rows.length"><td :colspan="columns.length" class="empty-state editorial-empty-state">No se encontraron etiquetas.</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <AdminPagination
        v-if="!loadError && tags?.pagination"
        :pagination="tags.pagination"
        :per-page="filter.per_page"
        :per-page-options="[25, 50, 100]"
        item-label="etiquetas"
        @update:per-page="changePerPage"
    />

    <!-- Confirma el borrado cuando no hay uso directo visible; Laravel revalida también el historial de fusión. -->
    <ConfirmActionModal
        :open="deleteTarget !== null"
        eyebrow="Eliminar etiqueta"
        :title="deleteTarget ? `¿Eliminar la etiqueta ${deleteTagDisplayName(deleteTarget.name)}?` : ''"
        message="La etiqueta se eliminará definitivamente y esta acción no se puede deshacer."
        help="Esta opción solo está disponible cuando la etiqueta no está asociada a ninguna noticia ni conserva historial de fusión."
        :error="deleteError"
        confirm-label="Eliminar etiqueta"
        processing-label="Eliminando..."
        :processing="deleteProcessing"
        danger
        @confirm="confirmDeleteTag"
        @cancel="closeDeleteConfirmation"
    />

    <!-- Modal para crear una nueva etiqueta. -->
    <div
        v-if="createOpen"
        class="admin-modal-backdrop"
    >
        <form
            class="admin-modal admin-modal-refined"
            @submit.prevent="storeTag"
        >
            <header>
                <div>
                    <p class="eyebrow">
                        Contenido
                    </p>

                    <h2>
                        Nueva etiqueta
                    </h2>

                    <p class="admin-modal-subtitle">
                        Creá una referencia temática reutilizable para las noticias.
                    </p>
                </div>

                <button
                    type="button"
                    class="admin-modal-close"
                    aria-label="Cerrar"
                    :disabled="createTagBusy"
                    @click="createOpen = false"
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

            <!-- Nombre corto de la etiqueta. -->
            <label class="admin-tag-field">
                <span>Nombre</span>

                <input
                    v-model="createForm.name"
                    required
                    :minlength="TAG_NAME_MIN"
                    :maxlength="TAG_NAME_MAX"
                    @blur="normalizeTagField(createForm, 'name')"
                />

                <span
                    v-if="createForm.errors.name"
                    class="form-error"
                >
                    {{ createForm.errors.name }}
                </span>

                <small
                    class="admin-tag-character-counter"
                    :class="{
                        'admin-tag-character-counter--warning':
                            createForm.name.trim().length < TAG_NAME_MIN,
                    }"
                >
                    {{ tagCounterText(createNameLength, TAG_NAME_MIN, TAG_NAME_MAX) }}
                </small>
            </label>

            <!-- Descripción breve que explica el alcance temático. -->
            <label class="admin-tag-field">
                <span>Descripción</span>

                <textarea
                    v-model="createForm.description"
                    required
                    rows="4"
                    :minlength="TAG_DESCRIPTION_MIN"
                    :maxlength="TAG_DESCRIPTION_MAX"
                    @keydown.enter.prevent
                    @blur="normalizeTagField(createForm, 'description')"
                />

                <span
                    v-if="createForm.errors.description"
                    class="form-error"
                >
                    {{ createForm.errors.description }}
                </span>

                <small
                    class="admin-tag-character-counter"
                    :class="{
                        'admin-tag-character-counter--warning':
                            createForm.description.trim().length
                                < TAG_DESCRIPTION_MIN,
                    }"
                >
                    {{ tagCounterText(createDescriptionLength, TAG_DESCRIPTION_MIN, TAG_DESCRIPTION_MAX) }}
                </small>
            </label>

            <p class="admin-limit-note">
                La etiqueta se crea activa. Después podés desactivarla desde la grilla.
            </p>

            <footer class="admin-modal-footer">
                <button
                    type="button"
                    class="admin-secondary-button"
                    :disabled="createTagBusy"
                    @click="createOpen = false"
                >
                    Cancelar
                </button>

                <button
                    class="admin-gold-button"
                    :disabled="createTagBusy || !canSubmitCreateTag"
                >
                    {{ createTagBusy ? 'Creando...' : 'Crear etiqueta' }}
                </button>
            </footer>
        </form>
    </div>

    <!-- Modal para editar una etiqueta existente. -->
    <div
        v-if="editing"
        class="admin-modal-backdrop"
    >
        <form
            class="admin-modal admin-modal-refined"
            @submit.prevent="updateTag"
        >
            <header>
                <div>
                    <p class="eyebrow">
                        Contenido
                    </p>

                    <h2>
                        Editar etiqueta
                    </h2>

                    <p class="admin-modal-subtitle">
                        El slug histórico #{{ editing.slug }} se conserva aunque
                        cambie el nombre.
                    </p>
                </div>

                <button
                    type="button"
                    class="admin-modal-close"
                    aria-label="Cerrar"
                    :disabled="editTagBusy"
                    @click="editing = null"
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

            <!-- Nombre corto de la etiqueta. -->
            <label class="admin-tag-field">
                <span>Nombre</span>

                <input
                    v-model="editForm.name"
                    required
                    :minlength="TAG_NAME_MIN"
                    :maxlength="TAG_NAME_MAX"
                    @blur="normalizeTagField(editForm, 'name')"
                />

                <span
                    v-if="editForm.errors.name"
                    class="form-error"
                >
                    {{ editForm.errors.name }}
                </span>

                <small
                    class="admin-tag-character-counter"
                    :class="{
                        'admin-tag-character-counter--warning':
                            editForm.name.trim().length < TAG_NAME_MIN,
                    }"
                >
                    {{ tagCounterText(editNameLength, TAG_NAME_MIN, TAG_NAME_MAX) }}
                </small>
            </label>

            <!-- Descripción breve de la etiqueta. -->
            <label class="admin-tag-field">
                <span>Descripción</span>

                <textarea
                    v-model="editForm.description"
                    required
                    rows="4"
                    :minlength="TAG_DESCRIPTION_MIN"
                    :maxlength="TAG_DESCRIPTION_MAX"
                    @keydown.enter.prevent
                    @blur="normalizeTagField(editForm, 'description')"
                />

                <span
                    v-if="editForm.errors.description"
                    class="form-error"
                >
                    {{ editForm.errors.description }}
                </span>

                <small
                    class="admin-tag-character-counter"
                    :class="{
                        'admin-tag-character-counter--warning':
                            editForm.description.trim().length
                                < TAG_DESCRIPTION_MIN,
                    }"
                >
                    {{ tagCounterText(editDescriptionLength, TAG_DESCRIPTION_MIN, TAG_DESCRIPTION_MAX) }}
                </small>
            </label>

            <p class="admin-limit-note">
                La disponibilidad se cambia desde la columna Acciones de la grilla.
            </p>

            <footer class="admin-modal-footer">
                <button
                    type="button"
                    class="admin-secondary-button"
                    :disabled="editTagBusy"
                    @click="editing = null"
                >
                    Cancelar
                </button>

                <button
                    class="admin-gold-button"
                    :disabled="editTagBusy || !canSubmitEditTag || !editTagChanged"
                >
                    {{ editTagBusy ? 'Guardando...' : 'Guardar cambios' }}
                </button>
            </footer>
        </form>
    </div>

    <div 
        v-if="merging" 
        class="admin-modal-backdrop"
    >
        <form class="admin-modal admin-modal-refined admin-tag-merge-modal" @submit.prevent="mergeTag">
            <header>
                <div class="admin-tag-merge-modal__heading">
                    <p class="eyebrow">Fusión segura</p>
                    <h2>
                        Fusionar
                        <span :title="merging.name">“{{ merging.name }}”</span>
                    </h2>
                    <p class="admin-modal-subtitle">
                        Elegí la etiqueta principal que va a reemplazarla.
                    </p>
                </div>

                <button
                    type="button"
                    class="admin-modal-close"
                    :disabled="mergeTagBusy"
                    @click="closeMergeModal"
                >
                    <X />
                </button>
            </header>

            <div class="admin-info-box admin-tag-merge-modal__info">
                <strong>No se pierde contenido.</strong>
                <p>
                    Las noticias que actualmente usan
                    <b :title="merging.name">“{{ merging.name }}”</b>
                    pasarán automáticamente a utilizar la etiqueta seleccionada.
                    La etiqueta original quedará marcada como fusionada y dejará
                    de estar disponible para nuevas noticias.
                </p>
                <small>
                    Usá esta opción únicamente cuando dos etiquetas representen
                    el mismo tema o sean duplicadas.
                </small>
            </div>

            <p v-if="mergeForm.errors.operation" class="operation-error" role="alert">
                {{ mergeForm.errors.operation }}
            </p>

            <label>
                Etiqueta principal
                <div ref="mergeSearchControl" class="tag-search-control admin-tag-merge-search">
                    <input
                        v-model="mergeSearch"
                        type="search"
                        placeholder="Buscar etiqueta..."
                        autocomplete="off"
                        :disabled="mergeTagBusy"
                        @click="toggleMergeSuggestions"
                        @input="handleMergeSearchInput"
                    />

                    <button
                        v-if="mergeSuggestionsOpen && !mergeTagBusy"
                        type="button"
                        class="tag-search-close"
                        aria-label="Cerrar lista de etiquetas"
                        @click="closeMergeSuggestions"
                    >
                        <X :size="16" />
                    </button>

                    <button
                        type="button"
                        class="tag-search-chevron admin-tag-merge-chevron"
                        aria-label="Abrir lista de etiquetas"
                        :aria-expanded="mergeSuggestionsOpen"
                        :disabled="mergeTagBusy"
                        @click="toggleMergeSuggestions"
                    >
                        <ChevronDown :size="16" />
                    </button>

                    <div
                        v-if="mergeSuggestionsOpen"
                        class="tag-suggestions admin-tag-merge-suggestions"
                    >
                        <button
                            v-for="tag in mergeTagSuggestions"
                            :key="tag.id"
                            type="button"
                            @mousedown.prevent="selectMergeTarget(tag)"
                        >
                            <span>{{ tag.name }}</span>
                        </button>
                        <p v-if="!mergeTagSuggestions.length">
                            No se encontraron etiquetas disponibles.
                        </p>
                    </div>
                </div>
                <span v-if="mergeForm.errors.target_tag_id" class="form-error">
                    {{ mergeForm.errors.target_tag_id }}
                </span>
            </label>

            <footer class="admin-modal-footer">
                <button
                    type="button"
                    class="admin-secondary-button"
                    :disabled="mergeTagBusy"
                    @click="closeMergeModal"
                >
                    Cancelar
                </button>
                <button class="admin-gold-button" :disabled="!canSubmitMergeTag">
                    <GitMerge :size="17" />
                    {{ mergeTagBusy ? 'Fusionando...' : 'Fusionar etiqueta' }}
                </button>
            </footer>
        </form>
    </div>
</template>

<style src="../../../../css/admin/tags.css"></style>
