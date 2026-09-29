<script setup>
/* ============================================================================
 * PAGE: Admin/Advertisements/Index.vue
 * ============================================================================
 *
 * Panel administrador de publicidades de TRAMA.
 *
 * La pantalla separa dos responsabilidades:
 *
 *      - Métricas/filtros generales de campañas;
 *      - Orden, cantidad por página y paginación propios de la grilla.
 *
 * Los banners se crean y editan con una ubicación de tamaño fijo. El navegador
 * valida las dimensiones para dar una respuesta inmediata, pero Laravel vuelve
 * a validar el archivo y además ejecuta el clasificador de seguridad antes de
 * convertirlo a WebP y almacenarlo.
 *
 * Los estilos propios de esta página viven en resources/css/admin/advertisements.css.
 * ============================================================================ */

import AdminPagination from '@/Components/Admin/AdminPagination.vue';
import ConfirmActionModal from '@/Components/Shared/ConfirmActionModal.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, shallowRef, watch } from 'vue';
import { route } from 'ziggy-js';
import { VueDatePicker } from '@vuepic/vue-datepicker';
import { es } from 'date-fns/locale';
import { Chart as ChartJS, CategoryScale, LinearScale, LineElement, PointElement, Tooltip, Legend } from 'chart.js';
import { Line } from 'vue-chartjs';
import { ChevronDown, ExternalLink, ImageUp, Pencil, Search, Trash2, X } from '@lucide/vue';

// Props de administración de publicidades.
const props = defineProps({
    // Página actual de campañas publicitarias y datos de paginación
    // utilizados por la grilla.
    advertisements: {
        type: Object,
        default: () => ({
            data: [],
            pagination: {
                links: [],
            },
        }),
    },
    // Métricas agregadas del período seleccionado:
    // impresiones, clicks, CTR y cantidad de banners activos.
    report: {
        type: Object,
        default: () => ({}),
    },
    // Series utilizadas por el gráfico de rendimiento diario.
    // Contiene las etiquetas del eje temporal, impresiones y clicks.
    chart: {
        type: Object,
        default: () => ({
            labels: [],
            impressions: [],
            clicks: [],
        }),
    },
    // Filtros actualmente aplicados al listado:
    // búsqueda, ubicación, estado, marca, período, orden y paginación.
    filters: {
        type: Object,
        default: () => ({}),
    },
    // Opciones válidas enviadas por Laravel para los filtros y formularios,
    // como ubicaciones publicitarias disponibles y marcas existentes.
    filterOptions: {
        type: Object,
        default: () => ({
            placements: [],
            brands: [],
        }),
    },
    // Día editorial utilizado por filtros y reportes de métricas.
    referenceDate: {
        type: String,
        default: '',
    },
    // Momento exacto del reloj editorial usado para iniciar campañas nuevas.
    editorialNow: {
        type: String,
        default: '',
    },
    // Mensaje mostrado cuando Laravel no pudo cargar correctamente
    // los datos de la pantalla.
    loadError: {
        type: String,
        default: '',
    },
});

// Registra en Chart.js únicamente los módulos utilizados por este gráfico:
// escalas, líneas, puntos, tooltips y leyenda.
ChartJS.register(
    CategoryScale,
    LinearScale,
    LineElement,
    PointElement,
    Tooltip,
    Legend,
);

// Tamaño máximo permitido para la imagen de una publicidad: 4 MB.
const MAX_BANNER_BYTES = 4 * 1024 * 1024;

// Tipos MIME de imagen aceptados para los banners publicitarios.
const ACCEPTED_BANNER_TYPES = new Set([
    'image/jpeg',
    'image/png',
    'image/webp',
]);

// Cantidades disponibles para seleccionar cuántas publicidades se muestran por página.
const PER_PAGE_OPTIONS = [25, 50, 100];

/* ============================================================================
 * FILTROS Y CONTROLES DE LA GRILLA
 * ============================================================================ */

// Estos cinco valores filtran campañas y también modifican las tarjetas y el
// gráfico. q conserva únicamente la búsqueda ya aplicada en Laravel; el texto
// que el administrador está escribiendo se mantiene aparte hasta pulsar Buscar.
const filter = reactive({
    q: props.filters.q || '',
    placement: props.filters.placement || '',
    status: props.filters.status || '',
    brand: props.filters.brand || '',
    period: props.filters.period || '30',
});

// Texto visible del buscador. No se copia a filter.q hasta enviar el formulario.
const searchText = ref(props.filters.q || '');

const grid = reactive({
    sort: props.filters.sort || 'banner',
    direction: props.filters.direction || 'asc',
    per_page: Number(props.filters.per_page || 25),
});

// Conserva la misma referencia de datos mientras el contenido real del gráfico
// no cambie.
//
// Ordenar la grilla provoca una nueva respuesta Inertia y Laravel vuelve a
// entregar screenProps. Aunque las métricas sean idénticas, props.chart recibe
// un objeto nuevo. Si se pasara ese objeto directamente a vue-chartjs, Chart.js
// interpretaría el cambio de referencia como datos nuevos y volvería a animar
// el gráfico.
//
// La firma evita ese redibujado: Banner, Ubicación y Estado pueden reordenar la
// tabla sin tocar visualmente el gráfico. Cuando un filtro general sí modifica
// las métricas, la firma cambia y entonces el gráfico se actualiza normalmente.
const chartSignature = ref(JSON.stringify(props.chart || {}));
const chartData = shallowRef(buildChartData(props.chart));

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    animation: false,    
    interaction: {
        intersect: false,
        mode: 'index',
    },
    plugins: {
        legend: {
            labels: { color: '#DCE3EF' },
        },
        tooltip: {
            backgroundColor: '#111820',
            borderColor: 'rgba(214, 162, 58, 0.35)',
            borderWidth: 1,
        },
    },
    scales: {
        x: {
            ticks: { color: '#AAB4C5' },
            grid: { color: 'rgba(255, 255, 255, 0.06)' },
        },
        yImpressions: {
            beginAtZero: true,
            position: 'left',
            title: { display: true, text: 'Impresiones', color: '#AAB4C5' },
            ticks: { color: '#AAB4C5' },
            grid: { color: 'rgba(255, 255, 255, 0.07)' },
        },
        yClicks: {
            beginAtZero: true,
            position: 'right',
            title: { display: true, text: 'Clicks', color: '#AAB4C5' },
            ticks: { color: '#AAB4C5' },
            grid: { drawOnChartArea: false },
        },
    },
};

/* ============================================================================
 * FORMULARIOS DE CREACIÓN Y EDICIÓN
 * ============================================================================ */

