<script setup>
/* ============================================================================
 * PAGE: Admin/Articles/Form.vue
 * ============================================================================
 *
 * Pantalla principal para crear, editar, revisar y consultar noticias en TRAMA.
 *
 * Gestiona:
 *
 *      - Contenido, configuración, portada e historial de versiones;
 *      - Validación de campos, límites y mensajes de error;
 *      - Permisos y bloqueos según autor, rol y estado editorial;
 *      - Autoguardado y protección de cambios sin guardar;
 *      - Previsualización de la noticia;
 *      - Devolución con observaciones y reenvío a revisión;
 *      - Programación, reprogramación, publicación y archivo;
 *      - Modales de confirmación y bloqueo del scroll de fondo.
 *
 * Las reglas definitivas de autorización, validación y transición de estados
 * permanecen en Laravel. Esta pantalla coordina la interface y envía las
 * acciones correspondientes al backend.
 * ============================================================================ */

import { computed, inject, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { ChevronDown, Eye, Send, X } from '@lucide/vue';
import { VueDatePicker } from '@vuepic/vue-datepicker';
import { es } from 'date-fns/locale';
import RichTextEditor from '@/Components/Editor/RichTextEditor.vue';
import RevisionHistory from '@/Components/Editor/RevisionHistory.vue';
import ConfirmActionModal from '@/Components/Shared/ConfirmActionModal.vue';

// Props del formulario de noticias.
const props = defineProps({
    // Noticia que se está editando o consultando.
    // Al crear una noticia nueva su valor puede ser null.
    article: Object,
    // Categorías disponibles para seleccionar dentro del formulario.
    categories: Array,
    // Etiquetas disponibles para asociar a la noticia.
    tags: Array,
    // Indica el modo actual del formulario, por ejemplo creación o edición.
    mode: String,
    // Estados editoriales disponibles para la operación actual.
    statusOptions: Array,
    // Permisos del usuario autenticado para revisar, publicar,
    // programar o realizar otras acciones editoriales.
    permissions: Object,
    // Solicitudes de corrección y observaciones editoriales asociadas
    // a la noticia.
    feedback: Array,
    // Historial de revisiones y acciones realizadas sobre la noticia.
    revisions: Array,
    // Fecha editorial de referencia configurada para TRAMA.
    referenceDate: {
        type: String,
        default: '',
    },
    // Límites permitidos para programar la publicación de una noticia.
    // min contiene la fecha y hora más tempranas disponibles.
    // max contiene la fecha y hora máximas permitidas.
    scheduleWindow: {
        type: Object,
        default: () => ({
            min: '',
            max: '',
        }),
    },
    // Indica si el formulario debe mostrarse únicamente para consulta,
    // bloqueando la edición de sus campos.
    readOnly: {
        type: Boolean,
        default: false,
    },
});

// Formulario principal de la noticia: contenido, estado, portada, flags y etiquetas.
const form = useForm({
    // Al crear una noticia obliga a elegir una categoría.
    // Al editar conserva la categoría que ya estaba guardada.
    category_id: props.article?.category_id || '',
    title: props.article?.title || '',
    subtitle: props.article?.subtitle || '',
    excerpt: props.article?.excerpt || '',
    body: props.article?.body || '',
    cover_image_file: null,
    remove_cover_image: false,
    status: props.article?.status || 'draft',
    is_breaking: props.article?.is_breaking || false,
    is_featured: props.article?.is_featured || false,    
    published_at: props.article?.published_at || '',
    scheduled_at: props.article?.scheduled_at || '',
    tag_ids: props.article?.tag_ids || [],
});

// Recupera la función compartida por AdminEditorialLayout.
//
// inject() permite acceder a un valor o una función que un componente
// padre dejó disponible mediante provide().
//
// En este caso, Form.vue recibe la función que oculta
// el mensaje flash global del panel editorial.
const hideEditorialFlash = inject(
    'hideEditorialFlash',
    () => {}
);

// Recupera la función del layout que vuelve
// a mostrar el mensaje después de guardar.
const showEditorialFlash = inject(
    'showEditorialFlash',
    () => {}
);

// Formulario para devolver una noticia con observaciones editoriales.
const feedbackForm = useForm({ message: '' });

// Permite leer errores producidos por acciones que utilizan router
// en lugar del formulario principal, como archivar o restaurar.
const page = usePage();

// Recuerda un error de router que ya fue mostrado dentro de una confirmación.
// Así no reaparece duplicado arriba del formulario al cerrar la modal.
const handledConfirmationOperationError = ref('');

/*
 * Reúne el error general de cualquiera de las operaciones de la pantalla.
 *
 * form cubre guardados generales fuera de una confirmación.
 * La devolución para corrección muestra su error dentro de su propia modal.
 * page.props cubre acciones de router que no fueron absorbidas por una confirmación.
 */
const operationError = computed(() => {
    const pageOperationError = page.props.errors?.operation || '';
    const visiblePageOperationError = pageOperationError
        && pageOperationError !== handledConfirmationOperationError.value
            ? pageOperationError
            : '';

    return form.errors.operation
        || visiblePageOperationError
        || '';
});


// Buscador local de etiquetas. Laravel limita el catálogo activo a una taxonomía
// administrada y Vue muestra solamente un grupo corto de sugerencias relevantes.
const tagSearch = ref('');
const tagSuggestionsOpen = ref(false);
// Referencia al control completo para cerrar sugerencias cuando se hace clic afuera.
const tagSearchControl = ref(null);
const MAX_TAGS_PER_ARTICLE = 5;

// Etiquetas que ya pertenecen a la noticia, incluidas históricas/inactivas.
const selectedTags = computed(() => {
    const selectedIds = new Set((form.tag_ids || []).map((id) => Number(id)));
    return props.tags.filter((tag) => selectedIds.has(Number(tag.id)));
});

// Sugerencias activas no seleccionadas. La categoría elegida aumenta la prioridad
// de las etiquetas usadas históricamente allí, pero nunca impide buscar otra.
const tagSuggestions = computed(() => {
    const selectedIds = new Set((form.tag_ids || []).map((id) => Number(id)));
    const term = tagSearch.value.trim().toLocaleLowerCase('es-AR');
    const hasSearchTerm = term.length >= 2;
    const categoryId = String(form.category_id || '');

    return props.tags
        .filter((tag) => tag.is_active !== false && !tag.merged && !selectedIds.has(Number(tag.id)))
        // Con menos de dos caracteres conserva las sugerencias frecuentes; desde
        // dos caracteres el buscador filtra coincidencias por nombre.
        .filter((tag) => !hasSearchTerm || tag.name.toLocaleLowerCase('es-AR').includes(term))
        .sort((left, right) => {
            const leftUsage = Number(left.usage_by_category?.[categoryId] || 0);
            const rightUsage = Number(right.usage_by_category?.[categoryId] || 0);

            if (categoryId && leftUsage !== rightUsage) {
                return rightUsage - leftUsage;
            }

            const leftTotal = Number(left.usage_total || 0);
            const rightTotal = Number(right.usage_total || 0);

            if (leftTotal !== rightTotal) {
                return rightTotal - leftTotal;
            }

            return left.name.localeCompare(right.name, 'es-AR');
        })
        .slice(0, 10);
});

const tagLimitReached = computed(() => (form.tag_ids || []).length >= MAX_TAGS_PER_ARTICLE);

// Pestaña activa del formulario: contenido, configuración o historial.
const activeTab = ref('content');

// Referencia al bloque completo del cuerpo de la noticia.
// Se usa para ubicar el tooltip y desplazar la pantalla hasta el editor.
const bodyEditorField = ref(null);
// Controla si debe mostrarse el tooltip personalizado del cuerpo.
const bodyTooltipVisible = ref(false);

// Controla el modal de previsualización de la noticia.
const previewOpen = ref(false);
// Controla el modal que confirma el envio inicial a revision.
const sendToReviewOpen = ref(false);
// Error inesperado mostrado dentro de la confirmacion de envio a revision.
const sendToReviewError = ref('');
// Controla el modal para solicitar correcciones.
const feedbackOpen = ref(false);
// Referencia al campo de observaciones del modal de devolución.
//
// Permite colocar el foco automáticamente en el textarea después de que Vue
// termina de renderizar el modal.
const feedbackTextarea = ref(null);
// Controla el modal donde el periodista consulta
// las observaciones pendientes dejadas por la edición.
const observationsOpen = ref(false);
// Controla el modal que confirma la publicación inmediata.
const publishOpen = ref(false);
// Error inesperado mostrado dentro de la confirmación de publicación.
const publishError = ref('');
// Controla el modal para elegir fecha y hora de programación.
const scheduleOpen = ref(false);
// Controla el modal que confirma el reenvío de una noticia corregida.
const resubmitOpen = ref(false);
// Error inesperado mostrado dentro de la confirmación de reenvío.
const resubmitError = ref('');
// Bloquea el modal mientras se procesa el reenvío.
const resubmitProcessing = ref(false);
// Bloquea el botón de archivar mientras Laravel procesa la acción.
const archiveProcessing = ref(false);
// Controla la apertura del modal que confirma el archivo de una noticia.
const archiveOpen = ref(false);
// Error inesperado mostrado dentro de la confirmación de archivo.
const archiveError = ref('');

// Controla el modal de eliminación definitiva.
const deleteOpen = ref(false);
// Error inesperado mostrado dentro de la confirmación de eliminación.
const deleteError = ref('');
// Bloquea la eliminación mientras Laravel procesa la petición.
const deleteProcessing = ref(false);

// Controla el modal que confirma la cancelación de una programación.
const cancelScheduleOpen = ref(false);
// Muestra dentro del modal un error ocurrido al cancelar la programación.
const cancelScheduleError = ref('');
// Bloquea la cancelación mientras Laravel procesa la petición.
const cancelScheduleProcessing = ref(false);
// Controla el modal que protege los cambios sin guardar.
const unsavedChangesOpen = ref(false);

// Indica si existe algún modal abierto en la pantalla.
//
// Mientras el valor sea true, se bloquea el scroll de la página
// que queda detrás del modal.
const hasOpenModal = computed(() =>
    previewOpen.value
    || sendToReviewOpen.value
    || feedbackOpen.value
    || observationsOpen.value
    || publishOpen.value
    || cancelScheduleOpen.value
    || archiveOpen.value
    || deleteOpen.value
    || scheduleOpen.value
    || resubmitOpen.value
    || unsavedChangesOpen.value
);

// Indica si se está guardando antes de abandonar la pantalla.
const savingBeforeLeave = ref(false);

// Referencia al formulario principal.
// Permite validarlo también desde el modal.
const articleForm = ref(null);

// Guarda temporalmente la navegación que el usuario intentó realizar.
let pendingVisit = null;

// Función utilizada para eliminar el listener de Inertia al desmontar.
let removeBeforeVisitListener = null;

// Permite ejecutar una navegación intencional sin volver
// a abrir el modal de cambios sin guardar.
let allowNextInertiaVisit = false;

// Referencia al input real de archivo para abrirlo desde un botón estilizado.
const imageInput = ref(null);

// Referencia al bloque completo de la imagen de portada.
// Se usa para llevar al usuario hasta la imagen cuando falta.
const coverUploadField = ref(null);

// URL temporal de la imagen elegida antes de guardarla.
const selectedImagePreview = ref('');
// Nombre visible del archivo elegido.
const selectedImageName = ref('');
// Fecha editable dentro del modal de programación.
const scheduleDate = ref(props.article?.scheduled_at || '');

// Tamaño máximo permitido para la portada: 5 MB.
const MAX_COVER_SIZE = 5 * 1024 * 1024;
// Dimensiones mínimas requeridas para la portada.
const MIN_COVER_WIDTH = 1600;
const MIN_COVER_HEIGHT = 900;

// Estado del guardado automático: idle, pending, saving, saved o error.
const autosaveState = ref('idle');
// Mensaje visible del último guardado automático.
const autosaveMessage = ref('');
let autosaveTimer = null;
let autosaveEnabled = false;

// Límites visuales de los campos principales de la noticia.
// Estos valores también se usan en minlength, maxlength y en los contadores.
const textLimits = {
    title: { min: 20, max: 90 },
    subtitle: { min: 50, max: 140 },
    excerpt: { min: 50, max: 200 },
};

// Límites del cuerpo de la noticia.
// Se declaran también acá para pasárselos explícitamente al RichTextEditor
// y para impedir el autoguardado mientras el cuerpo esté incompleto.
const bodyLimits = {
    min: 300,
    max: 10000,
};

// Estados que exigen una noticia completa.
//
// Guardar borrador y guardar correcciones permiten avanzar con campos incompletos.
// En cambio, enviar a revisión, programar o publicar representan una entrega formal,
// por eso recién ahí se muestran los mínimos y se validan todos los campos obligatorios.
const completeArticleStatuses = ['review', 'scheduled', 'published'];

// Límites de la observación que escribe el editor al devolver una noticia.
//
// El mínimo obliga a explicar el pedido de corrección con algo más que una palabra.
// El máximo evita que la devolución se convierta en un informe demasiado largo.
const feedbackLimits = {
    min: 15,
    max: 1200,
};

// Mantiene el texto de devolución legible sin permitir bloques enormes de
// renglones vacíos. Un Enter se conserva y tres o más se reducen a dos, igual
// que en RequestArticleChangesRequest antes de guardar.
function normalizeFeedbackMessage(value) {
    return String(value || '')
        .replace(/\r\n?/g, '\n')
        .replace(/[ \t]*\n[ \t]*/g, '\n')
        .replace(/\n{3,}/g, '\n\n');
}

function handleFeedbackMessageInput(event) {
    const normalized = normalizeFeedbackMessage(
        event?.target?.value ?? feedbackForm.message,
    ).slice(0, feedbackLimits.max);

    feedbackForm.message = normalized;
    feedbackForm.clearErrors('message');

    if (event?.target && event.target.value !== normalized) {
        event.target.value = normalized;
    }
}

// Recuerda si el usuario ya intentó una acción que exige noticia completa.
//
// Mientras solo escribe, los contadores muestran 18/90.
// Después de intentar enviar, programar o publicar, muestran también los faltantes.
const attemptedCompleteArticleAction = ref(false);

// Guarda temporalmente la acción formal que se está validando.
//
// No modifica form.status, porque la noticia todavía no fue
// programada, publicada ni enviada a revisión.
const pendingCompleteArticleStatus = ref('');

// Título de pantalla según modo de creación, edición o consulta.
const title = computed(() => props.readOnly ? 'Ver noticia' : (props.mode === 'create' ? 'Nueva noticia' : 'Editar noticia'));
// Nombre del archivo de portada ya guardado en la noticia.
const currentImageName = computed(() => props.article?.cover_image?.split('/').pop() || '');
// Detecta una ruta guardada cuyo archivo físico ya no existe.
const currentImageMissing = computed(() => Boolean(
    props.article?.cover_image
    && props.article?.cover_image_exists === false
));
// Indica si hay una portada existente y físicamente válida que todavía debe mostrarse.
const hasCurrentImage = computed(() => Boolean(
    props.article?.cover_image
    && props.article?.cover_image_exists !== false
    && !form.remove_cover_image
    && !selectedImagePreview.value
));
// Imagen que se muestra en la previsualización: nueva, existente válida o vacía.
const visibleImagePreview = computed(() => selectedImagePreview.value || (hasCurrentImage.value ? props.article.cover_image : ''));
// Texto del botón de imagen según exista o no una portada visible.
const imageActionLabel = computed(() => visibleImagePreview.value ? 'Cambiar imagen' : 'Seleccionar imagen');

// Observaciones abiertas que todavía requieren corrección.
const openFeedback = computed(() => (props.feedback || []).filter((item) => item.is_open));

/*
 * Nombre del periodista autor mostrado cuando el editor
 * revisa una noticia perteneciente a otra persona.
 */
const articleAuthorName = computed(() => (
    props.article?.author?.name || 'Autor no disponible'
));

/*
 * Última devolución que todavía permanece abierta.
 *
 * Laravel envía las devoluciones ordenadas desde la más reciente.
 */
const latestOpenFeedback = computed(() => (
    openFeedback.value[0] || null
));

// Última vez que la edición devolvió la noticia para corregir.
const latestChangesRequestedRevision = computed(() => (
    (props.revisions || []).find(
        (revision) => revision.action === 'changes_requested'
    ) || null
));

// Última vez que el periodista reenvió la noticia corregida.
const latestResubmittedRevision = computed(() => (
    (props.revisions || []).find(
        (revision) => revision.action === 'resubmitted'
    ) || null
));

// Fecha y hora de la última devolución realizada por la edición.
const latestReturnDateLabel = computed(() => (
    formatEditorialDateTime(
        latestChangesRequestedRevision.value?.created_at
    )
));

// Fecha y hora del último reenvío realizado por el periodista.
const latestResubmissionDateLabel = computed(() => (
    formatEditorialDateTime(
        latestResubmittedRevision.value?.created_at
    )
));

// Obtiene el estado real de la noticia guardada.
//
// En una noticia existente usa article.status.
// En una noticia nueva, donde todavía no existe article,
// utiliza "draft" como estado inicial.
const currentStatus = computed(() => {
    return props.article?.status || 'draft';
});

// Traduce los códigos internos de estado utilizados por Laravel
// a textos claros para mostrar dentro del panel editorial.
//
// Esta lista no depende de los estados que el usuario pueda seleccionar.
// Por eso también permite mostrar correctamente una noticia publicada,
// archivada o programada aunque el usuario esté en modo de solo lectura.
const statusLabels = {
    draft: 'En borrador',
    review: 'En revisión',
    needs_changes: 'Devuelta para corrección',
    scheduled: 'Programada',
    published: 'Publicada',
    archived: 'Archivada',
};

// Devuelve la etiqueta correspondiente al estado real de la noticia.
//
// Los estados posibles están definidos por el flujo editorial,
// por lo que cada código debe tener su etiqueta correspondiente
// dentro de statusLabels.
const currentStatusLabel = computed(() => {
    return statusLabels[currentStatus.value];
});

// Fecha y hora más tempranas permitidas para programar.
// Laravel la calcula con el reloj editorial completo de TRAMA; ya no mezcla
// la fecha fija del proyecto con la hora real del servidor.
const scheduleMin = computed(() => props.scheduleWindow?.min || '');
// Fecha y hora más tardías permitidas para programar:
// treinta días después de la fecha editorial, a las 23:59.
const scheduleMax = computed(() => props.scheduleWindow?.max || '');
// Indica si la noticia editada ya está guardada como Programada.
const isScheduledArticle = computed(() => currentStatus.value === 'scheduled');
// Texto del botón de programación según se cree una nueva fecha o se modifique una existente.
const scheduleActionLabel = computed(() => isScheduledArticle.value ? 'Reprogramar noticia' : 'Programar noticia');
// Título del modal según se programe por primera vez o se reprograma una noticia.
const scheduleModalTitle = computed(() => isScheduledArticle.value ? 'Reprogramar noticia' : 'Programar noticia');
// Texto del botón que confirma la fecha dentro del modal.
const scheduleConfirmLabel = computed(() => isScheduledArticle.value ? 'Confirmar reprogramación' : 'Confirmar programación');
// Mensaje de proceso mostrado mientras Laravel guarda la fecha.
const scheduleProcessingLabel = computed(() => isScheduledArticle.value ? 'Reprogramando...' : 'Programando...');

// Fecha prevista para una noticia programada.
const scheduledAtLabel = computed(() => (
    formatArticleDateTime(props.article?.scheduled_at)
));

// Fecha en que la noticia quedó publicada.
const publishedAtLabel = computed(() => (
    formatArticleDateTime(props.article?.published_at)
));

// Indica si el usuario puede tomar decisiones de edición: devolver, programar, publicar o archivar.
const isEditor = computed(() => Boolean(props.permissions.can_review));

// Indica si la noticia pertenece al usuario autenticado.
const isOwner = computed(() =>
    props.article?.author_id === props.permissions.user_id
);

/*
 * Indica si el aviso superior de correcciones ya está mostrando
 * el estado "Devuelta para corrección".
 *
 * Cuando este aviso está visible no hace falta repetir el mismo
 * estado dentro de la pestaña Configuración.
 */
const showCorrectionFeedbackBanner = computed(() =>
    openFeedback.value.length > 0
    && currentStatus.value === 'needs_changes'
    && isOwner.value
    && !isEditor.value
);

// Revisiones asociadas a la programación de una noticia.
const schedulingRevisionActions = [
    'scheduled',
    'created_and_scheduled',
];

// Revisiones asociadas a la publicación de una noticia.
const publicationRevisionActions = [
    'published',
    'created_and_published',
    'published_automatically',
];

// Revisiones asociadas al archivado de una noticia.
const archivalRevisionActions = [
    'archived',
];

/*
 * Busca la revisión más reciente que produjo el estado editorial actual.
 *
 * Si la noticia está programada, obtiene la última acción de programación.
 * Si está publicada, obtiene la acción que aprobó o ejecutó la publicación.
 * Si está archivada, obtiene la acción con la que fue archivada.
 *
 * Esa revisión permite identificar al editor responsable de la decisión.
 * Laravel envía las revisiones desde la más reciente, por lo que find()
 * devuelve la última acción editorial correspondiente.
 */
const workflowDecisionRevision = computed(() => {

    // Busca la revisión que dejó la noticia programada.
    if (currentStatus.value === 'scheduled') {
        return (props.revisions || []).find(
            (revision) =>
                schedulingRevisionActions.includes(revision.action)
        ) || null;
    }

    // Busca la revisión que dejó la noticia publicada.
    if (currentStatus.value === 'published') {
        return (props.revisions || []).find(
            (revision) =>
                publicationRevisionActions.includes(revision.action)
        ) || null;
    }

    // Busca la revisión con la que se archivó la noticia.
    if (currentStatus.value === 'archived') {
        return (props.revisions || []).find(
            (revision) =>
                archivalRevisionActions.includes(revision.action)
        ) || null;
    }

    // Los demás estados no tienen una decisión final asociada.
    return null;
});

/*
 * Obtiene el nombre del editor responsable de la decisión editorial.
 *
 * El nombre solo se muestra cuando la acción fue realizada
 * por una persona distinta del usuario que está consultando la noticia.
 */
const workflowDecisionActorName = computed(() => {
    const user = workflowDecisionRevision.value?.user;

    if (
        !user?.name
        || Number(user.id) === Number(props.permissions.user_id)
    ) {
        return '';
    }

    return user.name;
});

// Construye la fecha que se muestra en el aviso superior de la noticia
// cuando está programada, publicada o archivada.
//
// Programada: muestra cuándo está prevista su publicación.
// Publicada: muestra cuándo fue publicada.
// Archivada: muestra cuándo se realizó el archivado, tomando la fecha
// de la revisión editorial que registró esa acción.
const workflowDecisionDateLabel = computed(() => {
    // Muestra la fecha prevista cuando la noticia está programada.
    if (
        currentStatus.value === 'scheduled'
        && scheduledAtLabel.value
    ) {
        return `Publicación prevista: ${scheduledAtLabel.value}`;
    }

    // Muestra cuándo quedó publicada.
    if (
        currentStatus.value === 'published'
        && publishedAtLabel.value
    ) {
        return `Publicada el ${publishedAtLabel.value}`;
    }

    // Para archivadas, toma la fecha de la revisión que registró el archivado.
    if (currentStatus.value === 'archived') {
        const archivedAtLabel = formatArticleDateTime(
            workflowDecisionRevision.value?.created_at
        );

        // Solo muestra la línea si pudo obtener una fecha válida.
        if (archivedAtLabel) {
            return `Archivada el ${archivedAtLabel}`;
        }
    }

    return '';
});

// Indica si el editor está revisando una noticia escrita por otra persona.
//
// En esta situación puede tomar decisiones editoriales, pero no modificar
// directamente el contenido enviado por el periodista.
const isReviewingForeignArticle = computed(() =>
    isEditor.value
    && !isOwner.value
    && currentStatus.value === 'review'
);

// Define el título del aviso que aparece cuando
// el contenido de la noticia está bloqueado.
const readOnlyBannerTitle = computed(() => {
    if (isReviewingForeignArticle.value) {
        return 'Revisión editorial en curso';
    }

    if (currentStatus.value === 'review') {        
        return 'En revisión editorial';
    }

    if (currentStatus.value === 'scheduled') {
        return 'La noticia ya tiene fecha de publicación';
    }

    return 'Esta noticia no admite edición';
});

// Define la explicación del bloqueo según el estado
// de la noticia y el rol del usuario que la consulta.
const readOnlyBannerMessage = computed(() => {
    if (isReviewingForeignArticle.value) {
        return 'Revisá el contenido y elegí una decisión editorial. Los campos pertenecientes al periodista autor no pueden modificarse desde esta pantalla.';
    }

    if (currentStatus.value === 'review') {
        return 'La noticia está siendo revisada por el equipo editorial.';
    }

    if (currentStatus.value === 'scheduled') {
        if (isEditor.value) {
            return 'Cancelá la programación para volver a editarla.';
        }

        return 'La noticia no admite cambios mientras espera su publicación.';
    }

    if (currentStatus.value === 'published') {
        return 'La noticia está publicada y puede consultarse sin modificar el formulario.';
    }

    return 'Podés consultar su contenido y versiones sin modificar el formulario.';
});

// Indica si la noticia está completamente bloqueada.
//
// Una noticia programada no puede modificarse hasta que el editor
// cancele su programación. El bloqueo se aplica tanto al periodista
// como al editor.
const fieldsLocked = computed(() => {
    if (props.readOnly) {
        return true;
    }

    if (
        ['scheduled', 'published', 'archived']
            .includes(currentStatus.value)
    ) {
        return true;
    }

    // El periodista tampoco puede modificar una noticia
    // que ya fue enviada a revisión.
    return !isEditor.value
        && currentStatus.value === 'review';
});

// Indica si deben bloquearse el contenido y la configuración.
//
// Incluye todos los bloqueos generales y agrega el caso en que un editor
// revisa una noticia escrita por otra persona. En ese caso, los campos quedan
// bloqueados, pero las decisiones editoriales continúan disponibles.
const contentFieldsLocked = computed(() =>
    fieldsLocked.value
    || isReviewingForeignArticle.value
);

// Permite al editor devolver una noticia ajena que está en revisión.
const canRequestChanges = computed(() =>
    !props.readOnly
    && props.permissions.can_review
    && !isOwner.value
    && props.article?.status === 'review'
);

// Permite al periodista autor reenviar su noticia corregida.
const canResubmit = computed(() =>
    !props.readOnly
    && !isEditor.value
    && isOwner.value
    && props.article?.status === 'needs_changes'
);

// Activa guardado automático solo cuando el contenido admite modificaciones.
const canAutosave = computed(() =>
    !contentFieldsLocked.value
    && props.mode === 'edit'
    && ['draft', 'needs_changes'].includes(props.article?.status)
);

// Texto visible del estado actual de autoguardado.
const autosaveLabel = computed(() => ({
    idle: 'Autoguardado disponible',
    pending: 'Cambios pendientes...',
    saving: 'Guardando automáticamente...',
    saved: autosaveMessage.value || 'Cambios autoguardados',
    error: autosaveMessage.value || 'No se pudo autoguardar',
}[autosaveState.value]));

// Permite guardar como borrador cuando la noticia todavía no entró al circuito de revisión.
const canSaveDraftAction = computed(() => {
    return !fieldsLocked.value
        && currentStatus.value === 'draft'
        && (props.mode === 'create' || isOwner.value || isEditor.value);
});

// Permite enviar a revisión una noticia nueva o un borrador escrito por periodista.
const canSendToReviewAction = computed(() => {
    return !fieldsLocked.value
        && !isEditor.value
        && (props.mode === 'create' || currentStatus.value === 'draft');
});

// Permite conservar una noticia devuelta sin reenviarla todavía.
const canSaveCorrectionsAction = computed(() => {
    return !fieldsLocked.value
        && !isEditor.value
        && isOwner.value
        && currentStatus.value === 'needs_changes';
});

// Permite al editor programar una noticia nueva, propia o enviada por un periodista.
const canScheduleAction = computed(() => {
    return !fieldsLocked.value
        && isEditor.value
        && !['published', 'archived'].includes(currentStatus.value);
});

// Permite guardar cambios de contenido en una noticia programada sin alterar su fecha.
const canSaveScheduledChangesAction = computed(() => {
    return !fieldsLocked.value
        && isEditor.value
        && props.mode === 'edit'
        && isScheduledArticle.value;
});

// Permite al editor cancelar la programación aunque el contenido
// de la noticia permanezca bloqueado.
//
// Esta es la única acción que debe seguir disponible mientras
// la noticia se encuentre programada.
const canCancelScheduleAction = computed(() => {
    return !props.readOnly
        && isEditor.value
        && props.mode === 'edit'
        && isScheduledArticle.value;
});

// Permite al editor publicar inmediatamente una noticia nueva, propia o enviada por un periodista.
const canPublishAction = computed(() => {
    return !fieldsLocked.value
        && isEditor.value
        && !['published', 'archived'].includes(currentStatus.value);
});

// Permite archivar una noticia publicada sin habilitar nuevamente la edición del contenido.
const canArchiveAction = computed(() => {
    return isEditor.value
        && props.mode === 'edit'
        && currentStatus.value === 'published';
});

/*
 * Permite al autor eliminar definitivamente una noticia
 * mientras siga siendo un borrador o esté devuelta para corrección.
 *
 * No se muestra al crear una noticia todavía no guardada ni cuando
 * el formulario pertenece a otra persona o está en modo de consulta.
 */
const canDeleteArticleAction = computed(() => {
    return !props.readOnly
        && props.mode === 'edit'
        && isOwner.value
        && ['draft', 'needs_changes'].includes(currentStatus.value);
});

/*
 * Define los textos de la eliminación según el estado actual.
 *
 * Un borrador se elimina y una noticia devuelta se descarta,
 * pero ambas operaciones son definitivas.
 */
const deleteActionCopy = computed(() => {
    if (currentStatus.value === 'needs_changes') {
        return {
            eyebrow: 'Descarte definitivo',
            title: '¿Descartar esta noticia?',
            message:
                'La noticia y las observaciones editoriales se eliminarán de forma permanente. Esta acción no se puede deshacer.',
            confirmLabel: 'Descartar noticia',
            processingLabel: 'Descartando...',
        };
    }

    return {
        eyebrow: 'Eliminación definitiva',
        title: '¿Eliminar este borrador?',
        message:
            'La noticia se eliminará de forma permanente. Esta acción no se puede deshacer.',
        confirmLabel: 'Eliminar borrador',
        processingLabel: 'Eliminando...',
    };
});

// Indica si deben aplicarse las validaciones de noticia completa.
//
// Utiliza la acción temporal que el usuario intentó ejecutar,
// sin cambiar todavía el estado real contenido en form.status.
const requiresCompleteArticle = computed(() => {
    return attemptedCompleteArticleAction.value
        && completeArticleStatuses.includes(
            pendingCompleteArticleStatus.value
        );
});

// Indica si el borrador contiene al menos algún texto.
//
// Para considerar que existe contenido alcanza con escribir
// en el título, bajada, resumen o cuerpo.
const hasDraftContent = computed(() => {
    const hasSimpleText = [
        form.title,
        form.subtitle,
        form.excerpt,
    ].some((value) => String(value || '').trim().length > 0);

    return hasSimpleText || bodyTextLength() > 0;
});

// Controla cuándo debe deshabilitarse el botón principal.
//
// Se deshabilita mientras se guarda, cuando no hubo cambios
// o cuando el borrador todavía no contiene ningún texto.
const draftActionDisabled = computed(() => {
    if (form.processing || fieldsLocked.value) {
        return true;
    }

    // No permite guardar nuevamente si el formulario
    // todavía no fue modificado.
    if (!form.isDirty) {
        return true;
    }

    // Un borrador necesita al menos algún contenido textual.
    if (
        form.status === 'draft'
        && !hasDraftContent.value
    ) {
        return true;
    }

    return false;
});

// Bloquea publicar o programar cuando la portada ya alcanzó el cupo
// y la noticia sigue marcada como destacada.
const hasFeaturedHomepageError = computed(() => {
    return Boolean(form.is_featured && form.errors.is_featured);
});

// Bloquea las acciones formales cuando no hay nada escrito todavía.
//
// Cuando ya existe contenido, el botón queda habilitado aunque falten campos:
// esa primera presión sirve para mostrarle al usuario qué debe completar.
const completeArticleActionDisabled = computed(() => {
    return form.processing
        || fieldsLocked.value
        || !hasDraftContent.value
        || hasFeaturedHomepageError.value;
});

// Bloquea "Guardar cambios" en una programada si no hubo edición del contenido.
const scheduledSaveDisabled = computed(() => {
    return form.processing
        || fieldsLocked.value
        || !form.isDirty;
});

// Programar una noticia nueva exige que haya contenido; reprogramar una existente
// puede abrir el calendario aunque no se hayan editado campos.
const scheduleButtonDisabled = computed(() => {
    return form.processing
        || fieldsLocked.value
        || (!isScheduledArticle.value && !hasDraftContent.value)
        || hasFeaturedHomepageError.value;
});

// Bloquea acciones editoriales que no dependen del contenido principal.
const editorialActionDisabled = computed(() => form.processing || fieldsLocked.value || cancelScheduleProcessing.value);

// Cantidad de caracteres escritos en la observación de devolución.
const feedbackMessageLength = computed(() => String(feedbackForm.message || '').length);

// Texto del contador que aparece debajo del campo Observaciones.
const feedbackCounterText = computed(() => {
    const length = feedbackMessageLength.value;

    if (length < feedbackLimits.min) {
        const remaining = feedbackLimits.min - length;
        const verb = remaining === 1 ? 'falta' : 'faltan';
        const unit = remaining === 1 ? 'carácter' : 'caracteres';

        return `${length}/${feedbackLimits.max} · ${verb} ${remaining} ${unit}`;
    }

    return `${length}/${feedbackLimits.max}`;
});

// Resalta el contador de observaciones si el texto todavía no alcanza el mínimo.
const feedbackCounterState = computed(() => ({
    warning: feedbackMessageLength.value < feedbackLimits.min,
}));

// Evita enviar devoluciones vacías, demasiado cortas o repetidas.
const feedbackSubmitDisabled = computed(() => {
    return feedbackForm.processing
        || feedbackMessageLength.value < feedbackLimits.min
        || feedbackMessageLength.value > feedbackLimits.max;
});

// Indica si existen cambios que podrían perderse
// al abandonar el formulario.
const shouldProtectUnsavedChanges = computed(() => {
    return (
        !props.readOnly
        && !fieldsLocked.value
        && form.isDirty
        && !form.processing
    );
});

// Determina si la noticia contiene suficiente contenido
// como para guardar antes de salir.
//
// Cuando un editor revisa una noticia ajena, las marcas Urgente y Destacada
// son decisiones editoriales que solamente deben aplicarse al programar o
// publicar. Por eso ese flujo no permite guardarlas por separado al salir.
const canSaveBeforeLeave = computed(() => {
    return (
        !isReviewingForeignArticle.value
        && hasDraftContent.value
        && !fieldsLocked.value
        && !form.processing
        && !savingBeforeLeave.value
        && autosaveState.value !== 'saving'
    );
});

// Define el texto normal del botón de guardado según el tipo
// de noticia que se está editando. El estado "Guardando..." se muestra
// por separado para conservar estable el ancho del botón durante el proceso.
const saveBeforeLeaveIdleLabel = computed(() => {
    if (
        props.mode === 'create'
        || props.article?.status === 'draft'
    ) {
        return 'Guardar borrador y salir';
    }

    if (props.article?.status === 'needs_changes') {
        return 'Guardar correcciones y salir';
    }

    return 'Guardar cambios y salir';
});

function addTag(tag) {
    if (contentFieldsLocked.value || tagLimitReached.value) {
        return;
    }

    const id = Number(tag.id);
    if (!(form.tag_ids || []).map(Number).includes(id)) {
        form.tag_ids = [...(form.tag_ids || []), id];
    }

    tagSearch.value = '';
    tagSuggestionsOpen.value = true;
    form.clearErrors('tag_ids');
}

function removeTag(tagId) {
    if (contentFieldsLocked.value) {
        return;
    }

    form.tag_ids = (form.tag_ids || []).filter((id) => Number(id) !== Number(tagId));
    form.clearErrors('tag_ids');
}

// Cierra únicamente el desplegable; nunca elimina las etiquetas ya elegidas.
function closeTagSuggestions() {
    tagSuggestionsOpen.value = false;
}

// Alterna la lista de etiquetas al volver a presionar el buscador.
//
// Si la lista está cerrada la abre; si ya está visible la cierra.
// Este cambio afecta únicamente al desplegable y no modifica las etiquetas
// seleccionadas ni el texto escrito en el buscador.
function toggleTagSuggestions() {
    tagSuggestionsOpen.value = !tagSuggestionsOpen.value;
}

// Cierra el selector cuando el puntero sale del control completo.
function handleTagSelectorPointerDown(event) {
    if (!tagSuggestionsOpen.value || !tagSearchControl.value) {
        return;
    }

    if (!tagSearchControl.value.contains(event.target)) {
        closeTagSuggestions();
    }
}

/*
 * Formatea un timestamp editorial completo enviado por Laravel.
 *
 * Antes esta función reemplazaba el día por referenceDate y conservaba la hora
 * real del registro. Ahora no debe reconstruir nada: TramaClock ya entrega la
 * fecha y la hora correctas como una sola unidad, por ejemplo 19/07/2026 16:35.
 */
function formatEditorialDateTime(createdAt) {
    const date = new Date(createdAt);

    if (!createdAt || Number.isNaN(date.getTime())) {
        return '';
    }

    return new Intl.DateTimeFormat('es-AR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
        timeZone: 'America/Argentina/Buenos_Aires',
    }).format(date);
}

