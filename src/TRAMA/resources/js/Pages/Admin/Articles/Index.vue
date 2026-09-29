<script setup>
/* ============================================================================
 * PAGE: Admin/Articles/Index.vue
 * ============================================================================
 *
 * Muestra y administra el listado editorial de noticias mediante TanStack Table.
 *
 * Laravel se encarga de la búsqueda, los filtros, el ordenamiento y la
 * paginación. TanStack representa únicamente las noticias de la página actual
 * y gestiona la estructura visual de la tabla.
 *
 * Esta separación evita cargar el listado completo en el navegador y mantiene
 * la pantalla eficiente aunque aumente la cantidad de noticias.
 * ============================================================================ */

import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { computed, h, nextTick, reactive, ref, watch, onMounted, onUnmounted } from 'vue';
import { FlexRender, rowSortingFeature, tableFeatures, useTable } from '@tanstack/vue-table';
import { CalendarDays, Eye, FilePlus2, Newspaper, Pencil, RotateCcw, Search } from '@lucide/vue';
import { VueDatePicker } from '@vuepic/vue-datepicker';
import { es } from 'date-fns/locale';
import AdminPagination from '@/Components/Admin/AdminPagination.vue';

/*
 * Propiedades enviadas por Laravel al listado editorial.
 */
const props = defineProps({
    /*
     * Filtros actualmente aplicados al listado.
     *
     * Incluye búsqueda, estado, categoría, autor, fechas, ordenamiento,
     * dirección y cantidad de resultados por página.
     */
    filters: {
        type: Object,
        required: true,
    },
    // Noticias de la página actual junto con la información de paginación.     
    articles: {
        type: Object,
        required: true,
    },
    // Categorías disponibles dentro del selector del listado.     
    categories: {
        type: Array,
        default: () => [],
    },
    /*
     * Estados editoriales que el usuario puede utilizar como filtros.
     *
     * Ejemplos: borrador, en revisión, publicada o archivada.
     */
    statusOptions: {
        type: Array,
        default: () => [],
    },
    /*
     * Cantidad de noticias correspondiente a cada estado editorial.
     *
     * También incluye el total utilizado por el filtro "Todas".
     */
    statusCounts: {
        type: Object,
        default: () => ({
            total: 0,
            statuses: {},
        }),
    },
    // Cantidad de noticias que actualmente esperan revisión.     
    reviewCount: {
        type: Number,
        default: 0,
    },    
    // Cantidad de noticias propias que fueron devueltas y requieren cambios.    
    changesCount: {
        type: Number,
        default: 0,
    },
    /*
     * Fecha que TRAMA considera como el día actual.
     *
     * Ejemplo: 2026-07-19.
     */
    referenceDate: {
        type: String,
        required: true,
    },
    /*
     * Permisos y alcance editorial del usuario autenticado.
     *
     * Permite decidir qué noticias puede consultar, editar o revisar.
     */
    permissions: {
        type: Object,
        required: true,
    },
    /*
    * Mensaje amigable cuando Laravel no pudo completar
    * la carga del listado por un error inesperado.
    */
    loadError: {
        type: String,
        default: '',
    },
});

/*
 * TanStack v9 permite registrar únicamente las funcionalidades utilizadas.
 *
 * El listado necesita la API de ordenamiento, pero no el modelo de ordenamiento
 * del navegador porque Laravel ya devuelve las filas ordenadas.
 */
const features = tableFeatures({
    rowSortingFeature,
});

/*
 * Estado local de los controles visibles del listado editorial.
 */
const form = reactive({
    /*
     * Texto del buscador general. Solamente se aplica al presionar Buscar.
     */
    search: props.filters.search || '',

    /*
    * Valor seleccionado dentro del filtro de categorías.
    *
    * Puede contener:
    *
    * - una cadena vacía para mostrar todas las categorías;
    * - el identificador de una categoría;
    * - "without_category" para mostrar noticias sin categoría.
    */
    category_id: props.filters.without_category
        ? 'without_category'
        : props.filters.category_id
            ? Number(props.filters.category_id)
            : '',

    /*
     * Tratamiento editorial aplicado en portada.
     *
     * Puede ser featured, breaking, normal o una cadena vacía para mostrar
     * todas las noticias sin filtrar por estas marcas.
     */
    homepage: props.filters.homepage || '',

    /*
     * Nombre completo o fragmento utilizado para filtrar por autor.
     */
    author: props.filters.author || '',

    /*
     * Fechas editadas dentro del cuadro flotante.
     *
     * No se aplican hasta que el usuario presiona Aplicar.
     */
    date_from: props.filters.date_from || '',
    date_to: props.filters.date_to || '',

    /*
     * Cantidad de noticias mostradas por página.
     */
    per_page: Number(props.filters.per_page || 25),
});

/*
 * Controla si el cuadro flotante del filtro de fechas está visible.
 */
const isDateFilterOpen = ref(false);

/*
 * Contenedor utilizado para detectar clicks fuera del filtro de fecha.
 */
const dateFilterContainer = ref(null);

// Controla el modal que permite cambiar únicamente las marcas de portada.
const homepageModalOpen = ref(false);
// Noticia cuya configuración de portada se está administrando.
const homepageArticle = ref(null);
// Formulario independiente para no desbloquear ni modificar el contenido publicado.
const homepageForm = useForm({
    is_breaking: false,
    is_featured: false,
});

/*
 * Guarda el temporizador utilizado para demorar la búsqueda por autor.
 */
let authorFilterTimer = null;

/*
 * TanStack representa el ordenamiento como una lista de columnas.
 *
 * TRAMA permite una sola columna ordenada por vez, por eso el arreglo contiene
 * como máximo un elemento.
 */
const sorting = ref([
    {
        id: props.filters.sort || 'updated_at',
        desc: (props.filters.direction || 'desc') === 'desc',
    },
]);

/*
 * Formato visible dentro de las cajas de fecha.
 *
 * El valor enviado a Laravel continúa siendo YYYY-MM-DD.
 */
const datePickerFormats = {
    input: 'dd/MM/yyyy',
};