const creating = ref(false);
const editing = ref(null);
const createSubmitting = ref(false);
const editSubmitting = ref(false);
const deleting = ref(null);
const deleteProcessing = ref(false);
const deleteError = ref('');
const createPreview = ref('');
const editPreview = ref('');
const createImageInputKey = ref(0);
const editImageInputKey = ref(0);
const editInitial = ref(null);

const initialDates = defaultCampaignDates(props.editorialNow);

const createForm = useForm({
    name: '',
    brand: '',
    placement: 'header',
    image_file: null,
    target_url: '',
    starts_at: initialDates.startsAt,
    ends_at: initialDates.endsAt,
    is_active: true,
});

const editForm = useForm({
    name: '',
    brand: '',
    placement: 'header',
    image_file: null,
    target_url: '',
    starts_at: '',
    ends_at: '',
    is_active: true,
});

const advertisementRows = computed(() => props.advertisements?.data || []);
const pagination = computed(() => props.advertisements?.pagination || null);

// Normaliza el buscador para comparar lo escrito con la búsqueda ya aplicada.
const normalizedSearch = computed(() => searchText.value.trim());
const appliedSearch = computed(() => String(filter.q || '').trim());

// Buscar solo se habilita cuando realmente existe un cambio que enviar.
const canApplySearch = computed(() => normalizedSearch.value !== appliedSearch.value);

// Limpiar se habilita únicamente cuando existe algún filtro distinto del estado
// inicial del panel. El período de 30 días es el valor predeterminado.
const hasActiveFilters = computed(() => (
    appliedSearch.value !== ''
    || filter.placement !== ''
    || filter.status !== ''
    || filter.brand !== ''
    || filter.period !== '30'
));

const createAdvertisementBusy = computed(() => createForm.processing || createSubmitting.value);
const editAdvertisementBusy = computed(() => editForm.processing || editSubmitting.value);

// La fecha de inicio queda bloqueada en edición cuando la campaña ya comenzó.
const editStartLocked = computed(() => Boolean(editing.value?.has_started));

// El botón Crear se habilita únicamente cuando todos los campos obligatorios
// contienen valores válidos. La imagen ya pasó la validación de tipo, peso y
// dimensiones antes de asignarse a createForm.image_file.
const createAdvertisementReady = computed(() => {
    const brand = String(createForm.brand || '').trim();
    const name = String(createForm.name || '').trim();
    const startsAt = new Date(createForm.starts_at);
    const endsAt = new Date(createForm.ends_at);

    return !createAdvertisementBusy.value
        && brand.length > 0
        && name.length > 0
        && Boolean(placementOption(createForm.placement))
        && String(createForm.target_url || '').trim().length > 0
        && Boolean(createForm.image_file)
        && !Number.isNaN(startsAt.getTime())
        && !Number.isNaN(endsAt.getTime())
        && endsAt.getTime() > startsAt.getTime();
});

const editAdvertisementChanged = computed(() => {
    if (!editing.value || !editInitial.value) {
        return false;
    }

    const current = {
        name: editForm.name,
        brand: editForm.brand,
        placement: editForm.placement,
        target_url: editForm.target_url,
        starts_at: editForm.starts_at,
        ends_at: editForm.ends_at,
        is_active: Boolean(editForm.is_active),
    };

    return editForm.image_file !== null
        || Object.keys(current).some((key) => current[key] !== editInitial.value[key]);
});

// Construye la consulta completa que necesita Laravel. Los controles de grilla
// viajan en la misma URL, pero filteredAdvertisementQuery no los utiliza para
// calcular métricas ni gráfico.
function currentQuery(overrides = {}) {
    return cleanQuery({
        ...filter,
        ...grid,
        ...overrides,
    });
}

// Elimina de la consulta los valores vacíos para no enviar parámetros
// innecesarios en la URL ni alterar los valores predeterminados de Laravel.
function cleanQuery(values) {
    return Object.fromEntries(
        Object.entries(values).filter(([, value]) => (
            value !== ''
            && value !== null
            && value !== undefined
        )),
    );
}

// Navega sin perder la posición exacta de scroll. Se utiliza para ordenar,
// cambiar cantidad por página y aplicar filtros desde una página ya desplazada.
function visitList(overrides = {}, replace = true) {
    const scrollX = window.scrollX;
    const scrollY = window.scrollY;

    router.get(route('admin.advertisements.index'), currentQuery(overrides), {
        preserveState: true,
        preserveScroll: true,
        replace,
        onSuccess: async () => {
            await nextTick();

            const root = document.documentElement;
            const previousBehavior = root.style.scrollBehavior;
            root.style.scrollBehavior = 'auto';
            window.scrollTo({ left: scrollX, top: scrollY, behavior: 'auto' });
            root.style.scrollBehavior = previousBehavior;
        },
    });
}

// Aplica únicamente el texto del buscador al presionar Buscar o Enter.
function applySearch() {
    if (!canApplySearch.value) {
        return;
    }

    filter.q = normalizedSearch.value;
    visitList({ page: 1 });
}

// Los combos representan opciones cerradas, por lo que cada cambio se aplica
// inmediatamente sin obligar al administrador a pulsar un botón adicional.
function applyAutomaticFilter() {
    visitList({ page: 1 });
}

// Restablece todos los filtros generales a su estado inicial y vuelve
// a solicitar la primera página del listado.
function clearFilters() {
    searchText.value = '';

    Object.assign(filter, {
        q: '',
        placement: '',
        status: '',
        brand: '',
        period: '30',
    });

    visitList({ page: 1 });
}

// Devuelve el símbolo correspondiente al estado de orden de una cabecera.
function sortIndicator(column) {
    if (grid.sort !== column) {
        return '↕';
    }

    return grid.direction === 'asc' ? '▲' : '▼';
}

// Traduce el estado de orden a aria-sort para lectores de pantalla.
function sortAria(column) {
    if (grid.sort !== column) {
        return 'none';
    }

    return grid.direction === 'asc' ? 'ascending' : 'descending';
}

// Alterna el orden de una cabecera y vuelve a la primera página.
//
// Solo Banner, Ubicación y Estado son ordenables desde la grilla. Vigencia se
// mantiene informativa porque las campañas demo pueden compartir exactamente
// las mismas fechas y una flecha allí no aporta una diferencia visible.
//
// Laravel realiza el orden real sobre el conjunto completo de campañas.
function changeGridSorting(column) {
    const sortableColumns = ['banner', 'placement', 'status'];

    if (!sortableColumns.includes(column)) {
        return;
    }

    if (grid.sort === column) {
        grid.direction = grid.direction === 'asc' ? 'desc' : 'asc';
    } else {
        grid.sort = column;
        grid.direction = 'asc';
    }

    visitList({
        sort: grid.sort,
        direction: grid.direction,
        page: 1,
    });
}