// Las devoluciones editoriales usan exactamente el mismo reloj que revisiones,
// publicaciones y autoguardados. Se reutiliza el mismo formateador para evitar
// volver a mostrar accidentalmente la fecha real del sistema.
function formatFeedbackDateTime(createdAt) {
    return formatEditorialDateTime(createdAt);
}

// Convierte las fechas editoriales de la noticia a un texto legible
// sin alterar el día ni la hora mediante conversiones de zona horaria.
function formatArticleDateTime(value) {
    const match = String(value || '').match(
        /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/
    );

    if (!match) {
        return '';
    }

    const [, year, month, day, hour, minute] = match;

    const monthName = new Intl.DateTimeFormat('es-AR', {
        month: 'long',
    }).format(
        new Date(
            Number(year),
            Number(month) - 1,
            1
        )
    );

    return `${Number(day)} de ${monthName} de ${year}, ${hour}:${minute}`;
}

// Calcula caracteres de un campo de texto del formulario.
function textLength(field) {
    return String(form[field] || '').length;
}

// Construye el texto visible debajo de cada campo.
//
// Campo vacío cuando ya se exige contenido completo:
//      0/120 · faltan 20 caracteres
//
// Campo incompleto:
//      15/120 · faltan 5 caracteres
//
// Campo válido:
//      25/120
function counterText(field) {
    const length = textLength(field);
    const limit = textLimits[field];

    // En borrador solo muestra la cantidad escrita y el máximo permitido.
    // Los mínimos se informan únicamente cuando se envía a revisión.
    if (!requiresCompleteArticle.value) {
        return `${length}/${limit.max}`;
    }

    // Mientras no se alcance el mínimo, incluso desde cero, informa
    // directamente cuántos caracteres faltan.
    if (length < limit.min) {
        const remaining = limit.min - length;

        // Se ajusta singular o plural según corresponda.
        const verb = remaining === 1 ? 'falta' : 'faltan';
        const unit = remaining === 1 ? 'carácter' : 'caracteres';

        return `${length}/${limit.max} · ${verb} ${remaining} ${unit}`;
    }

    // Cuando el campo ya cumple el mínimo, se muestra solo el contador.
    return `${length}/${limit.max}`;
}