/*
 * El menú del calendario debe quedar por encima de la paginación fija del
 * listado cuando el filtro se abre cerca del borde inferior de la ventana.
 */
const dateFilterPickerUi = {
    menu: 'editorial-date-picker-menu',
};

/*
 * Formateadores reutilizados por las celdas de vistas y última actualización.
 */
const numberFormatter = new Intl.NumberFormat('es-AR');

/*
 * Formato compacto utilizado por la columna Última edición.
 *
 * Ejemplo:
 *      19/07/2026 11:40
 */
const dateFormatter = new Intl.DateTimeFormat('es-AR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
});

/*
 * Definición de las columnas de la tabla editorial.
 *
 * Los id de las columnas ordenables coinciden exactamente con los valores que
 * ArticleIndexRequest admite dentro del parámetro sort.
 *
 * TanStack Table utiliza esta configuración para:
 *
 * - obtener el valor de cada celda;
 * - construir los encabezados;
 * - determinar qué columnas permiten ordenamiento;
 * - generar el contenido visual de cada fila.
 */
const columns = [
    /*
     * Columna principal de la noticia.
     *
     * Muestra la imagen de portada y el título dentro de una misma celda.
     */
    {
        /*
         * El identificador "title" también se envía al backend cuando el usuario
         * ordena el listado por el título de la noticia.
         */
        id: 'title',

        /*
         * Obtiene el título utilizado internamente por TanStack Table.
         *
         * Cuando el artículo no tiene título, devuelve una cadena vacía para
         * mantener un valor consistente.
         */
        accessorFn: (article) => article.title || '',

        // Texto visible dentro del encabezado de la columna.
        header: 'Noticia',

        // Permite ordenar el listado al presionar el encabezado.
        enableSorting: true,

        /*
         * Construye manualmente el contenido visual de la celda.
         *
         * info.row.original contiene el objeto completo de la noticia recibido
         * desde Laravel.
         */
        cell: (info) => {
            const article = info.row.original;

            return h(
                'div',
                {
                    class: 'editorial-title-cell',
                },
                [
                    /*
                     * Muestra la portada de la noticia.
                     *
                     * Cuando no existe una portada, utiliza la imagen
                     * predeterminada del portal.
                     */
                    h('img', {
                        src: article.cover_image && article.cover_image_exists
                            ? article.cover_image
                            : '/images/brand/noticia-default.webp',
                        alt: article.title || 'Noticia sin título',
                        loading: 'lazy',
                    }),

                    /*
                     * Muestra el título y, cuando corresponde, el problema físico
                     * detectado en la portada sin romper la fila de la grilla.
                     */
                    h(
                        'span',
                        { class: 'editorial-title-copy' },
                        [
                            h(
                                'strong',
                                article.title || 'Sin título',
                            ),
                            article.cover_image && !article.cover_image_exists
                                ? h(
                                    'small',
                                    { class: 'editorial-missing-cover' },
                                    'Imagen no encontrada',
                                )
                                : null,
                        ].filter(Boolean),
                    ),
                ],
            );
        },
    },

    /*
     * Columna correspondiente al estado editorial de la noticia.
     *
     * Ejemplos: borrador, en revisión, publicada o archivada.
     */
    {
        id: 'status',
        /*
         * Obtiene directamente la propiedad status del artículo.
         */
        accessorKey: 'status',
        header: 'Estado',
        enableSorting: true,
        /*
         * Convierte el estado interno en una etiqueta visible y agrega una clase
         * CSS específica para mostrar el color correspondiente.
         *
         * Ejemplo:
         *
         * published → status-published
         */
        cell: (info) => h(
            'span',
            {
                class: [
                    'status-badge',
                    `status-${info.getValue()}`,
                ],
            },            
            statusLabel(info.getValue()),
        ),
    },

    /*
     * Columna derivada de las dos decisiones editoriales de portada.
     *
     * NORMAL indica que la noticia no tiene tratamiento especial. DESTACADA y
     * URGENTE pueden coexistir porque se almacenan como marcas independientes.
     *
     * La columna también permite ordenar las noticias por prioridad editorial:
     * NORMAL, DESTACADA, URGENTE y DESTACADA + URGENTE. Las operaciones para
     * editar la noticia o gestionar su portada viven en la columna Acciones.
     */
    {
        id: 'homepage',
        accessorFn: (article) => homepageStateLabel(article),
        header: 'Portada',
        enableSorting: true,
        cell: (info) => {
            const article = info.row.original;
            const badges = [];

            if (!article.is_featured && !article.is_breaking) {
                badges.push(
                    h('span', { class: 'homepage-state-badge homepage-state-normal' }, 'Normal'),
                );
            } else {
                if (article.is_featured) {
                    badges.push(
                        h('span', { class: 'homepage-state-badge homepage-state-featured' }, 'Destacada'),
                    );
                }

                if (article.is_breaking) {
                    badges.push(
                        h('span', { class: 'homepage-state-badge homepage-state-breaking' }, 'Urgente'),
                    );
                }
            }

            return h('div', { class: 'homepage-state-cell' }, badges);
        },
    },

    /*
     * Columna correspondiente a la categoría principal de la noticia.
     */
    {
        id: 'category',
        /*
         * Obtiene el nombre desde la relación category.
         *
         * Cuando la noticia no tiene una categoría asociada, muestra
         * "Sin categoría".
         */
        accessorFn: (article) =>
            article.category?.name || 'Sin categoría',
        header: 'Categoría',
        enableSorting: true,
        // Muestra directamente el nombre obtenido por accessorFn.
        cell: (info) => info.getValue(),
    },

    /*
     * Columna correspondiente al autor o periodista responsable de la noticia.        
     * Los editores necesitan identificar al autor de cada noticia.
     * Para periodistas se omite porque todas las noticias son propias.
     */
    ...(props.permissions.can_review
        ? [
            {
                id: 'author',
                accessorFn: (article) =>
                    article.author?.name || 'Sin autor',
                header: 'Autor',
                enableSorting: true,
                cell: (info) => info.getValue(),
            },
        ]
        : []),

    /*
     * Columna que muestra la cantidad acumulada de visualizaciones.
     */
    {
        id: 'views',
        // Obtiene directamente la propiedad views del artículo.
        accessorKey: 'views',
        header: 'Vistas',
        enableSorting: true,

        /*
         * Formatea el número según la configuración regional argentina.
         *
         * Ejemplo:
         *
         * 12500 → 12.500
         */
        cell: (info) => formatViews(info.getValue()),
    },

    /*
     * Columna que muestra la fecha y hora del último cambio guardado
     * en la noticia.
     */
    {
        id: 'updated_at',
        // Obtiene directamente la propiedad updated_at enviada por Laravel.
        accessorKey: 'updated_at',
        header: 'Última edición',
        enableSorting: true,

        /*
         * Convierte la fecha recibida al formato utilizado por la tabla.
         *
         * Ejemplo:
         *
         * 2026-07-19T11:40:00 → 19/07/2026 11:40
         */
        cell: (info) => formatDate(info.getValue()),
    },

    /*
     * Columna final con las operaciones disponibles para cada noticia.
     *
     * Editar/Consultar abre el formulario correspondiente. Gestionar portada
     * aparece únicamente cuando el usuario puede modificar las marcas Urgente
     * y Destacada de una noticia publicada o programada.
     */
    {
        id: 'actions',
        header: 'Acciones',
        enableSorting: false,
        cell: (info) => {
            const article = info.row.original;
            const actions = [];
            const canEdit = canEditArticle(article);

            actions.push(
                h(
                    'button',
                    {
                        type: 'button',
                        title: canEdit ? 'Editar noticia' : 'Consultar noticia',
                        'aria-label': canEdit ? 'Editar noticia' : 'Consultar noticia',
                        onClick: (event) => {
                            event.stopPropagation();
                            openArticle(article);
                        },
                        onKeydown: (event) => event.stopPropagation(),
                    },
                    [
                        h(canEdit ? Pencil : Eye, {
                            size: 16,
                            'aria-hidden': 'true',
                        }),
                    ],
                ),
            );

            if (canManageHomepageArticle(article)) {
                actions.push(
                    h(
                        'button',
                        {
                            type: 'button',
                            title: 'Gestionar portada',
                            'aria-label': 'Gestionar portada',
                            onClick: (event) => {
                                event.stopPropagation();
                                openHomepageModal(article);
                            },
                            onKeydown: (event) => event.stopPropagation(),
                        },
                        [
                            h(Newspaper, {
                                size: 16,
                                'aria-hidden': 'true',
                            }),
                        ],
                    ),
                );
            }

            return h('div', { class: 'row-actions article-row-actions' }, actions);
        },
    },
];