// Cambia la cantidad de publicidades visibles por página sin recalcular
// las métricas ni el gráfico, ya que solo modifica la paginación de la grilla.
function updatePerPage(value) {
    grid.per_page = Number(value);
    visitList({ page: 1 });
}

/* ============================================================================
 * MÉTRICAS Y GRÁFICO
 * ============================================================================ */

// Formatea cantidades enteras con la configuración regional argentina.
function formatNumber(value) {
    return new Intl.NumberFormat('es-AR').format(Number(value || 0));
}

// Formatea un valor porcentual con dos decimales y agrega el símbolo %.
function formatPercentage(value) {
    const percentage = new Intl.NumberFormat('es-AR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(value || 0));

    return `${percentage}%`;
}

// Separa fecha y hora con un punto medio para facilitar el escaneo de la grilla.
function formatGridDateTime(value) {
    const [date = '', time = ''] = String(value || '').split(' ');

    if (!date || !time) {
        return String(value || '');
    }

    return `${date} · ${time}`;
}

// Traduce el estado de una campaña a la clase visual reutilizada
// por los badges del panel editorial.
function statusClass(status) {
    return {
        active: 'status-published',
        scheduled: 'status-review',
        finished: 'status-archived',
        paused: 'status-needs-changes',
    }[status] || 'status-archived';
}

function canDeleteAdvertisement(advertisement) {
    return ['paused', 'finished'].includes(advertisement?.status);
}

function deleteAdvertisementTitle(advertisement) {
    if (canDeleteAdvertisement(advertisement)) {
        return 'Eliminar publicidad';
    }

    return 'Pausá la campaña antes de eliminarla';
}

// Construye la estructura que consume vue-chartjs a partir de las métricas
// agregadas enviadas por Laravel.
function buildChartData(chart) {
    return {
        labels: chart?.labels || [],
        datasets: [
            {
                label: 'Impresiones',
                data: chart?.impressions || [],
                borderColor: '#D6A23A',
                backgroundColor: 'rgba(214, 162, 58, 0.18)',
                tension: 0.35,
                pointRadius: 2,
                yAxisID: 'yImpressions',
            },
            {
                label: 'Clicks',
                data: chart?.clicks || [],
                borderColor: '#62B6FF',
                backgroundColor: 'rgba(98, 182, 255, 0.18)',
                tension: 0.35,
                pointRadius: 2,
                yAxisID: 'yClicks',
            },
        ],
    };
}

// Busca la configuración completa de una ubicación publicitaria a partir
// de su valor interno.
function placementOption(placement) {
    return props.filterOptions.placements?.find((item) => item.value === placement) || null;
}

// Devuelve las dimensiones visibles de una ubicación en formato ancho × alto.
function placementDimensions(placement) {
    const dimensions = placementOption(placement)?.dimensions;

    if (!dimensions) {
        return '';
    }

    return `${dimensions.width} × ${dimensions.height} px`;
}

/*
 * Construye una campaña nueva desde el momento editorial exacto.
 *
 * Antes se tomaba solamente referenceDate y se forzaba T00:00:00. Con un reloj
 * demo que puede estar en 15:30, 16:35 o 17:10 eso hacía que una campaña recién
 * creada pareciera haber empezado muchas horas antes.
 */
function defaultCampaignDates(editorialNow) {
    if (!editorialNow) {
        return { startsAt: '', endsAt: '' };
    }

    const start = new Date(editorialNow);
    const end = new Date(editorialNow);
    end.setDate(end.getDate() + 30);
    end.setHours(23, 59, 0, 0);

    return {
        startsAt: toDateTimeLocal(start),
        endsAt: toDateTimeLocal(end),
    };
}

// Convierte un objeto Date al formato yyyy-MM-ddTHH:mm requerido por
// los campos y datepickers que trabajan con datetime-local.
function toDateTimeLocal(date) {
    const pad = (value) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

// Agrega un error general de operación solamente cuando Laravel no devolvió
// ya un error específico que pueda mostrarse al administrador.
function ensureFormOperationError(form, errors, fallback) {
    if (errors?.operation || Object.keys(errors || {}).length > 0) {
        return;
    }

    form.setError('operation', fallback);
}

// Limpia espacios sobrantes del destino antes de enviarlo y elimina
// un posible error previo asociado al campo.
function normalizeTarget(form) {
    const value = String(form.target_url || '').trim();

    form.target_url = value;
    form.clearErrors('target_url');
    return true;
}

// Restablece por completo el formulario de creación, sus errores, fechas,
// imagen seleccionada y previsualización temporal.
function resetCreateForm() {
    createSubmitting.value = false;
    createForm.reset();
    createForm.placement = 'header';
    createForm.target_url = '';
    createForm.starts_at = initialDates.startsAt;
    createForm.ends_at = initialDates.endsAt;
    createForm.is_active = true;
    createForm.clearErrors();
    revokePreview(createPreview);
    createImageInputKey.value += 1;
}

// Prepara un formulario limpio y abre el modal para crear una publicidad.
function openCreate() {
    resetCreateForm();
    createSubmitting.value = false;
    creating.value = true;
}

// Cierra el modal de creación únicamente cuando no existe un envío en curso
// y deja el formulario listo para una apertura futura.
function closeCreate() {
    if (createAdvertisementBusy.value) {
        return;
    }

    creating.value = false;
    resetCreateForm();
}

// Carga los datos de la campaña seleccionada en el formulario de edición
// y conserva una copia inicial para detectar cambios reales.
function openEdit(advertisement) {
    editSubmitting.value = false;
    editing.value = advertisement;
    editForm.name = advertisement.name || '';
    editForm.brand = advertisement.brand || '';
    editForm.placement = advertisement.placement || 'header';
    editForm.image_file = null;
    editForm.target_url = advertisement.target_url || '';
    editForm.starts_at = advertisement.starts_at || '';
    editForm.ends_at = advertisement.ends_at || '';
    editForm.is_active = Boolean(advertisement.is_active);
    editForm.clearErrors();

    /*
    * Si Laravel detectó que el archivo registrado ya no existe físicamente,
    * mostramos el problema desde el momento en que se abre el modal.
    *
    * handleEditImage() limpia este error automáticamente cuando el administrador
    * selecciona una imagen nueva válida.
    */
    if (!advertisement.image_exists) {
        editForm.setError(
            'image_file',
            'El archivo actual del banner no existe. Subí nuevamente la imagen para reparar la publicidad.',
        );
    }

    revokePreview(editPreview);
    editImageInputKey.value += 1;

    editInitial.value = {
        name: editForm.name,
        brand: editForm.brand,
        placement: editForm.placement,
        target_url: editForm.target_url,
        starts_at: editForm.starts_at,
        ends_at: editForm.ends_at,
        is_active: Boolean(editForm.is_active),
    };
}

// Cierra el modal de edición cuando no hay una operación en curso y limpia
// el estado temporal asociado a la campaña editada.
function closeEdit() {
    if (editAdvertisementBusy.value) {
        return;
    }

    editSubmitting.value = false;
    editing.value = null;
    editInitial.value = null;
    editForm.clearErrors();
    editForm.image_file = null;
    revokePreview(editPreview);
    editImageInputKey.value += 1;
}

// Finaliza el estado de creación después de una respuesta exitosa y deja
// cerrado y limpio el modal correspondiente.
function closeCreateAdvertisementAfterSuccess() {
    createSubmitting.value = false;
    creating.value = false;
    resetCreateForm();
}

// Finaliza el estado de edición después de guardar correctamente y elimina
// la previsualización temporal utilizada por el modal.
function closeEditAdvertisementAfterSuccess() {
    editSubmitting.value = false;
    editing.value = null;
    editInitial.value = null;
    revokePreview(editPreview);
    editImageInputKey.value += 1;
}

// Selecciona la campaña que se quiere eliminar y abre el modal de confirmación.
function openDelete(advertisement) {
    if (!canDeleteAdvertisement(advertisement)) {
        return;
    }

    deleting.value = advertisement;
    deleteError.value = '';
}

// Cierra la confirmación de eliminación siempre que no haya una solicitud
// de borrado actualmente en proceso.
function closeDelete() {
    if (deleteProcessing.value) {
        return;
    }

    deleting.value = null;
    deleteError.value = '';
}

// Elimina la campaña seleccionada mediante Inertia y mantiene abierto el
// modal únicamente si Laravel devuelve un error.
function deleteAdvertisement() {
    if (!deleting.value || deleteProcessing.value) {
        return;
    }

    deleteProcessing.value = true;
    deleteError.value = '';

    router.delete(route('admin.advertisements.destroy', deleting.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            deleting.value = null;
        },
        onError: (errors) => {
            deleteError.value = errors?.operation || 'No pudimos eliminar la publicidad. Intentá nuevamente.';
        },
        onFinish: () => {
            deleteProcessing.value = false;
        },
    });
}

// Libera la URL temporal creada para una previsualización de imagen y evita
// mantener recursos del navegador innecesariamente en memoria.
function revokePreview(previewRef) {
    if (!previewRef.value) {
        return;
    }

    URL.revokeObjectURL(previewRef.value);
    previewRef.value = '';
}

// Lee las dimensiones reales de una imagen local sin subirla al servidor.
// La URL temporal utilizada para cargarla se libera al terminar.
function imageDimensions(file) {
    return new Promise((resolve, reject) => {
        const objectUrl = URL.createObjectURL(file);
        const image = new Image();

        image.onload = () => {
            resolve({ width: image.naturalWidth, height: image.naturalHeight });
            URL.revokeObjectURL(objectUrl);
        };

        image.onerror = () => {
            URL.revokeObjectURL(objectUrl);
            reject(new Error('invalid-image'));
        };

        image.src = objectUrl;
    });
}

// Comprueba exactamente el formato asociado a la ubicación antes de enviar.
// Laravel repite esta validación y luego ejecuta el filtro sexual/gore.
async function validateBannerFile(file, placement, form) {
    form.clearErrors('image_file');

    if (!file) {
        return false;
    }

    if (!ACCEPTED_BANNER_TYPES.has(file.type)) {
        form.setError('image_file', 'La imagen debe estar en JPG, JPEG, PNG o WEBP.');
        return false;
    }

    if (file.size > MAX_BANNER_BYTES) {
        form.setError('image_file', 'La imagen no puede superar los 4 MB.');
        return false;
    }

    const option = placementOption(placement);

    if (!option?.dimensions) {
        form.setError('placement', 'La ubicación seleccionada no es válida.');
        return false;
    }

    let dimensions;

    try {
        dimensions = await imageDimensions(file);
    } catch {
        form.setError('image_file', 'No se pudo leer la imagen seleccionada.');
        return false;
    }

    const expected = option.dimensions;

    if (dimensions.width !== expected.width || dimensions.height !== expected.height) {
        form.setError(
            'image_file',
            `La imagen para ${option.label} debe medir exactamente ${expected.width} × ${expected.height} px.`,
        );
        return false;
    }

    return true;
}

// Procesa una imagen seleccionada, limpia la anterior, valida archivo y
// dimensiones, y genera la previsualización solamente cuando es válida.
async function handleImage(event, form, previewRef, placement) {
    const input = event.currentTarget;
    const file = input?.files?.[0] || null;

    form.image_file = null;
    revokePreview(previewRef);

    if (!file) {
        return;
    }

    const valid = await validateBannerFile(file, placement, form);

    if (!valid) {
        input.value = '';
        return;
    }

    form.image_file = file;
    previewRef.value = URL.createObjectURL(file);
}

// Envía la imagen elegida en creación al validador común usando la
// ubicación actualmente seleccionada.
function handleCreateImage(event) {
    handleImage(event, createForm, createPreview, createForm.placement);
}

// Envía la imagen elegida en edición al validador común usando la
// ubicación fija de la campaña.
function handleEditImage(event) {
    handleImage(event, editForm, editPreview, editForm.placement);
}

// Envía el formulario de alta de una publicidad cuando todos los datos
// requeridos son válidos y controla sus estados de procesamiento y error.
function createAdvertisement() {
    if (createAdvertisementBusy.value) {
        return;
    }

    createForm.clearErrors('operation');

    // type="url" acepta también HTTP. Esta comprobación informa el motivo
    // antes de detener un envío que no cumple la regla HTTPS de TRAMA.
    normalizeTarget(createForm);

    if (!createAdvertisementReady.value) {
        return;
    }

    createForm.post(route('admin.advertisements.store'), {
        preserveScroll: true,
        forceFormData: true,
        onStart: () => { createSubmitting.value = true; },
        onSuccess: closeCreateAdvertisementAfterSuccess,
        onError: (errors) => {
            createSubmitting.value = false;
            ensureFormOperationError(
                createForm,
                errors,
                'No pudimos crear la publicidad. Intentá nuevamente.',
            );
        },
        onFinish: () => {
            if (createForm.wasSuccessful) {
                closeCreateAdvertisementAfterSuccess();
            }

            createSubmitting.value = false;
        },
    });
}

// Guarda los cambios de una campaña existente. Si únicamente cambia su
// estado, envía solo is_active para no modificar innecesariamente otros datos.
function updateAdvertisement() {
    if (!editing.value || !editAdvertisementChanged.value || editAdvertisementBusy.value) {
        return;
    }

    editForm.clearErrors('operation');

    const statusOnlyChange = editInitial.value
        && editForm.image_file === null
        && editForm.name === editInitial.value.name
        && editForm.brand === editInitial.value.brand
        && editForm.placement === editInitial.value.placement
        && editForm.target_url === editInitial.value.target_url
        && editForm.starts_at === editInitial.value.starts_at
        && editForm.ends_at === editInitial.value.ends_at
        && Boolean(editForm.is_active) !== editInitial.value.is_active;

    if (!statusOnlyChange) {
        normalizeTarget(editForm);
    }

    // POST + _method permite enviar multipart/form-data con imagen y conservar
    // la semántica PATCH en Laravel.
    editForm
        .transform((data) => {
            if (statusOnlyChange) {
                return {
                    is_active: Boolean(data.is_active),
                    _method: 'patch',
                };
            }

            return { ...data, _method: 'patch' };
        })
        .post(route('admin.advertisements.update', editing.value.id), {
            preserveScroll: true,
            forceFormData: true,
            onStart: () => { editSubmitting.value = true; },
            onSuccess: () => {
                closeEditAdvertisementAfterSuccess();
            },
            onError: (errors) => {
                editSubmitting.value = false;
                ensureFormOperationError(
                    editForm,
                    errors,
                    'No pudimos guardar los cambios de la publicidad. Intentá nuevamente.',
                );
            },
            onFinish: () => {
                if (editForm.wasSuccessful) {
                    closeEditAdvertisementAfterSuccess();
                    return;
                }

                editSubmitting.value = false;
            },
        });
}

/* ============================================================================
 * TECLADO Y LIMPIEZA
 * ============================================================================ */

// Cierra con Escape el modal activo respetando cualquier operación que
// todavía se encuentre en proceso.
function handleEscape(event) {
    if (event.key !== 'Escape') {
        return;
    }

    if (editing.value) {
        if (!editAdvertisementBusy.value) {
            event.preventDefault();
            closeEdit();
        }
        return;
    }

    if (deleting.value && !deleteProcessing.value) {
        event.preventDefault();
        closeDelete();
        return;
    }

    if (creating.value && !createAdvertisementBusy.value) {
        event.preventDefault();
        closeCreate();
    }
}

// Sincroniza los datos del gráfico solamente cuando cambian realmente sus
// métricas; una respuesta Inertia con los mismos valores no fuerza otro dibujo.
watch(
    () => props.chart,
    (nextChart) => {
        const nextSignature = JSON.stringify(nextChart || {});

        if (nextSignature === chartSignature.value) {
            return;
        }

        chartSignature.value = nextSignature;
        chartData.value = buildChartData(nextChart);
    },
    { deep: true },
);

// Si cambia la ubicación, una imagen ya elegida deja de ser válida porque cada
// espacio publicitario exige dimensiones diferentes.
watch(() => createForm.placement, () => {
    if (createForm.image_file) {
        createForm.image_file = null;
        revokePreview(createPreview);
        createImageInputKey.value += 1;
    }
    createForm.clearErrors('image_file');
});

// Registra el atajo global utilizado para cerrar los modales con Escape.
onMounted(() => {
    document.addEventListener('keydown', handleEscape);
});

// Elimina listeners y libera las URLs temporales antes de desmontar la página.
onBeforeUnmount(() => {
    document.removeEventListener('keydown', handleEscape);
    revokePreview(createPreview);
    revokePreview(editPreview);
});
</script>

<template>
    <Head title="Publicidades" />

    <div v-if="loadError" class="operation-error admin-load-error" role="alert">
        <strong>No se pudo cargar Publicidades</strong>
        <span>{{ loadError }}</span>
    </div>

    <section class="advertisement-report-grid">
        <article>
            <span>Impresiones</span>
            <strong>{{ formatNumber(props.report.impressions_total) }}</strong>
        </article>
        <article>
            <span>Clicks</span>
            <strong>{{ formatNumber(props.report.clicks_total) }}</strong>
        </article>
        <article>
            <span>CTR</span>
            <strong>{{ formatPercentage(props.report.ctr) }}</strong>
        </article>
        <article>
            <span>Banners activos</span>
            <strong>{{ formatNumber(props.report.active_ads_count) }}</strong>
        </article>
    </section>

    <!--
        El texto se aplica solamente con Buscar o Enter. Los combos representan
        opciones cerradas y filtran automáticamente en cuanto cambian.
    -->
    <section class="admin-toolbar advertisement-filter-toolbar">
        <form
            class="admin-filter-form compact advertisement-search-form"
            @submit.prevent="applySearch"
        >
            <Search :size="18" aria-hidden="true" />

            <input
                v-model="searchText"
                class="editorial-search-input"
                type="search"
                name="q"
                maxlength="120"
                placeholder="Buscar banner o marca..."
                aria-label="Buscar banner o marca"
            />

            <button
                type="submit"
                :disabled="!canApplySearch"
            >
                Buscar
            </button>
        </form>

        <select
            v-model="filter.placement"
            class="editorial-filter-control advertisement-placement-filter"
            aria-label="Filtrar por ubicación"
            @change="applyAutomaticFilter"
        >
            <option value="">Todas las ubicaciones</option>
            <option
                v-for="placement in props.filterOptions.placements"
                :key="placement.value"
                :value="placement.value"
            >
                {{ placement.label }}
            </option>
        </select>

        <select
            v-model="filter.status"
            class="editorial-filter-control advertisement-status-filter"
            aria-label="Filtrar por estado"
            @change="applyAutomaticFilter"
        >
            <option value="">Todos los estados</option>
            <option value="active">Activas</option>
            <option value="scheduled">Programadas</option>
            <option value="finished">Finalizadas</option>
            <option value="paused">Pausadas</option>
        </select>

        <select
            v-model="filter.brand"
            class="editorial-filter-control advertisement-brand-filter"
            aria-label="Filtrar por marca"
            @change="applyAutomaticFilter"
        >
            <option value="">Todas las marcas</option>
            <option
                v-for="brand in props.filterOptions.brands"
                :key="brand"
                :value="brand"
            >
                {{ brand }}
            </option>
        </select>

        <select
            v-model="filter.period"
            class="editorial-filter-control advertisement-period-filter"
            aria-label="Filtrar por período"
            @change="applyAutomaticFilter"
        >
            <option value="7">Últimos 7 días</option>
            <option value="30">Últimos 30 días</option>
            <option value="all">Todo el historial</option>
        </select>

        <button
            type="button"
            class="editorial-filter-clear advertisement-filter-clear"
            :disabled="!hasActiveFilters"
            @click="clearFilters"
        >
            Limpiar
        </button>

        <button
            type="button"
            class="admin-primary-action advertisement-create-button"
            @click="openCreate"
        >           
            +
        </button>
    </section>

    <section class="advertisement-chart-panel">
        <header>
            <div>
                <p class="eyebrow">Rendimiento diario</p>
                <h2>Impresiones y clicks</h2>
            </div>
            <small>Escalas independientes para comparar ambas métricas.</small>
        </header>
        <div class="advertisement-chart">
            <Line :data="chartData" :options="chartOptions" />
        </div>
    </section>

    <!-- Reutiliza exactamente el patrón de grilla administrativa de Comentarios. -->
    <section class="editorial-table-panel advertisement-table-panel">
        <div class="editorial-table-scroll">
            <table class="editorial-table admin-advertisements-table">
                <thead>
                    <tr>
                        <th :aria-sort="sortAria('banner')">
                            <button
                                type="button"
                                class="editorial-sort-button"
                                @click="changeGridSorting('banner')"
                            >
                                Banner
                                <span class="sort-indicator" aria-hidden="true">
                                    {{ sortIndicator('banner') }}
                                </span>
                            </button>
                        </th>

                        <th :aria-sort="sortAria('placement')">
                            <button
                                type="button"
                                class="editorial-sort-button"
                                @click="changeGridSorting('placement')"
                            >
                                Ubicación
                                <span class="sort-indicator" aria-hidden="true">
                                    {{ sortIndicator('placement') }}
                                </span>
                            </button>
                        </th>

                        <!--
                            Vigencia es solamente informativa. No se ofrece ordenamiento
                            porque varias campañas pueden compartir exactamente el mismo
                            inicio y fin, por lo que la acción no aporta una lectura útil.
                        -->
                        <th>Vigencia</th>

                        <th>Rendimiento</th>

                        <th :aria-sort="sortAria('status')">
                            <button
                                type="button"
                                class="editorial-sort-button"
                                @click="changeGridSorting('status')"
                            >
                                Estado
                                <span class="sort-indicator" aria-hidden="true">
                                    {{ sortIndicator('status') }}
                                </span>
                            </button>
                        </th>

                        <th class="table-actions-heading">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="advertisement in advertisementRows"
                        :key="advertisement.id"
                        class="advertisement-table-row"
                        tabindex="0"
                        @click="openEdit(advertisement)"
                        @keydown.enter.prevent="openEdit(advertisement)"
                    >
                        <td>
                            <div class="advertisement-banner-cell">
                                <!--
                                    Sólo intentamos renderizar el WebP cuando Laravel confirmó que el
                                    archivo indicado por image_path continúa existiendo físicamente.
                                -->
                                <img
                                    v-if="advertisement.image_exists"
                                    :src="advertisement.image_url"
                                    :alt="`Publicidad de ${advertisement.brand}`"
                                />
                                <!--
                                    Un archivo eliminado o renombrado manualmente se muestra como una
                                    inconsistencia administrable en vez de dejar el icono de imagen rota.
                                -->
                                <span
                                    v-else
                                    class="advertisement-missing-file"
                                >
                                    Archivo no encontrado
                                </span>
                                <div>
                                    <strong>{{ advertisement.brand }}</strong>
                                    <span>{{ advertisement.name }}</span>
                                    <a
                                        :href="advertisement.target_url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        @click.stop
                                    >
                                        <ExternalLink :size="13" />
                                        Abrir destino
                                    </a>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="advertisement-placement-cell">
                                <strong>{{ advertisement.placement_label }}</strong>
                                <small>{{ placementDimensions(advertisement.placement) }}</small>
                            </div>
                        </td>
                        <td>
                            <div class="advertisement-vigency-cell">
                                <span>
                                    <small>Inicio</small>
                                    <strong>{{ formatGridDateTime(advertisement.starts_at_label) }}</strong>
                                </span>
                                <span>
                                    <small>Fin</small>
                                    <strong>{{ formatGridDateTime(advertisement.ends_at_label) }}</strong>
                                </span>
                            </div>
                        </td>
                        <td>
                            <div class="advertisement-performance-cell">
                                <span>{{ formatNumber(advertisement.impressions_total) }} impresiones</span>
                                <small>
                                    {{ formatNumber(advertisement.clicks_total) }} clicks ·
                                    {{ formatPercentage(advertisement.ctr) }} CTR
                                </small>
                            </div>
                        </td>
                        <td>
                            <span
                                :class="[
                                    'status-badge',
                                    'advertisement-status-badge',
                                    statusClass(advertisement.status),
                                ]"
                            >
                                {{ advertisement.status_label }}
                            </span>
                        </td>
                        <td>
                            <div class="row-actions">
                                <button
                                    type="button"
                                    title="Editar publicidad"
                                    @click.stop="openEdit(advertisement)"
                                >
                                    <Pencil :size="16" />
                                </button>
                                <button
                                    type="button"
                                    :title="deleteAdvertisementTitle(advertisement)"
                                    :disabled="!canDeleteAdvertisement(advertisement)"
                                    @click.stop="openDelete(advertisement)"
                                >
                                    <Trash2 :size="16" />
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p v-if="!loadError && !advertisementRows.length" class="empty-state">
                No hay publicidades para los filtros seleccionados.
            </p>
        </div>
    </section>

    <AdminPagination
        v-if="!loadError && pagination"
        :pagination="pagination"
        :per-page="grid.per_page"
        :per-page-options="PER_PAGE_OPTIONS"
        item-label="publicidades"
        @update:per-page="updatePerPage"
    />

    <ConfirmActionModal
        :open="Boolean(deleting)"
        eyebrow="Eliminar campaña"
        title="¿Eliminar esta campaña?"
        confirm-label="Eliminar campaña"
        processing-label="Eliminando..."
        :processing="deleteProcessing"
        :error="deleteError"
        danger
        @confirm="deleteAdvertisement"
        @cancel="closeDelete"
    >
        <template v-if="deleting">
            <strong>{{ deleting.brand }} - {{ deleting.name }}</strong>
            se eliminará junto con sus métricas. Esta acción no se puede deshacer.
        </template>
        <template v-else>
            La campaña se eliminará junto con sus métricas. Esta acción no se puede deshacer.
        </template>
    </ConfirmActionModal>

    <!-- =====================================================================
         MODAL · NUEVA PUBLICIDAD
         ===================================================================== -->
    <div v-if="creating" class="admin-modal-backdrop">
        <form class="admin-modal advertisement-admin-modal" @submit.prevent="createAdvertisement">
            <header>
                <div>
                    <p class="eyebrow">Publicidad</p>
                    <h2>Nueva publicidad</h2>
                    <p class="admin-modal-subtitle">Configurá la campaña y el espacio donde será mostrada.</p>
                </div>
                <button
                    type="button"
                    aria-label="Cerrar"
                    :disabled="createAdvertisementBusy"
                    @click="closeCreate"
                >
                    <X />
                </button>
            </header>

            <div class="advertisement-modal-grid">
                <label>
                    Marca
                    <input
                        v-model.trim="createForm.brand"
                        class="advertisement-modal-control"
                        type="text"
                        required
                        maxlength="50"
                        :disabled="createForm.processing"
                        placeholder="Banco NEXO"
                    />
                    <span v-if="createForm.errors.brand" class="form-error">{{ createForm.errors.brand }}</span>
                </label>

                <label>
                    Nombre de campaña
                    <input
                        v-model.trim="createForm.name"
                        class="advertisement-modal-control"
                        type="text"
                        required
                        maxlength="60"
                        :disabled="createForm.processing"
                        placeholder="Cuenta digital - Agosto 2026"
                    />
                    <span v-if="createForm.errors.name" class="form-error">{{ createForm.errors.name }}</span>
                </label>

                <label>
                    Ubicación
                    <span class="advertisement-select-control">
                        <select
                            v-model="createForm.placement"
                            required
                            :disabled="createForm.processing"
                        >
                            <option
                                v-for="placement in props.filterOptions.placements"
                                :key="placement.value"
                                :value="placement.value"
                            >
                                {{ placement.label }} · {{ placementDimensions(placement.value) }}
                            </option>
                        </select>
                        <ChevronDown :size="16" aria-hidden="true" />
                    </span>
                    <span v-if="createForm.errors.placement" class="form-error">{{ createForm.errors.placement }}</span>
                </label>

                <label>
                    Destino del anuncio
                    <input
                        v-model.trim="createForm.target_url"
                        class="advertisement-modal-control"
                        type="text"
                        required
                        inputmode="url"
                        placeholder="/demo/anunciante o https://sitio.com"
                        maxlength="255"
                        :disabled="createForm.processing"
                        @blur="normalizeTarget(createForm)"
                    />
                    <span v-if="createForm.errors.target_url" class="form-error">{{ createForm.errors.target_url }}</span>
                </label>

                <label class="admin-upload-card advertisement-upload-card">
                    <div class="admin-upload-copy">
                        <ImageUp :size="22" />
                        <div>
                            <strong>Imagen del banner</strong>
                            <span>
                                JPG, JPEG, PNG o WEBP · {{ placementDimensions(createForm.placement) }} exactos · máximo 4 MB
                            </span>
                        </div>
                    </div>
                    <input
                        :key="createImageInputKey"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        required
                        :disabled="createForm.processing"
                        @change="handleCreateImage"
                    />
                    <img
                        v-if="createPreview"
                        :src="createPreview"
                        alt="Previsualización del banner"
                        class="admin-upload-preview banner-upload-preview"
                    />
                    <span v-if="createForm.errors.image_file" class="form-error">{{ createForm.errors.image_file }}</span>
                </label>

                <label class="advertisement-date-field">
                    <span>Inicio</span>
                    <VueDatePicker
                        v-model="createForm.starts_at"
                        class="editorial-date-picker"
                        model-type="yyyy-MM-dd'T'HH:mm"
                        :formats="{ input: 'dd/MM/yyyy HH:mm' }"
                        placeholder="Seleccionar fecha y hora"
                        :locale="es"
                        dark
                        :clearable="false"
                        :disabled="createForm.processing"
                        :teleport="true"
                        :action-row="{
                            selectBtnLabel: 'Listo',
                            cancelBtnLabel: 'Cancelar',
                            showSelect: true,
                            showCancel: true,
                            showNow: false,
                            showPreview: false,
                        }"
                        :time-config="{
                            enableTimePicker: true,
                            enableSeconds: false,
                            enableMinutes: true,
                            is24: true,
                            minutesIncrement: 1,
                            timePickerInline: true,
                        }"
                    />
                    <span v-if="createForm.errors.starts_at" class="form-error">{{ createForm.errors.starts_at }}</span>
                </label>

                <label class="advertisement-date-field">
                    <span>Finalización</span>
                    <VueDatePicker
                        v-model="createForm.ends_at"
                        class="editorial-date-picker"
                        model-type="yyyy-MM-dd'T'HH:mm"
                        :formats="{ input: 'dd/MM/yyyy HH:mm' }"
                        placeholder="Seleccionar fecha y hora"
                        :locale="es"
                        dark
                        :clearable="false"
                        :disabled="createForm.processing"
                        :teleport="true"
                        :action-row="{
                            selectBtnLabel: 'Listo',
                            cancelBtnLabel: 'Cancelar',
                            showSelect: true,
                            showCancel: true,
                            showNow: false,
                            showPreview: false,
                        }"
                        :time-config="{
                            enableTimePicker: true,
                            enableSeconds: false,
                            enableMinutes: true,
                            is24: true,
                            minutesIncrement: 1,
                            timePickerInline: true,
                        }"
                    />
                    <span v-if="createForm.errors.ends_at" class="form-error">{{ createForm.errors.ends_at }}</span>
                </label>

                <label class="admin-toggle-card advertisement-toggle-card">
                    <span>
                        <strong>Campaña habilitada</strong>
                        <small>Se mostrará únicamente dentro del período indicado.</small>
                    </span>
                    <input v-model="createForm.is_active" type="checkbox" :disabled="createForm.processing" />
                </label>
            </div>

            <span v-if="createForm.errors.is_active" class="form-error">{{ createForm.errors.is_active }}</span>
            <p v-if="createForm.errors.operation" class="operation-error" role="alert">{{ createForm.errors.operation }}</p>

            <footer class="admin-modal-footer">
                <button
                    type="button"
                    class="admin-modal-cancel"
                    :disabled="createAdvertisementBusy"
                    @click="closeCreate"
                >
                    Cancelar
                </button>
                <button
                    type="submit"
                    class="admin-modal-submit"
                    :disabled="!createAdvertisementReady"
                >
                    {{ createAdvertisementBusy ? 'Creando...' : 'Crear publicidad' }}
                </button>
            </footer>
        </form>
    </div>

    <!-- =====================================================================
         MODAL · EDITAR PUBLICIDAD
         ===================================================================== -->
    <div v-if="editing" class="admin-modal-backdrop">
        <form class="admin-modal advertisement-admin-modal" @submit.prevent="updateAdvertisement">
            <header>
                <div>
                    <p class="eyebrow">Publicidad</p>
                    <h2>Editar publicidad</h2>
                    <p class="admin-modal-subtitle">Actualizá la campaña sin perder sus métricas históricas.</p>
                </div>
                <button
                    type="button"
                    aria-label="Cerrar"
                    :disabled="editAdvertisementBusy"
                    @click="closeEdit"
                >
                    <X />
                </button>
            </header>

            <div class="advertisement-modal-grid">
                <label>
                    Marca
                    <input
                        v-model.trim="editForm.brand"
                        class="advertisement-modal-control"
                        type="text"
                        required
                        maxlength="50"
                        disabled
                    />
                    <span v-if="editForm.errors.brand" class="form-error">{{ editForm.errors.brand }}</span>
                </label>

                <label>
                    Nombre de campaña
                    <input
                        v-model.trim="editForm.name"
                        class="advertisement-modal-control"
                        type="text"
                        required
                        maxlength="60"
                        :disabled="editForm.processing"
                    />
                    <span v-if="editForm.errors.name" class="form-error">{{ editForm.errors.name }}</span>
                </label>

                <label>
                    Ubicación
                    <span class="advertisement-select-control advertisement-select-control--locked">
                        <select
                            v-model="editForm.placement"
                            required
                            disabled
                        >
                            <option
                                v-for="placement in props.filterOptions.placements"
                                :key="placement.value"
                                :value="placement.value"
                            >
                                {{ placement.label }} · {{ placementDimensions(placement.value) }}
                            </option>
                        </select>
                        <ChevronDown :size="16" aria-hidden="true" />
                    </span>
                    <span v-if="editForm.errors.placement" class="form-error">{{ editForm.errors.placement }}</span>
                </label>

                <label>
                    Destino del anuncio
                    <input
                        v-model.trim="editForm.target_url"
                        class="advertisement-modal-control"
                        type="text"
                        required
                        inputmode="url"
                        placeholder="/demo/anunciante o https://sitio.com"
                        maxlength="255"
                        :disabled="editForm.processing"
                        @blur="normalizeTarget(editForm)"
                    />
                    <span v-if="editForm.errors.target_url" class="form-error">{{ editForm.errors.target_url }}</span>
                </label>

                <div class="advertisement-edit-image-block">
                    <div class="advertisement-modal-preview">
                        <!--
                            Si el usuario ya seleccionó una imagen nueva, editPreview tiene
                            prioridad porque representa la reparación que está por guardar.

                            Si todavía no seleccionó nada, sólo mostramos la imagen histórica
                            cuando Laravel confirmó que el archivo físico continúa existiendo.
                        -->
                        <img
                            v-if="editPreview || editing.image_exists"
                            :src="editPreview || editing.image_url"
                            :alt="`Publicidad de ${editing.brand}`"
                        />

                        <!--
                            Evita mostrar una imagen rota cuando alguien eliminó o renombró el
                            WebP manualmente fuera del panel administrador.
                        -->
                        <span
                            v-else
                            class="advertisement-missing-preview"
                        >
                            Banner no disponible
                        </span>

                        <div>
                            <strong>{{ placementOption(editForm.placement)?.label }}</strong>
                            <small>{{ editing.image_path }}</small>
                            <span>{{ placementDimensions(editForm.placement) }}</span>
                        </div>
                    </div>
                    <label class="admin-upload-card compact-upload-card advertisement-upload-card">
                        <div class="admin-upload-copy">
                            <ImageUp :size="20" />
                            <div>
                                <strong>Cambiar imagen</strong>

                                <span v-if="editing.image_exists">
                                    Opcional · {{ placementDimensions(editForm.placement) }} exactos · máximo 4 MB
                                </span>

                                <span v-else>
                                    Necesaria para reparar el banner · {{ placementDimensions(editForm.placement) }} exactos · máximo 4 MB
                                </span>
                            </div>
                        </div>
                        <input
                            :key="editImageInputKey"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            :disabled="editForm.processing"
                            @change="handleEditImage"
                        />
                        <span v-if="editForm.errors.image_file" class="form-error">{{ editForm.errors.image_file }}</span>
                    </label>
                </div>

                <label class="advertisement-date-field">
                    <span>Inicio</span>
                    <VueDatePicker
                        v-model="editForm.starts_at"
                        class="editorial-date-picker"
                        model-type="yyyy-MM-dd'T'HH:mm"
                        :formats="{ input: 'dd/MM/yyyy HH:mm' }"
                        placeholder="Seleccionar fecha y hora"
                        :locale="es"
                        dark
                        :clearable="false"
                        :disabled="editForm.processing || editStartLocked"
                        :teleport="true"
                        :action-row="{
                            selectBtnLabel: 'Listo',
                            cancelBtnLabel: 'Cancelar',
                            showSelect: true,
                            showCancel: true,
                            showNow: false,
                            showPreview: false,
                        }"
                        :time-config="{
                            enableTimePicker: true,
                            enableSeconds: false,
                            enableMinutes: true,
                            is24: true,
                            minutesIncrement: 1,
                            timePickerInline: true,
                        }"
                    />
                    <span v-if="editForm.errors.starts_at" class="form-error">{{ editForm.errors.starts_at }}</span>
                </label>

                <label class="advertisement-date-field">
                    <span>Finalización</span>
                    <VueDatePicker
                        v-model="editForm.ends_at"
                        class="editorial-date-picker"
                        model-type="yyyy-MM-dd'T'HH:mm"
                        :formats="{ input: 'dd/MM/yyyy HH:mm' }"
                        placeholder="Seleccionar fecha y hora"
                        :locale="es"
                        dark
                        :clearable="false"
                        :disabled="editForm.processing"
                        :teleport="true"
                        :action-row="{
                            selectBtnLabel: 'Listo',
                            cancelBtnLabel: 'Cancelar',
                            showSelect: true,
                            showCancel: true,
                            showNow: false,
                            showPreview: false,
                        }"
                        :time-config="{
                            enableTimePicker: true,
                            enableSeconds: false,
                            enableMinutes: true,
                            is24: true,
                            minutesIncrement: 1,
                            timePickerInline: true,
                        }"
                    />
                    <span v-if="editForm.errors.ends_at" class="form-error">{{ editForm.errors.ends_at }}</span>
                </label>

                <label class="admin-toggle-card advertisement-toggle-card">
                    <span>
                        <strong>Campaña habilitada</strong>
                        <small>Desactivarla la deja en estado PAUSADA sin borrar el historial.</small>
                    </span>
                    <input v-model="editForm.is_active" type="checkbox" :disabled="editForm.processing" />
                </label>
            </div>

            <span v-if="editForm.errors.is_active" class="form-error">{{ editForm.errors.is_active }}</span>
            <p v-if="editForm.errors.operation" class="operation-error" role="alert">{{ editForm.errors.operation }}</p>

            <footer class="admin-modal-footer">
                <button
                    type="button"
                    class="admin-modal-cancel"
                    :disabled="editAdvertisementBusy"
                    @click="closeEdit"
                >
                    Cancelar
                </button>
                <button
                    type="submit"
                    class="admin-modal-submit"
                    :disabled="editAdvertisementBusy || !editAdvertisementChanged"
                >
                    {{ editAdvertisementBusy ? 'Guardando...' : 'Guardar cambios' }}
                </button>
            </footer>
        </form>
    </div>
</template>

<style src="../../../../css/admin/advertisements.css"></style>