// Cuenta únicamente los caracteres visibles del cuerpo.
//
// El cuerpo contiene HTML generado por el RichTextEditor.
// Esta función elimina etiquetas como:
//
//      <p>
//      <strong>
//      <h2>
//
// para que el autoguardado use la misma lógica que Laravel.
function bodyTextLength() {
    const html = String(form.body || '');

    // Respaldo para una posible ejecución sin acceso al DOM.
    if (typeof document === 'undefined') {
        return html
            .replace(/<[^>]*>/g, ' ')
            .replace(/\s+/g, ' ')
            .trim()
            .length;
    }

    // Se coloca el HTML en un elemento temporal para obtener solo su texto.
    const container = document.createElement('div');
    container.innerHTML = html;

    return (container.textContent || '')
        .replace(/\s+/g, ' ')
        .trim()
        .length;
}

// Valida manualmente el cuerpo de la noticia.
//
// El cuerpo puede estar incompleto mientras la noticia sea un borrador.
// Al enviarla a revisión debe cumplir el mínimo y no puede estar vacío.
//
// El máximo se controla siempre, sin importar el estado.
function validateBody() {
    const length = bodyTextLength();

    // Elimina cualquier error anterior antes de volver a validar.
    form.clearErrors('body');
    bodyTooltipVisible.value = false;

    // El máximo se controla tanto en borrador como en revisión.
    if (length > bodyLimits.max) {
        form.setError(
            'body',
            `El cuerpo de la noticia no puede superar los ${bodyLimits.max} caracteres visibles.`
        );

        showBodyValidationTooltip();

        return false;
    }

    // En borrador se permite guardar el cuerpo vacío o incompleto.
    if (!requiresCompleteArticle.value) {
        return true;
    }

    // Desde acá se aplican las reglas obligatorias de revisión.

    // El cuerpo está completamente vacío.
    if (length === 0) {
        form.setError(
            'body',
            'Completa este campo'
        );

        showBodyValidationTooltip();

        return false;
    }

    // Hay contenido, pero todavía no alcanza el mínimo requerido.
    if (length < bodyLimits.min) {
        form.setError(
            'body',
            `El cuerpo de la noticia debe tener al menos ${bodyLimits.min} caracteres visibles.`
        );

        showBodyValidationTooltip();

        return false;
    }

    return true;
}

// Muestra el tooltip del cuerpo y lleva el foco al editor.
//
// El resto de los campos conserva la validación nativa del navegador.
// Esta función existe porque contenteditable no soporta required,
// minlength y maxlength como un input o textarea.
function showBodyValidationTooltip() {
    // Hace visible el tooltip de validación asociado al cuerpo de la noticia.
    bodyTooltipVisible.value = true;
    // Abre la pestaña "Contenido"
    activeTab.value = 'content';

    // Espera a que Vue actualice el DOM y muestre la pestaña de contenido
    // antes de buscar, desplazar y enfocar el editor.
    // nextTick() es necesario porque activeTab.value = 'content' no actualiza el HTML instantáneamente. 
    // Espera a que Vue termine ese cambio antes de buscar .rich-editor-content.
    nextTick(() => {
        // Busca el contenteditable que está dentro de RichTextEditor.
        const editable = bodyEditorField.value
            ?.querySelector('.rich-editor-content');

        // Lleva la pantalla hasta el cuerpo para que el error quede visible.
        bodyEditorField.value?.scrollIntoView({
            behavior: 'smooth',
            block: 'center',
        });

        // Coloca el cursor dentro del editor.
        editable?.focus();
    });
}

// Oculta únicamente el tooltip personalizado del cuerpo.
//
// No limpia el error todavía porque, si el cuerpo sigue siendo inválido,
// validateBody() volverá a comprobarlo cuando se intente guardar nuevamente.
function hideBodyValidationTooltip() {
    bodyTooltipVisible.value = false;
}

// Indica cuándo el contador debe mostrarse como advertencia.
//
// En revisión advierte cuando todavía no se alcanzó el mínimo.
// En cualquier estado también advierte al acercarse al máximo.
function counterState(field) {
    const length = textLength(field);
    const limit = textLimits[field];

    return {
        warning:
            (
                requiresCompleteArticle.value
                && length < limit.min
            )
            || length > limit.max * 0.9,
    };
}

// Limpia saltos y espacios repetidos en campos que deben ser de una sola línea.
function normalizeSingleLineField(field) {
    form[field] = String(form[field] || '').replace(/\s+/g, ' ').trim();
}

// Abre el selector de archivo si la noticia permite edición.
function selectImage() {
    if (contentFieldsLocked.value) {
        return;
    }

    imageInput.value?.click();
}
// Obtiene las dimensiones visibles de la imagen seleccionada.
function getImageDimensions(file) {
    return new Promise((resolve, reject) => {
        // Crea una URL temporal para que el navegador pueda leer la imagen.
        const temporaryUrl = URL.createObjectURL(file);
        const image = new Image();

        image.onload = () => {
            // Obtiene el ancho y el alto reales de la imagen.
            const dimensions = {
                width: image.naturalWidth,
                height: image.naturalHeight,
            };

            // Libera la URL temporal porque ya no se necesita.
            URL.revokeObjectURL(temporaryUrl);

            resolve(dimensions);
        };

        image.onerror = () => {
            // Libera la URL temporal si la imagen no pudo cargarse.
            URL.revokeObjectURL(temporaryUrl);

            reject(new Error('No se pudieron leer las dimensiones de la imagen.'));
        };

        image.src = temporaryUrl;
    });
}

// Guarda el archivo elegido y crea una URL temporal para verlo antes de subirlo.
async function handleImageChange(event) {

    // Impide procesar una portada cuando el contenido está bloqueado.
    if (contentFieldsLocked.value) {
        event.target.value = '';

        return;
    }

    // Obtiene el primer archivo seleccionado en el input.
    const file = event.target.files?.[0];

    // Elimina cualquier error anterior relacionado con la portada.
    form.clearErrors('cover_image_file');

    // Detiene el proceso si no se seleccionó ningún archivo.
    if (!file) {
        return;
    }

    // Rechaza la imagen antes de enviarla si supera los 5 MB.
    if (file.size > MAX_COVER_SIZE) {
        form.cover_image_file = null;
        selectedImageName.value = '';

        // Libera y elimina la vista previa anterior, si existía.
        if (selectedImagePreview.value) {
            URL.revokeObjectURL(selectedImagePreview.value);
            selectedImagePreview.value = '';
        }

        // Limpia el input para permitir seleccionar nuevamente el mismo archivo.
        event.target.value = '';

        // Muestra el error debajo del campo de portada.
        form.setError(
            'cover_image_file',
            'La imagen no puede superar los 5 MB.'
        );

        return;
    }

    try {
        // Lee las dimensiones de la imagen antes de guardarla en el formulario.
        const dimensions = await getImageDimensions(file);

        // Rechaza la imagen si su ancho o alto no alcanzan el mínimo requerido.
        if (
            dimensions.width < MIN_COVER_WIDTH
            || dimensions.height < MIN_COVER_HEIGHT
        ) {
            form.cover_image_file = null;
            selectedImageName.value = '';

            // Libera y elimina la vista previa anterior, si existía.
            if (selectedImagePreview.value) {
                URL.revokeObjectURL(selectedImagePreview.value);
                selectedImagePreview.value = '';
            }

            // Limpia el input para permitir seleccionar nuevamente el mismo archivo.
            event.target.value = '';

            // Muestra el error debajo del campo de portada.
            form.setError(
                'cover_image_file',
                `La imagen debe medir al menos ${MIN_COVER_WIDTH}\u00A0×\u00A0${MIN_COVER_HEIGHT} píxeles. La imagen seleccionada mide ${dimensions.width}\u00A0×\u00A0${dimensions.height} píxeles.`
            );

            return;
        }
    } catch {
        // Limpia el archivo cuando el navegador no puede leer la imagen.
        form.cover_image_file = null;
        selectedImageName.value = '';
        event.target.value = '';

        form.setError(
            'cover_image_file',
            'No se pudieron leer las dimensiones de la imagen.'
        );

        return;
    }

    // Libera la URL temporal de la vista previa anterior.
    if (selectedImagePreview.value) {
        URL.revokeObjectURL(selectedImagePreview.value);
    }

    // Guarda el archivo válido dentro del formulario.
    form.cover_image_file = file;

    // Indica que la portada no debe eliminarse al guardar.
    form.remove_cover_image = false;

    // Guarda el nombre visible del archivo seleccionado.
    selectedImageName.value = file.name;

    // Crea una URL temporal para mostrar la vista previa.
    selectedImagePreview.value = URL.createObjectURL(file);
}

