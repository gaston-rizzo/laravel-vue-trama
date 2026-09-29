<script setup>
/* ============================================================================
 * PAGE: Admin/Comments/Index.vue
 * ============================================================================
 *
 * Grilla editorial de moderación de comentarios construida con TanStack Table.
 *
 * La pantalla utiliza la misma arquitectura general que el listado de Noticias:
 *
 *      - Laravel busca, filtra, ordena y pagina;
 *      - TanStack representa solamente las filas de la página actual;
 *      - los filtros viven en la URL;
 *      - el ordenamiento es manual y se ejecuta en el servidor;
 *      - la cantidad por página puede ser 25, 50 o 100;
 *      - la tabla conserva el scroll al navegar mediante Inertia.
 *
 * La grilla incluye acciones rápidas para aprobar, rechazar o retirar comentarios
 * directamente desde el listado de moderación. Al seleccionar una fila se abre
 * un modal con el contenido completo, el contexto y los reportes disponibles.
 * ============================================================================ */

import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { computed, h, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { FlexRender, rowSortingFeature, tableFeatures, useTable } from '@tanstack/vue-table';
import { CalendarDays, Check, Flag, MessageSquare, MessagesSquare, RotateCcw, Search, ShieldAlert, X } from '@lucide/vue';
import { VueDatePicker } from '@vuepic/vue-datepicker';
import { es } from 'date-fns/locale';
import AdminPagination from '@/Components/Admin/AdminPagination.vue';
import CommentModerationModal from '@/Components/Admin/CommentModerationModal.vue';

/*
 * Propiedades enviadas por App\Http\Controllers\Admin\CommentController.
 */
// Props globales compartidas por Laravel, incluida la fecha editorial vigente.
const page = usePage();

const props = defineProps({    
    // Filtros actualmente aplicados por Laravel.     
    filters: {
        type: Object,
        required: true,
    },
    // Filas de la página actual e información de paginación.     
    comments: {
        type: Object,
        required: true,
    },    
    // Conteos utilizados por las pestañas superiores.    
    queueCounts: {
        type: Object,
        default: () => ({
            total: 0,
            pending: 0,
            reported: 0,
            rejected: 0,
            automatic_rejected: 0,
            approved: 0,
            attention: 0,
        }),
    },    
    // Mensaje amigable cuando Laravel no pudo completar la carga de la grilla.     
    loadError: {
        type: String,
        default: '',
    },
});

/*
 * TanStack v9 registra solamente la funcionalidad utilizada por la pantalla.
 *
 * La API de sorting es necesaria para representar el estado de los encabezados,
 * pero manualSorting evita que el navegador reordene solamente las 25 filas que
 * ya tiene cargadas. El orden real siempre lo decide Laravel.
 */
const features = tableFeatures({
    rowSortingFeature,
});

/*
 * Estado local de los controles visibles de la barra de filtros.
 */
const form = reactive({
    search: props.filters.search || '',
    type: props.filters.type || '',
    author: props.filters.author || '',
    article: props.filters.article || '',
    date_from: props.filters.date_from || '',
    date_to: props.filters.date_to || '',
    per_page: Number(props.filters.per_page || 25),
});

/*
 * Comentario que actualmente está siendo aprobado, rechazado o retirado.
 *
 * Se utiliza para impedir acciones simultáneas mientras Inertia procesa la
 * actualización del estado.
 */
const processingCommentId = ref(null);
// Error amigable de una acción rápida; nunca contiene el detalle técnico del backend.
const actionError = ref('');

/*
 * Comentario seleccionado para abrir el modal de moderación.
 *
 * El listado conserva solamente el id porque el detalle completo se solicita
 * a Laravel cuando CommentModerationModal se encuentra visible.
 */
const selectedCommentId = ref(null);
const isModerationModalOpen = ref(false);

/*
 * Control del cuadro flotante utilizado para filtrar por fecha.
 */
const isDateFilterOpen = ref(false);
const dateFilterContainer = ref(null);

/*
 * Temporizadores independientes para evitar una petición por cada tecla en los
 * filtros de autor y noticia.
 */
let authorFilterTimer = null;
let articleFilterTimer = null;

/*
 * TanStack representa el ordenamiento mediante un arreglo.
 *
 * TRAMA permite una sola columna ordenada por vez.
 */
const sorting = ref([
    {
        id: props.filters.sort || 'created_at',
        desc: (props.filters.direction || 'desc') === 'desc',
    },
]);

/*
 * Formato visible dentro de VueDatePicker.
 * Laravel continúa recibiendo YYYY-MM-DD.
 */
const datePickerFormats = {
    input: 'dd/MM/yyyy',
};

/*
 * Opciones principales utilizadas para filtrar la grilla de moderación.
 *
 * Reportados no corresponde a comments.status: representa comentarios que
 * poseen al menos un reporte abierto.
 *
 * Rechazados representa `status = rejected` resuelto editorialmente.
 * Rechazos automáticos usa el mismo status pero `moderation_source = automatic`.
 */
const queueTabs = [
    {
        value: '',
        label: 'Todos',
        countKey: 'total',
    },
    {
        value: 'pending',
        label: 'Pendientes',
        countKey: 'pending',
    },
    {
        value: 'reported',
        label: 'Reportados',
        countKey: 'reported',
    },
    {
        value: 'rejected',
        label: 'Rechazados',
        countKey: 'rejected',
    },
    {
        value: 'automatic_rejected',
        label: 'Rechazos automáticos',
        countKey: 'automatic_rejected',
    },
    {
        value: 'approved',
        label: 'Aprobados',
        countKey: 'approved',
    },
];

/*
 * Definición completa de columnas de TanStack Table.
 *
 * Los id ordenables coinciden exactamente con CommentIndexRequest.
 */
const columns = [
    /* Estado de moderación. */
    {
        id: 'status',
        accessorKey: 'status',
        header: 'Estado',
        enableSorting: true,
        cell: (info) => {
            const comment = info.row.original;
            const label = statusLabels[info.getValue()]
                || info.getValue();

            return h(
                'div',
                {
                    class: 'comment-moderation-status-cell',
                },
                [
                    h(
                        'span',
                        {
                            class: [
                                'status-badge',
                                `status-${info.getValue()}`,
                            ],
                        },
                        label,
                    ),
                    comment.status === 'rejected'
                        && comment.moderation_source === 'automatic'
                        ? h(
                            'small',
                            {
                                class: 'comment-moderation-source-label',
                            },
                            'Automático',
                        )
                        : null,
                ],
            );
        },
    },

    /*
     * Fragmento del comentario.
     *
     * No se ordena por texto porque no aporta valor editorial y obligaría a
     * ordenar una columna larga de contenido libre.
     */
    {
        id: 'comment',
        accessorFn: (comment) => comment.body_preview || '',
        header: 'Comentario',
        enableSorting: false,
        cell: (info) => h(
            'div',
            {
                class: 'comment-moderation-text-cell',
            },
            [
                h(
                    'p',
                    info.getValue() || 'Sin contenido',
                ),
            ],
        ),
    },

    /* Autor actual o histórico del comentario. */
    {
        id: 'author',
        accessorFn: (comment) => comment.author?.name || '',
        header: 'Autor',
        enableSorting: true,
        cell: (info) => {
            const author = info.row.original.author || {};
            const children = [
                h(
                    'strong',
                    author.name || 'Cuenta eliminada',
                ),
            ];

            if (author.email) {
                children.push(
                    h(
                        'small',
                        author.email,
                    ),
                );
            }

            if (author.context_label) {
                children.push(
                    h(
                        'span',
                        {
                            class: 'comment-moderation-author-context',
                        },
                        author.context_label,
                    ),
                );
            } else if (author.account_blocked) {
                children.push(
                    h(
                        'span',
                        {
                            class: 'comment-moderation-author-blocked-badge',
                        },
                        'CUENTA BLOQUEADA',
                    ),
                );
            }

            return h(
                'div',
                {
                    class: 'comment-moderation-author-cell',
                },
                children,
            );
        },
    },

   /*
    * Noticia a la que pertenece el comentario o la respuesta.
    *
    * El título abre la publicación en el sitio público para que el editor
    * pueda consultar el contexto del comentario sin ingresar al formulario
    * de edición de la noticia.
    */
    {
        id: 'article',
        accessorFn: (comment) => comment.article?.title || '',
        header: 'Noticia',
        enableSorting: true,
        cell: (info) => {
            const article = info.row.original.article;

            if (!article) {
                return h(
                    'span',
                    {
                        class: 'comment-moderation-muted',
                    },
                    'Sin noticia',
                );
            }

            return h(
                Link,
                {
                    href: route(
                        'articles.show',
                        article.slug,
                    ),
                    class: 'comment-moderation-article-link',
                    onClick: (event) => {
                        /*
                         * El enlace abre la noticia pública y no el modal de la fila.
                         */
                        event.stopPropagation();
                    },
                },
                () => article.title,
            );
        },
    },

    /* Comentario principal o respuesta. */
    {
        id: 'type',
        accessorKey: 'type',
        header: 'Tipo',
        enableSorting: true,
        cell: (info) => {
            const isReply = info.getValue() === 'reply';
            const Icon = isReply
                ? MessagesSquare
                : MessageSquare;

            return h(
                'span',
                {
                    class: [
                        'comment-moderation-type',
                        isReply
                            ? 'comment-moderation-type--reply'
                            : 'comment-moderation-type--comment',
                    ],
                },
                [
                    h(Icon, {
                        size: 14,
                        'aria-hidden': 'true',
                    }),
                    h(
                        'span',
                        isReply
                            ? 'Respuesta'
                            : 'Comentario',
                    ),
                ],
            );
        },
    },

    /* Cantidad de reportes abiertos. */
    {
        id: 'reports',
        accessorKey: 'reports_count',
        header: 'Reportes',
        enableSorting: true,
        cell: (info) => {
            const count = Number(
                info.getValue()
                || 0,
            );

            if (count <= 0) {
                return h(
                    'span',
                    {
                        class: 'comment-moderation-report-empty',
                    },
                    '—',
                );
            }

            return h(
                'span',
                {
                    class: 'comment-moderation-report-count',
                    title: `${count} reporte${count === 1 ? '' : 's'} abierto${count === 1 ? '' : 's'}`,
                },
                [
                    h(Flag, {
                        size: 14,
                        'aria-hidden': 'true',
                    }),
                    h(
                        'strong',
                        String(count),
                    ),
                ],
            );
        },
    },

    /* Fecha de creación del comentario o respuesta. */
    {
        id: 'created_at',
        accessorKey: 'created_at',
        header: 'Fecha',
        enableSorting: true,
        cell: (info) => formatDate(
            info.getValue(),
        ),
    },

    /*
     * Acciones rápidas disponibles desde la grilla de moderación.
     */
    {
        id: 'actions',
        header: 'Acciones',
        enableSorting: false,
        cell: (info) => {
            const comment = info.row.original;
            const actions = [];

            /*
            * Aprobar solamente corresponde a comentarios pendientes.
            *
            * Un comentario rechazado es definitivo y no se puede volver a aprobar
            */
            if (comment.status === 'pending') {
                 // Agrega el botón "Aprobar" únicamente cuando el comentario está pendiente.
                actions.push(
                    moderationActionButton(
                        comment,
                        'approve',
                        'Aprobar',
                        'approve',
                        Check,
                    ),
                );
            }

            /*
             * En la cola Reportados, un comentario aprobado con reportes abiertos
             * puede mantenerse publicado directamente desde la grilla.
             *
             * Mantener conserva status=approved y cierra los reportes abiertos como
             * revisados, igual que la acción disponible dentro del modal.
             */
            if (
                (props.filters.queue || '') === 'reported'
                && comment.status === 'approved'
                && Number(comment.reports_count || 0) > 0
            ) {
                actions.push(
                    moderationActionButton(
                        comment,
                        'maintain',
                        'Mantener',
                        'maintain',
                        Check,
                    ),
                );
            }

            /*
             * La acción interna reject está disponible mientras el comentario no haya
             * sido rechazado; la interface dice "Retirar" cuando ya estaba aprobado.
             */
            if (comment.status !== 'rejected') {
                // Agrega "Rechazar" para un pendiente o "Retirar" para un aprobado.
                // Ambas opciones utilizan internamente la acción reject.
                actions.push(
                    moderationActionButton(
                        comment,
                        'reject',
                        comment.status === 'approved' ? 'Retirar' : 'Rechazar',
                        'reject',
                        X,
                    ),
                );
            }

            return h(
                'div',
                {
                    class: 'comment-moderation-actions',
                },
                actions,
            );
        },
    },
];

/*
 * Las filas se leen directamente desde las props para actualizar la tabla cada
 * vez que Inertia reemplaza la página actual.
 */
const tableData = computed(() => props.comments.data || []);

/*
 * Instancia TanStack con ordenamiento completamente delegado a Laravel.
 */
const table = useTable({
    key: 'editorial-comments-table',
    features,
    columns,
    data: tableData,

    /*
     * Utiliza el identificador real del comentario para que Vue y TanStack
     * puedan distinguir correctamente las filas cuando Laravel reemplaza
     * los resultados después de aplicar filtros, ordenamiento o paginación.
     */
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

/*
 * Traducción visual de los estados internos de comments.status.
 */
const statusLabels = {
    pending: 'Pendiente',
    approved: 'Aprobado',
    rejected: 'Rechazado',
};

/*
 * Texto actualmente escrito dentro del buscador de comentarios.
 */
const normalizedSearch = computed(() => form.search.trim());

/*
 * Búsqueda que Laravel tiene aplicada en este momento.
 */
const appliedSearch = computed(() => (
    props.filters.search || ''
).trim());

/*
 * El botón Buscar se habilita cuando:
 *
 * - existen al menos tres caracteres y el valor cambió; o
 * - había una búsqueda aplicada y el usuario vació la caja para quitarla.
 */
const canApplySearch = computed(() => {
    if (
        normalizedSearch.value === ''
        && appliedSearch.value !== ''
    ) {
        return true;
    }

    return normalizedSearch.value.length >= 3
        && normalizedSearch.value !== appliedSearch.value;
});

/*
 * Indica si existe cualquier filtro visible aplicado.
 *
 * No incluye ordenamiento, página ni cantidad por página porque esos valores
 * pertenecen a la forma de recorrer la grilla, no al conjunto filtrado.
 */
const hasActiveFilters = computed(() => Boolean(
    props.filters.queue
    || (props.filters.search || '').trim()
    || props.filters.type
    || (props.filters.author || '').trim()
    || (props.filters.article || '').trim()
    || props.filters.date_from
    || props.filters.date_to
));

/*
 * La moderación usa los mismos timestamps editoriales que el resto de TRAMA.
 * Por eso el calendario toma el día compartido por Laravel y no el día real
 * del navegador. Si TRAMA está en 19/07/2026, el filtro también abre en 19/07.
 */
const todayObject = computed(() => (
    parseLocalDate(page.props.site?.reference_date)
    || new Date(2026, 6, 19)
));

/*
 * La fecha Desde no puede superar Hasta cuando esa fecha ya fue elegida.
 */
const dateFromMaximum = computed(() => (
    parseLocalDate(form.date_to)
    || todayObject.value
));

/*
 * Hasta no puede quedar antes de Desde.
 */
const dateToMinimum = computed(() => (
    parseLocalDate(form.date_from)
));

/*
 * "Limpiar fecha" solamente se habilita cuando Laravel tiene un rango aplicado.
 */
const canClearDateFilter = computed(() => Boolean(
    props.filters.date_from
    || props.filters.date_to
));

/*
 * Texto visible dentro del botón del filtro de fechas.
 */
const dateFilterLabel = computed(() => {
    const dateFrom = props.filters.date_from || '';
    const dateTo = props.filters.date_to || '';

    if (dateFrom && dateTo) {
        if (dateFrom === dateTo) {
            return formatFilterDate(dateFrom);
        }

        return `${formatFilterDate(dateFrom)}–${formatFilterDate(dateTo)}`;
    }

    if (dateFrom) {
        return `Desde ${formatFilterDate(dateFrom)}`;
    }

    if (dateTo) {
        return `Hasta ${formatFilterDate(dateTo)}`;
    }

    return 'Fecha';
});

/*
 * Mantiene el icono cuando el texto del botón todavía tiene espacio suficiente.
 */
const showDateButtonIcon = computed(() => {
    const dateFrom = props.filters.date_from || '';
    const dateTo = props.filters.date_to || '';

    if (!dateFrom && !dateTo) {
        return true;
    }

    return Boolean(
        dateFrom
        && dateTo
        && dateFrom === dateTo
    );
});

/*
 * Convierte YYYY-MM-DD en Date local sin pasar por UTC.
 */
function parseLocalDate(value) {
    if (!value) {
        return null;
    }

    const [
        year,
        month,
        day,
    ] = value
        .split('-')
        .map(Number);

    if (!year || !month || !day) {
        return null;
    }

    return new Date(
        year,
        month - 1,
        day,
    );
}

/*
 * Formatea comments.created_at para la columna Fecha.
 */
function formatDate(value) {
    if (!value) {
        return 'Sin fecha';
    }

    return new Intl.DateTimeFormat('es-AR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(new Date(value));
}

/*
 * Formato DD/MM utilizado dentro del botón compacto de fecha.
 */
function formatFilterDate(value) {
    if (!value) {
        return '';
    }

    const [
        year,
        month,
        day,
    ] = value.split('-');

    if (!year || !month || !day) {
        return '';
    }

    return `${day}/${month}`;
}

/*
 * Abre o cierra el cuadro de fechas recuperando el rango aplicado.
 */
function toggleDateFilter() {
    if (!isDateFilterOpen.value) {
        form.date_from = props.filters.date_from || '';
        form.date_to = props.filters.date_to || '';
    }

    isDateFilterOpen.value = !isDateFilterOpen.value;
}

/*
 * Cierra el cuadro de fechas cuando el usuario hace click fuera.
 */
function closeDateFilterOnOutsideClick(event) {
    if (!isDateFilterOpen.value) {
        return;
    }

    const target = event.target;

    if (!(target instanceof Element)) {
        return;
    }

    if (
        dateFilterContainer.value?.contains(target)
        || target.closest('.dp--menu')
    ) {
        return;
    }

    isDateFilterOpen.value = false;
}

/*
 * Devuelve el número correspondiente a una pestaña.
 */
function queueCount(tab) {
    return Number(
        props.queueCounts?.[tab.countKey]
        || 0,
    );
}

/*
 * Una pestaña activa o sin resultados no necesita ser un enlace navegable.
 */
function isQueueTabStatic(tab) {
    return (props.filters.queue || '') === tab.value
        || queueCount(tab) === 0;
}

/*
 * Construye los parámetros conservados entre búsquedas, filtros y ordenamiento.
 *
 * page se elimina deliberadamente: cualquier cambio del conjunto de resultados
 * debe comenzar desde la página 1.
 */
function buildQuery(overrides = {}) {
    const query = {
        queue: props.filters.queue || undefined,
        search: props.filters.search || undefined,
        type: props.filters.type || undefined,
        author: props.filters.author || undefined,
        article: props.filters.article || undefined,
        date_from: props.filters.date_from || undefined,
        date_to: props.filters.date_to || undefined,
        sort: props.filters.sort || 'created_at',
        direction: props.filters.direction || 'desc',
        per_page: Number(props.filters.per_page || 25),
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

/*
 * Navega la grilla mediante Inertia y conserva exactamente la posición de la
 * ventana. Esto evita saltos visibles cuando cambia un filtro u ordenamiento.
 */
function visitList(overrides = {}) {
    const scrollX = window.scrollX;
    const scrollY = window.scrollY;

    router.get(
        route('admin.comments.index'),
        buildQuery(overrides),
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,

            onSuccess: async () => {
                await nextTick();

                const documentElement = document.documentElement;
                const previousScrollBehavior =
                    documentElement.style.scrollBehavior;

                documentElement.style.scrollBehavior = 'auto';

                window.scrollTo({
                    left: scrollX,
                    top: scrollY,
                    behavior: 'auto',
                });

                documentElement.style.scrollBehavior =
                    previousScrollBehavior;
            },
        },
    );
}

/*
 * URL utilizada por cada filtro principal de moderación.
 */
function queueFilterUrl(tab) {
    return route(
        'admin.comments.index',
        buildQuery({
            queue: tab.value || undefined,
        }),
    );
}

function shouldUseBrowserNavigation(event) {
    return event.defaultPrevented
        || event.button !== 0
        || event.metaKey
        || event.ctrlKey
        || event.shiftKey
        || event.altKey;
}

function visitQueueFilter(event, tab) {
    if (
        !event.currentTarget?.getAttribute('href')
        || shouldUseBrowserNavigation(event)
    ) {
        return;
    }

    event.preventDefault();

    visitList({
        queue: tab.value || undefined,
    });
}

/*
 * URL utilizada por las métricas del resumen superior.
 *
 * Conserva los filtros secundarios, el ordenamiento y la cantidad por página
 * mientras cambia únicamente el grupo principal mostrado en la grilla.
 */
function overviewFilterUrl(queue) {
    return route(
        'admin.comments.index',
        buildQuery({
            queue,
        }),
    );
}

/*
 * Aplica la búsqueda por contenido del comentario.
 */
function applySearch() {
    if (!canApplySearch.value) {
        return;
    }

    visitList({
        search: normalizedSearch.value || undefined,
    });
}

/*
 * Aplica inmediatamente el tipo elegido.
 */
function changeType() {
    visitList({
        type: form.type || undefined,
    });
}

/*
 * Aplica el filtro específico por autor.
 */
function applyAuthorFilter() {
    clearTimeout(authorFilterTimer);

    const author = form.author.trim();
    const currentAuthor = (props.filters.author || '').trim();

    if (author === currentAuthor) {
        return;
    }

    visitList({
        author: author || undefined,
    });
}

/*
 * Espera 500 ms después de la última tecla antes de consultar Laravel.
 */
function scheduleAuthorFilter() {
    clearTimeout(authorFilterTimer);

    authorFilterTimer = setTimeout(() => {
        applyAuthorFilter();
    }, 500);
}

/*
 * Aplica el filtro específico por título de noticia.
 */
function applyArticleFilter() {
    clearTimeout(articleFilterTimer);

    const article = form.article.trim();
    const currentArticle = (props.filters.article || '').trim();

    if (article === currentArticle) {
        return;
    }

    visitList({
        article: article || undefined,
    });
}

/*
 * Espera 500 ms después de la última tecla antes de consultar Laravel.
 */
function scheduleArticleFilter() {
    clearTimeout(articleFilterTimer);

    articleFilterTimer = setTimeout(() => {
        applyArticleFilter();
    }, 500);
}

/*
 * Aplica el rango de fechas elegido.
 */
function applyDateFilter() {
    isDateFilterOpen.value = false;

    visitList({
        date_from: form.date_from || undefined,
        date_to: form.date_to || undefined,
    });
}

/*
 * Elimina solamente el filtro de fecha.
 */
function clearDateFilter() {
    if (!canClearDateFilter.value) {
        return;
    }

    form.date_from = '';
    form.date_to = '';
    isDateFilterOpen.value = false;

    visitList({
        date_from: undefined,
        date_to: undefined,
    });
}

/*
 * Restablece todos los filtros de contenido y vuelve a la pestaña Todos.
 *
 * Conserva ordenamiento y cantidad por página.
 */
function clearFilters() {
    if (!hasActiveFilters.value) {
        return;
    }

    clearTimeout(authorFilterTimer);
    clearTimeout(articleFilterTimer);

    form.search = '';
    form.type = '';
    form.author = '';
    form.article = '';
    form.date_from = '';
    form.date_to = '';

    isDateFilterOpen.value = false;

    visitList({
        queue: undefined,
        search: undefined,
        type: undefined,
        author: undefined,
        article: undefined,
        date_from: undefined,
        date_to: undefined,
    });
}

/*
 * Cambia la cantidad de filas por página.
 */
function changePerPage(perPage) {
    const selectedPerPage = Number(perPage);

    form.per_page = selectedPerPage;

    visitList({
        per_page: selectedPerPage,
    });
}

/*
 * Convierte el estado de ordenamiento de TanStack a sort/direction para Laravel.
 */
function changeSorting(updater) {
    const nextSorting = typeof updater === 'function'
        ? updater(sorting.value)
        : updater;

    const selected = nextSorting[0] || {
        id: 'created_at',
        desc: true,
    };

    sorting.value = [selected];

    visitList({
        sort: selected.id,
        direction: selected.desc ? 'desc' : 'asc',
    });
}

/*
 * Abre el modal utilizando el identificador real de la fila seleccionada.
 */
function openModerationModal(comment) {
    if (!comment?.id) {
        return;
    }

    selectedCommentId.value = comment.id;
    isModerationModalOpen.value = true;
}

/*
 * Cierra el modal y limpia la selección actual.
 */
function closeModerationModal() {
    isModerationModalOpen.value = false;
    selectedCommentId.value = null;
}

/*
 * Envía una acción rápida de moderación desde la grilla.
 *
 * Permite aprobar, rechazar, retirar o mantener un comentario sin abandonar
 * el listado. Mantener se utiliza para resolver reportes sin retirar el contenido.
 */
function moderate(comment, action) {
    if (
        processingCommentId.value !== null
        || !['approve', 'reject', 'maintain'].includes(action)
    ) {
        return;
    }

    processingCommentId.value = comment.id;
    actionError.value = '';

    router.patch(
        route('admin.comments.update', comment.id),
        { action },
        {
            preserveState: true,
            preserveScroll: true,

            onError: (errors) => {
                actionError.value = errors?.operation
                    || errors?.action
                    || 'No pudimos aplicar la decisión. Intentá nuevamente.';
            },

            onFinish: () => {
                processingCommentId.value = null;
            },
        },
    );
}

/*
 * Construye un botón compacto dentro de la columna Acciones.
 */
function moderationActionButton(
    comment,
    action,
    label,
    variant,
    icon,
) {
    const isProcessing = processingCommentId.value !== null;

    return h(
        'button',
        {
            type: 'button',
            class: [
                'comment-moderation-action',
                `comment-moderation-action--${variant}`,
            ],
            disabled: isProcessing,
            title: label,
            onClick: (event) => {
                /*
                 * La acción rápida no debe abrir también el modal de la fila.
                 */
                event.stopPropagation();

                moderate(
                    comment,
                    action,
                );
            },
        },
        [
            h(icon, {
                size: 15,
                'aria-hidden': 'true',
            }),
            h(
                'span',
                label,
            ),
        ],
    );
}

/*
 * Anchos estables de la grilla de moderación.
 *
 * La tabla usa layout fijo para que comentarios o títulos largos no cambien la
 * distribución al cargar otra página.
 */
function commentColumnWidth(columnId) {
    const widths = {
        status: '10%',
        comment: '24%',
        author: '14%',
        article: '18%',
        type: '9%',
        reports: '8%',
        created_at: '10%',
        actions: '12%',
    };

    return widths[columnId];
}

/*
 * Sincroniza la caja de búsqueda después de una navegación Inertia.
 */
watch(
    () => props.filters.search,
    (search) => {
        form.search = search || '';
    },
);

/*
 * Sincroniza el selector de tipo.
 */
watch(
    () => props.filters.type,
    (type) => {
        form.type = type || '';
    },
);

/*
 * Sincroniza el filtro por autor.
 */
watch(
    () => props.filters.author,
    (author) => {
        form.author = author || '';
    },
);

/*
 * Sincroniza el filtro por noticia.
 */
watch(
    () => props.filters.article,
    (article) => {
        form.article = article || '';
    },
);

/*
 * Sincroniza el rango de fechas aplicado por Laravel.
 */
watch(
    [
        () => props.filters.date_from,
        () => props.filters.date_to,
    ],
    ([dateFrom, dateTo]) => {
        form.date_from = dateFrom || '';
        form.date_to = dateTo || '';
    },
);

/*
 * Mantiene sincronizado el estado visual de ordenamiento cuando la URL cambia
 * por una navegación externa o por el historial del navegador.
 */
watch(
    [
        () => props.filters.sort,
        () => props.filters.direction,
    ],
    ([sort, direction]) => {
        sorting.value = [
            {
                id: sort || 'created_at',
                desc: (direction || 'desc') === 'desc',
            },
        ];
    },
);

/*
 * Si Desde avanza más allá de Hasta, Hasta se limpia para impedir un rango
 * imposible antes de enviar el formulario.
 */
watch(
    () => form.date_from,
    (dateFrom) => {
        if (
            dateFrom
            && form.date_to
            && dateFrom > form.date_to
        ) {
            form.date_to = '';
        }
    },
);

/*
 * Registra el cierre por click externo.
 */
onMounted(() => {
    document.addEventListener(
        'pointerdown',
        closeDateFilterOnOutsideClick,
        true,
    );
});

/*
 * Limpia listeners y búsquedas demoradas al abandonar la pantalla.
 */
onUnmounted(() => {
    clearTimeout(authorFilterTimer);
    clearTimeout(articleFilterTimer);

    document.removeEventListener(
        'pointerdown',
        closeDateFilterOnOutsideClick,
        true,
    );
});
</script>

<template>
    <Head title="Moderación de comentarios" />

    <!--
        Resumen superior de la moderación de comentarios.

        Las tres métricas funcionan también como accesos directos a la grilla.
        Por revisar reúne comentarios pendientes y comentarios con reportes
        abiertos. Las otras dos métricas permiten consultar cada grupo por
        separado sin perder los filtros secundarios aplicados.
    -->
    <section class="comment-moderation-overview">
        <div class="comment-moderation-overview__icon">
            <ShieldAlert
                :size="22"
                aria-hidden="true"
            />
        </div>

        <div class="comment-moderation-overview__copy">
            <strong>Moderación de comentarios</strong>

            <span>
                Revisá participaciones pendientes y reportes enviados por lectores.
            </span>
        </div>

        <div class="comment-moderation-overview__metrics">
            <span
                v-if="(filters.queue || '') === 'attention'
                    || Number(queueCounts.attention || 0) === 0"
                class="comment-moderation-overview__metric comment-moderation-overview__metric--attention"
                :class="{
                    active: (filters.queue || '') === 'attention',
                    disabled: Number(queueCounts.attention || 0) === 0,
                }"
            >
                <strong>{{ queueCounts.attention || 0 }}</strong>
                <span>por revisar</span>
            </span>

            <Link
                v-else
                :href="overviewFilterUrl('attention')"
                class="comment-moderation-overview__metric comment-moderation-overview__metric--attention"
            >
                <strong>{{ queueCounts.attention || 0 }}</strong>
                <span>por revisar</span>
            </Link>

            <span
                v-if="(filters.queue || '') === 'pending'
                    || Number(queueCounts.pending || 0) === 0"
                class="comment-moderation-overview__metric"
                :class="{
                    active: (filters.queue || '') === 'pending',
                    disabled: Number(queueCounts.pending || 0) === 0,
                }"
            >
                <strong>{{ queueCounts.pending || 0 }}</strong>
                <span>pendientes</span>
            </span>

            <Link
                v-else
                :href="overviewFilterUrl('pending')"
                class="comment-moderation-overview__metric"
            >
                <strong>{{ queueCounts.pending || 0 }}</strong>
                <span>pendientes</span>
            </Link>

            <span
                v-if="(filters.queue || '') === 'reported'
                    || Number(queueCounts.reported || 0) === 0"
                class="comment-moderation-overview__metric"
                :class="{
                    active: (filters.queue || '') === 'reported',
                    disabled: Number(queueCounts.reported || 0) === 0,
                }"
            >
                <strong>{{ queueCounts.reported || 0 }}</strong>
                <span>reportados</span>
            </span>

            <Link
                v-else
                :href="overviewFilterUrl('reported')"
                class="comment-moderation-overview__metric"
            >
                <strong>{{ queueCounts.reported || 0 }}</strong>
                <span>reportados</span>
            </Link>
        </div>
    </section>

    <!-- Error recuperable de carga. -->
    <div v-if="actionError" class="operation-error" role="alert">
        <strong>No se pudo completar la moderación</strong>
        <span>{{ actionError }}</span>
    </div>

    <div
        v-if="loadError"
        class="operation-error"
        role="alert"
    >
        <p class="eyebrow">
            No se pudo cargar la moderación
        </p>

        <p>
            {{ loadError }}
        </p>
    </div>

    <!-- Filtros principales de moderación. -->
    <section class="comment-moderation-tabs">
        <component
            v-for="tab in queueTabs"
            :is="isQueueTabStatic(tab) ? 'span' : 'a'"
            :key="`${tab.value || 'all'}-${isQueueTabStatic(tab) ? 'static' : 'link'}`"
            :href="isQueueTabStatic(tab)
                ? undefined
                : queueFilterUrl(tab)"
            class="comment-moderation-tab"
            :class="{
                active: (filters.queue || '') === tab.value,
                disabled: queueCount(tab) === 0,
                reported: tab.value === 'reported',
            }"
            :aria-disabled="queueCount(tab) === 0
                ? 'true'
                : undefined"
            @click="visitQueueFilter($event, tab)"
        >
            <Flag
                v-if="tab.value === 'reported'"
                :size="14"
                aria-hidden="true"
            />

            <span>{{ tab.label }}</span>
            <small>{{ queueCount(tab) }}</small>
        </component>
    </section>

    <!--
        Barra de filtros del mismo sistema visual utilizado por Noticias.
    -->
    <section class="admin-toolbar editorial-toolbar comment-moderation-toolbar-v2">
        <!-- Búsqueda por contenido del comentario. -->
        <form
            class="admin-filter-form compact editorial-search-form"
            @submit.prevent="applySearch"
        >
            <Search
                :size="18"
                aria-hidden="true"
            />

            <input
                v-model="form.search"
                class="editorial-search-input"
                type="search"
                name="search"
                minlength="3"
                maxlength="120"
                placeholder="Buscar en comentario…"
                aria-label="Buscar en el contenido del comentario"
            />

            <button
                type="submit"
                :disabled="!canApplySearch"
            >
                Buscar
            </button>
        </form>

        <!-- Autor o correo con debounce. -->
        <input
            v-model="form.author"
            class="editorial-filter-control comment-moderation-author-filter"
            type="search"
            name="author"
            maxlength="100"
            placeholder="Autor o correo…"
            aria-label="Filtrar comentarios por autor o correo"
            autocomplete="off"
            @input="scheduleAuthorFilter"
            @keydown.enter.prevent="applyAuthorFilter"
        />

        <!-- Noticia con debounce. -->
        <input
            v-model="form.article"
            class="editorial-filter-control comment-moderation-article-filter"
            type="search"
            name="article"
            maxlength="140"
            placeholder="Noticia…"
            aria-label="Filtrar comentarios por noticia"
            autocomplete="off"
            @input="scheduleArticleFilter"
            @keydown.enter.prevent="applyArticleFilter"
        />

        <!-- Tipo de participación. -->
        <select
            v-model="form.type"
            class="editorial-filter-control comment-moderation-type-filter"
            name="type"
            aria-label="Filtrar por tipo de comentario"
            @change="changeType"
        >
            <option value="">
                Todos los tipos
            </option>

            <option value="comment">
                Comentarios
            </option>

            <option value="reply">
                Respuestas
            </option>
        </select>

        <!-- Rango de fecha de creación. -->
        <div
            ref="dateFilterContainer"
            class="editorial-date-filter"
            @keydown.esc="isDateFilterOpen = false"
        >
            <button
                type="button"
                class="editorial-date-button"
                :aria-expanded="isDateFilterOpen"
                aria-label="Filtrar por fecha del comentario"
                @click="toggleDateFilter"
            >
                <CalendarDays
                    v-if="showDateButtonIcon"
                    :size="16"
                    aria-hidden="true"
                />

                <span>{{ dateFilterLabel }}</span>
            </button>

            <form
                v-if="isDateFilterOpen"
                class="editorial-date-popup"
                @submit.prevent="applyDateFilter"
            >
                <header class="editorial-date-popup-header">
                    <div>
                        <strong>Filtrar por fecha del comentario</strong>

                        <span>
                            Elegí una fecha inicial, una final o ambas.
                        </span>
                    </div>
                </header>

                <div class="editorial-date-fields">
                    <label class="editorial-date-field">
                        <span>Fecha desde</span>

                        <VueDatePicker
                            v-model="form.date_from"
                            class="editorial-date-picker"
                            model-type="yyyy-MM-dd"
                            :formats="datePickerFormats"
                            placeholder="Seleccionar fecha"
                            :locale="es"
                            dark
                            auto-apply
                            no-today
                            :clearable="false"
                            :max-date="dateFromMaximum"
                            :start-date="todayObject"
                            :time-config="{ enableTimePicker: false }"
                        />
                    </label>

                    <span
                        class="editorial-date-range-separator"
                        aria-hidden="true"
                    >
                        →
                    </span>

                    <label class="editorial-date-field">
                        <span>Fecha hasta</span>

                        <VueDatePicker
                            v-model="form.date_to"
                            class="editorial-date-picker"
                            model-type="yyyy-MM-dd"
                            :formats="datePickerFormats"
                            placeholder="Seleccionar fecha"
                            :locale="es"
                            dark
                            auto-apply
                            no-today
                            :clearable="false"
                            :min-date="dateToMinimum"
                            :max-date="todayObject"
                            :start-date="todayObject"
                            :time-config="{ enableTimePicker: false }"
                        />
                    </label>
                </div>

                <p class="editorial-date-help">
                    El filtro incluye el día completo, desde las 00:00 hasta las 23:59.
                </p>

                <div class="editorial-date-actions">
                    <button
                        type="button"
                        class="editorial-date-clear"
                        :disabled="!canClearDateFilter"
                        @click="clearDateFilter"
                    >
                        Limpiar fecha
                    </button>

                    <button type="submit">
                        Aplicar
                    </button>
                </div>
            </form>
        </div>

        <!-- Limpia todos los filtros de contenido. -->
        <button
            type="button"
            class="editorial-filter-clear"
            :disabled="!hasActiveFilters"
            @click="clearFilters"
        >
            <RotateCcw
                :size="16"
                aria-hidden="true"
            />

            <span>Limpiar</span>
        </button>
    </section>

    <!-- Tabla TanStack. -->
    <section
        v-if="!loadError"
        class="editorial-table-panel comment-moderation-table-panel"
    >
        <div class="editorial-table-scroll">
            <table class="editorial-table comment-moderation-table">
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
                                numeric: header.column.id === 'reports',
                                actions: header.column.id === 'actions',
                            }"
                            :style="{
                                width: commentColumnWidth(header.column.id),
                            }"
                            :aria-sort="header.column.getIsSorted() === 'asc'
                                ? 'ascending'
                                : header.column.getIsSorted() === 'desc'
                                    ? 'descending'
                                    : undefined"
                        >
                            <button
                                v-if="!header.isPlaceholder
                                    && header.column.getCanSort()"
                                type="button"
                                class="editorial-sort-button"
                                @click="header.column
                                    .getToggleSortingHandler()?.($event)"
                            >
                                <FlexRender :header="header" />

                                <span
                                    class="sort-indicator"
                                    aria-hidden="true"
                                >
                                    {{ header.column.getIsSorted() === 'asc'
                                        ? '▲'
                                        : header.column.getIsSorted() === 'desc'
                                            ? '▼'
                                            : '↕' }}
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
                        class="editorial-table-row comment-moderation-table-row"
                        :class="{
                            'comment-moderation-table-row--reported':
                                Number(row.original.reports_count || 0) > 0,
                        }"
                        tabindex="0"
                        title="Abrir detalle de moderación"
                        @click="openModerationModal(row.original)"
                        @keydown.enter="openModerationModal(row.original)"
                        @keydown.space.prevent="openModerationModal(row.original)"
                    >
                        <td
                            v-for="cell in row.getAllCells()"
                            :key="cell.id"
                            :class="{
                                numeric: cell.column.id === 'reports',
                                actions: cell.column.id === 'actions',
                            }"
                            :style="{
                                width: commentColumnWidth(cell.column.id),
                            }"
                        >
                            <FlexRender :cell="cell" />
                        </td>
                    </tr>

                    <tr
                        v-if="!table.getRowModel().rows.length"
                    >
                        <td
                            :colspan="columns.length"
                            class="empty-state editorial-empty-state"
                        >
                            No hay comentarios que coincidan con los filtros.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Paginación numerada y selector 25 / 50 / 100. -->
    <AdminPagination
        v-if="!loadError"
        :pagination="comments.pagination"
        :per-page="form.per_page"
        :per-page-options="[25, 50, 100]"
        @update:per-page="changePerPage"
    />

    <!-- Detalle completo y acciones editoriales del comentario seleccionado. -->
    <CommentModerationModal
        :open="isModerationModalOpen"
        :comment-id="selectedCommentId"
        @close="closeModerationModal"
    />
</template>

<style src="../../../../css/admin/comments.css"></style>