/*
 * Las filas se calculan desde las props para que la tabla se actualice después
 * de cada navegación de Inertia.
 */
const tableData = computed(() => props.articles.data || []);

/*
 * Instancia TanStack. manualSorting evita ordenar solamente las filas cargadas,
 * una conducta incorrecta cuando la paginación ocurre en el servidor.
 */
const table = useTable({
    key: 'editorial-articles-table',
    features,
    columns,
    data: tableData,
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
 * Texto actualmente escrito dentro del buscador, sin espacios laterales.
 */
const normalizedSearch = computed(() => form.search.trim());

/*
 * Búsqueda que Laravel tiene aplicada actualmente.
 */
const appliedSearch = computed(() => (
    props.filters.search || ''
).trim());

/*
 * Habilita el botón solamente cuando existen al menos tres caracteres
 * y el texto es distinto de la búsqueda actualmente aplicada.
 */
const canApplySearch = computed(() => (
    normalizedSearch.value.length >= 3
    && normalizedSearch.value !== appliedSearch.value
));

/*
 * Indica si hay filtros aplicados.
 *
 * Lo usa el botón "Limpiar" para habilitarse y clearFilters()
 * para evitar peticiones cuando no hay nada que borrar.
 *
 * No incluye ordenamiento, paginación ni cantidad por página.
 */
const hasActiveFilters = computed(() => Boolean(
    /*
     * Búsqueda general.
     */
    (props.filters.search || '').trim()

    /*
     * Estado editorial.
     */
    || props.filters.status

    /*
     * Tratamiento de portada.
     */
    || props.filters.homepage

    /*
     * Categoría seleccionada.
     */
    || props.filters.category_id

    /*
     * Noticias sin categoría.
     */
    || props.filters.without_category

    /*
     * Autor seleccionado o buscado por nombre.
     */
    || props.filters.author_id
    || (props.filters.author || '').trim()

    /*
     * Rango de fechas.
     */
    || props.filters.date_from
    || props.filters.date_to
));

/*
 * Convierte la fecha de referencia de TRAMA en un objeto Date local.
 *
 * Se construye por partes para evitar que el navegador modifique el día
 * debido a una conversión automática de zona horaria.
 */
const referenceDateObject = computed(() => {
    const [
        year,
        month,
        day,
    ] = props.referenceDate
        .split('-')
        .map(Number);

    return new Date(
        year,
        month - 1,
        day,
    );
});

/*
 * La fecha Desde no puede superar la fecha Hasta ya elegida.
 * Cuando todavía no existe Hasta, el límite es la fecha actual de TRAMA.
 */
const dateFromMaximum = computed(() => (
    parseLocalDate(form.date_to)
    || referenceDateObject.value
));

/*
 * La fecha Hasta no puede ser anterior a Desde.
 */
const dateToMinimum = computed(() => (
    parseLocalDate(form.date_from)
));

/*
 * Habilita "Limpiar fecha" únicamente cuando Laravel
 * tiene realmente aplicado un filtro de fechas.
 *
 * Las fechas precargadas al abrir el modal todavía
 * no representan un filtro aplicado.
 */
const canClearDateFilter = computed(() => Boolean(
    props.filters.date_from
    || props.filters.date_to
));

/*
 * Traduce los códigos internos de estado a las etiquetas mostradas al usuario.
 */
const statusLabels = computed(() => Object.fromEntries(
    props.statusOptions.map((status) => [
        status.value,
        status.label,
    ]),
));

/*
 * Texto compacto mostrado dentro del botón de fecha.
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
 * Muestra el icono cuando no existe ningún filtro o cuando Desde y Hasta
 * representan exactamente el mismo día.
 *
 * Se oculta en rangos y filtros parciales para que el texto entre dentro
 * del ancho fijo de 125px.
 */
const showDateButtonIcon = computed(() => {
    const dateFrom = props.filters.date_from || '';
    const dateTo = props.filters.date_to || '';

    /*
     * Sin ninguna fecha aplicada: el botón dice "Fecha".
     */
    if (! dateFrom && ! dateTo) {
        return true;
    }

    /*
     * Ambas fechas son iguales: el botón muestra una sola fecha, por ejemplo
     * "19/07", por lo que todavía hay espacio para el icono.
     */
    return Boolean(
        dateFrom
        && dateTo
        && dateFrom === dateTo
    );
});

/*
 * Devuelve el total visible para el botón Todas.
 */
const allArticlesCount = computed(() => props.statusCounts?.total || 0);

/*
 * Convierte una fecha YYYY-MM-DD en un objeto Date local.
 *
 * No se utiliza new Date('YYYY-MM-DD') porque algunos navegadores interpretan
 * ese formato como UTC y podrían mostrar el día anterior en Argentina.
 */
function parseLocalDate(value) {
    if (! value) {
        return null;
    }

    const [
        year,
        month,
        day,
    ] = value
        .split('-')
        .map(Number);

    if (! year || ! month || ! day) {
        return null;
    }

    return new Date(
        year,
        month - 1,
        day,
    );
}

/*
 * Formatea la cantidad de visualizaciones utilizando la configuración
 * numérica de Argentina.
 *
 * Ejemplo:
 *      12500 → 12.500
 */
function formatViews(value) {
    return numberFormatter.format(Number(value || 0));
}

/*
 * Convierte la fecha recibida en un formato compacto y uniforme para la tabla.
 *
 * Ejemplo:
 *      2026-07-19T11:40:00 → 19/07/2026 11:40
 *
 * formatToParts evita diferencias entre navegadores, como comas,
 * indicadores a. m. y p. m. o variaciones en el orden de los datos.
 */
function formatDate(value) {
    if (! value) {
        return 'Sin fecha';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'Sin fecha';
    }

    const parts = Object.fromEntries(
        dateFormatter
            .formatToParts(date)
            .filter((part) => part.type !== 'literal')
            .map((part) => [
                part.type,
                part.value,
            ]),
    );

    return `${parts.day}/${parts.month}/${parts.year}, `
        + `${parts.hour}:${parts.minute}`;
}

/*
 * Devuelve la etiqueta visible del estado dentro de la tabla.
 *
 * La noticia devuelta utiliza una versión abreviada
 * para que el chip permanezca en una sola línea.
 */
function statusLabel(status) {
    if (status === 'needs_changes') {
        return 'Devuelta';
    }

    return statusLabels.value[status] || status;
}
/*
 * Devuelve el estado visual de portada a partir de las dos marcas booleanas.
 */
function homepageStateLabel(article) {
    if (article.is_featured && article.is_breaking) {
        return 'Destacada · Urgente';
    }

    if (article.is_featured) {
        return 'Destacada';
    }

    if (article.is_breaking) {
        return 'Urgente';
    }

    return 'Normal';
}

/*
 * Permite gestionar portada solo a editores y únicamente en noticias que ya
 * están publicadas o programadas. El contenido permanece bloqueado.
 */
function canManageHomepageArticle(article) {
    return Boolean(
        props.permissions.can_manage_homepage
        && ['published', 'scheduled'].includes(article.status)
    );
}

/*
 * Abre el modal copiando las marcas actuales de la noticia seleccionada.
 */
function openHomepageModal(article) {
    if (!canManageHomepageArticle(article)) {
        return;
    }

    homepageArticle.value = article;
    homepageForm.clearErrors();
    homepageForm.is_breaking = Boolean(article.is_breaking);
    homepageForm.is_featured = Boolean(article.is_featured);
    homepageModalOpen.value = true;
}

/* Cierra el modal mientras no exista una petición en curso. */
function closeHomepageModal() {
    if (homepageForm.processing) {
        return;
    }

    homepageModalOpen.value = false;
    homepageArticle.value = null;
    homepageForm.clearErrors();
}

/*
 * Indica si el editor modificó alguna de las dos marcas de portada.
 */
const homepageFormChanged = computed(() => Boolean(
    homepageArticle.value
    && (
        Boolean(homepageForm.is_breaking) !== Boolean(homepageArticle.value.is_breaking)
        || Boolean(homepageForm.is_featured) !== Boolean(homepageArticle.value.is_featured)
    )
));

/*
 * Guarda únicamente Urgente y Destacada mediante la ruta editorial específica.
 */
function saveHomepageSettings() {
    if (
        !homepageArticle.value
        || !homepageFormChanged.value
        || homepageForm.processing
    ) {
        return;
    }

    homepageForm.patch(
        route('admin.articles.homepage.update', homepageArticle.value.id),
        {
            preserveScroll: true,
            onSuccess: () => {
                homepageModalOpen.value = false;
                homepageArticle.value = null;
                homepageForm.clearErrors();
            },
        },
    );
}

/*
 * Aplica directamente el filtro de destacadas y cierra el modal actual.
 */
function showFeaturedArticles() {
    homepageModalOpen.value = false;
    homepageArticle.value = null;
    homepageForm.clearErrors();

    // El acceso desde el error debe mostrar todas las destacadas que reservan
    // lugar en portada, sin quedar limitado por otros filtros de la grilla.
    form.search = '';
    form.category_id = '';
    form.homepage = 'featured';
    form.author = '';
    form.date_from = '';
    form.date_to = '';
    isDateFilterOpen.value = false;

    visitList({
        search: undefined,
        status: undefined,
        homepage: 'featured',
        category_id: undefined,
        without_category: undefined,
        author_id: undefined,
        author: undefined,
        date_from: undefined,
        date_to: undefined,
    });
}

/*
 * Determina si la acción de la última columna debe decir Editar o Consultar.
 */
function canEditArticle(article) {
    return props.permissions.can_review
        || ['draft', 'needs_changes'].includes(article.status);
}

/*
 * Distribuye las columnas del listado del periodista,
 * donde no se muestra la columna Autor.
 */
function journalistColumnWidth(columnId) {
    if (props.permissions.can_review) {
        return undefined;
    }

    const widths = {
        title: '30%',
        status: '12%',
        homepage: '17%',
        category: '12%',
        views: '8%',
        updated_at: '13%',
        actions: '8%',
    };

    return widths[columnId];
}

/*
 * Elimina valores vacíos antes de generar una URL o enviar una petición.
 *
 * page no se conserva al cambiar la búsqueda, el estado, el ordenamiento o la
 * cantidad de resultados porque el nuevo conjunto debe comenzar en la página 1.
 */
function buildQuery(overrides = {}) {
    const query = {
        search: props.filters.search || undefined,
        status: props.filters.status || undefined,
        homepage: props.filters.homepage || undefined,
        category_id: props.filters.category_id || undefined,
        without_category: props.filters.without_category
            ? 1
            : undefined,
        author_id: props.filters.author_id || undefined,
        author: props.filters.author || undefined,
        date_from: props.filters.date_from || undefined,
        date_to: props.filters.date_to || undefined,
        sort: props.filters.sort || 'updated_at',
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
 * Ejecuta una navegación del listado conservando el estado local y la posición
 * exacta de la ventana.
 *
 * preserveScroll evita normalmente que Inertia vuelva al comienzo de la página.
 * Además, la posición se guarda y restaura manualmente porque Chrome puede mover
 * la ventana cuando Vue reemplaza las filas de la tabla durante el ordenamiento.
 */
function visitList(overrides = {}) {
    /*
     * Guarda la posición exacta antes de comenzar la petición.
     */
    const scrollX = window.scrollX;
    const scrollY = window.scrollY;

    router.get(
        route('admin.articles.index'),
        buildQuery(overrides),
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,

            /*
            * Espera a que Vue actualice las filas de la tabla y después devuelve
            * la ventana a la posición que tenía antes del ordenamiento.
            */
            onSuccess: async () => {
                await nextTick();

                /*
                 * Desactiva temporalmente cualquier desplazamiento suave para
                 * evitar que Chrome anime o altere la restauración del scroll.
                 */
                const documentElement = document.documentElement;
                const previousScrollBehavior =
                    documentElement.style.scrollBehavior;

                documentElement.style.scrollBehavior = 'auto';

                window.scrollTo({
                    left: scrollX,
                    top: scrollY,
                    behavior: 'auto',
                });

                /*
                 * Recupera el comportamiento de scroll que tenía la página.
                 */
                documentElement.style.scrollBehavior =
                    previousScrollBehavior;
            },
        },
    );
}

/*
 * Envía a Laravel solamente el texto escrito dentro del buscador general.
 *
 * Los filtros de estado, categoría, autor y fecha que ya estén aplicados
 * se conservan mediante buildQuery().
 */
function applySearch() {
    /*
     * Impide enviar el formulario mediante Enter cuando el texto todavía
     * no cumple las condiciones de búsqueda.
     */
    if (! canApplySearch.value) {
        return;
    }

    visitList({
        search: normalizedSearch.value || undefined,
    });
}

/*
 * Aplica inmediatamente la opción elegida dentro del filtro de categorías.
 *
 * El selector permite mostrar:
 *
 * - todas las noticias;
 * - una categoría específica;
 * - solamente noticias sin categoría.
 */
function changeCategory() {
    const withoutCategory =
        form.category_id === 'without_category';

    visitList({
        /*
         * Cuando se selecciona "Sin categoría", se elimina cualquier
         * identificador de categoría aplicado anteriormente.
         */
        category_id: withoutCategory
            ? undefined
            : form.category_id || undefined,

        /*
         * Se envía 1 solamente cuando se seleccionó "Sin categoría".
         */
        without_category: withoutCategory
            ? 1
            : undefined,
    });
}

/*
 * Aplica inmediatamente el tratamiento de portada seleccionado.
 */
function changeHomepageFilter() {
    visitList({
        homepage: form.homepage || undefined,
    });
}

/*
 * Cancela cualquier búsqueda pendiente y aplica inmediatamente el nombre
 * escrito dentro del filtro de autor.
 */
function applyAuthorFilter() {
    clearTimeout(authorFilterTimer);

    const author = form.author.trim();
    const currentAuthor = props.filters.author || '';

    /*
     * Evita repetir la petición cuando el valor aplicado no cambió.
     */
    if (author === currentAuthor) {
        return;
    }

    visitList({
        author: author || undefined,
    });
}

/*
 * Espera 500 milisegundos desde la última letra escrita en la caja de texto
 * del filtro de autor antes de consultar nuevamente el listado de noticias.
 *
 * Cada letra reinicia el temporizador. De esta manera, Laravel no recibe una
 * petición por cada tecla presionada, sino cuando el usuario deja de escribir.
 */
function scheduleAuthorFilter() {
    clearTimeout(authorFilterTimer);

    authorFilterTimer = setTimeout(() => {
        applyAuthorFilter();
    }, 500);
}

/*
 * Convierte una fecha YYYY-MM-DD al formato breve DD/MM utilizado dentro
 * del botón del filtro.
 */
function formatFilterDate(value) {
    if (! value) {
        return '';
    }

    const [
        year,
        month,
        day,
    ] = value.split('-');

    if (! year || ! month || ! day) {
        return '';
    }

    return `${day}/${month}`;
}

/*
 * Abre o cierra el cuadro flotante de fechas.
 *
 * Cuando ya existe un filtro aplicado, recupera exactamente ese rango.
 *
 * Cuando todavía no existe ningún filtro de fecha, utiliza automáticamente
 * la fecha de referencia de TRAMA tanto para Desde como para Hasta.
 *
 * La fecha queda seleccionada dentro del formulario, pero el listado no se
 * filtra hasta que el usuario presiona Aplicar.
 */
function toggleDateFilter() {
    if (! isDateFilterOpen.value) {
        /*
         * Comprueba si Laravel ya tiene alguna fecha aplicada.
         */
        const hasAppliedDateFilter = Boolean(
            props.filters.date_from
            || props.filters.date_to
        );

        if (hasAppliedDateFilter) {
            /*
             * Conserva exactamente el rango actualmente aplicado.
             */
            form.date_from = props.filters.date_from || '';
            form.date_to = props.filters.date_to || '';
        } else {
            /*
             * Sin filtro previo, selecciona el día de referencia de TRAMA.
             *
             * En tu proyecto:
             *
             * 2026-07-19
             */
            form.date_from = props.referenceDate;
            form.date_to = props.referenceDate;
        }
    }

    isDateFilterOpen.value = ! isDateFilterOpen.value;
}

/*
 * Cierra el filtro al hacer click fuera, sin impedir la acción presionada.
 */
function closeDateFilterOnOutsideClick(event) {
    if (! isDateFilterOpen.value) {
        return;
    }

    const target = event.target;

    if (! (target instanceof Element)) {
        return;
    }

    /*
     * No cierra al interactuar con el botón, el popup o el calendario.
     */
    if (
        dateFilterContainer.value?.contains(target)
        || target.closest('.dp--menu')
    ) {
        return;
    }

    isDateFilterOpen.value = false;
}

/*
 * Aplica a Laravel el rango elegido dentro del cuadro de fechas.
 */
function applyDateFilter() {
    isDateFilterOpen.value = false;

    visitList({
        date_from: form.date_from || undefined,
        date_to: form.date_to || undefined,
    });
}

/*
 * Elimina solamente el rango de fechas y conserva los demás filtros activos.
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
 * Restablece la búsqueda, el estado, la portada, la categoría, el autor
 * y el rango de fechas del listado editorial.
 *
 * El ordenamiento, la dirección y la cantidad de noticias por página
 * se conservan.
 */
function clearFilters() {

    /*
    * Evita ejecutar una petición cuando no existe ningún filtro aplicado.
    */
    if (! hasActiveFilters.value) {
        return;
    }

    clearTimeout(authorFilterTimer);

    form.search = '';
    form.category_id = '';
    form.homepage = '';
    form.author = '';
    form.date_from = '';
    form.date_to = '';

    isDateFilterOpen.value = false;

    visitList({
        search: undefined,
        status: undefined,
        homepage: undefined,
        category_id: undefined,
        without_category: undefined,
        author_id: undefined,
        author: undefined,
        date_from: undefined,
        date_to: undefined,
    });
}

/*
 * Recibe desde AdminPagination.vue la cantidad elegida por el usuario.
 *
 * El componente emite valores como 25, 50 o 100. Se convierten a número,
 * se guardan en el formulario local y se envían a Laravel.
 */
function changePerPage(perPage) {
    const selectedPerPage = Number(perPage);

    form.per_page = selectedPerPage;

    visitList({
        per_page: selectedPerPage,
    });
}

/*
 * Recibe el nuevo orden calculado por TanStack y lo convierte a los parámetros
 * sort y direction comprendidos por Laravel.
 */
function changeSorting(updater) {
    const nextSorting = typeof updater === 'function'
        ? updater(sorting.value)
        : updater;

    const selected = nextSorting[0] || {
        id: 'updated_at',
        desc: true,
    };

    sorting.value = [selected];

    visitList({
        sort: selected.id,
        direction: selected.desc ? 'desc' : 'asc',
    });
}

function statusCount(status) {
    return props.statusCounts?.statuses?.[status] || 0;
}

function isAllFilterStatic() {
    return ! props.filters.status || allArticlesCount.value === 0;
}

function isStatusFilterStatic(status) {
    return props.filters.status === status || statusCount(status) === 0;
}

/*
 * Genera la URL de un filtro de estado conservando la búsqueda, el
 * ordenamiento y la cantidad de resultados por página.
 *
 * La página actual se descarta para comenzar el nuevo filtro desde la página 1.
 */
function statusFilterUrl(status) {
    return route(
        'admin.articles.index',
        buildQuery({
            status: status || undefined,
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

function visitStatusFilter(event, status) {
    if (
        !event.currentTarget?.getAttribute('href')
        || shouldUseBrowserNavigation(event)
    ) {
        return;
    }

    event.preventDefault();

    visitList({
        status: status || undefined,
    });
}

/*
 * Abre el formulario correspondiente al hacer clic o presionar Enter en una
 * fila de la tabla.
 */
function openArticle(article) {
    router.visit(
        route('admin.articles.edit', article.id),
    );
}

/*
 * Después de buscar o limpiar el listado, copia en la caja de búsqueda
 * el texto que Laravel dejó actualmente aplicado.
 */
watch(
    () => props.filters.search,
    (search) => {
        form.search = search || '';
    },
);

/*
 * Después de cambiar o limpiar el filtro de categorías, actualiza el selector
 * con el valor que Laravel dejó aplicado.
 *
 * También permite mostrar seleccionada la opción "Sin categoría".
 */
watch(
    [
        () => props.filters.category_id,
        () => props.filters.without_category,
    ],
    ([categoryId, withoutCategory]) => {
        form.category_id = withoutCategory
            ? 'without_category'
            : categoryId
                ? Number(categoryId)
                : '';
    },
);

/*
 * Mantiene sincronizado el selector de portada después de cada navegación.
 */
watch(
    () => props.filters.homepage,
    (homepage) => {
        form.homepage = homepage || '';
    },
);

/*
 * Después de aplicar o limpiar el filtro de autor, copia en su caja de texto
 * el nombre o fragmento que Laravel dejó aplicado.
 */
watch(
    () => props.filters.author,
    (author) => {
        form.author = author || '';
    },
);

/*
 * Después de aplicar o limpiar el filtro de fechas, copia en los campos
 * Desde y Hasta el rango que Laravel dejó aplicado.
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
 * Si el usuario cambia Desde y deja a Hasta antes del nuevo inicio,
 * se limpia Hasta para impedir que se aplique un rango imposible.
 */
watch(
    () => form.date_from,
    (dateFrom) => {
        if (
            dateFrom
            && form.date_to
            && form.date_to < dateFrom
        ) {
            form.date_to = '';
        }
    },
);

/*
 * Después de cambiar la cantidad de noticias por página, copia en el selector
 * el valor que Laravel dejó aplicado.
 *
 * Si Laravel no devuelve un valor, se muestran 25 noticias por página.
 */
watch(
    () => props.filters.per_page,
    (perPage) => {
        form.per_page = Number(perPage || 25);
    },
);

/*
 * Mantiene sincronizado el estado de ordenamiento de TanStack Table cuando
 * Laravel devuelve nuevos valores para sort y direction.
 *
 * Esto permite que la flecha visible en el encabezado coincida siempre con el
 * orden aplicado realmente por el backend.
 */
watch(
    [
        /*
         * Columna utilizada para ordenar el listado.
         */
        () => props.filters.sort,

        /*
         * Dirección del ordenamiento: ascendente o descendente.
         */
        () => props.filters.direction,
    ],
    ([sort, direction]) => {
        sorting.value = [
            {
                /*
                 * Si Laravel no envía una columna, utiliza updated_at.
                 */
                id: sort || 'updated_at',

                /*
                 * TanStack representa la dirección mediante una propiedad
                 * booleana: true para descendente y false para ascendente.
                 */
                desc: (direction || 'desc') === 'desc',
            },
        ];
    },
);

/*
 * Registra el listener global que permite cerrar el filtro de fecha cuando
 * el usuario hace clic fuera del botón, del popup o del calendario.
 */
onMounted(() => {
    document.addEventListener(
        'pointerdown',
        closeDateFilterOnOutsideClick,
        true,
    );
});

/*
 * Limpia los recursos registrados por la pantalla antes de desmontarla.
 *
 * Cancela cualquier búsqueda de autor pendiente y elimina el listener utilizado
 * para cerrar el filtro de fecha al hacer clic fuera de él.
 */
onUnmounted(() => {
    clearTimeout(authorFilterTimer);

    document.removeEventListener(
        'pointerdown',
        closeDateFilterOnOutsideClick,
        true,
    );
});

</script>

<template>
    <Head title="Noticias" />

    <section class="workflow-banner">
        <div>
            <strong>{{ permissions.scope_label }}</strong>
        </div>

        <nav class="workflow-links">
            <Link
                :href="route('admin.articles.index', { status: 'review' })"
            >
                {{ reviewCount }} en revisión
            </Link>

            <Link
                v-if="!permissions.can_review"
                :href="route('admin.articles.index', { status: 'needs_changes' })"
                class="warning-link"
            >
                {{ changesCount }} devueltas
            </Link>
        </nav>
    </section>

    <div
        v-if="loadError"
        class="operation-error"
        role="alert"
    >
        <p class="eyebrow">
            No se pudo cargar el listado
        </p>

        <p>
            {{ loadError }}
        </p>
    </div>

    <section class="article-status-tabs">
        <component
            :is="isAllFilterStatic() ? 'span' : 'a'"
            :href="isAllFilterStatic() ? undefined : statusFilterUrl('')"
            class="article-status-tab"
            :class="{
                active: !filters.status,
                disabled: allArticlesCount === 0,
            }"
            :aria-disabled="allArticlesCount === 0 ? 'true' : undefined"
            @click="visitStatusFilter($event, '')"
        >
            <span>Todas</span>
            <small>{{ allArticlesCount }}</small>
        </component>

        <component
            v-for="status in statusOptions"
            :is="isStatusFilterStatic(status.value) ? 'span' : 'a'"
            :key="status.value"
            :href="isStatusFilterStatic(status.value)
                ? undefined
                : statusFilterUrl(status.value)"
            class="article-status-tab"
            :class="{
                active: filters.status === status.value,
                disabled: statusCount(status.value) === 0,
            }"
            :aria-disabled="statusCount(status.value) === 0
                ? 'true'
                : undefined"
            @click="visitStatusFilter($event, status.value)"
        >
            <span>{{ status.filter_label }}</span>
            <small>{{ statusCount(status.value) }}</small>
        </component>
    </section>

    <section class="admin-toolbar editorial-toolbar articles-toolbar">
        <!--
            El formulario contiene exclusivamente el buscador general.

            La lupa, la caja de texto y el botón permanecen juntos, como estaban
            originalmente.
        -->
        <form
            class="
                admin-filter-form
                compact
                editorial-search-form
            "
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
                placeholder="Buscar noticias..."
                aria-label="Buscar noticias..."
            />

            <button
                type="submit"
                :disabled="!canApplySearch"
            >
                Buscar
            </button>
        </form>

        <!-- Se aplica inmediatamente al elegir una categoría. -->
        <select
            v-model="form.category_id"
            class="
                editorial-filter-control
                editorial-category-filter
            "
            name="category_id"
            aria-label="Filtrar por categoría"
            @change="changeCategory"
        >
            <option value="">
                Todas las categorías
            </option>

            <option value="without_category">
                Sin categoría
            </option>

            <option
                v-for="category in categories"
                :key="category.id"
                :value="category.id"
            >
                {{ category.name }}
            </option>
        </select>

        <!-- El tratamiento de portada se aplica inmediatamente al seleccionarlo. -->
        <select
            v-if="permissions.can_manage_homepage"
            v-model="form.homepage"
            class="editorial-filter-control editorial-homepage-filter"
            name="homepage"
            aria-label="Filtrar por tratamiento de portada"
            @change="changeHomepageFilter"
        >
            <option value="">Portada: todas</option>
            <option value="featured">Destacadas</option>
            <option value="breaking">Urgentes</option>
            <option value="normal">Sin marca</option>
        </select>

        <!--
            Se aplica automáticamente 500 milisegundos después de que el usuario
            deja de escribir. Enter permite aplicarlo inmediatamente.
        -->
        <input
            v-if="permissions.can_review"
            v-model="form.author"
            class="
                editorial-filter-control
                editorial-author-filter
            "
            type="search"
            name="author"
            maxlength="80"
            placeholder="Autor…"
            aria-label="Filtrar por autor"
            autocomplete="off"
            @input="scheduleAuthorFilter"
            @keydown.enter.prevent="applyAuthorFilter"
        />

        <!--
            El rango permanece dentro de un cuadro flotante para no ocupar una
            segunda fila en la barra de filtros.
        -->
        <div
            ref="dateFilterContainer"
            class="editorial-date-filter"
            @keydown.esc="isDateFilterOpen = false"
        >
            <button
                type="button"
                class="editorial-date-button"
                :aria-expanded="isDateFilterOpen"
                aria-label="Filtrar por fecha de última edición"
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
                        <strong>Filtrar por última edición</strong>

                        <span>
                            Elegí una fecha inicial, una final o ambas.
                        </span>
                    </div>
                </header>

                <div class="editorial-date-fields">
                    <!--
                        Fecha inicial del rango.

                        El calendario muestra DD/MM/AAAA, pero Vue entrega a
                        Laravel el valor estable YYYY-MM-DD.
                    -->
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
                            :ui="dateFilterPickerUi"
                            :max-date="dateFromMaximum"
                            :start-date="referenceDateObject"
                            :time-config="{ enableTimePicker: false }"
                        />
                    </label>

                    <span
                        class="editorial-date-range-separator"
                        aria-hidden="true"
                    >
                        →
                    </span>

                    <!--
                        Fecha final del rango.

                        min-date evita seleccionar un día anterior a Desde y
                        max-date impide superar la fecha actual de TRAMA.
                    -->
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
                            :ui="dateFilterPickerUi"
                            :min-date="dateToMinimum"
                            :max-date="referenceDateObject"
                            :start-date="referenceDateObject"
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

        <div class="editorial-toolbar-actions">
            <Link
                :href="route('admin.articles.create')"
                class="admin-primary-action"
            >
                <FilePlus2 :size="18" />
                Nueva noticia
            </Link>
        </div>
    </section>

    <section
        v-if="!loadError"
        class="editorial-table-panel"
    >
        <div class="editorial-table-scroll">
            <table
                class="editorial-table editorial-articles-table"
                :class="{
                    'editorial-table--journalist': !permissions.can_review,
                }"
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
                                numeric: header.column.id === 'views',
                                actions: header.column.id === 'actions',
                                homepage: header.column.id === 'homepage',
                            }"
                            :style="{
                                width: journalistColumnWidth(header.column.id),
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
                        class="editorial-table-row"
                        tabindex="0"
                        @click="openArticle(row.original)"
                        @keydown.enter="openArticle(row.original)"
                    >
                        <td
                            v-for="cell in row.getAllCells()"
                            :key="cell.id"                            
                            :class="{
                                numeric: cell.column.id === 'views',
                                'admin-numeric-value-centered': cell.column.id === 'views',
                                actions: cell.column.id === 'actions',
                                homepage: cell.column.id === 'homepage',
                            }"
                            :style="{
                                width: journalistColumnWidth(cell.column.id),
                            }"
                        >
                            <FlexRender :cell="cell" />
                        </td>
                    </tr>
                    <tr
                        v-if="
                            !loadError
                            && !table.getRowModel().rows.length
                        "
                    >
                        <td
                            :colspan="columns.length"
                            class="empty-state editorial-empty-state"
                        >
                            No hay noticias que coincidan con los filtros.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Permite cambiar Urgente y Destacada sin desbloquear el contenido publicado. -->
    <div
        v-if="homepageModalOpen && homepageArticle"
        class="admin-modal-backdrop"
    >
        <form
            class="admin-modal article-homepage-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="article-homepage-modal-title"
            @submit.prevent="saveHomepageSettings"
        >
            <header>
                <div>
                    <p class="eyebrow">Configuración de portada</p>
                    <h2 id="article-homepage-modal-title">Gestionar portada</h2>
                </div>
            </header>

            <p class="article-homepage-modal-title">
                {{ homepageArticle.title }}
            </p>

            <p class="article-homepage-modal-help">
                La noticia seguirá {{ homepageArticle.status === 'published' ? 'publicada' : 'programada' }}.
                Estos cambios solo modifican su tratamiento editorial en portada.
            </p>

            <div class="flag-box article-homepage-flags">
                <label class="editorial-flag">
                    <input
                        v-model="homepageForm.is_breaking"
                        type="checkbox"
                        :disabled="homepageForm.processing"
                    />
                    <span>
                        <strong>Marcar como urgente</strong>
                        <small>
                            Aparece identificada como “Urgente” en la cinta de titulares.
                        </small>
                    </span>
                </label>

                <label class="editorial-flag">
                    <input
                        v-model="homepageForm.is_featured"
                        type="checkbox"
                        :disabled="homepageForm.processing"
                    />
                    <span>
                        <strong>Destacar en portada</strong>
                        <small>
                            Puede ocupar la noticia principal o uno de los bloques destacados.
                        </small>
                    </span>
                </label>

                <div
                    v-if="homepageForm.errors.is_featured"
                    class="article-homepage-feature-error"
                >
                    <span class="form-error">
                        {{ homepageForm.errors.is_featured }}
                    </span>

                    <button
                        type="button"
                        class="homepage-featured-filter-link"
                        @click="showFeaturedArticles"
                    >
                        Ver noticias destacadas
                    </button>
                </div>
            </div>

            <p
                v-if="homepageForm.errors.operation"
                class="operation-error"
                role="alert"
            >
                {{ homepageForm.errors.operation }}
            </p>

            <div class="unsaved-changes-actions">
                <button
                    type="button"
                    class="secondary-admin-link"
                    :disabled="homepageForm.processing"
                    @click="closeHomepageModal"
                >
                    Cancelar
                </button>

                <button
                    type="submit"
                    class="admin-primary-action"
                    :disabled="!homepageFormChanged || homepageForm.processing"
                >
                    <span class="modal-action-label">
                        <span :class="{ 'is-hidden': homepageForm.processing }">
                            Guardar cambios
                        </span>
                        <span :class="{ 'is-hidden': !homepageForm.processing }">
                            Guardando...
                        </span>
                    </span>
                </button>
            </div>
        </form>
    </div>

    <AdminPagination
        v-if="!loadError && !isDateFilterOpen"
        :pagination="articles.pagination"
        :per-page="form.per_page"
        :per-page-options="[25, 50, 100]"
        item-label="noticias"
        @update:per-page="changePerPage"
    />
</template>