// Quita la portada visible del formulario.
//
// Si la noticia ya tenía una portada guardada,
// se marca para eliminarla cuando se guarde.
// Si era una imagen nueva, simplemente se descarta.
function removeImage() {
    // No permite modificar la portada cuando la noticia está bloqueada.
    if (contentFieldsLocked.value) {
        return;
    }

    // Libera la URL temporal utilizada para la vista previa.
    if (selectedImagePreview.value) {
        URL.revokeObjectURL(selectedImagePreview.value);
    }

    // Descarta cualquier archivo nuevo que todavía
    // no haya sido enviado a Laravel.
    form.cover_image_file = null;

    // Informa al backend que debe quitar la portada existente
    // cuando se guarde la noticia.
    form.remove_cover_image = true;

    // Limpia los datos utilizados para mostrar
    // el nombre y la vista previa de la imagen.
    selectedImageName.value = '';
    selectedImagePreview.value = '';

    // Limpia también el input real de archivo.
    //
    // Esto permite volver a seleccionar exactamente
    // el mismo archivo después de haberlo quitado.
    if (imageInput.value) {
        imageInput.value.value = '';
    }
}

// Valida que exista una imagen de portada antes de ejecutar
// una acción formal sobre la noticia.
//
// Guardar como borrador permite continuar sin portada.
// Enviar a revisión, programar y publicar exigen una imagen.
function validateCoverImage() {
    // Elimina cualquier error anterior relacionado con la portada.
    form.clearErrors('cover_image_file');

    // Mientras se guarda como borrador, la portada es opcional.
    if (!requiresCompleteArticle.value) {
        return true;
    }

    // La portada puede ser una imagen nueva seleccionada
    // o una imagen que ya estuviera guardada en la noticia.
    const hasCoverImage = Boolean(
        form.cover_image_file || hasCurrentImage.value
    );

    // La noticia ya tiene una portada válida.
    if (hasCoverImage) {
        return true;
    }

    // Si existía una ruta pero el archivo físico desapareció, se muestra el
    // problema real. Una imagen nueva permite reparar la noticia inmediatamente.
    if (currentImageMissing.value && !form.remove_cover_image) {
        form.setError(
            'cover_image_file',
            'El archivo actual de la portada no existe. Subí nuevamente la imagen para reparar la noticia.',
        );
    } else {
        // Define el mensaje según la acción que el usuario está intentando realizar.
        const coverImageErrorMessages = {
            review:
                'Seleccioná una imagen de portada antes de enviar la noticia a revisión.',

            scheduled:
                'Seleccioná una imagen de portada antes de programar la noticia.',

            published:
                'Seleccioná una imagen de portada antes de publicar la noticia.',
        };

        form.setError(
            'cover_image_file',
            coverImageErrorMessages[pendingCompleteArticleStatus.value]
        );
    }

    // Abre Configuración, donde se encuentra el campo de portada.
    activeTab.value = 'settings';

    // Espera a que Vue muestre la pestaña antes de desplazar
    // la pantalla y enfocar el selector de archivos.
    nextTick(() => {
        coverUploadField.value?.scrollIntoView({
            behavior: 'smooth',
            block: 'center',
        });

        imageInput.value?.focus();
    });

    return false;
}

// Elimina el mensaje de validación personalizado
// cuando el usuario vuelve a modificar el campo.
function clearTextValidation(event) {
    event.currentTarget.setCustomValidity('');
}

// Valida manualmente las longitudes mínimas de los campos de contenido.
//
// Se utiliza setCustomValidity() para que Chrome muestre
// su tooltip nativo aunque el formulario tenga novalidate.
function validateTextMinimums(formElement) {
    const fields = [
        {
            name: 'title',
            label: 'El título',
            min: textLimits.title.min,
        },
        {
            name: 'subtitle',
            label: 'La bajada',
            min: textLimits.subtitle.min,
        },
        {
            name: 'excerpt',
            label: 'El resumen',
            min: textLimits.excerpt.min,
        },
    ];

    // Elimina mensajes personalizados anteriores,
    // incluso si se volvió al estado Borrador.
    for (const field of fields) {
        const element = formElement.elements.namedItem(field.name);
        element?.setCustomValidity('');
    }

    // En Borrador no se exigen longitudes mínimas.
    if (!requiresCompleteArticle.value) {
        return true;
    }

    for (const field of fields) {
        const element = formElement.elements.namedItem(field.name);

        if (!element) {
            // Omite este campo si no fue encontrado en el formulario
            // y continúa con el siguiente elemento de la lista.
            continue;
        }

        // Convierte el valor del campo en texto, elimina los espacios
        // al inicio y al final, y obtiene la cantidad de caracteres.
        const length = String(element.value || '').trim().length;

        // Los campos vacíos quedan a cargo de required,
        // para que Chrome muestre “Completa este campo”.
        if (length === 0) {
            // Deja los campos vacíos a cargo de la validación required
            // y continúa comprobando el siguiente campo.
            continue;
        }

        // Comprueba si el contenido no alcanza la longitud mínima requerida.
        if (length < field.min) {

            
            // Limpia cualquier mensaje personalizado anterior para que
            // el navegador calcule su mensaje nativo de minlength.
            element.setCustomValidity('');

            // Obtiene el mensaje de validación generado por Chrome.
            const nativeValidationMessage = element.validationMessage;

            // Mantiene el campo inválido utilizando el mismo texto
            // que mostraría normalmente el navegador.
            element.setCustomValidity(
                nativeValidationMessage
                || `${field.label} debe tener al menos ${field.min} caracteres.`
            );

            // Abre la pestaña donde se encuentra el campo inválido.
            activeTab.value = 'content';

            // Espera a que Vue muestre la pestaña antes de enfocar
            // el campo y presentar el tooltip nativo del navegador.
            nextTick(() => {
                element.focus();
                element.reportValidity();
            });

            // Detiene la validación porque se encontró un campo inválido.
            return false;
        }
    }

    return true;
}

// Valida los campos HTML de una pestaña, por ejemplo:
// título, resumen, categoría o fecha de publicación.
//
// Recibe:
// - formElement: el formulario completo.
// - selector: los campos inválidos que debe buscar.
// - tab: la pestaña que contiene esos campos.
function validateNativeFields(formElement, selector, tab) {
    // Busca el primer campo inválido dentro de la pestaña indicada.
    const invalidField = formElement.querySelector(selector);

    // Si no encontró ninguno, todos los campos de esa pestaña son válidos.
    if (!invalidField) {
        return true;
    }

    // Abre la pestaña donde está el campo inválido.
    activeTab.value = tab;

    // Espera a que Vue muestre la pestaña antes de enfocar el campo
    // y abrir el mensaje de validación del navegador.
    nextTick(() => {
        invalidField.focus();
        invalidField.reportValidity();
    });

    // Informa que hay un campo inválido y detiene el guardado.
    return false;
}

// Evita guardar un borrador completamente vacío.
//
// Para guardar alcanza con que el periodista haya escrito algo
// en el título, bajada, el resumen o el cuerpo de la noticia.
function validateDraftHasContent(formElement) {
    // Elimina el error anterior antes de volver a comprobar.
    form.clearErrors('draft_content');

    // Esta regla se aplica únicamente cuando se guarda como borrador.
    if (form.status !== 'draft') {
        return true;
    }

    // Comprueba los campos de texto simple.
    const hasSimpleText = [
        form.title,
        form.subtitle,
        form.excerpt,
    ].some((value) => String(value || '').trim().length > 0);

    // Comprueba el texto visible del editor enriquecido.
    const hasBodyText = bodyTextLength() > 0;

    // Hay suficiente contenido para crear el borrador.
    if (hasSimpleText || hasBodyText) {
        return true;
    }

    // Muestra un solo error general, en lugar de marcar
    // individualmente todos los campos como obligatorios.
    form.setError(
        'draft_content',
        'Escribí al menos algún contenido antes de guardar el borrador.'
    );

    // Abre la pestaña "Contenido".
    activeTab.value = 'content';

    // Espera a que Vue muestre la pestaña y coloca
    // el cursor en el primer campo.
    nextTick(() => {
        formElement
            .querySelector('.editor-content-panel input')
            ?.focus();
    });

    return false;
}

/* ============================================================================
 * PROTECCIÓN DE CAMBIOS SIN GUARDAR
 * ============================================================================ */

/**
 * Intercepta las navegaciones de Inertia.
 *
 * Esto incluye:
 *
 * - opciones del menú lateral;
 * - botón Volver;
 * - acceso al portal;
 * - cierre de sesión.
 */
function handleBeforeInertiaVisit(event) {
    // Permite una navegación iniciada intencionalmente
    // después de guardar o descartar los cambios.
    if (allowNextInertiaVisit) {
        allowNextInertiaVisit = false;
        return;
    }

    const visit = event.detail.visit;

    // Las precargas de enlaces no abandonan la pantalla
    // y por lo tanto no deben abrir el modal.
    if (visit.prefetch) {
        return;
    }

    // Sin modificaciones pendientes, la navegación continúa normalmente.
    if (!shouldProtectUnsavedChanges.value) {
        return;
    }

    // Guarda la navegación para poder retomarla después.
    pendingVisit = visit;

    // Abre la advertencia y cancela la navegación actual.
    unsavedChangesOpen.value = true;
    event.preventDefault();
}

/**
 * Protege los cambios cuando el usuario intenta
 * actualizar o cerrar la pestaña del navegador.
 *
 * Los navegadores muestran su propio mensaje de seguridad.
 * No permiten utilizar una modal personalizada en este caso.
 */
function handleBeforeUnload(event) {
    if (!shouldProtectUnsavedChanges.value) {
        return;
    }

    event.preventDefault();
    event.returnValue = '';
}

/**
 * Retoma la navegación que había sido cancelada.
 *
 * Conserva el método HTTP original. Esto es importante
 * porque cerrar sesión utiliza una petición POST.
 */
function continuePendingVisit() {
    const visit = pendingVisit;

    pendingVisit = null;
    unsavedChangesOpen.value = false;

    if (!visit) {
        return;
    }

    // Copia las opciones originales de la navegación.
    const visitOptions = { ...visit };

    // Estas propiedades describen el estado interno de la visita
    // y no deben reenviarse como opciones.
    delete visitOptions.url;
    delete visitOptions.completed;
    delete visitOptions.cancelled;
    delete visitOptions.interrupted;

    // Evita que el listener intercepte nuevamente
    // esta navegación ya confirmada.
    allowNextInertiaVisit = true;

    router.visit(visit.url, visitOptions);
}

/**
 * Cancela la salida y mantiene al usuario en el formulario.
 */
function keepEditing() {
    if (savingBeforeLeave.value) {
        return;
    }

    pendingVisit = null;
    unsavedChangesOpen.value = false;
}

/**
 * Descarta las modificaciones y continúa
 * hacia el destino seleccionado.
 */
function leaveWithoutSaving() {
    if (savingBeforeLeave.value) {
        return;
    }

    // Evita que se ejecute un autoguardado pendiente
    // después de que el usuario decidió descartar.
    clearTimeout(autosaveTimer);

    continuePendingVisit();
}

/**
 * Guarda el trabajo antes de abandonar la pantalla.
 *
 * No ejecuta la acción formal que hubiera fallado anteriormente.
 *
 * Ejemplo:
 * - el editor intenta publicar una noticia;
 * - la validación falla porque no tiene portada;
 * - después elige "Guardar borrador y salir";
 * - se descarta el intento de publicación;
 * - la noticia se guarda como borrador y continúa la navegación.
 */
async function saveBeforeLeaving() {
    if (!canSaveBeforeLeave.value) {
        return;
    }

    /*
     * Descarta cualquier intento anterior de enviar a revisión,
     * programar o publicar.
     *
     * Esto desactiva las validaciones de noticia completa para que
     * "Guardar borrador y salir" no vuelva a exigir una portada.
     */
    pendingCompleteArticleStatus.value = '';
    attemptedCompleteArticleAction.value = false;

    /*
     * Una noticia nueva se guarda como borrador.
     *
     * Al editar una noticia existente, conserva el estado real
     * que tenía antes del intento fallido.
     */
    form.status = props.mode === 'create'
        ? 'draft'
        : currentStatus.value;

    // Elimina el error dejado por el intento fallido de publicación.
    form.clearErrors('cover_image_file');

    // Oculta posibles mensajes de validación del cuerpo.
    hideBodyValidationTooltip();

    /*
     * Espera a que Vue quite required, minlength y las demás
     * validaciones correspondientes a una noticia completa.
     */
    await nextTick();

    savingBeforeLeave.value = true;

    const requestStarted = submit(null, {
        /*
         * Solamente continúa hacia Noticias o hacia el menú elegido
         * después de que Laravel haya guardado correctamente.
         */
        onSuccess: () => {
            continuePendingVisit();
        },

        /*
         * Si Laravel rechaza el guardado, abandona la navegación
         * pendiente y mantiene al usuario en el formulario.
         */
        onError: () => {
            pendingVisit = null;
            unsavedChangesOpen.value = false;
        },

        onFinish: () => {
            savingBeforeLeave.value = false;
        },
    });

    /*
     * submit() puede devolver false si todavía existe otro error local,
     * por ejemplo si el cuerpo supera el máximo permitido.
     *
     * En ese caso no hubo guardado y tampoco debe continuar la salida.
     */
    if (!requestStarted) {
        savingBeforeLeave.value = false;
        pendingVisit = null;
        unsavedChangesOpen.value = false;
    }
}

/**
 * Vuelve al listado de noticias.
 *
 * Mientras el formulario está enviando una petición,
 * impide abandonar la pantalla para evitar interrumpir
 * el guardado o generar una navegación simultánea.
 */
function goBack() {
    // No permite salir mientras la noticia se está guardando.
    if (form.processing) {
        return;
    }

    // Navega al listado de noticias mediante Inertia.
    router.visit(route('admin.articles.index'));
}

/* ============================================================================ */

// Obtiene el formulario HTML que contiene todos los campos de la noticia.
//
// Las acciones que no nacen del submit nativo, como Programar o Publicar,
// también necesitan validar ese mismo formulario antes de abrir su modal.
function getArticleFormElement() {
    return articleForm.value;
}

// Ejecuta todas las validaciones locales del formulario sin enviar datos.
//
// Se usa en dos momentos:
// - antes de abrir los modales de Programar o Publicar;
// - justo antes de enviar la petición a Laravel.
//
// Si encuentra un error, abre la pestaña correcta, lleva al primer campo inválido
// y devuelve false para cortar la acción.
function validateArticleForm(formElement) {
    if (!formElement) {
        return false;
    }

    // Antes de cualquier otra validación, impide crear
    // un borrador completamente vacío.
    if (!validateDraftHasContent(formElement)) {
        return false;
    }

    // Primero comprueba campos vacíos mediante required.
    // Primero valida título, bajada y resumen.    
    if (
        !validateNativeFields(
            formElement,
            `
                .editor-content-panel input:invalid,
                .editor-content-panel textarea:invalid,
                .editor-content-panel select:invalid
            `,
            'content'
        )
    ) {
        return false;
    }

    // Después comprueba campos que tienen contenido,
    // pero no alcanzan la longitud mínima.    
    if (!validateTextMinimums(formElement)) {
        return false;
    }

    // Después valida el cuerpo de la noticia.
    if (!validateBody()) {
        return false;
    }

    // Luego valida categoría y demás campos de Configuración.
    if (
        !validateNativeFields(
            formElement,
            `
                .editor-settings-grid input:invalid,
                .editor-settings-grid textarea:invalid,
                .editor-settings-grid select:invalid
            `,
            'settings'
        )
    ) {
        return false;
    }

    // Finalmente comprueba la imagen de portada.
    if (!validateCoverImage()) {
        return false;
    }

    return true;
}

// Valida una acción formal antes de abrir su modal.
//
// La acción se guarda temporalmente y no modifica form.status.
// De esta manera, un error de validación no marca el formulario
// como si el usuario hubiera realizado cambios.
async function validateBeforeOpeningActionModal(status) {
    if (completeArticleActionDisabled.value) {
        return false;
    }

    pendingCompleteArticleStatus.value = status;
    attemptedCompleteArticleAction.value = true;

    await nextTick();

    return validateArticleForm(getArticleFormElement());
}

// Limpia la acción temporal cuando el usuario cierra
// el modal sin confirmar la operación.
//
// Ejemplo:
// la noticia está en borrador y el editor pulsa "Publicar noticia".
// La validación se supera y se abre el modal de confirmación.
// Si después pulsa "Cancelar", se elimina la acción temporal
// "published" y la noticia continúa en estado "draft".
function resetCancelledCompleteAction() {
    pendingCompleteArticleStatus.value = '';
    attemptedCompleteArticleAction.value = false;
}

// Valida la noticia y solamente modifica su estado
// cuando todas las validaciones locales fueron superadas.
async function saveWithStatus(status, callbacks = {}) {
    if (form.processing || fieldsLocked.value) {
        return false;
    }

    const requiresCompleteContent =
        completeArticleStatuses.includes(status);

    // Activa las validaciones correspondientes sin cambiar
    // todavía el estado que forma parte de los datos del formulario.
    pendingCompleteArticleStatus.value =
        requiresCompleteContent ? status : '';

    attemptedCompleteArticleAction.value =
        requiresCompleteContent;

    await nextTick();

    // Un error local detiene la operación sin alterar form.status.
    if (!validateArticleForm(getArticleFormElement())) {
        return false;
    }

    // El estado cambia recién cuando la noticia ya pasó
    // correctamente todas las validaciones locales.
    form.status = status;

    await nextTick();

    return submit(null, callbacks);
}

// Valida la noticia completa y recién después abre el modal de programación.
async function openScheduleModal() {
    const isReadyToSchedule = await validateBeforeOpeningActionModal('scheduled');

    if (!isReadyToSchedule) {
        return;
    }

    if (!scheduleDate.value) {
        // Si Laravel envió una fecha mínima, se usa como valor inicial del calendario.
        // Así el modal abre parado en el día actual de TRAMA.
        // No se usa la fecha real del navegador porque rompería la fecha editorial
        // configurada para el proyecto en TRAMA_REFERENCE_DATE.
        scheduleDate.value = scheduleMin.value;
    }

    scheduleOpen.value = true;
}

// Cierra el modal de programación si no hay guardado en curso.
function closeScheduleModal() {
    if (form.processing) {
        return;
    }

    resetCancelledCompleteAction();
    scheduleOpen.value = false;
}

// Programa la noticia usando la fecha elegida en el modal.
async function confirmSchedule() {
    if (form.processing || !scheduleDate.value) {
        return;
    }

    form.scheduled_at = scheduleDate.value;
    scheduleOpen.value = false;

    await saveWithStatus('scheduled');
}

/*
 * Abre la confirmación sin cancelar todavía la programación.
 */
function openCancelScheduleConfirmation() {
    // Evita abrir la modal si la acción no está permitida o ya se está procesando.
    if (!canCancelScheduleAction.value || cancelScheduleProcessing.value) {
        return;
    }

    // Limpia un posible error anterior antes de volver a mostrar la confirmación.
    cancelScheduleError.value = '';

    // Muestra la modal de confirmación.
    cancelScheduleOpen.value = true;
}

/*
 * Cierra la confirmación mientras no exista una petición en curso.
 */
function closeCancelScheduleConfirmation() {
    // Impide cerrar la modal mientras Laravel está procesando la cancelación.
    if (cancelScheduleProcessing.value) {
        return;
    }

    // Limpia cualquier error mostrado dentro de la confirmación.
    cancelScheduleError.value = '';

    // Oculta la modal sin modificar la programación.
    cancelScheduleOpen.value = false;
}

/*
 * Cancela la programación solamente después de la confirmación.
 */
function confirmCancelSchedule() {
    // Evita ejecutar la acción si perdió permisos o ya existe una petición activa.
    if (!canCancelScheduleAction.value || cancelScheduleProcessing.value) {
        return;
    }

    // Limpia errores anteriores antes de iniciar una nueva petición.
    cancelScheduleError.value = '';

    // Bloquea nuevas confirmaciones mientras Laravel procesa la operación.
    cancelScheduleProcessing.value = true;

    // Permite la visita de Inertia producida por esta acción intencional.
    allowNextInertiaVisit = true;

    // Solicita al backend cancelar la programación de la noticia actual.
    router.patch(
        route('admin.articles.cancel-schedule', props.article.id),
        {},
        {
            // Mantiene la posición actual mientras se procesa la respuesta.
            preserveScroll: true,

            onSuccess: () => {
                // Limpia el estado de error después de una cancelación correcta.
                cancelScheduleError.value = '';

                // Cierra la confirmación cuando Laravel completó la operación.
                cancelScheduleOpen.value = false;
            },

            onError: (errors) => {
                // Mantiene la modal abierta y muestra el error dentro de su contexto.
                cancelScheduleError.value =
                    errors?.operation
                    || errors?.scheduled_at
                    || 'No pudimos cancelar la programación. Intentá nuevamente.';

                // Evita repetir arriba del formulario un error ya mostrado en la modal.
                handledConfirmationOperationError.value =
                    errors?.operation || '';
            },

            onFinish: () => {
                // Libera la acción tanto si la petición terminó bien como si falló.
                cancelScheduleProcessing.value = false;
            },
        }
    );
}

// Valida la noticia completa y recien despues abre el modal de envio a revision.
async function openSendToReviewConfirmation() {
    const isReadyToSend = await validateBeforeOpeningActionModal('review');

    if (!isReadyToSend) {
        return;
    }

    sendToReviewError.value = '';
    sendToReviewOpen.value = true;
}

// Cierra el modal de envio a revision si no hay guardado en curso.
function closeSendToReviewConfirmation() {
    if (form.processing) {
        return;
    }

    resetCancelledCompleteAction();
    sendToReviewError.value = '';
    sendToReviewOpen.value = false;
}

// Envia la noticia a revision y conserva la modal abierta si falla la operacion.
async function confirmSendToReview() {
    if (form.processing) {
        return;
    }

    sendToReviewError.value = '';

    const requestStarted = await saveWithStatus('review', {
        onSuccess: () => {
            sendToReviewError.value = '';
            sendToReviewOpen.value = false;
        },
        onOperationError: (message) => {
            form.status = currentStatus.value;
            sendToReviewError.value = message;
        },
        onError: (errors) => {
            if (!errors?.operation) {
                form.status = currentStatus.value;
                sendToReviewOpen.value = false;
            }
        },
    });

    if (!requestStarted) {
        sendToReviewOpen.value = false;
    }
}

// Valida la noticia completa y recien despues abre el modal de publicacion.
async function openPublishConfirmation() {
    const isReadyToPublish = await validateBeforeOpeningActionModal('published');

    if (!isReadyToPublish) {
        return;
    }

    publishError.value = '';
    publishOpen.value = true;
}

// Cierra el modal de publicación si no hay guardado en curso.
function closePublishConfirmation() {
    if (form.processing) {
        return;
    }

    resetCancelledCompleteAction();
    publishError.value = '';
    publishOpen.value = false;
}

// Publica la noticia y conserva la confirmación abierta si ocurre un fallo inesperado.
async function confirmPublish() {
    if (form.processing) {
        return;
    }

    publishError.value = '';

    const requestStarted = await saveWithStatus('published', {
        onSuccess: () => {
            publishError.value = '';
            publishOpen.value = false;
        },
        onOperationError: (message) => {
            // Laravel rechazó la publicación: el formulario vuelve al estado real,
            // pero la confirmación permanece abierta para mostrar el mensaje.
            form.status = currentStatus.value;
            publishError.value = message;
        },
        onError: (errors) => {
            // Los errores de campos requieren volver al formulario para corregirlos.
            if (!errors?.operation) {
                form.status = currentStatus.value;
                publishOpen.value = false;
            }
        },
    });

    if (!requestStarted) {
        publishOpen.value = false;
    }
}

/*
 * Abre el modal sin archivar todavía la noticia.
 */
function openArchiveConfirmation() {
    if (! canArchiveAction.value || archiveProcessing.value) {
        return;
    }

    archiveError.value = '';
    archiveOpen.value = true;
}

/*
 * Cierra el modal mientras no exista una petición en curso.
 */
function closeArchiveConfirmation() {
    if (archiveProcessing.value) {
        return;
    }

    archiveError.value = '';
    archiveOpen.value = false;
}

/*
 * Archiva la noticia solamente después de la confirmación.
 */
function archiveArticle() {
    if (! canArchiveAction.value || archiveProcessing.value) {
        return;
    }

    archiveError.value = '';
    archiveProcessing.value = true;
    allowNextInertiaVisit = true;

    router.delete(route('admin.articles.destroy', props.article.id), {
        preserveScroll: true,

        onSuccess: () => {
            archiveError.value = '';
            archiveOpen.value = false;
        },
        onError: (errors) => {
            archiveError.value = errors?.operation
                || 'No pudimos archivar la noticia. Intentá nuevamente.';
            handledConfirmationOperationError.value = errors?.operation || '';
        },
        onFinish: () => {
            archiveProcessing.value = false;
        },
    });
}

/*
 * Abre la confirmación solamente cuando el usuario
 * puede eliminar la noticia y no hay otra petición en curso.
 */
function openDeleteConfirmation() {
    if (
        !canDeleteArticleAction.value
        || deleteProcessing.value
        || form.processing
        || autosaveState.value === 'saving'
    ) {
        return;
    }

    deleteError.value = '';
    deleteOpen.value = true;
}

/*
 * Cierra la confirmación mientras no se esté ejecutando el borrado.
 */
function closeDeleteConfirmation() {
    if (deleteProcessing.value) {
        return;
    }

    deleteError.value = '';
    deleteOpen.value = false;
}

/*
 * Elimina definitivamente la noticia después de la confirmación.
 */
function deleteArticlePermanently() {
    if (
        !canDeleteArticleAction.value
        || deleteProcessing.value
        || form.processing
        || autosaveState.value === 'saving'
    ) {
        return;
    }

    /*
     * Evita que un autoguardado pendiente intente actualizar
     * la noticia después de haber confirmado su eliminación.
     */
    clearTimeout(autosaveTimer);

    deleteProcessing.value = true;
    allowNextInertiaVisit = true;

    router.delete(
        route(
            'admin.articles.delete-permanently',
            props.article.id
        ),
        {
            onSuccess: () => {
                deleteError.value = '';
                deleteOpen.value = false;
            },
            onError: (errors) => {
                // Mantiene la confirmación abierta y muestra el fallo en su contexto.
                deleteError.value = errors?.operation
                    || 'No pudimos eliminar la noticia. Intentá nuevamente.';
                handledConfirmationOperationError.value = errors?.operation || '';
            },

            onFinish: () => {
                deleteProcessing.value = false;
            },
        }
    );
}

// Guarda la noticia: crea una nueva o actualiza la existente.
//
// callbacks permite reutilizar el mismo guardado
// desde el modal de cambios sin guardar.
function submit(event, callbacks = {}) {
    if (fieldsLocked.value) {
        return false;
    }

    // Cuando se llama desde el submit utiliza event.currentTarget.
    // Cuando se llama desde un botón externo utiliza articleForm.
    const formElement = event?.currentTarget || getArticleFormElement();

    if (!validateArticleForm(formElement)) {
        return false;
    }

    clearTimeout(autosaveTimer);

    // Elimina el error general de una operación anterior antes
    // de realizar un nuevo intento de guardado.
    form.clearErrors('operation');

    const options = {
        forceFormData: true,
        preserveScroll: true,

        onSuccess: () => {
            /*
            * El archivo ya fue recibido y guardado por Laravel.
            *
            * Debe eliminarse del estado del formulario para impedir
            * que vuelva a enviarse en el próximo guardado.
            */
            form.cover_image_file = null;
            form.remove_cover_image = false;

            /*
            * Libera la URL temporal creada para previsualizar
            * la imagen antes de subirla.
            */
            if (selectedImagePreview.value) {
                URL.revokeObjectURL(selectedImagePreview.value);
            }

            /*
            * Limpia los datos temporales de la selección.
            *
            * Después de la respuesta de Inertia, la portada visible
            * se obtiene nuevamente desde props.article.cover_image.
            */
            selectedImagePreview.value = '';
            selectedImageName.value = '';

            /*
            * Limpia también el input nativo.
            *
            * Esto permite elegir posteriormente el mismo archivo
            * y evita que el navegador lo conserve como seleccionado.
            */
            if (imageInput.value) {
                imageInput.value.value = '';
            }

            /*
            * Establece los valores actuales como nueva referencia del formulario.
            *
            * De esta manera, Inertia considera que los cambios ya fueron guardados
            * y restablece form.isDirty a false.
            */
            form.defaults();

            // Vuelve a mostrar el mensaje correspondiente
            // al guardado que acaba de completarse.
            showEditorialFlash();

            // Espera a que Vue muestre el mensaje y luego
            // desplaza la pantalla hasta la confirmación.
            nextTick(() => {
                // Desplaza la página hasta el inicio después de guardar.
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth',
                });
            });

            autosaveState.value = 'saved';
            autosaveMessage.value = 'Guardado manual completado';

            // Ejecuta acciones adicionales del flujo que inició el guardado.
            // En el modal de cambios pendientes, retoma la navegación cancelada.
            callbacks.onSuccess?.();
        },

        // Si Laravel devuelve errores de validación, abre la pestaña
        // donde se encuentra el campo que debe corregirse.
        //
        // Los errores inesperados de la operación no cambian de pestaña:
        // se muestran como aviso general en la parte superior de la pantalla.
        onError: (errors) => {
            /*
            * Un error inesperado pertenece a la operación completa.
            *
            * Como el aviso se muestra en la parte superior de la pantalla,
            * se desplaza la página hasta el inicio para que el usuario
            * pueda verlo inmediatamente.
            */
            if (errors.operation) {
                if (typeof callbacks.onOperationError === 'function') {
                    // La acción que abrió una confirmación puede conservar el error
                    // dentro de esa modal y evitar un aviso duplicado en la página.
                    callbacks.onOperationError(errors.operation, errors);
                    form.clearErrors('operation');
                } else {
                    nextTick(() => {
                        window.scrollTo({
                            top: 0,
                            behavior: 'smooth',
                        });
                    });
                }

                callbacks.onError?.(errors);

                return;
            }

            /*
            * Los errores normales de validación abren la pestaña
            * que contiene el campo que debe corregirse.
            */
            const hasContentErrors =
                errors.draft_content
                || errors.title
                || errors.subtitle
                || errors.excerpt
                || errors.body;

            activeTab.value = hasContentErrors
                ? 'content'
                : 'settings';

            callbacks.onError?.(errors);
        },

        onFinish: () => {
            callbacks.onFinish?.();
        },
    };

    // El guardado también produce una visita de Inertia.
    // Se autoriza para que el modal no lo intercepte.
    allowNextInertiaVisit = true;

    if (props.mode === 'create') {
        form.post(
            route('admin.articles.store'),
            options
        );

        return true;
    }

    form.transform((data) => ({
        ...data,
        _method: 'put',
    }))
        .post(
            route('admin.articles.update', props.article.id),
            options
        );

    return true;
}

// Abre el modal que muestra las observaciones editoriales pendientes.
function openObservationsModal() {
    observationsOpen.value = true;
}

// Cierra el modal de observaciones editoriales.
function closeObservationsModal() {
    observationsOpen.value = false;
}

// Abre el modal de devolución y coloca el cursor directamente en Observaciones.
//
// nextTick espera a que Vue haya incorporado el textarea al DOM antes de
// intentar enfocarlo.
async function openFeedbackModal() {
    // Si el formulario de observaciones o el formulario principal ya están
    // procesando una petición, no se abre otro modal ni se altera su estado.
    if (feedbackForm.processing || form.processing) {
        return;
    }

    // Abre el modal de devolución.
    //
    // Al cambiar este valor Vue tiene que renderizar primero el contenido que está
    // condicionado por v-if="feedbackOpen", incluido el textarea de Observaciones.
    feedbackOpen.value = true;

    // Espera al siguiente ciclo de actualización de Vue.
    //
    // Sin nextTick(), el código podría intentar enfocar feedbackTextarea
    // inmediatamente después de abrir el modal, cuando el textarea todavía no fue
    // agregado al DOM porque Vue aún no terminó de renderizarlo.
    await nextTick();

    // Una vez renderizado el modal, se coloca el cursor directamente en Observaciones
    // para que el editor pueda empezar a escribir sin hacer un segundo click.
    feedbackTextarea.value?.focus();
}

// Cierra el modal de devolución solamente cuando no hay una petición en curso.
function closeFeedbackModal() {
    if (feedbackForm.processing) {
        return;
    }

    feedbackOpen.value = false;
}

// Envía la observación de corrección y cambia la noticia a devuelta.
function requestChanges() {
    if (feedbackSubmitDisabled.value) {
        return;
    }

    // Elimina el error general de un intento anterior antes
    // de volver a enviar la devolución para corrección.
    feedbackForm.clearErrors('operation');

    // La devolución es una acción editorial intencional.
    // Por eso se permite esta visita de Inertia sin abrir el modal
    // de cambios sin guardar que protege el formulario principal.
    allowNextInertiaVisit = true;

    // Envía una petición PATCH porque la acción modifica parcialmente la noticia:    
    // PATCH es un método HTTP usado para modificar solo una parte de un recurso existente.
    // En este caso no se reemplaza toda la noticia: únicamente se guarda la observación
    // editorial y se cambia su estado a devuelta para corrección.
    feedbackForm.patch(route('admin.articles.request-changes', props.article.id), {
        // Vuelve al inicio del listado para que el editor vea
        // el mensaje de confirmación después de enviar la noticia a corrección.
        preserveScroll: false,
        onSuccess: () => {
            /*
            * Laravel redirige al listado de noticias porque la noticia devuelta
            * deja de estar disponible para el editor.
            */
            feedbackOpen.value = false;
            feedbackForm.reset();
        },
        onError: (errors) => {
            allowNextInertiaVisit = false;

            /*
             * El backend normal convierte un fallo inesperado en errors.operation.
             * Este fallback cubre además una respuesta Inertia sin errores de campo
             * para que el modal nunca falle en silencio.
             */
            if (!errors?.operation && Object.keys(errors || {}).length === 0) {
                feedbackForm.setError(
                    'operation',
                    'No pudimos devolver la noticia para corrección. No se realizaron cambios. Intentá nuevamente.',
                );
            }
        },
    });
}

/* =============================================================================
 * MODAL 
 * ============================================================================= */

/**
 * Valida la noticia completa antes de abrir el modal de reenvío.
 *
 * No exige un guardado previo: al confirmar, las correcciones actuales
 * se guardan junto con el cambio de estado editorial.
 */
async function openResubmitConfirmation() {
    if (resubmitProcessing.value || form.processing) {
        return;
    }

    /*
     * Activa las reglas completas correspondientes al estado "En revisión"
     * y comprueba contenido, configuración, etiquetas y portada.
     */
    const isReadyToResubmit =
        await validateBeforeOpeningActionModal('review');

    if (!isReadyToResubmit) {
        return;
    }

    resubmitError.value = '';
    resubmitOpen.value = true;
}

/**
 * Cierra el modal y restaura el estado real de la noticia.
 */
function closeResubmitConfirmation() {
    if (resubmitProcessing.value) {
        return;
    }

    resetCancelledCompleteAction();
    resubmitError.value = '';
    resubmitOpen.value = false;
}

/**
 * Guarda las correcciones actuales y reenvía la noticia a revisión.
 *
 * El contenido, las etiquetas, la portada y la transición editorial
 * se envían en una única petición.
 */
async function confirmResubmit() {
    if (
        !resubmitOpen.value
        || resubmitProcessing.value
        || form.processing
    ) {
        return;
    }

    resubmitProcessing.value = true;
    resubmitError.value = '';

    /*
     * Reutiliza el guardado completo del formulario.
     *
     * saveWithStatus establece "review", vuelve a validar y envía todos
     * los campos actuales a la ruta normal de actualización.
     */
    const requestStarted = await saveWithStatus('review', {
        onSuccess: () => {
            resubmitError.value = '';
            resubmitOpen.value = false;
        },
        onOperationError: (message) => {
            form.status = currentStatus.value;
            resubmitError.value = message;
        },
        onError: (errors) => {
            if (!errors?.operation) {
                form.status = currentStatus.value;
                resubmitOpen.value = false;
            }
        },
        onFinish: () => {
            resubmitProcessing.value = false;
        },
    });

    /*
     * Si la petición no comenzó porque una validación local volvió
     * a fallar, libera inmediatamente el estado de procesamiento.
     */
    if (!requestStarted) {
        resubmitProcessing.value = false;
        resubmitOpen.value = false;
    }
}

/* =============================================================================== */

/**
 * Programa el autoguardado quince segundos después del último cambio.
 *
 * En una noticia nueva no se ejecuta porque todavía no existe un registro
 * en la base de datos. El usuario debe realizar primero un guardado manual,
 * que crea la noticia y redirige el formulario al modo de edición.
 *
 * Una vez en modo de edición, el autoguardado queda disponible para borradores
 * y noticias devueltas para corrección. Cada modificación inicia una espera
 * de quince segundos. Si el usuario vuelve a escribir antes de que termine,
 * la espera anterior se cancela y comienza nuevamente desde el último cambio.
 *
 * Tampoco se programa mientras haya un guardado manual en curso.
 */
function scheduleAutosave() {
    if (
        !autosaveEnabled
        || !canAutosave.value
        || form.processing
    ) {
        return;
    }

    // Reinicia la espera para contar quince segundos desde el cambio más reciente.
    clearTimeout(autosaveTimer);

    // Indica que existen modificaciones todavía no autoguardadas.
    autosaveState.value = 'pending';

    // Guarda únicamente después de quince segundos sin nuevos cambios.
    autosaveTimer = window.setTimeout(
        performAutosave,
        15000
    );
}

// Ejecuta el autoguardado real contra el endpoint JSON de Laravel.
async function performAutosave() {
    if (!canAutosave.value) return;

    // No se ejecuta el autoguardado hasta que todos los campos
    // alcancen sus mínimos.
    //
    // Para el cuerpo se cuentan caracteres visibles,
    // no las etiquetas HTML del editor.
    if (
        textLength('title') < textLimits.title.min
        || textLength('subtitle') < textLimits.subtitle.min
        || textLength('excerpt') < textLimits.excerpt.min
        || bodyTextLength() < bodyLimits.min
    ) {
        autosaveState.value = 'pending';
        autosaveMessage.value = 'Completá los campos mínimos para autoguardar';
        return;
    }

    autosaveState.value = 'saving';

    try {

        // Guarda una copia exacta de los valores enviados.
        //
        // Si el usuario vuelve a escribir mientras se ejecuta la petición,
        // los cambios nuevos seguirán considerándose pendientes.
        const autosavePayload = {
            category_id: form.category_id,
            title: form.title,
            subtitle: form.subtitle,
            excerpt: form.excerpt,
            body: form.body,
        };

        const response = await window.axios.patch(
            route('admin.articles.autosave', props.article.id),
            autosavePayload
        );

       /*
        * Establece los valores autoguardados como la nueva referencia del formulario.
        *
        * Cuando el contenido actual coincide con esos valores, form.isDirty pasa
        * a false. Por eso, si el usuario abandona la pantalla después de un
        * autoguardado exitoso y no hizo cambios nuevos, no aparece el modal
        * «¿Querés guardar los cambios?».
        *
        * Si el usuario modificó el formulario mientras el autoguardado estaba en
        * curso, los valores actuales serán diferentes de los enviados y form.isDirty
        * permanecerá en true. En ese caso, cuando intente abandonar la pantalla,
        * aparecerá el modal «¿Querés guardar los cambios?» para evitar que pierda
        * esas modificaciones nuevas.
        */
        form.defaults(autosavePayload);

        autosaveState.value = 'saved';
        autosaveMessage.value = `Autoguardado ${new Intl.DateTimeFormat('es-AR', { hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(new Date(response.data.saved_at))}`;
    } catch (error) {
        autosaveState.value = 'error';
        autosaveMessage.value =
            error.response?.data?.errors?.operation?.[0]
            || error.response?.data?.message
            || 'No se pudo autoguardar';
    }
}

/*
 * Escape cierra el modal que está abierto sin ejecutar ninguna acción.
 * ConfirmActionModal gestiona publicación, cancelación de programación,
 * archivo, eliminación y reenvío; acá se cubren los modales específicos
 * del formulario y el selector de etiquetas.
 */
function handleDocumentEscape(event) {
    if (event.key !== 'Escape') {
        return;
    }

    // Los confirmadores compartidos tienen su propio listener y deben resolver
    // primero su cancelación sin que el formulario cierre otra cosa detrás.
    if (
        sendToReviewOpen.value
        || publishOpen.value
        || cancelScheduleOpen.value
        || archiveOpen.value
        || deleteOpen.value
        || resubmitOpen.value
    ) {
        return;
    }

    if (scheduleOpen.value) {
        if (!form.processing) {
            event.preventDefault();
            closeScheduleModal();
        }
        return;
    }

    if (feedbackOpen.value) {
        if (!feedbackForm.processing) {
            event.preventDefault();
            closeFeedbackModal();
        }
        return;
    }

    if (unsavedChangesOpen.value) {
        if (!savingBeforeLeave.value) {
            event.preventDefault();
            keepEditing();
        }
        return;
    }

    if (observationsOpen.value) {
        event.preventDefault();
        closeObservationsModal();
        return;
    }

    if (previewOpen.value) {
        event.preventDefault();
        previewOpen.value = false;
        return;
    }

    if (tagSuggestionsOpen.value) {
        event.preventDefault();
        closeTagSuggestions();
    }
}

// El tooltip se comporta de manera parecida al nativo de Chrome:
// desaparece cuando el usuario vuelve a modificar el contenido.
//
// Si vuelve a presionar Guardar sin cumplir el mínimo,
// validateBody() lo mostrará nuevamente.
watch(
    () => form.body,
    () => {
        // Oculta el tooltip cuando el usuario vuelve a escribir.
        hideBodyValidationTooltip();

        // Elimina el error anterior para no conservar un estado viejo.
        form.clearErrors('body');
    },
);

// Elimina el error de borrador vacío
// cuando el usuario empieza a escribir contenido.
watch(
    () => [
        form.title,
        form.subtitle,
        form.excerpt,
        form.body,
    ],
    () => {
        if (hasDraftContent.value) {
            form.clearErrors('draft_content');
        }
    }
);

// Cuando el usuario vuelve a Borrador,
// elimina los errores que solo corresponden a En revisión.
watch(
    () => form.status,
    () => {
        if (requiresCompleteArticle.value) {
            return;
        }

        hideBodyValidationTooltip();

        form.clearErrors(
            'title',
            'subtitle',
            'excerpt',
            'body',
            'category_id',
            'tag_ids',
            'cover_image_file'
        );
    }
);

// Observa cambios importantes del formulario y dispara el autoguardado diferido.
watch(
    () => [form.title, form.subtitle, form.excerpt, form.body, form.category_id],
    scheduleAutosave,
);

// Elimina el mensaje cuando el usuario modifica
// la selección de etiquetas.
watch(
    () => form.tag_ids,
    () => {
        form.clearErrors('tag_ids');
    },
    { deep: true }
);

// Si deja de intentar destacar en portada,
// el error de cupo destacado ya no corresponde.
watch(
    () => form.is_featured,
    (isFeatured) => {
        if (!isFeatured) {
            form.clearErrors('is_featured');
        }
    }
);

// Observa si el formulario tiene cambios respecto del último guardado.
//
// form.isDirty pasa a true cuando el usuario modifica cualquier campo.
// En ese momento se oculta el mensaje correspondiente al guardado anterior.
watch(
    () => form.isDirty,
    (isDirty) => {
        if (isDirty) {
            hideEditorialFlash();
        }
    }
);

// Bloquea el desplazamiento de la página mientras haya un modal abierto.
//
// El contenido interno del modal puede seguir desplazándose
// cuando supera la altura disponible.
watch(hasOpenModal, (isOpen) => {
    document.body.style.overflow = isOpen
        ? 'hidden'
        : '';
});

// Activa el autoguardado y la protección
// contra navegaciones accidentales.
onMounted(() => {
    autosaveEnabled = true;

    // Cierra selector de etiquetas y modales específicos con interacciones
    // estándar de teclado/puntero.
    document.addEventListener('keydown', handleDocumentEscape);
    document.addEventListener('pointerdown', handleTagSelectorPointerDown);

    // Intercepta enlaces y peticiones de navegación de Inertia.
    removeBeforeVisitListener = router.on(
        'before',
        handleBeforeInertiaVisit
    );

    // Protege actualizaciones y cierre de la pestaña.
    window.addEventListener(
        'beforeunload',
        handleBeforeUnload
    );
});

// Limpia listeners, timers, URLs temporales y bloqueos al salir.
onBeforeUnmount(() => {
    clearTimeout(autosaveTimer);

    document.removeEventListener('keydown', handleDocumentEscape);
    document.removeEventListener('pointerdown', handleTagSelectorPointerDown);

    removeBeforeVisitListener?.();

    window.removeEventListener(
        'beforeunload',
        handleBeforeUnload
    );

    // Restaura el scroll aunque el componente se desmonte
    // mientras todavía existe un modal abierto.
    document.body.style.overflow = '';

    if (selectedImagePreview.value) {
        URL.revokeObjectURL(selectedImagePreview.value);
    }
});

</script>

<template>
    <Head :title="title" />

    <section
        v-if="contentFieldsLocked"
        class="read-only-banner"
    >
        <div>
            <p class="eyebrow">
                Estado actual: {{ currentStatusLabel }}
            </p>
            <h2>
                {{ readOnlyBannerTitle }}
            </h2>
            <p
                v-if="
                    workflowDecisionActorName
                    && currentStatus === 'scheduled'
                "
                class="workflow-person"
            >
                Programada por
                <strong>{{ workflowDecisionActorName }}</strong>
                · Editor
            </p>

            <p
                v-if="
                    workflowDecisionActorName
                    && currentStatus === 'published'
                "
                class="workflow-person"
            >
                Publicación aprobada por
                <strong>{{ workflowDecisionActorName }}</strong>
                · Editor
            </p>

            <p
                v-if="
                    workflowDecisionActorName
                    && currentStatus === 'archived'
                "
                class="workflow-person"
            >
                Archivada por
                <strong>{{ workflowDecisionActorName }}</strong>
                · Editor
            </p>

            <p
                v-if="workflowDecisionDateLabel"
                class="workflow-person"
            >
                {{ workflowDecisionDateLabel }}
            </p>
            <p
                v-if="isReviewingForeignArticle"
                class="workflow-person"
            >
                Autor:
                <strong>{{ articleAuthorName }}</strong>
                · Periodista
            </p>
        </div>

        <p>
            {{ readOnlyBannerMessage }}
        </p>
    </section>

    <section
        v-if="showCorrectionFeedbackBanner"
        class="editor-feedback-banner"
    >
        <div class="editor-feedback-summary">
            <div>
                <p class="eyebrow">Devuelta para corrección</p>

                <h2>Cambios solicitados por la edición</h2>

                <p class="editor-feedback-description">
                    Devuelta por
                    <strong>
                        {{ latestOpenFeedback?.returned_by || 'la edición' }}
                    </strong>

                    <template v-if="latestReturnDateLabel">
                        · {{ latestReturnDateLabel }}
                    </template>
                </p>
            </div>

            <button
                type="button"
                class="secondary-admin-link"
                @click="openObservationsModal"
            >
                {{
                    openFeedback.length === 1
                        ? 'Ver observación'
                        : `Ver observaciones (${openFeedback.length})`
                }}
            </button>
        </div>
    </section>

    <!--
        Muestra un fallo inesperado de la operación completa.
        El usuario permanece en la misma pantalla y puede volver a intentar la acción.
    -->
    <div
        v-if="operationError"
        class="operation-error"
        role="alert"
    >
        <p class="eyebrow">
            No se pudo completar la operación
        </p>
        <p>
            {{ operationError }}
        </p>
    </div>

    <nav
        class="editor-tabs"
        aria-label="Secciones del formulario"
    >
        <button
            type="button"
            :class="{ active: activeTab === 'content' }"
            :disabled="activeTab === 'content'"
            @click="activeTab = 'content'"
        >
            Contenido
        </button>

        <button
            type="button"
            :class="{ active: activeTab === 'settings' }"
            :disabled="activeTab === 'settings'"
            @click="activeTab = 'settings'"
        >
            Configuración
        </button>

        <button
            v-if="mode === 'edit'"
            type="button"
            :class="{ active: activeTab === 'history' }"
            :disabled="activeTab === 'history'"
            @click="activeTab = 'history'"
        >
            Historial
        </button>

        <span
            v-if="canAutosave"
            :class="['autosave-status', autosaveState]"
        >
            {{ autosaveLabel }}
        </span>
    </nav>

    <form 
        v-if="activeTab !== 'history'" 
        ref="articleForm"
        :class="[
            'editor-tab-panel',
            `editor-tab-panel--${activeTab}`,
        ]"
        enctype="multipart/form-data"                 
        novalidate                 
        @invalid.capture="hideBodyValidationTooltip"        
        @submit.prevent="submit"
    >
        <section 
            v-show="activeTab === 'content'" 
            class="editor-main editor-content-panel"
        >
            <!-- Error general cuando se intenta guardar un borrador vacío. -->
            <p
                v-if="form.errors.draft_content"
                class="form-error"
                role="alert"
            >
                {{ form.errors.draft_content }}
            </p>
            <label>
                Título
            <input
                v-model="form.title"
                name="title"                
                :readonly="contentFieldsLocked"
                :required="requiresCompleteArticle"
                :minlength="requiresCompleteArticle ? textLimits.title.min : null"
                :maxlength="textLimits.title.max"
                @blur="normalizeSingleLineField('title')"                
                @input="clearTextValidation"
            />
                <div class="field-meta">
                    <!--
                        Muestra el máximo y, mientras corresponda,
                        el mínimo o la cantidad de caracteres faltantes.
                    -->
                    <span
                        class="character-counter"
                        :class="counterState('title')"
                    >
                        {{ counterText('title') }}
                    </span>
                </div>
                <span v-if="form.errors.title" class="form-error">
                    {{ form.errors.title }}
                </span>
            </label>

            <label>
                Bajada
                <input
                    v-model="form.subtitle"
                    name="subtitle"
                    :readonly="contentFieldsLocked"
                    :required="requiresCompleteArticle"
                    :minlength="requiresCompleteArticle ? textLimits.subtitle.min : null"
                    :maxlength="textLimits.subtitle.max"
                    @blur="normalizeSingleLineField('subtitle')"
                    @input="clearTextValidation"
                />
                <div class="field-meta">
                    <!-- Contador del texto ubicado debajo del título. -->
                    <span
                        class="character-counter"
                        :class="counterState('subtitle')"
                    >
                        {{ counterText('subtitle') }}
                    </span>
                </div>
                <span v-if="form.errors.subtitle" class="form-error">
                    {{ form.errors.subtitle }}
                </span>
            </label>

            <label>
                Resumen
                <textarea
                    v-model="form.excerpt"
                    name="excerpt"
                    :readonly="contentFieldsLocked"
                    rows="3"
                    :required="requiresCompleteArticle"
                    :minlength="requiresCompleteArticle ? textLimits.excerpt.min : null"
                    :maxlength="textLimits.excerpt.max"
                    @keydown.enter.prevent
                    @blur="normalizeSingleLineField('excerpt')"
                    @input="clearTextValidation"                    
                />
                <div class="field-meta">
                    <!-- Contador del resumen usado en tarjetas y listados. -->
                    <span
                        class="character-counter"
                        :class="counterState('excerpt')"
                    >
                        {{ counterText('excerpt') }}
                    </span>
                </div>
                <span v-if="form.errors.excerpt" class="form-error">
                    {{ form.errors.excerpt }}
                </span>
            </label>

            <div
                ref="bodyEditorField"
                class="editor-field"
            >
                <span class="editor-field-label">
                    Cuerpo de la noticia
                </span>

                <!--
                    Contenedor relativo usado para colocar el tooltip
                    sobre el RichTextEditor sin modificar el componente.
                -->
                <div class="body-editor-validation">                    
                    <RichTextEditor
                        v-model="form.body"
                        :article-id="article?.id"
                        :min-characters="requiresCompleteArticle ? bodyLimits.min : 0"
                        :max-characters="bodyLimits.max"                        
                        :disabled="contentFieldsLocked"
                    />

                    <!--
                        Tooltip personalizado del cuerpo.
                        Se muestra únicamente después de intentar guardar.
                    -->
                    <div
                        v-if="bodyTooltipVisible && form.errors.body"
                        class="body-validation-tooltip"
                        role="alert"
                        aria-live="assertive"
                    >
                        <span
                            class="body-validation-tooltip-icon"
                            aria-hidden="true"
                        >
                            !
                        </span>

                        <span>{{ form.errors.body }}</span>
                    </div>
                </div>
  
            </div>
        </section>

        <section v-show="activeTab === 'settings'" class="editor-settings-grid">
            <div class="editor-main">
                <label>
                    Categoría
                    <span class="article-category-select-control">
                        <select
                            v-model.number="form.category_id"
                            class="article-category-select"
                            :disabled="contentFieldsLocked"
                            :required="requiresCompleteArticle"
                        >
                            <!-- Opción inicial sin valor para obligar a elegir una categoría. -->
                            <option disabled hidden value="">
                                Seleccioná una categoría
                            </option>

                            <option
                                v-for="category in categories"
                                :key="category.id"
                                :value="category.id"
                            >
                                {{ category.name }}
                                {{ category.is_active === false ? ' (inactiva)' : '' }}
                            </option>
                        </select>
                        <ChevronDown :size="16" aria-hidden="true" />
                    </span>
                    <span v-if="form.errors.category_id" class="form-error">{{ form.errors.category_id }}</span>
                </label>

                <div class="tag-selector-field">
                    <span class="tag-selector-label">
                        <span>Etiquetas</span>
                        <small>{{ (form.tag_ids || []).length }} / {{ MAX_TAGS_PER_ARTICLE }}</small>
                    </span>

                    <div class="tag-chip-list" :class="{ 'is-locked': contentFieldsLocked }">
                        <span
                            v-for="tag in selectedTags"
                            :key="tag.id"
                            class="tag-chip"
                            :class="{ 'tag-chip--inactive': tag.is_active === false || tag.merged }"
                        >
                            {{ tag.name }}{{ tag.is_active === false || tag.merged ? ' (inactiva)' : '' }}
                            <button
                                type="button"
                                :disabled="contentFieldsLocked"
                                :aria-label="`Quitar ${tag.name}`"
                                @click="removeTag(tag.id)"
                            >×</button>
                        </span>
                    </div>

                    <div ref="tagSearchControl" class="tag-search-control">
                        <input
                            v-model="tagSearch"
                            type="search"
                            aria-label="Buscar etiqueta"
                            placeholder="Buscar etiqueta..."
                            autocomplete="off"
                            :disabled="contentFieldsLocked || tagLimitReached"
                            @click="toggleTagSuggestions"                            
                            @input="tagSuggestionsOpen = true"
                        />
                        <button
                            v-if="tagSuggestionsOpen && !contentFieldsLocked && !tagLimitReached"
                            type="button"
                            class="tag-search-close"
                            aria-label="Cerrar lista de etiquetas"
                            @click="closeTagSuggestions"
                        >
                            <X :size="16" />
                        </button>
                        <button
                            type="button"
                            class="tag-search-chevron"
                            aria-label="Abrir lista de etiquetas"
                            :aria-expanded="tagSuggestionsOpen"
                            :disabled="contentFieldsLocked || tagLimitReached"
                            @click="toggleTagSuggestions"
                        >
                            <ChevronDown :size="16" />
                        </button>
                        <div
                            v-if="tagSuggestionsOpen && !contentFieldsLocked && !tagLimitReached"
                            class="tag-suggestions"
                        >
                            <button
                                v-for="tag in tagSuggestions"
                                :key="tag.id"
                                type="button"
                                @mousedown.prevent="addTag(tag)"
                            >
                                <span>{{ tag.name }}</span>
                                <small v-if="form.category_id && tag.usage_by_category?.[String(form.category_id)]">
                                    usada {{ tag.usage_by_category[String(form.category_id)] }} veces en esta categoría
                                </small>
                            </button>
                            <p v-if="!tagSuggestions.length">No se encontraron etiquetas disponibles.</p>
                        </div>
                    </div>

                    <small v-if="tagLimitReached" class="field-help">Máximo de etiquetas alcanzado.</small>
                    <small v-else class="field-help">Podés elegir hasta 5. La categoría solo ordena las sugerencias y no limita la búsqueda.</small>

                    <span v-if="form.errors.tag_ids" class="form-error">
                        {{ form.errors.tag_ids }}
                    </span>
                </div>

                <div v-if="permissions.can_manage_homepage" class="flag-box">
                    <label class="editorial-flag">
                        <input
                            v-model="form.is_breaking"
                            :disabled="fieldsLocked"
                            type="checkbox"
                        />
                        <span>
                            <strong>Marcar como urgente</strong>
                            <small>
                                Cuando esté publicada, aparecerá en la cinta de titulares
                                con la etiqueta “Urgente” y tendrá prioridad sobre las
                                noticias comunes.
                            </small>
                        </span>
                    </label>

                    <label class="editorial-flag">
                        <input
                            v-model="form.is_featured"
                            :disabled="fieldsLocked"
                            type="checkbox"
                        />
                        <span>
                            <strong>Destacar en portada</strong>
                            <small>
                                Cuando esté publicada, podrá ocupar la noticia principal
                                o uno de los bloques destacados. La destacada más reciente
                                ocupa la posición principal.
                            </small>
                        </span>
                    </label>

                    <div
                        v-if="hasFeaturedHomepageError"
                        class="homepage-feature-error-block"
                    >
                        <span class="form-error">
                            {{ form.errors.is_featured }}
                        </span>

                        <a
                            :href="route('admin.articles.index', { homepage: 'featured' })"
                            class="homepage-featured-link"
                            target="_blank"
                            rel="noopener"
                        >
                            Ver noticias destacadas
                        </a>
                    </div>
                </div>

                <section
                    v-if="!contentFieldsLocked && !showCorrectionFeedbackBanner"
                    class="editor-status-info"
                >
                    <span>Estado actual</span>

                    <strong>
                        {{ currentStatusLabel }}
                    </strong>

                    <small v-if="isScheduledArticle && scheduledAtLabel">
                        Publicación prevista: {{ scheduledAtLabel }}
                    </small>

                    <span
                        v-if="form.errors.status"
                        class="form-error"
                    >
                        {{ form.errors.status }}
                    </span>

                    <span
                        v-if="form.errors.scheduled_at"
                        class="form-error"
                    >
                        {{ form.errors.scheduled_at }}
                    </span>
                </section>
            </div>

            <aside class="editor-side">
                <section
                    ref="coverUploadField"
                    class="cover-upload"
                >
                    <span>Imagen de portada</span>
                    <input 
                        ref="imageInput" 
                        :disabled="contentFieldsLocked" 
                        type="file" 
                        accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" 
                        @change="handleImageChange" 
                    />
                    <div
                        v-if="visibleImagePreview"
                        class="cover-preview"
                    >
                        <img
                            :src="visibleImagePreview"
                            :alt="form.title || 'Imagen de portada'"
                        />
                    </div>
                    <div 
                        v-else 
                        :class="[
                            'cover-empty',
                            {
                                'cover-empty--missing': currentImageMissing
                                    && !form.remove_cover_image
                                    && !form.cover_image_file,
                            },
                        ]"
                    >
                        <strong>
                            {{
                                currentImageMissing
                                    && !form.remove_cover_image
                                    && !form.cover_image_file
                                    ? 'Imagen no encontrada'
                                    : 'Sin imagen seleccionada'
                            }}
                        </strong>                        
                    </div>
                    <div
                        v-if="!contentFieldsLocked"
                        class="cover-actions"
                    >
                        <button 
                            type="button" 
                            @click="selectImage"
                        >
                            {{ imageActionLabel }}
                        </button>
                        <button 
                            v-if="
                                visibleImagePreview
                                || (currentImageMissing && !form.remove_cover_image)
                            " 
                            type="button" 
                            class="secondary-cover-action" 
                            @click="removeImage">
                            Quitar imagen
                        </button>
                    </div>
                    <small
                        v-if="!contentFieldsLocked"
                        class="field-help"
                    >
                        {{
                            requiresCompleteArticle
                                ? 'Imagen obligatoria para completar esta acción. JPG, JPEG, PNG o WEBP. Máximo 5 MB.'
                                : currentStatus === 'needs_changes'
                                    ? 'La portada debe estar presente para reenviar la noticia a revisión. JPG, JPEG, PNG o WEBP. Máximo 5 MB.'
                                    : 'Imagen opcional mientras la noticia esté en borrador. JPG, JPEG, PNG o WEBP. Máximo 5 MB.'
                        }}
                    </small>
                    <span
                        v-if="
                            currentImageMissing
                            && !form.remove_cover_image
                            && !form.cover_image_file
                            && !form.errors.cover_image_file
                        "
                        class="form-error"
                    >
                        El archivo actual de la portada no existe. Subí nuevamente la imagen para reparar la noticia.
                    </span>
                    <span 
                        v-if="form.errors.cover_image_file" 
                        class="form-error">
                            {{ form.errors.cover_image_file }}
                    </span>
                </section>

                <button type="button" class="secondary-admin-link preview-button" @click="previewOpen = true"><Eye :size="17" />Previsualizar noticia</button>
            </aside>
        </section>

        <footer
            :class="[
                'editor-save-bar',
                {
                    /*
                    * En la pestaña Contenido la barra se alinea con los campos internos
                    * del formulario, que están 18 px hacia adentro por el padding de
                    * .editor-main.
                    *
                    * Configuración conserva el ancho general original de la barra.
                    */
                    'editor-save-bar--content': activeTab === 'content',
                },
            ]"
        >
            <button
                v-if="canDeleteArticleAction"
                type="button"
                class="danger-outline-button article-delete-button"
                :disabled="
                    deleteProcessing
                    || form.processing
                    || autosaveState === 'saving'
                "
                @click="openDeleteConfirmation"
            >
                {{
                    deleteProcessing
                        ? deleteActionCopy.processingLabel
                        : deleteActionCopy.confirmLabel
                }}
            </button>
            <button
                v-if="canRequestChanges"
                type="button"
                class="danger-outline-button"
                :disabled="feedbackForm.processing || form.processing"
                @click="openFeedbackModal"
            >
                Devolver para corrección
            </button>
            <button
                v-if="canCancelScheduleAction"
                type="button"
                class="danger-outline-button"
                :disabled="form.processing || cancelScheduleProcessing"
                @click="openCancelScheduleConfirmation"
            >
                Cancelar programación
            </button>
            <button
                v-if="canSaveDraftAction"
                type="button"
                class="secondary-admin-link"
                :disabled="draftActionDisabled"
                @click="saveWithStatus('draft')"
            >
                Guardar borrador
            </button>
            <button
                v-if="canSaveCorrectionsAction"
                type="button"
                class="secondary-admin-link"
                :disabled="draftActionDisabled"
                @click="saveWithStatus('needs_changes')"
            >
                Guardar correcciones
            </button>
            <button
                v-if="canSendToReviewAction"
                type="button"
                class="admin-primary-action article-save-button"
                :disabled="completeArticleActionDisabled"
                @click="openSendToReviewConfirmation"
            >
                Enviar a revisión
            </button>
            <button
                v-if="canResubmit"
                type="button"
                class="secondary-admin-link"
                :disabled="resubmitProcessing || form.processing"
                @click="openResubmitConfirmation"
            >
                <Send :size="17" />
                Reenviar a revisión
            </button>
            <button
                v-if="canScheduleAction"
                type="button"
                class="secondary-admin-link"
                :disabled="scheduleButtonDisabled"
                @click="openScheduleModal"
            >
                {{ scheduleActionLabel }}
            </button>
            <button
                v-if="canSaveScheduledChangesAction"
                type="button"
                class="admin-primary-action article-save-button"
                :disabled="scheduledSaveDisabled"
                @click="saveWithStatus('scheduled')"
            >
                Guardar cambios
            </button>
            <button
                v-if="canPublishAction"
                type="button"
                class="admin-primary-action article-save-button"
                :disabled="completeArticleActionDisabled"
                @click="openPublishConfirmation"
            >
                Publicar noticia
            </button>
            <button
                v-if="canArchiveAction"
                type="button"
                class="danger-outline-button"
                :disabled="archiveProcessing"
                @click="openArchiveConfirmation"
            >
                {{ archiveProcessing ? 'Archivando...' : 'Archivar noticia' }}
            </button>
            <button
                type="button"
                class="secondary-admin-link"
                :disabled="form.processing"
                @click="goBack"
            >
                Volver a noticias
            </button>
        </footer>        
    </form>

    <RevisionHistory
        v-else
        :revisions="revisions"
        :article="article"
        :categories="categories"
        :can-restore="
            permissions.can_review
            && !isReviewingForeignArticle
        "
    />

    <!-- Muestra al periodista las observaciones pendientes dejadas por la edición. -->
    <div
        v-if="observationsOpen"
        class="admin-modal-backdrop"
    >
        <section
            class="admin-modal observations-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="observations-modal-title"
        >
            <header>
                <div>
                    <p class="eyebrow">Observaciones editoriales</p>

                    <h2 id="observations-modal-title">
                        Cambios solicitados por la edición
                    </h2>
                </div>

                <button
                    type="button"
                    aria-label="Cerrar observaciones"
                    @click="closeObservationsModal"
                >
                    <X />
                </button>
            </header>

            <p class="modal-copy">
                Revisá estas indicaciones antes de volver a enviar la noticia.
            </p>

            <div class="observations-list">
                <article
                    v-for="item in openFeedback"
                    :key="item.id"
                    class="observations-item"
                >
                    <strong>
                        {{ item.returned_by }} ·
                        {{ formatFeedbackDateTime(item.created_at) }}
                    </strong>

                    <p class="observations-message">
                        {{ item.message }}
                    </p>
                </article>
            </div>

            <div class="observations-actions">
                <button
                    type="button"
                    class="secondary-admin-link"
                    @click="closeObservationsModal"
                >
                    Cerrar
                </button>
            </div>
        </section>
    </div>

    <div
        v-if="scheduleOpen"
        class="admin-modal-backdrop"
    >
        <form
            class="admin-modal"
            @submit.prevent="confirmSchedule"
        >
            <header>
                <div>
                    <p class="eyebrow">Programación editorial</p>
                    <h2>{{ scheduleModalTitle }}</h2>
                </div>

                <button
                    type="button"
                    :disabled="form.processing"
                    @click="closeScheduleModal"
                >
                    <X />
                </button>
            </header>

            <p class="modal-copy">
                Elegí cuándo debe publicarse la noticia en el portal.
            </p>

            <div class="schedule-date-field">
                <span class="schedule-date-field-label">
                    Fecha y hora de publicación
                </span>

                <VueDatePicker
                    v-model="scheduleDate"
                    class="editorial-date-picker schedule-date-picker"
                    model-type="yyyy-MM-dd'T'HH:mm"
                    :formats="{
                        input: 'dd/MM/yyyy HH:mm',
                    }"
                    placeholder="Seleccionar fecha y hora"
                    :locale="es"
                    dark                    
                    :clearable="false"
                    :min-date="scheduleMin"                    
                    :max-date="scheduleMax"
                    prevent-min-max-navigation
                    :disabled="form.processing"
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
            </div>

            <span
                v-if="form.errors.scheduled_at"
                class="form-error"
            >
                {{ form.errors.scheduled_at }}
            </span>

            <div class="unsaved-changes-actions">
                <button
                    type="button"
                    class="secondary-admin-link"
                    :disabled="form.processing"
                    @click="closeScheduleModal"
                >
                    Cancelar
                </button>

                <button
                    type="submit"
                    class="admin-primary-action"
                    :disabled="form.processing || !scheduleDate"
                >
                    <!-- Conserva el ancho entre la confirmación y el procesamiento. -->
                    <span class="modal-action-label">
                        <span :class="{ 'is-hidden': form.processing }">
                            {{ scheduleConfirmLabel }}
                        </span>
                        <span :class="{ 'is-hidden': !form.processing }">
                            {{ scheduleProcessingLabel }}
                        </span>
                    </span>
                </button>
            </div>
        </form>
    </div>

    <!-- Protege el trabajo cuando se intenta abandonar el formulario. -->
    <div
        v-if="unsavedChangesOpen"
        class="admin-modal-backdrop"
    >
        <section
            class="admin-modal unsaved-changes-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="unsaved-changes-title"
        >
            <header>
                <div>
                    <p class="eyebrow">
                        {{ isReviewingForeignArticle ? 'Decisiones pendientes' : 'Cambios pendientes' }}
                    </p>

                    <h2 id="unsaved-changes-title">
                        {{
                            isReviewingForeignArticle
                                ? 'Tenés decisiones editoriales sin aplicar'
                                : 'Tenés cambios sin guardar'
                        }}
                    </h2>
                </div>

                <button
                    type="button"
                    :title="isReviewingForeignArticle ? 'Seguir revisando' : 'Seguir editando'"
                    :disabled="savingBeforeLeave"
                    @click="keepEditing"
                >
                    <X />
                </button>
            </header>

            <p
                v-if="isReviewingForeignArticle"
                class="modal-copy"
            >
                Marcaste opciones editoriales que todavía no fueron aplicadas.
                Si salís ahora, se perderá esta selección.
            </p>

            <p
                v-else
                class="modal-copy"
            >
                ¿Querés guardar la noticia antes de salir?
                Si continuás sin guardar, se perderán los cambios realizados.
            </p>

            <p
                v-if="!isReviewingForeignArticle && !hasDraftContent"
                class="unsaved-changes-help"
            >
                Escribí al menos algún contenido para poder guardar el borrador.
            </p>

            <div class="unsaved-changes-actions">
                <button
                    type="button"
                    class="secondary-admin-link"
                    :disabled="savingBeforeLeave"
                    @click="keepEditing"
                >
                    {{ isReviewingForeignArticle ? 'Seguir revisando' : 'Seguir editando' }}
                </button>

                <button
                    type="button"
                    class="danger-outline-button"
                    :disabled="savingBeforeLeave"
                    @click="leaveWithoutSaving"
                >
                    {{ isReviewingForeignArticle ? 'Salir sin aplicar' : 'Salir sin guardar' }}
                </button>

                <button
                    v-if="!isReviewingForeignArticle"
                    type="button"
                    class="admin-primary-action"
                    :disabled="!canSaveBeforeLeave"
                    @click="saveBeforeLeaving"
                >
                    <!-- Conserva el ancho entre el texto de guardado y "Guardando...". -->
                    <span class="modal-action-label">
                        <span :class="{ 'is-hidden': savingBeforeLeave }">
                            {{ saveBeforeLeaveIdleLabel }}
                        </span>
                        <span :class="{ 'is-hidden': !savingBeforeLeave }">
                            Guardando...
                        </span>
                    </span>
                </button>
            </div>
        </section>
    </div>

    <div
        v-if="feedbackOpen"
        class="admin-modal-backdrop"
    >
        <form
            class="admin-modal"
            @submit.prevent="requestChanges"
        >
            <header>
                <div>
                    <p class="eyebrow">Revisión editorial</p>
                    <h2>Devolver para corrección</h2>
                </div>

                <button
                    type="button"
                    :disabled="feedbackForm.processing"
                    @click="closeFeedbackModal"
                >
                    <X />
                </button>
            </header>

            <p class="modal-copy">
                La noticia cambiará a “Devuelta para corrección”.
                La observación quedará registrada en su historial.
            </p>

            <label>
                Observaciones
                <textarea
                    ref="feedbackTextarea"
                    v-model="feedbackForm.message"
                    class="feedback-observation-textarea"
                    rows="7"
                    required
                    :minlength="feedbackLimits.min"
                    :maxlength="feedbackLimits.max"
                    :disabled="feedbackForm.processing"
                    placeholder="Explicá con claridad qué debe corregirse..."
                    @input="handleFeedbackMessageInput"
                />

                <div class="field-meta">
                    <!-- Contador de las observaciones alineado a la derecha. -->
                    <span
                        class="character-counter"
                        :class="feedbackCounterState"
                    >
                        {{ feedbackCounterText }}
                    </span>
                </div>

                <span
                    v-if="feedbackForm.errors.message"
                    class="form-error"
                >
                    {{ feedbackForm.errors.message }}
                </span>
            </label>

            <p
                v-if="feedbackForm.errors.operation"
                class="operation-error"
                role="alert"
            >
                {{ feedbackForm.errors.operation }}
            </p>

            <!--
                Mantiene las acciones del modal en una única fila y alineadas
                a la derecha, igual que el resto de los modales editoriales.
            -->
            <div class="unsaved-changes-actions">
                <button
                    type="button"
                    class="secondary-admin-link"
                    :disabled="feedbackForm.processing"
                    @click="closeFeedbackModal"
                >
                    Cancelar
                </button>

                <button
                    type="submit"
                    class="admin-primary-action"
                    :disabled="feedbackSubmitDisabled"
                >
                    <!-- Conserva el ancho entre "Enviar observaciones" y "Enviando...". -->
                    <span class="modal-action-label">
                        <span :class="{ 'is-hidden': feedbackForm.processing }">
                            Enviar observaciones
                        </span>
                        <span :class="{ 'is-hidden': !feedbackForm.processing }">
                            Enviando...
                        </span>
                    </span>
                </button>
            </div>
        </form>
    </div>

    <div v-if="previewOpen" class="admin-modal-backdrop preview-backdrop">
        <article class="article-preview-modal">
            <button type="button" class="preview-close" @click="previewOpen = false"><X /></button>
            <p class="category-label">Vista previa editorial</p>
            <h1>{{ form.title || 'Título de la noticia' }}</h1>
            <p class="preview-subtitle">{{ form.subtitle }}</p>
            <img v-if="visibleImagePreview" :src="visibleImagePreview" :alt="form.title" />
            <div class="article-body" v-html="form.body" />
        </article>
    </div>

    <!-- =========================================================================
        MODALES DE CONFIRMACIÓN COMPARTIDOS
        ========================================================================= -->

    <!-- Confirma la publicación inmediata de la noticia. -->
    <!-- Confirma el envio inicial de una noticia a revision. -->
    <ConfirmActionModal
        :open="sendToReviewOpen"
        eyebrow="Revisión editorial"
        title="¿Enviar esta noticia a revisión?"
        message="La noticia pasará al circuito editorial para que un editor la evalúe."
        help="Al confirmar, se guardarán los cambios actuales y la noticia quedará en estado En revisión."
        confirm-label="Enviar a revisión"
        processing-label="Enviando..."
        :error="sendToReviewError"
        :processing="form.processing"
        @cancel="closeSendToReviewConfirmation"
        @confirm="confirmSendToReview"
    />

    <ConfirmActionModal
        :open="publishOpen"
        eyebrow="Publicación inmediata"
        title="¿Publicar esta noticia ahora?"
        message="La noticia quedará visible en el portal apenas confirmes la publicación."
        confirm-label="Publicar noticia"
        processing-label="Publicando..."
        :error="publishError"
        :processing="form.processing"
        @cancel="closePublishConfirmation"
        @confirm="confirmPublish"
    />

    <!-- Confirma la cancelación de una publicación programada. -->
    <ConfirmActionModal
        :open="cancelScheduleOpen"
        eyebrow="Programación"
        title="¿Cancelar esta programación?"
        message="Se eliminará la fecha prevista y la noticia no se publicará automáticamente."
        help="Después de cancelar, quedará editable: en revisión si pertenece a un periodista, o en borrador si fue creada por un editor."
        confirm-label="Cancelar programación"
        cancel-label="Mantener programación"
        processing-label="Cancelando..."
        :error="cancelScheduleError"
        :processing="cancelScheduleProcessing"
        danger
        @cancel="closeCancelScheduleConfirmation"
        @confirm="confirmCancelSchedule"
    />

    <!-- Confirma el archivo de una noticia publicada. -->
    <ConfirmActionModal
        :open="archiveOpen"
        eyebrow="Archivo editorial"
        title="¿Archivar esta noticia?"
        message="La noticia dejará de estar visible en el portal y pasará al estado Archivada."
        help="El contenido y el historial se conservarán, pero la publicación se retirará inmediatamente del sitio."
        confirm-label="Archivar noticia"
        cancel-label="Cancelar"
        processing-label="Archivando..."
        :error="archiveError"
        :processing="archiveProcessing"
        danger
        @cancel="closeArchiveConfirmation"
        @confirm="archiveArticle"
    />

    <!-- Confirma la eliminación definitiva de una noticia privada. -->
    <ConfirmActionModal
        :open="deleteOpen"
        :eyebrow="deleteActionCopy.eyebrow"
        :title="deleteActionCopy.title"
        :message="deleteActionCopy.message"
        :confirm-label="deleteActionCopy.confirmLabel"
        cancel-label="Cancelar"
        :processing-label="deleteActionCopy.processingLabel"
        :error="deleteError"
        :processing="deleteProcessing"
        danger
        @cancel="closeDeleteConfirmation"
        @confirm="deleteArticlePermanently"
    />

    <!-- Confirma el reenvío de una noticia corregida. -->
    <ConfirmActionModal
        :open="resubmitOpen"
        eyebrow="Reenviar noticia"
        title="¿Reenviar la noticia corregida?"
        message="La noticia volverá al estado En revisión para que un editor evalúe las correcciones."
        help="Al confirmar, las correcciones actuales se guardarán y la noticia volverá a revisión."
        confirm-label="Reenviar a revisión"
        processing-label="Reenviando..."
        :error="resubmitError"
        :processing="resubmitProcessing"
        @confirm="confirmResubmit"
        @cancel="closeResubmitConfirmation"
    />

</template>

<style src="../../../../css/admin/article-form.css"></style>
