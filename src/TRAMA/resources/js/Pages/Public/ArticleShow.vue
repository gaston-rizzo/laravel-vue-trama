<script setup>
/* ============================================================================
 * PAGE: Public/ArticleShow.vue
 * ============================================================================
 *
 * Muestra una noticia completa en el sitio público de TRAMA.
 *
 * Incluye el título, autor, imagen, contenido, etiquetas, publicidad y noticias
 * relacionadas. También permite ver y gestionar los comentarios de la noticia:
 * comentar, responder, editar, eliminar, dar "me gusta" y reportar comentarios.
 *
 * Laravel envía los datos y permisos necesarios, y Vue controla lo que se muestra
 * en pantalla y las acciones que realiza el usuario.
 * ============================================================================ */

import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import { Flag, LogIn, MessageCircle, ThumbsUp, UserPlus } from '@lucide/vue';
import AdvertisementBanner from '@/Components/News/AdvertisementBanner.vue';
import ArticleCard from '@/Components/News/ArticleCard.vue';
import SectionHeader from '@/Components/News/SectionHeader.vue';

// Props de la página de noticia:
// article contiene la nota completa y sus comentarios iniciales, related trae
// noticias recomendadas para "Seguir leyendo" y advertisements trae los banners
// laterales ya elegidos por Laravel para esta carga.
const props = defineProps({
    article: Object,
    related: Array,
    advertisements: Object,
});

// Accede a las props compartidas por Inertia, como el usuario autenticado
// y otros datos globales enviados por Laravel a la página actual.
const page = usePage();

// Formulario principal para crear un comentario nuevo en la noticia.
const comment = useForm({
    body: '',
});

// Cantidad de segundos que faltan para permitir un nuevo intento
// después de un error inesperado al enviar el comentario principal.
const commentRetrySeconds = ref(0);

// Timer que actualiza la cuenta regresiva del bloqueo temporal.
const commentRetryTimer = ref(null);

// Indica si el bloqueo actual corresponde al límite general de comentarios
// y no solamente a la pausa corta aplicada después de un error inesperado.
const commentRateLimitActive = ref(false);

// Texto mostrado por el botón mientras permanece bloqueado por un reintento.
const commentRetryLabel = computed(() => {
    if (commentRetrySeconds.value <= 0) {
        return 'Enviar comentario';
    }

    // Para bloqueos largos muestra minutos en lugar de cientos de segundos.
    if (commentRetrySeconds.value > 60) {
        const minutes = Math.ceil(commentRetrySeconds.value / 60);

        return `Reintentá en ${minutes} min`;
    }

    return `Reintentá en ${commentRetrySeconds.value} s`;
});

// Texto mostrado por el botón de respuesta cuando existe un bloqueo temporal.
const replyRetryLabel = computed(() => {
    if (commentRetrySeconds.value <= 0) {
        return 'Enviar respuesta';
    }

    // Para bloqueos largos muestra minutos en lugar de cientos de segundos.
    if (commentRetrySeconds.value > 60) {
        const minutes = Math.ceil(commentRetrySeconds.value / 60);

        return `Reintentá en ${minutes} min`;
    }

    return `Reintentá en ${commentRetrySeconds.value} s`;
});

// Mensaje actualizado del límite general utilizado tanto por comentarios
// principales como por respuestas.
const commentRateLimitMessage = computed(() => {
    if (
        !commentRateLimitActive.value
        || commentRetrySeconds.value <= 0
    ) {
        return '';
    }

    const minutes = Math.max(
        1,
        Math.ceil(commentRetrySeconds.value / 60)
    );

    return `Alcanzaste el límite de intentos para comentar. Podrás volver a intentarlo en ${minutes} minutos.`;
});

// Formulario reutilizado para responder un comentario principal.
const reply = useForm({
    body: '',
    parent_id: null,
});
// Formulario usado cuando el usuario edita un comentario pendiente.
const editComment = useForm({
    body: '',
});
// Lista local de comentarios visibles; se actualiza al cargar más, responder, editar o eliminar.
const comments = ref([...(props.article.comments || [])]);
// ID del comentario principal al que se está respondiendo.
const replyingToId = ref(null);

// ID del comentario principal cuya respuesta acaba de enviarse y todavía está
// atravesando la moderación automática. Permite reemplazar el formulario por un
// aviso local, exactamente debajo del hilo donde el lector realizó la acción.
const awaitingAutomaticReplyParentId = ref(null);

// ID del comentario que está en modo edición.
const editingCommentId = ref(null);
// Comentario pendiente de confirmación en la ventana modal de eliminación.
const pendingDeleteComment = ref(null);

// Timer utilizado únicamente mientras existe alguna participación propia en
// `processing`. Consulta un endpoint mínimo y se detiene apenas Node termina.
const moderationStatusTimer = ref(null);

// Cubre el instante posterior al submit mientras Inertia trae las props nuevas.
// El lector ya debe ver que su comentario esta en revision automatica.
const awaitingAutomaticModeration = ref(Boolean(props.article.has_processing_comments));

// Variante especifica del comentario principal para ocultar el formulario
// principal hasta que Node termine de evaluar esa participacion.
const awaitingAutomaticMainModeration = ref(Boolean(props.article.has_processing_main_comment));

/*
 * Resultado final de la ultima participacion que termino rechazada por el
 * pipeline automatico durante esta visita.
 *
 * Se conserva el objeto completo en una unica fuente de verdad en lugar de
 * mantener por separado un mensaje y otra bandera booleana. De esa manera no
 * puede ocurrir el estado contradictorio que teniamos antes:
 *
 *     automaticModerationNotice = "rechazado"
 *     rejectedAutomaticMainComment = false
 *
 * que terminaba mostrando al mismo tiempo el rechazo y el cartel incorrecto
 * "Ya tenes un comentario principal en esta noticia".
 *
 * El resultado se limpia cuando el usuario inicia una participacion nueva o
 * cuando navega hacia otra noticia.
 */
const automaticModerationResult = ref(null);

/*
 * Texto publico derivado DIRECTAMENTE del mismo resultado automatico.
 *
 * No existe un segundo estado independiente para el mensaje. Si el resultado
 * dice que el comentario principal fue rechazado, este aviso y la habilitacion
 * del formulario cambian juntos en el mismo ciclo reactivo de Vue.
 */
const automaticModerationNotice = computed(() => {
    const result = automaticModerationResult.value;

    if (
        !result
        || result.status !== 'rejected'
        || result.type !== 'comment'
    ) {
        return '';
    }

    return 'Tu comentario no pudo publicarse porque incumple las normas de participación.';
});

function hasRejectedAutomaticReplyNoticeFor(parentCommentId) {
    const result = automaticModerationResult.value;

    return (
        result?.status === 'rejected'
        && result?.type === 'reply'
        && Number(result?.parent_id || 0) === Number(parentCommentId)
    );
}

function clearRejectedAutomaticReplyNoticeFor(parentCommentId) {
    if (hasRejectedAutomaticReplyNoticeFor(parentCommentId)) {
        automaticModerationResult.value = null;
    }
}

/*
 * Un comentario principal rechazado deja de ser activo inmediatamente.
 *
 * Esta condicion se deriva del MISMO objeto que genera el aviso anterior.
 * Por lo tanto, mientras el usuario vea el mensaje de rechazo de un comentario
 * principal, `hasActiveOwnMainComment` no puede volver a considerar valida una
 * prop vieja de Inertia que todavia diga `has_active_main_comment = true`.
 */
const rejectedAutomaticMainComment = computed(() => (
    automaticModerationResult.value?.status === 'rejected'
    && automaticModerationResult.value?.type === 'comment'
));

/*
 * IDs de comentarios principales sobre los que la última respuesta del usuario
 * fue rechazada automáticamente.
 *
 * Esta lista funciona como una corrección local frente a `item.has_own_reply`
 * cuando ese dato todavía pertenece a la carga anterior de Inertia.
 *
 * Si una respuesta fue rechazada, ya no debe contar como respuesta activa y el
 * botón "Responder" tiene que volver a aparecer aunque el objeto `item` todavía
 * conserve temporalmente `has_own_reply = true`.
 */
const rejectedAutomaticReplyParentIds = ref([]);

// ID exacto de la ultima participacion enviada al pipeline automatico.
// Consultar esa fila evita confundir el resultado con moderaciones anteriores.
const pendingAutomaticCommentId = ref(Number(page.props.flash?.automatic_comment_id || 0));

// Evita superponer dos consultas si una respuesta de red tarda más que el
// intervalo normal del polling.
const moderationStatusRequestInFlight = ref(false);

// Cuatro segundos mantienen la interfaz razonablemente actualizada sin
// convertir el seguimiento temporal en una consulta agresiva contra Laravel/MySQL.
const MODERATION_STATUS_POLL_MS = 4000;

// Comentario que el lector eligió reportar.
const pendingReportComment = ref(null);

// Motivo seleccionado dentro del modal de reporte.
const reportReason = ref('');
// Evita enviar el mismo reporte varias veces mientras Laravel responde.
const reportProcessing = ref(false);
// Errores esperados relacionados con el motivo seleccionado.
const reportReasonError = ref('');
// Mensaje amigable para errores de la operación de reporte.
const reportOperationError = ref('');
// Mensaje temporal mostrado después de enviar correctamente un reporte.
const reportSuccessMessage = ref('');
// Timer utilizado para ocultar automáticamente el mensaje de éxito.
const reportSuccessTimer = ref(null);

// Orden activo de la sección de comentarios.
const sortMode = ref('recent');
// Bloquea el botón "Ver más comentarios" mientras llega la siguiente tanda.
const loadingMoreComments = ref(false);
// Guarda qué comentarios están cargando más respuestas.
const loadingReplyIds = ref({});
// Guarda qué comentarios están siendo eliminados para deshabilitar sus botones.
const deletingCommentIds = ref({});

// Guarda qué comentarios están procesando una acción de "me gusta".
const likingCommentIds = ref({});
// Guarda un posible error de "me gusta" asociado a cada comentario.
const likeOperationErrors = ref({});

// Cantidad de segundos que faltan para que Laravel vuelva a permitir
// acciones de "me gusta" después de alcanzar el límite general.
const likeRetrySeconds = ref(0);

// Timer utilizado para actualizar la cuenta regresiva del bloqueo
// de "me gusta" una vez por segundo.
const likeRetryTimer = ref(null);

// Mensaje dinámico mostrado mientras permanece activo el límite
// general de acciones de "me gusta".
const likeRateLimitMessage = computed(() => {
    if (likeRetrySeconds.value <= 0) {
        return '';
    }

    /*
     * Mientras queda más de un minuto se muestra una cantidad
     * legible de minutos.
     *
     * Ejemplo:
     * 247 segundos -> "5 minutos".
     */
    if (likeRetrySeconds.value > 60) {
        const minutes = Math.ceil(
            likeRetrySeconds.value / 60
        );

        return `Alcanzaste el límite de acciones de "Me gusta". Podrás volver a intentarlo en ${minutes} minutos.`;
    }

    /*
     * Durante el último minuto se muestran los segundos para que
     * el usuario pueda ver terminar la cuenta regresiva en tiempo real.
     */
    return `Alcanzaste el límite de acciones de "Me gusta". Podrás volver a intentarlo en ${likeRetrySeconds.value} segundos.`;
});

// Mensaje mostrado dentro del modal si no se pudo eliminar un comentario.
const deleteOperationError = ref('');

// Offset de comentarios principales ya cargados; las respuestas no cuentan en este número.
const mainCommentsOffset = ref(props.article.comments_next_offset || comments.value.length);
// Indica si todavía hay más comentarios principales para pedir al backend.
const mainCommentsHasMore = ref(Boolean(props.article.comments_has_more));
// Total visible de comentarios y respuestas que muestra el encabezado de la sección.
const commentsTotalCount = ref(props.article.comments_total_count || 0);
// ID resaltado cuando se llega desde la grilla "Mis comentarios".
const highlightedCommentId = ref(null);
// Evita volver a mover la página si el comentario enlazado ya quedó enfocado.
const hasCenteredLinkedComment = ref(false);
// Timer que apaga el resaltado visual del comentario enlazado.
const linkedCommentHighlightTimer = ref(null);

// Mínimo y máximo permitidos por Laravel para comentarios y respuestas.
const commentMinLength = 8;
const commentMaxLength = 1200;
// Cantidad de caracteres del comentario principal.
const commentBodyLength = computed(() => comment.body.length);
// Cantidad de caracteres de la respuesta en edición.
const replyBodyLength = computed(() => reply.body.length);
// Cuenta los caracteres escritos al editar un comentario o una respuesta.
const editCommentBodyLength = computed(() => editComment.body.length);
// Habilita el envío del comentario principal solo cuando hay texto real suficiente.
const canSubmitComment = computed(() => {
    const length = normalizedCommentLength(comment.body);

    return length >= commentMinLength && length <= commentMaxLength;
});
// Habilita el envío de respuestas solo cuando hay texto real suficiente.
const canSubmitReply = computed(() => {
    const length = normalizedCommentLength(reply.body);

    return length >= commentMinLength && length <= commentMaxLength;
});
// Habilita el guardado de ediciones solo cuando el texto editado sigue siendo válido.
const canSubmitEdit = computed(() => {
    const length = normalizedCommentLength(editComment.body);

    return length >= commentMinLength && length <= commentMaxLength;
});
// Usuario autenticado compartido por Laravel.
const currentUser = computed(() => page.props.auth?.user);
// Permite mostrar formularios solo cuando hay sesión iniciada.
const isAuthenticated = computed(() => Boolean(currentUser.value));

// Permisos de participación pública calculados por Laravel para esta noticia.
const commentPermissions = computed(() => props.article.comment_permissions || {});

// Solo los lectores pueden iniciar un comentario principal.
const canCreateMainComment = computed(() => Boolean(
    commentPermissions.value.can_create_main
));

// Lectores, el autor de la noticia y editores pueden responder según su rol.
const canReplyToComments = computed(() => Boolean(
    commentPermissions.value.can_reply
));

// Los "me gusta" quedan reservados para las cuentas públicas de lectores.
const canLikeComments = computed(() => Boolean(
    commentPermissions.value.can_like
));

// Nombre público que tendrá una respuesta escrita por una cuenta interna.
// Por ejemplo: "Autor de la nota" para un periodista o "Equipo TRAMA" para un editor.
const replyIdentity = computed(() => commentPermissions.value.reply_identity || '');

// Diferencia a un periodista que está leyendo una nota ajena de las cuentas administrativas.
const isNonAuthorJournalist = computed(() => (
    currentUser.value?.role === 'journalist'
    && !canCreateMainComment.value
    && !canReplyToComments.value
));

// Indica si existe cualquier participación propia todavía en `processing`,
// incluido un comentario principal o una respuesta. Esta bandera no muestra
// contenido: solamente mantiene activo el seguimiento liviano hasta que Node
// termina y Laravel puede refrescar el resultado final.
const hasProcessingOwnComments = computed(() => Boolean(
    props.article.has_processing_comments || awaitingAutomaticModeration.value
));

// Determina si el usuario ya tiene un comentario principal enviado realmente a
// moderación humana después de terminar el análisis automático.
const hasPendingOwnMainComment = computed(() => {
    /*
     * Si el comentario principal que estábamos siguiendo terminó rechazado,
     * cualquier `has_pending_main_comment` viejo de Inertia deja de representar
     * el estado actual. El rechazo cerró esa participación y habilita un intento
     * nuevo inmediatamente.
     */
    if (rejectedAutomaticMainComment.value) {
        return false;
    }

    // El backend informa la regla completa, incluso si el comentario pendiente
    // no está dentro de la tanda actualmente cargada en pantalla.
    if (props.article.has_pending_main_comment) {
        return true;
    }

    // Respaldo visual: si el comentario pendiente está en la lista local, también
    // se bloquea el formulario principal inmediatamente.
    return comments.value.some((item) => item.visible_only_to_author && !item.parent_id);
});

// Respaldo local para cuando la tanda JSON ya trajo el comentario aprobado o
// pendiente pero las props de Inertia todavia no terminaron de refrescar.
const hasLocalActiveOwnMainComment = computed(() => comments.value.some((item) => (
    item.is_own_comment
    && !item.parent_id
    && ['approved', 'pending'].includes(item.status)
)));

// Determina si el comentario principal del lector todavía está atravesando
// la validación automática. Si la lista local ya contiene el resultado aprobado
// o pendiente, el aviso de "recibido" se apaga aunque las props lleguen tarde.
const hasProcessingOwnMainComment = computed(() => {
    /*
     * Un rechazo automático ya es un estado final. Aunque una prop vieja todavía
     * diga `has_processing_main_comment = true`, no mostramos nuevamente el aviso
     * de "Comentario recibido" después de conocer el rechazo.
     */
    if (rejectedAutomaticMainComment.value) {
        return false;
    }

    return Boolean(
        (props.article.has_processing_main_comment || awaitingAutomaticMainModeration.value)
        && !hasLocalActiveOwnMainComment.value
    );
});

// Un comentario principal processing, pendiente o aprobado bloquea un segundo
// comentario principal en la misma noticia. Laravel informa la regla completa y
// el estado local cubre el instante posterior al envio, antes del primer refresh.
const hasActiveOwnMainComment = computed(() => {
    /*
     * Un rechazo automatico acaba de cerrar el comentario principal que estaba
     * en `processing`. Durante unos milisegundos Inertia puede conservar todavia
     * `has_active_main_comment = true` de la respuesta anterior. Esa bandera ya
     * no representa la realidad y no debe ocultar el formulario ni mostrar el
     * aviso de "Ya tenés un comentario principal".
     *
     * La anulacion es solamente local y transitoria: despues del refresh de
     * `article`, Laravel vuelve a ser la fuente de verdad.
     */
    if (rejectedAutomaticMainComment.value) {
        return false;
    }

    return Boolean(
        props.article.has_active_main_comment
        || awaitingAutomaticMainModeration.value
        || hasLocalActiveOwnMainComment.value
    );
});

// URL actual usada para volver a la noticia después de login o registro.
const articleReturnPath = computed(() => {
    if (typeof window === 'undefined') {
        return `/noticias/${props.article.slug}`;
    }

    return `${window.location.pathname}${window.location.search}`;
});
// Ordena los comentarios cargados según la pestaña seleccionada.
function isOwnMainComment(item) {
    return Boolean(item?.is_own_comment && !item?.parent_id);
}

function compareOwnMainFirst(first, second) {
    const firstIsOwnMain = isOwnMainComment(first);
    const secondIsOwnMain = isOwnMainComment(second);

    if (firstIsOwnMain === secondIsOwnMain) {
        return 0;
    }

    return firstIsOwnMain ? -1 : 1;
}

function uniqueCommentsById(items) {
    const seen = new Set();

    return items.filter((item) => {
        if (!item?.id || seen.has(item.id)) {
            return false;
        }

        seen.add(item.id);
        return true;
    });
}

const sortedComments = computed(() => {
    const loadedComments = uniqueCommentsById(comments.value);

    if (sortMode.value === 'valued') {
        return loadedComments.sort((first, second) => {
            const ownCommentDifference = compareOwnMainFirst(first, second);

            if (ownCommentDifference !== 0) {
                return ownCommentDifference;
            }

            const valueDifference = (second.likes_count || 0) - (first.likes_count || 0);

            if (valueDifference !== 0) {
                return valueDifference;
            }

            return new Date(second.created_at).getTime() - new Date(first.created_at).getTime();
        });
    }

    if (sortMode.value === 'oldest') {
        return loadedComments.sort((first, second) => {
            const ownCommentDifference = compareOwnMainFirst(first, second);

            if (ownCommentDifference !== 0) {
                return ownCommentDifference;
            }

            return new Date(first.created_at).getTime() - new Date(second.created_at).getTime();
        });
    }

    return loadedComments.sort((first, second) => {
        const ownCommentDifference = compareOwnMainFirst(first, second);

        if (ownCommentDifference !== 0) {
            return ownCommentDifference;
        }

        return new Date(second.created_at).getTime() - new Date(first.created_at).getTime();
    });
});
// Alias semántico para la lista que se renderiza en el template.
const visibleComments = computed(() => sortedComments.value);
// Total visible de comentarios mostrado en el encabezado.
const commentsCount = computed(() => commentsTotalCount.value);

// Los controles de orden sólo tienen utilidad si existen al menos dos hilos
// principales. El total general incluye respuestas, pero éstas no participan
// del orden de "Más recientes / Más valorados / Más antiguos".
const hasSortableMainComments = computed(() => (
    comments.value.length > 1
    || mainCommentsHasMore.value
));

// Imagen principal de la nota o fallback.
const coverImage = computed(() => props.article.cover_image || '/images/brand/noticia-default.webp');
// Avatar del autor o fallback.
const authorAvatar = computed(() => props.article.author.avatar || '/images/brand/avatar-default.webp');
// Banner 300x250 elegido por Laravel para la parte superior de la barra lateral.
const sidebarTopAdvertisement = computed(() => props.advertisements?.sidebar_top);
// Banner 300x600 elegido por Laravel para la barra lateral fija durante el scroll.
const sidebarBottomAdvertisement = computed(() => props.advertisements?.sidebar_bottom);
// Título usado por el documento y metadatos sociales.
const pageTitle = computed(() => props.article.title);
// Descripción usada por el documento y metadatos sociales.
const pageDescription = computed(() => props.article.excerpt);
// Fecha pública de publicación mostrada en la cabecera de la noticia.
const publishedAt = computed(() => formatDateTime(props.article.published_at));

// Normaliza el formato mientras el usuario escribe:
// conserva un Enter, permite dos y reduce tres o más a dos.
function normalizePublicCommentTyping(value) {
    return String(value || '')
        .replace(/\r\n?/g, '\n')
        .replace(/[ \t]*\n[ \t]*/g, '\n')
        .replace(/\n{3,}/g, '\n\n');
}

// Representación definitiva que se envía a Laravel.
// Además de limitar saltos, elimina espacio sobrante en los extremos.
function normalizePublicCommentBody(value) {
    return normalizePublicCommentTyping(value).trim();
}

// Aplica la normalización directamente al textarea en cada input.
// Así comentario, respuesta y edición comparten exactamente el mismo criterio.
function handlePublicCommentInput(formState, event) {
    const normalized = normalizePublicCommentTyping(
        event?.target?.value ?? formState.body,
    ).slice(0, commentMaxLength);

    formState.body = normalized;
    formState.clearErrors('body');

    if (event?.target && event.target.value !== normalized) {
        event.target.value = normalized;
    }
}

// Cuenta solo el texto que realmente se enviaría después de normalizarlo.
function normalizedCommentLength(value) {
    return normalizePublicCommentBody(value).length;
}

// Formatea fecha y hora larga para los metadatos superiores de la nota.
function formatDateTime(value) {
    if (!value) {
        return '';
    }

    const date = new Date(value);
    const formattedDate = new Intl.DateTimeFormat('es-AR', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
    }).format(date);
    const formattedTime = new Intl.DateTimeFormat('es-AR', {
        hour: '2-digit',
        hour12: false,
        minute: '2-digit',
    }).format(date);

    return `${formattedDate}, ${formattedTime}`;
}

// Genera una clave independiente por usuario para conservar el bloqueo
// aunque cambie de noticia o recargue la página.
function commentRetryStorageKey() {
    const userId = page.props.auth?.user?.id;

    return userId
        ? `trama-public-comment-retry-until:${userId}`
        : null;
}

// Detiene la cuenta regresiva utilizada para bloquear temporalmente
// nuevos intentos de comentarios y respuestas.
function clearCommentRetryCooldown(removeStoredLimit = true) {
    // Si hay un interval activo, lo cancela para evitar que siga
    // descontando segundos después de limpiar el bloqueo.
    if (commentRetryTimer.value) {
        window.clearInterval(commentRetryTimer.value);
        commentRetryTimer.value = null;
    }

    // Restablece el contador visible del bloqueo.
    commentRetrySeconds.value = 0;

    // Indica que ya no hay un rate limit general activo.
    commentRateLimitActive.value = false;

    // Obtiene la clave de almacenamiento correspondiente al usuario actual.
    const storageKey = commentRetryStorageKey();

    // Cuando corresponde, elimina también el vencimiento guardado
    // en localStorage para que el bloqueo no se restaure al recargar.
    if (
        removeStoredLimit
        && storageKey
        && typeof window !== 'undefined'
    ) {
        window.localStorage.removeItem(storageKey);
    }
}

// Inicia un bloqueo temporal.
//
// Los errores inesperados utilizan solamente 5 segundos. Cuando Laravel
// informa un rate limit, también se conserva su vencimiento en el navegador
// para mantener el bloqueo al cambiar de noticia o recargar la página.
function startCommentRetryCooldown(seconds = 5, persistRateLimit = false) {
    // Limpia cualquier contador anterior sin borrar todavía un posible
    // vencimiento persistido hasta decidir qué hacer con el nuevo bloqueo.
    clearCommentRetryCooldown(false);

    // Guarda la cantidad de segundos que faltan para permitir otro intento.
    commentRetrySeconds.value = seconds;

    // Marca si este bloqueo corresponde al rate limit general del backend.
    commentRateLimitActive.value = persistRateLimit;

    // Obtiene la clave independiente del usuario actual.
    const storageKey = commentRetryStorageKey();

    // localStorage solo existe en el navegador, por eso se comprueba
    // también que window esté disponible.
    if (
        storageKey
        && typeof window !== 'undefined'
    ) {
        if (persistRateLimit) {
            /*
             * EXCEPCIÓN TÉCNICA AL RELOJ EDITORIAL:
             * un rate limit de 5 minutos debe durar 5 minutos reales para el
             * navegador. Date.now() se usa solamente para esta cuenta regresiva
             * y nunca para fechar comentarios, noticias o acciones editoriales.
             */
            const retryUntil = Date.now() + (seconds * 1000);

            // Guarda el vencimiento para poder reconstruir el contador
            // si el usuario recarga o entra a otra noticia.
            window.localStorage.setItem(
                storageKey,
                String(retryUntil)
            );
        } else {
            // Los bloqueos cortos de errores inesperados no deben persistir
            // entre páginas ni después de una recarga.
            window.localStorage.removeItem(storageKey);
        }
    }

    // Descuenta un segundo por vez mientras el bloqueo permanece activo.
    commentRetryTimer.value = window.setInterval(() => {
        commentRetrySeconds.value -= 1;

        // Cuando llega a cero, limpia completamente el estado y cualquier
        // vencimiento persistido asociado al usuario.
        if (commentRetrySeconds.value <= 0) {
            clearCommentRetryCooldown();
        }
    }, 1000);
}

// Recupera un rate limit todavía vigente cuando el usuario vuelve a entrar
// a una noticia o recarga la página.
function restoreCommentRetryCooldown() {
    // Obtiene la clave usada para guardar el vencimiento del usuario actual.
    const storageKey = commentRetryStorageKey();

    // Si no hay usuario identificado o no estamos en el navegador,
    // no existe ningún bloqueo que pueda restaurarse.
    if (
        !storageKey
        || typeof window === 'undefined'
    ) {
        return;
    }

    // Lee desde localStorage el momento exacto en que debe terminar el bloqueo.
    const retryUntil = Number(
        window.localStorage.getItem(storageKey) || 0
    );

    // Calcula cuántos segundos faltan realmente desde el momento actual.
    const remainingSeconds = Math.ceil(
        (retryUntil - Date.now()) / 1000
    );

    // Si el vencimiento todavía está vigente, reconstruye el contador
    // y vuelve a marcarlo como un rate limit persistente.
    if (remainingSeconds > 0) {
        startCommentRetryCooldown(remainingSeconds, true);
        return;
    }

    // Si el vencimiento ya pasó, elimina el dato viejo para no intentar
    // restaurarlo nuevamente en futuras cargas.
    window.localStorage.removeItem(storageKey);
}

// Envía un comentario principal y limpia el textarea si Laravel lo acepta.
function submitComment() {
    // Impide repetir el envío mientras Laravel todavía está procesando
    // la solicitud o mientras permanece activo el bloqueo temporal.
    if (
        comment.processing
        || commentRetrySeconds.value > 0
    ) {
        return;
    }

    // La UI envía la misma representación que Laravel volverá a normalizar.
    // Así los detectores reciben un texto estable aunque el usuario haya escrito
    // muchos Enter seguidos.
    comment.body = normalizePublicCommentBody(comment.body);

    // Inertia manda el comentario con un POST HTTP normal a Laravel.
    // Laravel valida, aplica moderación previa y devuelve la noticia actualizada
    // con los comentarios visibles como nuevas props de esta pantalla Vue.
    comment.post(route('comments.store', props.article.id), {
        preserveScroll: true,

        // Si Laravel aceptó el comentario, limpia el textarea y cualquier
        // bloqueo temporal que pudiera haber quedado activo.
        onSuccess: (visitPage) => {
            clearCommentRetryCooldown();
            comment.reset('body');
            // Un intento nuevo reemplaza cualquier rechazo automatico anterior.
            automaticModerationResult.value = null;
            pendingAutomaticCommentId.value = Number(
                visitPage.props.flash?.automatic_comment_id
                || page.props.flash?.automatic_comment_id
                || 0
            );

            /*
             * El backend ya guardo el comentario como processing. Marcamos la espera
             * local y arrancamos el polling sin depender solamente del watcher de props.
             */
            awaitingAutomaticModeration.value = true;
            awaitingAutomaticMainModeration.value = true;

            nextTick(() => {
                startModerationStatusPolling();
            });
        },

        // Los errores de operación activan un bloqueo temporal antes de permitir
        // un nuevo intento.
        //
        // Si Laravel informa un tiempo de espera por rate limit, se respeta ese período.
        // En los demás errores inesperados se aplica una pausa corta de 5 segundos.
        //
        // No se aplica a errores normales de validación, porque en esos casos
        // el usuario debe poder corregir el contenido y volver a enviarlo.
        onError: (errors) => {
            /*
            * Si Laravel informa cuánto falta para que venza el límite general,
            * mantiene bloqueado el formulario durante todo ese período.
            */
            const retryAfterSeconds = Number(errors.retry_after_seconds || 0);

            if (retryAfterSeconds > 0) {
                startCommentRetryCooldown(retryAfterSeconds, true);
                return;
            }

            /*
            * Los demás errores inesperados conservan solamente la pausa corta
            * de 5 segundos antes de permitir un nuevo intento.
            */
            if (errors.operation) {
                startCommentRetryCooldown(5);
            }
        },
    });
}

/*
 * Indica si el último intento de respuesta sobre este comentario principal fue
 * rechazado por el filtro automático.
 *
 * Se mantiene como estado local hasta que el usuario vuelva a responder o cambie
 * de noticia. De esta forma un `has_own_reply = true` viejo no puede ocultar el
 * botón "Responder" después de un rechazo.
 */
function hasRejectedAutomaticReplyFor(parentCommentId) {
    return rejectedAutomaticReplyParentIds.value.includes(
        Number(parentCommentId)
    );
}

/*
 * Registra localmente que la respuesta enviada sobre un comentario principal
 * quedó rechazada y, por lo tanto, dejó de ser una respuesta activa.
 */
function rememberRejectedAutomaticReply(parentCommentId) {
    const normalizedId = Number(parentCommentId);

    if (
        !Number.isInteger(normalizedId)
        || normalizedId <= 0
        || hasRejectedAutomaticReplyFor(normalizedId)
    ) {
        return;
    }

    rejectedAutomaticReplyParentIds.value = [
        ...rejectedAutomaticReplyParentIds.value,
        normalizedId,
    ];
}

/*
 * Elimina la corrección local cuando el usuario inicia un nuevo intento sobre
 * ese mismo comentario principal.
 */
function forgetRejectedAutomaticReply(parentCommentId) {
    const normalizedId = Number(parentCommentId);

    rejectedAutomaticReplyParentIds.value =
        rejectedAutomaticReplyParentIds.value.filter(
            (id) => id !== normalizedId
        );
}

// Abre el formulario de respuesta únicamente cuando el usuario
// todavía puede interactuar con ese comentario principal.
function startReply(item) {
    // El rol debe tener permiso para responder y el comentario no puede
    // haber sido reportado previamente por esta misma cuenta.
    if (
        !canReplyToComments.value
        || item.is_own_comment
        || item.reported_by_current_user
    ) {
        return;
    }

    /*
     * Si el intento anterior sobre este hilo fue rechazado, al abrir un nuevo
     * formulario dejamos de necesitar aquella corrección local.
     */
    forgetRejectedAutomaticReply(item.id);
    clearRejectedAutomaticReplyNoticeFor(item.id);

    replyingToId.value = replyingToId.value === item.id ? null : item.id;
    reply.reset('body');
    reply.clearErrors();
    reply.parent_id = item.id;
}

// Indica si debe mostrarse el botón "Responder" de un comentario principal.
function canShowReplyButton(item) {
    // Laravel determina si el rol actual puede responder dentro de esta noticia.
    if (!canReplyToComments.value) {
        return false;
    }

    // Después de reportar un comentario, esta cuenta deja de poder
    // iniciar nuevas respuestas sobre ese mismo contenido.
    if (item.is_own_comment) {
        return false;
    }

    if (item.reported_by_current_user) {
        return false;
    }

    // Mientras la respuesta recién enviada todavía está siendo revisada por el
    // pipeline automático, no se vuelve a ofrecer "Responder" sobre el mismo
    // comentario. Así evitamos un segundo intento antes de conocer el resultado.
    if (awaitingAutomaticReplyParentId.value === item.id) {
        return false;
    }

    /*
     * Si Laravel/JSON todavía conserva `has_own_reply = true`, normalmente se
     * bloquea una segunda respuesta.
     *
     * Excepción: si acabamos de recibir el resultado automático `rejected` para
     * este mismo hilo, esa respuesta ya dejó de estar activa y el valor viejo se
     * ignora hasta que llegue la siguiente sincronización.
     */
    if (
        item.has_own_reply
        && !hasRejectedAutomaticReplyFor(item.id)
    ) {
        return false;
    }

    // Si el formulario de respuesta ya está abierto para este comentario, el
    // botón desaparece para no duplicar la misma acción en pantalla.
    return replyingToId.value !== item.id;
}

// Pone un comentario propio pendiente en modo edición.
function startEdit(item) {
    if (!item.can_edit) {
        return;
    }

    replyingToId.value = null;
    editingCommentId.value = item.id;
    editComment.body = item.body;
    editComment.clearErrors();
}

// Cierra la edición y limpia errores del formulario.
function cancelEdit() {
    editingCommentId.value = null;
    editComment.reset('body');
    editComment.clearErrors();
}

// Guarda la edición de un comentario o respuesta pendiente.
function submitEdit(item) {
    editComment.body = normalizePublicCommentBody(editComment.body);

    editComment.patch(route('comments.update', item.id), {
        preserveScroll: true,
        onSuccess: (visitPage) => {
            cancelEdit();

            /*
             * La edición vuelve a colocar esta misma participación en "processing".
             * Se conserva su ID exacto para consultar el resultado del nuevo análisis
             * aunque el worker termine antes de que Inertia alcance a actualizar las
             * banderas generales de la noticia.
             */
            pendingAutomaticCommentId.value = Number(
                visitPage.props.flash?.automatic_comment_id
                || item.id
                || 0
            );

            // Un análisis nuevo reemplaza cualquier rechazo automático anterior.
            automaticModerationResult.value = null;
            awaitingAutomaticModeration.value = true;

            if (item.parent_id) {
                /*
                 * Si se editó una respuesta, recordamos su comentario padre para
                 * restaurar inmediatamente "Responder" si el nuevo análisis la
                 * rechaza. Es la misma referencia utilizada al enviar una respuesta.
                 */
                awaitingAutomaticReplyParentId.value = Number(item.parent_id);
            } else {
                // Una edición del comentario principal vuelve a bloquear ese envío
                // solamente mientras esta revisión automática permanezca en curso.
                awaitingAutomaticReplyParentId.value = null;
                awaitingAutomaticMainModeration.value = true;
            }

            nextTick(() => {
                startModerationStatusPolling();
            });
        },
    });
}

// Envía una respuesta a un comentario principal.
function submitReply(parentComment) {
    // Impide repetir la solicitud mientras Laravel está procesando
    // la respuesta o mientras continúa vigente un bloqueo general.
    if (
        reply.processing
        || commentRetrySeconds.value > 0
        || parentComment.is_own_comment
    ) {
        return;
    }

    reply.parent_id = parentComment.id;
    reply.body = normalizePublicCommentBody(reply.body);

    // Inertia envía la respuesta mediante el mismo endpoint utilizado por los
    // comentarios principales, por lo que comparte también su rate limit.
    reply.post(route('comments.store', props.article.id), {
        preserveScroll: true,

        // Si Laravel acepta la respuesta, limpia el formulario y lo reemplaza
        // por un aviso local mientras Node ejecuta la moderación automática.
        onSuccess: (visitPage) => {
            clearCommentRetryCooldown();

            /*
             * Comenzó un intento nuevo sobre este hilo. Desde ahora vuelve a
             * bloquearse "Responder" mientras esa nueva fila esté en processing.
             */
            forgetRejectedAutomaticReply(parentComment.id);

            reply.reset('body', 'parent_id');
            replyingToId.value = null;
            awaitingAutomaticReplyParentId.value = parentComment.id;
            // Un intento nuevo reemplaza cualquier rechazo automatico anterior.
            automaticModerationResult.value = null;
            pendingAutomaticCommentId.value = Number(
                visitPage.props.flash?.automatic_comment_id
                || page.props.flash?.automatic_comment_id
                || 0
            );

            /*
             * Las respuestas tambien pasan por el pipeline automatico. Se sigue el
             * estado general, pero no se bloquea el formulario de comentario principal.
             */
            awaitingAutomaticModeration.value = true;

            nextTick(() => {
                startModerationStatusPolling();
            });
        },

        // Si Laravel devuelve el límite general, utiliza exactamente el mismo
        // bloqueo que el formulario de comentario principal.
        onError: (errors) => {
            const retryAfterSeconds = Number(
                errors.retry_after_seconds || 0
            );

            if (retryAfterSeconds > 0) {
                startCommentRetryCooldown(
                    retryAfterSeconds,
                    true
                );

                return;
            }

            // Los demás errores inesperados mantienen solamente
            // una pausa corta de 5 segundos.
            if (errors.operation) {
                startCommentRetryCooldown(5);
            }
        },
    });
}

// Indica si un comentario está esperando la respuesta del backend para "me gusta".
function isLikingComment(commentId) {
    return Boolean(likingCommentIds.value[commentId]);
}

// Genera una clave independiente por usuario para conservar el bloqueo
// general de "me gusta" aunque cambie de noticia o recargue la página.
function likeRetryStorageKey() {
    const userId = page.props.auth?.user?.id;

    return userId
        ? `trama-public-like-retry-until:${userId}`
        : null;
}

// Limpia el contador temporal utilizado por el límite de "me gusta".
function clearLikeRetryCooldown(removeStoredLimit = true) {
    // Detiene el interval si todavía continúa ejecutándose.
    if (likeRetryTimer.value) {
        window.clearInterval(likeRetryTimer.value);
        likeRetryTimer.value = null;
    }

    // Restablece el contador visible.
    likeRetrySeconds.value = 0;

    // Obtiene la clave correspondiente al usuario autenticado.
    const storageKey = likeRetryStorageKey();

    /*
     * Cuando el bloqueo terminó realmente, elimina también el momento
     * de vencimiento guardado en el navegador.
     */
    if (
        removeStoredLimit
        && storageKey
        && typeof window !== 'undefined'
    ) {
        window.localStorage.removeItem(storageKey);
    }
}

// Inicia la cuenta regresiva utilizando los segundos exactos
// enviados por Laravel cuando se alcanza el límite de "me gusta".
function startLikeRetryCooldown(seconds, storedRetryUntil = null) {
    // Detiene cualquier contador anterior sin borrar todavía
    // el vencimiento persistido.
    clearLikeRetryCooldown(false);

    // Normaliza el valor recibido para trabajar siempre con
    // una cantidad positiva y entera de segundos.
    const normalizedSeconds = Math.max(
        1,
        Math.ceil(Number(seconds) || 0)
    );

    /*
     * EXCEPCIÓN TÉCNICA AL RELOJ EDITORIAL:
     * el cooldown de "me gusta" mide una duración real de seguridad/uso. Por eso
     * Date.now() es correcto acá, pero no se utiliza para crear timestamps del
     * contenido de TRAMA.
     */
    const retryUntil = storedRetryUntil
        ? Number(storedRetryUntil)
        : Date.now() + (normalizedSeconds * 1000);

    // Muestra inmediatamente el tiempo restante.
    likeRetrySeconds.value = normalizedSeconds;

    // El límite de "me gusta" pertenece al usuario completo y no a un comentario
    // concreto. Por eso elimina cualquier mensaje individual que pudiera haber
    // quedado debajo de comentarios o respuestas antes de detectar el bloqueo.
    likeOperationErrors.value = {};

    // Obtiene la clave independiente del usuario.
    const storageKey = likeRetryStorageKey();

    /*
     * Guarda el momento exacto de vencimiento en localStorage.
     *
     * No se guarda "5 minutos", sino un timestamp. De esa manera,
     * si el usuario recarga después de dos minutos, Vue puede calcular
     * correctamente cuánto tiempo queda.
     */
    if (
        storageKey
        && typeof window !== 'undefined'
    ) {
        window.localStorage.setItem(
            storageKey,
            String(retryUntil)
        );
    }

    // Actualiza el contador una vez por segundo.
    likeRetryTimer.value = window.setInterval(() => {
        /*
         * Calcula nuevamente el tiempo contra el reloj real.
         *
         * Esto evita que el contador se atrase si el navegador reduce
         * la frecuencia de timers mientras la pestaña está en segundo plano.
         */
        const remainingSeconds = Math.ceil(
            (retryUntil - Date.now()) / 1000
        );

        likeRetrySeconds.value = Math.max(
            0,
            remainingSeconds
        );

        // Cuando el vencimiento llega a cero, vuelve a habilitar
        // todas las acciones de "me gusta".
        if (likeRetrySeconds.value <= 0) {
            clearLikeRetryCooldown();
        }
    }, 1000);
}

// Recupera un bloqueo de "me gusta" todavía vigente cuando el usuario
// recarga la página o entra a otra noticia.
function restoreLikeRetryCooldown() {
    const storageKey = likeRetryStorageKey();

    // Sin usuario autenticado o fuera del navegador no existe
    // ningún bloqueo visual que recuperar.
    if (
        !storageKey
        || typeof window === 'undefined'
    ) {
        return;
    }

    // Lee el momento exacto de vencimiento guardado anteriormente.
    const retryUntil = Number(
        window.localStorage.getItem(storageKey) || 0
    );

    // Calcula cuántos segundos continúan pendientes.
    const remainingSeconds = Math.ceil(
        (retryUntil - Date.now()) / 1000
    );

    if (remainingSeconds > 0) {
        // Reconstruye el contador manteniendo exactamente el mismo
        // momento de vencimiento que se guardó originalmente.
        startLikeRetryCooldown(
            remainingSeconds,
            retryUntil
        );

        return;
    }

    // Si ya venció, elimina cualquier dato viejo del navegador.
    window.localStorage.removeItem(storageKey);
}

// Actualiza el estado de procesamiento del "me gusta" de un comentario.
function setLikingComment(commentId, isLiking) {
    likingCommentIds.value = {
        ...likingCommentIds.value,
        [commentId]: isLiking,
    };
}

// Guarda o limpia el mensaje de error de "me gusta" de un comentario.
function setLikeOperationError(commentId, message = '') {
    likeOperationErrors.value = {
        ...likeOperationErrors.value,
        [commentId]: message,
    };
}

// Alterna el "me gusta" del usuario actual sobre un comentario aprobado.
function toggleLike(item) {
    /*
     * No permite iniciar otra solicitud cuando:
     * - la cuenta no puede utilizar "me gusta";
     * - el comentario todavía no es público;
     * - el usuario ya reportó ese comentario;
     * - ese mismo comentario está procesando una solicitud;
     * - permanece activo el límite general de "me gusta".
     */
    if (
        !canLikeComments.value
        || item.visible_only_to_author
        || item.is_own_comment
        || item.reported_by_current_user
        || isLikingComment(item.id)
        || likeRetrySeconds.value > 0
    ) {
        return;
    }

    // Marca solamente este comentario como procesando para impedir
    // clicks repetidos mientras Laravel responde.
    setLikingComment(item.id, true);

    // Limpia cualquier error anterior asociado al comentario.
    setLikeOperationError(item.id);

    router.post(route('comments.like', item.id), {}, {
        // Evita que la pantalla vuelva arriba después de votar.
        preserveScroll: true,

        onError: (errors) => {
            /*
             * Cuando Laravel informa cuánto falta para que termine
             * el rate limit, inicia el bloqueo global de "me gusta".
             */
            const retryAfterSeconds = Number(
                errors.retry_after_seconds || 0
            );

            if (retryAfterSeconds > 0) {
                // El contador utiliza exactamente el tiempo informado
                // por Laravel y lo conserva entre páginas.
                startLikeRetryCooldown(
                    retryAfterSeconds
                );

                /*
                 * El rate limit tendrá su propio mensaje dinámico global,
                 * por lo que no se deja un mensaje estático debajo
                 * de este comentario.
                 */
                setLikeOperationError(item.id);

                return;
            }

            // Cualquier otro problema continúa mostrándose solamente
            // debajo del comentario donde ocurrió.
            setLikeOperationError(
                item.id,
                errors.operation
                    || 'No pudimos actualizar tu "me gusta" en este momento. Intentá nuevamente.'
            );
        },

        // Al terminar la petición permite volver a utilizar este botón,
        // salvo que el límite global continúe activo.
        onFinish: () => {
            setLikingComment(item.id, false);
        },
    });
}

// Indica si ese comentario está esperando confirmación de borrado del backend.
function isDeletingComment(commentId) {
    return Boolean(deletingCommentIds.value[commentId]);
}

// Actualiza el mapa local de comentarios que están siendo eliminados.
function setDeletingComment(commentId, isDeleting) {
    deletingCommentIds.value = {
        ...deletingCommentIds.value,
        [commentId]: isDeleting,
    };
}

// Abre el modal estética de confirmación antes de borrar.
function deleteComment(item) {
    if (!item.can_delete || isDeletingComment(item.id)) {
        return;
    }

    deleteOperationError.value = '';
    pendingDeleteComment.value = item;
}

// Cierra el modal de eliminación sin tocar el comentario.
function cancelDeleteComment() {
    pendingDeleteComment.value = null;
    deleteOperationError.value = '';
}

// Confirma el borrado y espera a que Laravel quite el comentario.
function confirmDeleteComment() {
    const item = pendingDeleteComment.value;

    if (!item || isDeletingComment(item.id)) {
        return;
    }

    deleteOperationError.value = '';
    setDeletingComment(item.id, true);

    router.delete(route('comments.destroy', item.id), {
        preserveScroll: true,
        onSuccess: cancelDeleteComment,

        onError: (errors) => {
            deleteOperationError.value = errors.operation
                || 'No pudimos eliminar el comentario en este momento. Intentá nuevamente.';
        },

        onFinish: () => {
            setDeletingComment(item.id, false);
        },
    });
}

// Abre el modal únicamente cuando Laravel indicó que el comentario
// puede ser reportado por el usuario actual.
function startReport(item) {
    if (!item.can_report || reportProcessing.value) {
        return;
    }

    pendingReportComment.value = item;
    reportReason.value = '';
    reportReasonError.value = '';
    reportOperationError.value = '';
}

// Cierra el modal de reporte cuando no hay una solicitud en curso.
function cancelReport() {
    if (reportProcessing.value) {
        return;
    }

    pendingReportComment.value = null;
    reportReason.value = '';
    reportReasonError.value = '';
    reportOperationError.value = '';
}

// Envía el reporte sin recargar la noticia ni reemplazar la lista local
// de comentarios que el lector ya tiene cargada.
async function submitReport() {
    const item = pendingReportComment.value;

    if (
        !item
        || !reportReason.value
        || reportProcessing.value
    ) {
        return;
    }

    reportProcessing.value = true;
    reportReasonError.value = '';
    reportOperationError.value = '';

    try {
        const response = await window.axios.post(
            route('comments.reports.store', item.id),
            {
                reason: reportReason.value,
            }
        );

        /*
        * El comentario queda reportado inmediatamente en la interface.
        */
        item.can_report = false;
        item.reported_by_current_user = true;

        /*
        * Si el usuario tenía abierto el formulario de respuesta sobre este mismo
        * comentario, se cierra porque después de reportarlo ya no puede responderlo.
        */
        if (replyingToId.value === item.id) {
            replyingToId.value = null;
            reply.reset('body', 'parent_id');
            reply.clearErrors();
        }

        /*
        * Reportar elimina cualquier "me gusta" previo de esta misma cuenta.
        * Laravel devuelve el contador real después de realizar la operación.
        */
        item.liked_by_current_user = false;
        item.likes_count = Number(response.data.likes_count ?? item.likes_count ?? 0);

        /*
        * Muestra una confirmación temporal sin abrir otro modal.
        */
        reportSuccessMessage.value = response.data.message
            || 'Gracias por avisarnos. Recibimos tu reporte y será revisado por el equipo de TRAMA.';

        if (reportSuccessTimer.value) {
            window.clearTimeout(reportSuccessTimer.value);
        }

        reportSuccessTimer.value = window.setTimeout(() => {
            reportSuccessMessage.value = '';
            reportSuccessTimer.value = null;
        }, 5000);

        reportProcessing.value = false;
        cancelReport();
    } catch (error) {
        const errors = error.response?.data?.errors || {};

        reportReasonError.value = errors.reason?.[0] || '';

        reportOperationError.value = errors.operation?.[0]
            || (
                error.response?.status === 403
                    ? 'No podés reportar este comentario.'
                    : 'No pudimos enviar el reporte en este momento. Intentá nuevamente.'
            );
    } finally {
        reportProcessing.value = false;
    }
}

// Carga una tanda de comentarios principales desde el endpoint JSON.
async function loadCommentPage(reset = false) {
    if (loadingMoreComments.value) {
        return;
    }

    loadingMoreComments.value = true;

    try {
        // Pide al backend la siguiente tanda de comentarios principales.
        const response = await window.axios.get(route('comments.index', props.article.id), {
            params: {
                // Si cambió el orden, vuelve a pedir desde el principio.
                offset: reset ? 0 : mainCommentsOffset.value,
                // Mantiene en backend el mismo orden elegido en las pestañas.
                sort: sortMode.value,
            },
        });

        // Cuando se reinicia el orden, reemplaza la lista completa.
        // Cuando es "Ver más", agrega la nueva tanda al final.
        comments.value = reset
            ? uniqueCommentsById(response.data.comments)
            : uniqueCommentsById([...comments.value, ...response.data.comments]);
        // Guarda desde qué posición debe pedir la próxima tanda.
        mainCommentsOffset.value = response.data.next_offset;
        // Indica si todavía queda otra tanda de comentarios principales.
        mainCommentsHasMore.value = response.data.has_more;
        // Actualiza el total visible del encabezado de comentarios.
        commentsTotalCount.value = response.data.total_comments_count;
    } finally {
        // Rehabilita el botón aunque el pedido termine bien o con error.
        loadingMoreComments.value = false;
    }
}

// Pide la siguiente página de comentarios principales.
function showMoreComments() {
    loadCommentPage(false);
}

// Devuelve las respuestas ya cargadas para un comentario principal.
function visibleReplies(item) {
    return item.replies || [];
}

// Calcula cuántas respuestas quedan ocultas detrás del botón "Ver respuestas".
function hiddenReplyCount(item) {
    return item.replies_remaining || 0;
}

// Indica si un comentario está cargando más respuestas.
function isLoadingReplies(commentId) {
    return Boolean(loadingReplyIds.value[commentId]);
}

// Actualiza el mapa local de comentarios que están cargando respuestas.
function setRepliesLoading(commentId, isLoading) {
    loadingReplyIds.value = {
        ...loadingReplyIds.value,
        [commentId]: isLoading,
    };
}

// Carga otra tanda de respuestas para un comentario principal.
async function showMoreReplies(item) {
    if (isLoadingReplies(item.id)) {
        return;
    }

    setRepliesLoading(item.id, true);

    try {
        // Pide al backend la siguiente tanda de respuestas de este comentario.
        const response = await window.axios.get(route('comments.replies', item.id), {
            params: {
                // Continúa desde la cantidad de respuestas ya cargadas.
                offset: item.replies_next_offset || visibleReplies(item).length,
            },
        });

        // Agrega las respuestas nuevas debajo de las ya visibles.
        item.replies = [...visibleReplies(item), ...response.data.replies];
        // Guarda el próximo punto de inicio para seguir cargando respuestas.
        item.replies_next_offset = response.data.next_offset;
        // Cantidad de respuestas que quedan ocultas detrás del botón.
        item.replies_remaining = response.data.remaining;
        // Indica si el botón de cargar más respuestas debe seguir visible.
        item.replies_has_more = response.data.has_more;
    } finally {
        // Rehabilita solamente el botón del comentario que estaba cargando.
        setRepliesLoading(item.id, false);
    }
}

// Genera iniciales cortas para el avatar textual de cada comentario.
function commentInitials(name = '') {
    const parts = name.trim().split(/\s+/).filter(Boolean);

    return (parts[0]?.[0] || 'L') + (parts[1]?.[0] || '');
}

// Asigna un color estable al avatar textual según el nombre.
function commentAvatarStyle(name = '') {
    const colors = ['#29C7AC', '#4A8DFF', '#D6A23A', '#8A6DFF', '#D8323C'];
    const hash = [...name].reduce((total, char) => total + char.charCodeAt(0), 0);

    return { '--avatar-color': colors[hash % colors.length] };
}

/*
 * Muestra fechas relativas comparando contra el reloj editorial recibido de
 * Laravel. No usa Date.now() porque esa función pertenece al día real del
 * navegador y podría comparar 10/08 contra comentarios fechados el 19/07.
 */
function formatCommentTime(value) {
    if (!value) {
        return 'sin fecha';
    }

    const commentDate = new Date(value);
    const editorialNow = new Date(page.props.site?.editorial_now || '');
    const comparisonTime = Number.isNaN(editorialNow.getTime())
        ? commentDate.getTime()
        : editorialNow.getTime();

    const seconds = Math.max(0, Math.floor((comparisonTime - commentDate.getTime()) / 1000));
    const minutes = Math.floor(seconds / 60);
    const hours = Math.floor(minutes / 60);
    const days = Math.floor(hours / 24);

    if (minutes < 1) {
        return 'justo ahora';
    }

    if (minutes < 60) {
        return `hace ${minutes} min`;
    }

    if (hours < 24) {
        return `hace ${hours} h`;
    }

    if (days < 7) {
        return `hace ${days} d`;
    }

    return new Intl.DateTimeFormat('es-AR', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(new Date(value));
}

// Genera el ID HTML usado para anclar y resaltar comentarios.
function commentDomId(commentId) {
    return `comentario-${commentId}`;
}

// Lee la URL para saber si hay que enfocar un comentario concreto.
function linkedCommentId() {
    if (typeof window === 'undefined') {
        return null;
    }

    // La grilla "Mis comentarios" usa ?comentario=ID para que el navegador no
    // haga un salto automático antes de que Vue pueda centrar la posición.
    const queryCommentId = Number(new URLSearchParams(window.location.search).get('comentario'));

    if (Number.isInteger(queryCommentId) && queryCommentId > 0) {
        return queryCommentId;
    }

    // Compatibilidad con enlaces viejos o pegados manualmente que todavía usen
    // #comentario-ID como ancla tradicional del navegador.
    const match = window.location.hash.match(/^#comentario-(\d+)$/);

    return match ? Number(match[1]) : null;
}

// Limpia el timer del resaltado visual si el usuario cambia de pantalla.
function clearLinkedCommentHighlightTimer() {
    if (linkedCommentHighlightTimer.value) {
        window.clearTimeout(linkedCommentHighlightTimer.value);
        linkedCommentHighlightTimer.value = null;
    }
}

// Hace scroll hasta el comentario enlazado y lo deja centrado en la pantalla.
function scrollToLinkedComment(behavior = 'smooth') {
    const commentId = linkedCommentId();

    if (!commentId) {
        return false;
    }

    const element = document.getElementById(commentDomId(commentId));

    if (!element) {
        return false;
    }

    highlightedCommentId.value = commentId;
    element.scrollIntoView({ behavior, block: 'center', inline: 'nearest' });

    clearLinkedCommentHighlightTimer();

    linkedCommentHighlightTimer.value = window.setTimeout(() => {
        highlightedCommentId.value = null;
        linkedCommentHighlightTimer.value = null;
    }, 2800);

    return true;
}

// Centra el comentario una sola vez después de que Vue terminó de pintar la pantalla.
// No se hacen reintentos automáticos para evitar el "salto" de reacomodación.
function centerLinkedCommentAfterLayoutSettles() {
    if (hasCenteredLinkedComment.value) {
        return;
    }

    nextTick(() => {
        window.requestAnimationFrame(() => {
            if (scrollToLinkedComment('auto')) {
                hasCenteredLinkedComment.value = true;
            }
        });
    });
}

/**
 * Detiene el seguimiento de la moderación automática.
 *
 * El timer existe sólo mientras esta cuenta tiene al menos una participación
 * propia en `processing` dentro de la noticia actual.
 */
function stopModerationStatusPolling() {
    if (!moderationStatusTimer.value) {
        return;
    }

    window.clearInterval(
        moderationStatusTimer.value
    );

    moderationStatusTimer.value = null;
}

/**
 * Consulta un endpoint mínimo que responde exclusivamente si todavía queda
 * algún comentario/respuesta propio en `processing`.
 *
 * Mientras Node continúa trabajando no se modifica la pantalla. Cuando Laravel
 * responde `has_processing = false`, se detiene el polling y se recarga sólo la
 * prop `article` mediante Inertia. Así la UI refleja inmediatamente uno de los
 * tres destinos posibles sin recargar toda la página:
 *
 *     processing -> approved
 *     processing -> pending
 *     processing -> rejected
 */
/**
 * Traduce el resultado final del pipeline automatico a estado visible.
 *
 * Aprobado y pendiente se resuelven con el refresh de article: aparece el
 * comentario aprobado o el aviso de moderacion humana. Rechazado no tiene fila
 * visible, por eso necesita un mensaje local para que no parezca que no paso nada.
 */
function applyAutomaticModerationResult(result) {
    /*
     * Si lo que estaba esperando era una respuesta, guardamos primero el ID de
     * su comentario padre. `awaitingAutomaticReplyParentId` se limpia al terminar
     * el pipeline, pero ese dato todavia nos sirve para corregir inmediatamente
     * el `has_own_reply` local si la respuesta fue rechazada.
     */
    const trackedReplyParentId = awaitingAutomaticReplyParentId.value;

    awaitingAutomaticModeration.value = false;
    awaitingAutomaticMainModeration.value = false;
    awaitingAutomaticReplyParentId.value = null;
    pendingAutomaticCommentId.value = 0;

    if (!result || result.status !== 'rejected') {
        automaticModerationResult.value = null;
        return;
    }

    if (result.type === 'reply') {
        const parentId = Number(
            result.parent_id
            || trackedReplyParentId
            || 0
        );

        /*
         * Una respuesta rechazada ya no es una respuesta activa. El backend
         * aplica exactamente esa regla, pero el objeto `item` cargado en Vue
         * puede conservar durante un instante `has_own_reply = true`.
         *
         * Lo corregimos localmente antes del refresh para que "Responder" vuelva
         * a aparecer en el mismo momento en que mostramos el rechazo.
         */
        if (parentId) {
            /*
             * Corrige el objeto ya renderizado...
             */
            comments.value = comments.value.map((item) => (
                item.id === parentId
                    ? { ...item, has_own_reply: false }
                    : item
            ));

            /*
             * ...y además conserva una excepción local. Esto es importante
             * porque `loadCommentPage()` o un refresh de Inertia pueden volver a
             * escribir temporalmente el objeto con datos de una carga anterior.
             */
            rememberRejectedAutomaticReply(parentId);
        }

        // Conserva el resultado como unica fuente de verdad del rechazo visible.
        automaticModerationResult.value = {
            ...result,
            parent_id: parentId,
        };

        return;
    }

    /*
     * El comentario principal rechazado deja de estar activo inmediatamente.
     * Esta bandera evita que el valor viejo de `props.article.has_active_main_comment`
     * muestre durante el refresh el cartel contradictorio de que ya existe un
     * comentario principal. El formulario vuelve a quedar disponible enseguida.
     */
    // Conserva el resultado como unica fuente de verdad. El aviso de rechazo y
    // la habilitacion del formulario se derivan ambos de este mismo objeto.
    automaticModerationResult.value = result;
}

async function checkAutomaticModerationStatus() {
    /*
     * Si conocemos el ID exacto de la participación recién enviada debemos
     * consultar su resultado aunque las props de Inertia ya hayan cambiado a
     * `has_processing_comments = false`.
     *
     * El worker puede terminar muy rápido, incluso antes de que Vue procese la
     * respuesta del POST. En ese caso la fila ya no está en processing, pero
     * todavía necesitamos pedir una vez el resultado final para saber si fue
     * approved, pending o rejected.
     */
    const hasTrackedAutomaticComment =
        pendingAutomaticCommentId.value > 0;

    if (
        moderationStatusRequestInFlight.value
        || !isAuthenticated.value
        || (
            !hasProcessingOwnComments.value
            && !hasTrackedAutomaticComment
        )
    ) {
        return;
    }

    moderationStatusRequestInFlight.value = true;

    try {
        const statusUrl = new URL(
            route('comments.processing-status', { article: props.article.id }),
            window.location.origin
        );

        if (pendingAutomaticCommentId.value > 0) {
            statusUrl.searchParams.set(
                'comment_id',
                String(pendingAutomaticCommentId.value)
            );
        }

        const response = await fetch(
            statusUrl.toString(),
            {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                cache: 'no-store',
            }
        );

        /*
         * Si la sesión terminó o la noticia dejó de ser pública, no tiene sentido
         * continuar consultando este estado desde la pantalla actual.
         */
        if ([401, 403, 404].includes(response.status)) {
            stopModerationStatusPolling();
            return;
        }

        // Los errores transitorios mantienen el timer: el próximo ciclo puede
        // recuperarse sin alterar el comentario ni mostrar un falso resultado.
        if (!response.ok) {
            return;
        }

        const payload = await response.json();

        if (payload.has_processing !== false) {
            return;
        }

        /*
         * Node ya terminó. El polling deja de existir antes de iniciar la visita
         * Inertia para impedir que otro tick dispare una segunda recarga.
        */
        stopModerationStatusPolling();

        const finalAutomaticResult = payload.latest_result;

        applyAutomaticModerationResult(finalAutomaticResult);

        /*
         * Un rechazo automatico NO necesita recargar `article`: la fila rechazada
         * no se muestra en la noticia y el backend ya la dejo cerrada.
         *
         * Evitar el `router.reload()` en este caso es importante porque la respuesta
         * del POST que creo el comentario habia traido `has_active_main_comment=true`
         * mientras la fila estaba en `processing`. Una recarga parcial inmediata
         * podia rehidratar momentaneamente ese estado anterior y volver a pintar el
         * cartel "Ya tenes un comentario principal" despues del rechazo.
         *
         * Para `approved` y `pending` si refrescamos porque necesitamos incorporar
         * el comentario publicado o el comentario enviado a moderacion humana.
         */
        if (finalAutomaticResult?.status === 'rejected') {
            return;
        }

        /*
         * La lista visible se actualiza por el endpoint JSON de comentarios, el
         * mismo que usa "Ver mas". Asi un comentario aprobado aparece sin esperar
         * a que el lector recargue manualmente la noticia.
         *
         * Si esa consulta puntual falla, igual se intenta el refresh de Inertia
         * para no dejar colgado el aviso de "Comentario recibido".
         */
        await loadCommentPage(true).catch(() => {});

        router.reload({
            only: ['article'],
            preserveScroll: true,
            preserveState: true,
            onSuccess: (visitPage) => {
                awaitingAutomaticModeration.value = Boolean(
                    visitPage.props.article?.has_processing_comments
                );
                awaitingAutomaticMainModeration.value = Boolean(
                    visitPage.props.article?.has_processing_main_comment
                );

            },
        });
    } catch (_error) {
        /*
         * Una falla de red no modifica el estado visual. La cola/Node continúan
         * siendo la fuente de verdad y el próximo tick vuelve a consultar.
         */
    } finally {
        moderationStatusRequestInFlight.value = false;
    }
}

/**
 * Activa el polling sólo cuando realmente existe trabajo automático pendiente.
 */
function startModerationStatusPolling() {
    /*
     * El ID exacto del comentario recién enviado también alcanza para iniciar el
     * seguimiento. Así cubrimos el caso en que Node terminó antes de que Inertia
     * alcance a devolver `has_processing_comments = true`.
     */
    const hasTrackedAutomaticComment =
        pendingAutomaticCommentId.value > 0;

    if (
        typeof window === 'undefined'
        || moderationStatusTimer.value
        || !isAuthenticated.value
        || (
            !hasProcessingOwnComments.value
            && !hasTrackedAutomaticComment
        )
    ) {
        return;
    }

    // La primera comprobación se realiza sin esperar un intervalo completo.
    void checkAutomaticModerationStatus();

    moderationStatusTimer.value = window.setInterval(
        () => {
            void checkAutomaticModerationStatus();
        },
        MODERATION_STATUS_POLL_MS
    );
}

// Bloquea completamente el desplazamiento de la página mientras
// permanece abierto el modal para reportar un comentario.
//
// La scrollbar desaparece mientras el modal está visible.
function lockReportModalScroll() {
    if (typeof document === 'undefined') {
        return;
    }

    document.body.style.overflow = 'hidden';
}

// Restaura el desplazamiento normal cuando se cierra
// el modal de reporte.
function unlockReportModalScroll() {
    if (typeof document === 'undefined') {
        return;
    }

    document.body.style.overflow = '';
}

// Si cambia el orden, se vuelve a pedir la primera página al backend.
watch(sortMode, () => {
    loadCommentPage(true);
});

// Cuando Inertia trae comentarios nuevos, se sincroniza la lista local y se busca el ancla.
watch(() => props.article.comments, (newComments) => {
    comments.value = uniqueCommentsById(newComments || []);
    mainCommentsOffset.value = props.article.comments_next_offset || comments.value.length;
    mainCommentsHasMore.value = Boolean(props.article.comments_has_more);
    commentsTotalCount.value = props.article.comments_total_count || 0;
    centerLinkedCommentAfterLayoutSettles();
});

// Cuando Inertia navega desde una noticia hacia otra, vuelve a comprobar
// si el usuario todavía tiene activo el límite global de "me gusta".
//
// El bloqueo pertenece al usuario y no a una noticia concreta, por lo que
// debe mantenerse aunque cambie de artículo.
watch(
    () => props.article.id,
    () => {
        // Elimina errores individuales pertenecientes a la noticia anterior.
        likeOperationErrors.value = {};

        // También limpia estados de botones que pertenecían a comentarios
        // de la noticia que acaba de abandonarse.
        likingCommentIds.value = {};
        awaitingAutomaticReplyParentId.value = null;
        automaticModerationResult.value = null;
        rejectedAutomaticReplyParentIds.value = [];

        // Recupera desde localStorage el vencimiento todavía vigente.
        // Si quedan segundos, todos los Likes de la nueva noticia aparecerán
        // deshabilitados inmediatamente.
        restoreLikeRetryCooldown();
    }
);

// Inertia actualiza esta bandera al crear/editar una participación o al
// refrescar el resultado final. El watcher mantiene el polling sincronizado con
// la fuente de verdad del backend y nunca consulta cuando no hay processing.
watch(
    () => props.article.has_processing_comments,
    (hasProcessing) => {
        awaitingAutomaticModeration.value = Boolean(hasProcessing);

        if (hasProcessing) {
            startModerationStatusPolling();
            return;
        }

        /*
         * Si todavía conocemos el ID exacto de la participación enviada, que la
         * prop global ya sea false NO significa que podamos abandonar el
         * seguimiento: puede significar simplemente que el worker terminó muy
         * rápido. Hacemos una última consulta por ese ID para obtener el estado
         * final y recién `applyAutomaticModerationResult()` limpia el tracking.
         */
        if (pendingAutomaticCommentId.value > 0) {
            void checkAutomaticModerationStatus();
            return;
        }

        awaitingAutomaticReplyParentId.value = null;
        stopModerationStatusPolling();
    }
);

// Mantiene alineada la espera local del comentario principal con la bandera
// calculada por Laravel cada vez que Inertia refresca la noticia.
watch(
    () => props.article.has_processing_main_comment,
    (hasProcessingMainComment) => {
        awaitingAutomaticMainModeration.value = Boolean(hasProcessingMainComment);
    }
);

// Bloquea o restaura el scroll de la página según
// el estado del modal para reportar comentarios.
watch(pendingReportComment, (comment) => {
    if (comment) {
        lockReportModalScroll();
        return;
    }

    unlockReportModalScroll();
});

// Cuando la página termina de montarse, recupera cualquier bloqueo todavía
// vigente y después intenta centrar el comentario enlazado desde la URL.
onMounted(() => {
    restoreCommentRetryCooldown();
    restoreLikeRetryCooldown();
    centerLinkedCommentAfterLayoutSettles();

    // Si la página se abrió mientras Node todavía trabaja, empieza a seguir el
    // estado sin exigir una recarga manual del navegador.
    startModerationStatusPolling();
});

// Limpia timers activos antes de abandonar la página para evitar que
// sigan ejecutándose después de destruir este componente.
onBeforeUnmount(() => {
    // El seguimiento de moderación pertenece exclusivamente a esta noticia.
    stopModerationStatusPolling();

    // Cancela el timer utilizado para quitar el resaltado visual
    // del comentario al que se llegó mediante un enlace directo.
    clearLinkedCommentHighlightTimer();

    // Cancela el timer del mensaje temporal mostrado después
    // de enviar correctamente un reporte.
    if (reportSuccessTimer.value) {
        window.clearTimeout(reportSuccessTimer.value);
        reportSuccessTimer.value = null;
    }

    // Cancela la cuenta regresiva utilizada para bloquear temporalmente
    // nuevos intentos después de un error al enviar un comentario.
    if (commentRetryTimer.value) {
        window.clearInterval(commentRetryTimer.value);
        commentRetryTimer.value = null;
    }

    // Detiene el timer visual de "me gusta" al abandonar esta página.
    //
    // El vencimiento guardado en localStorage se conserva para que el bloqueo
    // pueda reconstruirse al entrar a otra noticia o recargar.
    if (likeRetryTimer.value) {
        window.clearInterval(likeRetryTimer.value);
        likeRetryTimer.value = null;
    }

    // Elimina el bloqueo visual y funcional de la scrollbar
    // si se abandona la noticia con el modal todavía abierto.
    unlockReportModalScroll();
});
</script>

<template>
    <Head :title="pageTitle">
        <meta name="description" :content="pageDescription" />
        <meta property="og:title" :content="pageTitle" />
        <meta property="og:description" :content="pageDescription" />
        <meta property="og:image" :content="coverImage" />
    </Head>

        <article class="article-page">
            <header class="article-hero">
                <p class="category-label" :style="{ color: article.category.accent_color }">{{ article.category.name }}</p>
                <h1>{{ article.title }}</h1>
                <p>{{ article.subtitle }}</p>
                <div class="article-meta">
                    <Link :href="article.author.profile_url" class="article-meta-author">{{ article.author.name }}</Link>
                    <span v-if="publishedAt">Publicado el {{ publishedAt }}</span>
                    <span>{{ article.reading_time }} min de lectura</span>
                    <span>{{ article.views }} vistas</span>
                </div>
            </header>

            <figure class="article-cover">
                <img
                    :src="coverImage"
                    :alt="article.cover_alt || article.title"
                    loading="eager"
                    fetchpriority="high"
                    decoding="async"
                />
                <figcaption v-if="article.image_credit || article.image_license">
                    <a v-if="article.image_source_url" :href="article.image_source_url" target="_blank" rel="noopener noreferrer">
                        {{ article.image_credit || 'Fuente de la imagen' }}
                    </a>
                    <span v-else>{{ article.image_credit }}</span>
                    <span v-if="article.image_license"> - {{ article.image_license }}</span>
                </figcaption>
            </figure>

            <div class="article-layout">
                <aside class="author-card">
                    <Link :href="article.author.profile_url" class="author-card-profile">
                        <img :src="authorAvatar" :alt="article.author.name" />
                        <strong>{{ article.author.name }}</strong>
                        <span>{{ article.author.job_title }}</span>
                    </Link>
                    <p>{{ article.author.bio }}</p>
                </aside>

                <section class="article-body">
                    <div class="article-rich-body" v-html="article.body" />

                    <div class="tag-row">
                        <span v-for="tag in article.tags" :key="tag.slug">#{{ tag.name }}</span>
                    </div>
                </section>

                <aside
                    v-if="sidebarTopAdvertisement || sidebarBottomAdvertisement"
                    class="article-ad-rail"
                    aria-label="Espacios publicitarios de la nota"
                >
                    <AdvertisementBanner :advertisement="sidebarTopAdvertisement" variant="sidebarTop" priority />
                    <AdvertisementBanner :advertisement="sidebarBottomAdvertisement" variant="sidebarBottom" />
                </aside>
            </div>
        </article>

        <section class="comments-panel">
            <SectionHeader eyebrow="Comunidad" title="Comentarios" />

            <div v-if="!isAuthenticated" class="comment-gate">
                <div class="comment-gate-copy">
                    <span class="subscriber-pill">
                        <MessageCircle :size="16" />
                        Usuarios registrados
                    </span>
                    <h3>Sumate a la conversación</h3>
                    <p>Para comentar las notas, necesitás iniciar sesión o crear una cuenta.</p>
                </div>
                <div class="comment-gate-actions">
                    <Link :href="route('login', { redirect: articleReturnPath })" class="comment-login-link">
                        <LogIn :size="17" />
                        Iniciar sesión
                    </Link>
                    <Link :href="route('register', { redirect: articleReturnPath })" class="comment-register-link">
                        <UserPlus :size="17" />
                        Crear cuenta
                    </Link>
                </div>
            </div>

            <form
                v-else-if="canCreateMainComment && !hasActiveOwnMainComment"
                class="comment-form"
                @submit.prevent="submitComment"
            >
                <div class="comment-account">
                    <div>
                        <span>Comentando como</span>
                        <strong>{{ currentUser.name }}</strong>
                    </div>

                    <p
                        v-if="
                            comment.errors.body
                            || commentRateLimitMessage
                            || comment.errors.operation
                        "
                        class="comment-limit-error"
                    >
                        {{
                            comment.errors.body
                            || commentRateLimitMessage
                            || comment.errors.operation
                        }}
                    </p>
                </div>

                <div class="comment-textarea-field">
                    <textarea
                        v-model="comment.body"
                        placeholder="Escribí tu comentario"
                        required
                        :minlength="commentMinLength"
                        :maxlength="commentMaxLength"
                        :disabled="comment.processing"
                        rows="6"
                        @input="handlePublicCommentInput(comment, $event)"
                    />
                    <span class="comment-counter" :class="{ warning: commentBodyLength > commentMaxLength * 0.9 }">
                        {{ commentBodyLength }}/{{ commentMaxLength }} caracteres
                    </span>
                </div>

                <div class="comment-form-actions">
                    <span>Gracias por participar con respeto.</span>
                    <button
                        type="submit"
                        :disabled="
                            comment.processing
                            || commentRetrySeconds > 0
                            || !canSubmitComment
                        "
                    >
                        <MessageCircle :size="17" />
                        {{
                            comment.processing
                                ? 'Enviando...'
                                : commentRetryLabel
                        }}
                    </button>
                </div>
            </form>

            <div
                v-else-if="
                    canCreateMainComment
                    && hasProcessingOwnMainComment
                    && !automaticModerationNotice
                "
                class="comment-pending-note"
            >
                <MessageCircle :size="18" />
                <div>
                    <strong>Comentario recibido.</strong>
                    <p>Estamos revisándolo antes de publicarlo.</p>
                </div>
            </div>

            <div
                v-else-if="canCreateMainComment && hasPendingOwnMainComment"
                class="comment-pending-note"
            >
                <MessageCircle :size="18" />
                <div>
                    <strong>Tu comentario está pendiente de moderación.</strong>
                    <p>El comentario está pendiente de moderación. Solo vos podés verlo mientras se revisa.</p>
                </div>
            </div>

            <div
                v-else-if="canCreateMainComment && hasActiveOwnMainComment"
                class="comment-pending-note"
            >
                <MessageCircle :size="18" />
                <div>
                    <strong>Ya tenés un comentario principal en esta noticia.</strong>
                    <p>TRAMA permite un solo comentario principal activo por usuario y noticia.</p>
                </div>
            </div>

            <div
                v-else
                class="comment-role-note"
            >
                <MessageCircle :size="18" />

                <div v-if="replyIdentity">
                    <strong>Participás como {{ replyIdentity }}.</strong>
                    <p>Podés responder comentarios de los lectores, pero no crear comentarios principales como un usuario común.</p>
                </div>

                <div v-else-if="isNonAuthorJournalist">
                    <strong>Solo el autor de la nota puede responder comentarios.</strong>
                    <p>Como periodista, podés responder comentarios únicamente en tus propias notas. En esta noticia participás solo como lector interno.</p>
                </div>

                <div v-else>
                    <strong>Esta cuenta no participa en los comentarios públicos.</strong>
                    <p>Las cuentas administrativas de TRAMA no comentan ni responden como usuarios registrados.</p>
                </div>
            </div>

            <div
                v-if="automaticModerationNotice"
                class="comment-role-note"
                role="status"
                aria-live="polite"
            >
                <MessageCircle :size="18" />

                <div>
                    <strong>{{ automaticModerationNotice }}</strong>
                </div>
            </div>

            <div
                v-if="reportSuccessMessage"
                class="comment-role-note"
                role="status"
                aria-live="polite"
            >
                <Flag :size="18" />

                <div>
                    <strong>{{ reportSuccessMessage }}</strong>
                </div>
            </div>

            <div class="comment-thread-head">
                <span>Comentarios <strong>{{ commentsCount }}</strong></span>
                <div
                    v-if="hasSortableMainComments"
                    class="comment-sort-tabs"
                    aria-label="Orden de comentarios"
                >
                    <button type="button" :class="{ active: sortMode === 'recent' }" @click="sortMode = 'recent'">
                        Más recientes
                    </button>
                    <button type="button" :class="{ active: sortMode === 'valued' }" @click="sortMode = 'valued'">
                        Más valorados
                    </button>
                    <button type="button" :class="{ active: sortMode === 'oldest' }" @click="sortMode = 'oldest'">
                        Más antiguos
                    </button>
                </div>
            </div>

            <p
                v-if="likeRateLimitMessage"
                class="comment-limit-error comment-like-limit-error"
                role="status"
                aria-live="polite"
            >
                {{ likeRateLimitMessage }}
            </p>

            <div class="comment-list">
                <article
                    v-for="item in visibleComments"
                    :id="commentDomId(item.id)"
                    :key="item.id || item.created_at + item.body"
                    class="comment-card"
                    :class="{ 'comment-card-highlighted': highlightedCommentId === item.id }"
                >
                    <div class="comment-avatar" :style="commentAvatarStyle(item.author_name)">
                        {{ commentInitials(item.author_name) }}
                    </div>
                    <div class="comment-content">
                        <header>
                            <strong>{{ item.author_name }}</strong>
                            <span
                                v-if="item.author_badge"
                                class="comment-author-badge"
                            >
                                {{ item.author_badge }}
                            </span>
                            <span v-if="item.visible_only_to_author" class="pending-comment-badge">                                
                                Pendiente de moderación
                            </span>
                            <span>{{ formatCommentTime(item.created_at) }}</span>
                        </header>
                        <form
                            v-if="editingCommentId === item.id"
                            class="reply-form"
                            @submit.prevent="submitEdit(item)"
                        >
                            <textarea
                                v-model="editComment.body"
                                required
                                :minlength="commentMinLength"
                                :maxlength="commentMaxLength"
                                :disabled="editComment.processing"
                                rows="4"
                                @input="handlePublicCommentInput(editComment, $event)"
                            />
                            <div class="reply-form-meta">
                                <span v-if="editComment.errors.body" class="form-error">{{ editComment.errors.body }}</span>
                                <span
                                    v-else-if="editComment.errors.operation"
                                    class="form-error"
                                >
                                    {{ editComment.errors.operation }}
                                </span>

                                <span class="comment-counter" :class="{ warning: editCommentBodyLength > commentMaxLength * 0.9 }">
                                    {{ editCommentBodyLength }}/{{ commentMaxLength }} caracteres
                                </span>
                            </div>
                            <div class="reply-actions comment-edit-actions">
                                <button type="button" class="comment-action-button comment-edit-cancel-action" :disabled="editComment.processing" @click="cancelEdit">
                                    Cancelar
                                </button>
                                <button type="submit" class="comment-edit-save-action" :disabled="editComment.processing || !canSubmitEdit">
                                    {{ editComment.processing ? 'Guardando...' : 'Guardar cambios' }}
                                </button>
                            </div>
                        </form>
                        <p v-else>{{ item.body }}</p>
                        <footer>
                            <template v-if="item.visible_only_to_author">                                
                                <button
                                    v-if="item.can_edit"
                                    type="button"
                                    class="comment-action-button"
                                    @click="startEdit(item)"
                                >
                                    Editar
                                </button>
                                <button
                                    v-if="item.can_delete"
                                    type="button"
                                    class="comment-action-button danger"
                                    :disabled="isDeletingComment(item.id)"
                                    @click="deleteComment(item)"
                                >
                                    {{ isDeletingComment(item.id) ? 'Eliminando...' : 'Eliminar' }}
                                </button>
                            </template>
                            <template v-else>
                                <button
                                    v-if="canLikeComments && !item.is_own_comment && !item.reported_by_current_user"
                                    type="button"
                                    class="comment-action-button comment-like-button"
                                    :class="{ active: item.liked_by_current_user }"
                                    :disabled="
                                        isLikingComment(item.id)
                                        || likeRetrySeconds > 0
                                    "
                                    @click="toggleLike(item)"
                                >
                                    <ThumbsUp :size="15" />
                                    <span>{{ item.likes_count || 0 }}</span>
                                </button>
                                <span v-else class="comment-like-count">
                                    <ThumbsUp :size="15" />
                                    <span>{{ item.likes_count || 0 }}</span>
                                </span>
                                <button
                                    v-if="canShowReplyButton(item)"
                                    type="button"
                                    class="comment-action-button"
                                    @click="startReply(item)"
                                >
                                    Responder
                                </button>
                                <button
                                    v-if="item.can_report"
                                    type="button"
                                    class="comment-action-button"
                                    :disabled="reportProcessing"
                                    @click="startReport(item)"
                                >
                                    <Flag :size="15" />
                                    Reportar
                                </button>
                                <span
                                    v-else-if="item.reported_by_current_user"
                                    class="comment-report-state"
                                >
                                    <Flag :size="15" />
                                    Reportado
                                </span>
                                <span
                                    v-else-if="item.report_reviewed_by_current_user"
                                    class="comment-report-state"
                                >
                                    <Flag :size="15" />
                                    Reporte revisado
                                </span>
                                <button
                                    v-if="item.can_delete"
                                    type="button"
                                    class="comment-action-button danger"
                                    :disabled="isDeletingComment(item.id)"
                                    @click="deleteComment(item)"
                                >
                                    {{ isDeletingComment(item.id) ? 'Eliminando...' : 'Eliminar' }}
                                </button>
                            </template>
                        </footer>

                        <p
                            v-if="
                                !likeRateLimitMessage
                                && likeOperationErrors[item.id]
                            "
                            class="form-error"
                        >
                            {{ likeOperationErrors[item.id] }}
                        </p>

                        <form
                            v-if="replyingToId === item.id"
                            class="reply-form"
                            @submit.prevent="submitReply(item)"
                        >
                            <textarea
                                v-model="reply.body"
                                placeholder="Escribí tu respuesta"
                                required
                                :minlength="commentMinLength"
                                :maxlength="commentMaxLength"
                                :disabled="reply.processing"
                                rows="4"
                                @input="handlePublicCommentInput(reply, $event)"
                            />
                            <div class="reply-form-meta">
                                <span v-if="reply.errors.body" class="form-error">{{ reply.errors.body }}</span>
                                <span v-else-if="reply.errors.parent_id" class="form-error">{{ reply.errors.parent_id }}</span>
                                <span
                                    v-else-if="commentRateLimitMessage || reply.errors.operation"
                                    class="comment-limit-error"
                                >
                                    {{ commentRateLimitMessage || reply.errors.operation }}
                                </span>
                                <span class="comment-counter" :class="{ warning: replyBodyLength > commentMaxLength * 0.9 }">
                                    {{ replyBodyLength }}/{{ commentMaxLength }} caracteres
                                </span>
                            </div>
                            <div class="reply-actions">
                                <button
                                    type="button"
                                    class="comment-action-button comment-reply-cancel-action"
                                    :disabled="reply.processing"
                                    @click="replyingToId = null"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    :disabled="
                                        reply.processing
                                        || commentRetrySeconds > 0
                                        || !canSubmitReply
                                    "
                                >
                                    {{
                                        reply.processing
                                            ? 'Enviando...'
                                            : replyRetryLabel
                                    }}
                                </button>
                            </div>
                        </form>

                        <div
                            v-if="awaitingAutomaticReplyParentId === item.id"
                            class="comment-pending-note comment-reply-processing-note"
                            role="status"
                            aria-live="polite"
                        >
                            <MessageCircle :size="18" />
                            <div>
                                <strong>Respuesta recibida.</strong>
                                <p>Estamos revisándola antes de publicarla.</p>
                            </div>
                        </div>

                        <div
                            v-else-if="hasRejectedAutomaticReplyNoticeFor(item.id)"
                            class="comment-pending-note comment-reply-processing-note comment-reply-rejected-note"
                            role="status"
                            aria-live="polite"
                        >
                            <MessageCircle :size="18" />
                            <div>
                                <strong>Tu respuesta no pudo publicarse.</strong>
                                <p>Incumple las normas de participación.</p>
                            </div>
                        </div>

                        <div v-if="item.replies?.length" class="comment-replies">
                            <article
                                v-for="replyItem in visibleReplies(item)"
                                :id="commentDomId(replyItem.id)"
                                :key="replyItem.id"
                                class="comment-card comment-reply"
                                :class="{ 'comment-card-highlighted': highlightedCommentId === replyItem.id }"
                            >
                                <div class="comment-avatar" :style="commentAvatarStyle(replyItem.author_name)">
                                    {{ commentInitials(replyItem.author_name) }}
                                </div>
                                <div class="comment-content">
                                    <header>
                                        <strong>{{ replyItem.author_name }}</strong>
                                        <span
                                            v-if="replyItem.author_badge"
                                            class="comment-author-badge"
                                        >
                                            {{ replyItem.author_badge }}
                                        </span>
                                        <span v-if="replyItem.visible_only_to_author" class="pending-comment-badge">
                                            Pendiente de moderación
                                        </span>
                                        <span>{{ formatCommentTime(replyItem.created_at) }}</span>
                                    </header>
                                    <form
                                        v-if="editingCommentId === replyItem.id"
                                        class="reply-form"
                                        @submit.prevent="submitEdit(replyItem)"
                                    >
                                        <textarea
                                            v-model="editComment.body"
                                            required
                                            :minlength="commentMinLength"
                                            :maxlength="commentMaxLength"
                                            :disabled="editComment.processing"
                                            rows="4"
                                            @input="handlePublicCommentInput(editComment, $event)"
                                        />
                                        <div class="reply-form-meta">
                                            <span v-if="editComment.errors.body" class="form-error">{{ editComment.errors.body }}</span>
                                            <span
                                                v-else-if="editComment.errors.operation"
                                                class="form-error"
                                            >
                                                {{ editComment.errors.operation }}
                                            </span>

                                            <span class="comment-counter" :class="{ warning: editCommentBodyLength > commentMaxLength * 0.9 }">
                                                {{ editCommentBodyLength }}/{{ commentMaxLength }} caracteres
                                            </span>
                                        </div>
                                        <div class="reply-actions comment-edit-actions">
                                            <button type="button" class="comment-action-button comment-edit-cancel-action" :disabled="editComment.processing" @click="cancelEdit">
                                                Cancelar
                                            </button>
                                            <button type="submit" class="comment-edit-save-action" :disabled="editComment.processing || !canSubmitEdit">
                                                {{ editComment.processing ? 'Guardando...' : 'Guardar cambios' }}
                                            </button>
                                        </div>
                                    </form>
                                    <p v-else>{{ replyItem.body }}</p>
                                    <footer>
                                        <template v-if="replyItem.visible_only_to_author">                                            
                                            <button
                                                v-if="replyItem.can_edit"
                                                type="button"
                                                class="comment-action-button"
                                                @click="startEdit(replyItem)"
                                            >
                                                Editar
                                            </button>
                                            <button
                                                v-if="replyItem.can_delete"
                                                type="button"
                                                class="comment-action-button danger"
                                                :disabled="isDeletingComment(replyItem.id)"
                                                @click="deleteComment(replyItem)"
                                            >
                                                {{ isDeletingComment(replyItem.id) ? 'Eliminando...' : 'Eliminar' }}
                                            </button>
                                        </template>
                                        <template v-else>
                                            <button
                                                v-if="canLikeComments && !replyItem.is_own_comment && !replyItem.reported_by_current_user"
                                                type="button"
                                                class="comment-action-button comment-like-button"
                                                :class="{ active: replyItem.liked_by_current_user }"
                                                :disabled="
                                                    isLikingComment(replyItem.id)
                                                    || likeRetrySeconds > 0
                                                "
                                                @click="toggleLike(replyItem)"
                                            >
                                                <ThumbsUp :size="15" />
                                                <span>{{ replyItem.likes_count || 0 }}</span>
                                            </button>

                                            <span v-else class="comment-like-count">
                                                <ThumbsUp :size="15" />
                                                <span>{{ replyItem.likes_count || 0 }}</span>
                                            </span>

                                            <button
                                                v-if="replyItem.can_report"
                                                type="button"
                                                class="comment-action-button"
                                                :disabled="reportProcessing"
                                                @click="startReport(replyItem)"
                                            >
                                                <Flag :size="15" />
                                                Reportar
                                            </button>

                                            <span
                                                v-else-if="replyItem.reported_by_current_user"
                                                class="comment-report-state"
                                            >
                                                <Flag :size="15" />
                                                Reportado
                                            </span>

                                            <span
                                                v-else-if="replyItem.report_reviewed_by_current_user"
                                                class="comment-report-state"
                                            >
                                                <Flag :size="15" />
                                                Reporte revisado
                                            </span>

                                            <button
                                                v-if="replyItem.can_delete"
                                                type="button"
                                                class="comment-action-button danger"
                                                :disabled="isDeletingComment(replyItem.id)"
                                                @click="deleteComment(replyItem)"
                                            >
                                                {{ isDeletingComment(replyItem.id) ? 'Eliminando...' : 'Eliminar' }}
                                            </button>
                                        </template>
                                    </footer>

                                    <p
                                        v-if="
                                            !likeRateLimitMessage
                                            && likeOperationErrors[replyItem.id]
                                        "
                                        class="form-error"
                                    >
                                        {{ likeOperationErrors[replyItem.id] }}
                                    </p>
                                </div>
                            </article>

                            <div v-if="hiddenReplyCount(item)" class="reply-load-row">
                                <button
                                    type="button"
                                    class="comment-action-button"
                                    :disabled="isLoadingReplies(item.id)"
                                    @click="showMoreReplies(item)"
                                >
                                    {{ isLoadingReplies(item.id) ? 'Cargando...' : `Ver las ${hiddenReplyCount(item)} respuestas restantes` }}
                                </button>
                            </div>
                        </div>
                    </div>
                </article>

                <button
                    v-if="mainCommentsHasMore"
                    type="button"
                    class="load-more-comments"
                    :disabled="loadingMoreComments"
                    @click="showMoreComments"
                >
                    {{ loadingMoreComments ? 'Cargando...' : 'Ver más comentarios' }}
                </button>
            </div>

            <p v-if="!article.comments.length" class="empty-state">Todavía no hay comentarios.</p>
        </section>

        <div v-if="pendingDeleteComment" class="comment-delete-backdrop" role="dialog" aria-modal="true" aria-labelledby="comment-delete-title">
            <div class="comment-delete-modal">
                <header>
                    <MessageCircle :size="20" />
                    <div>
                        <h2 id="comment-delete-title">{{ pendingDeleteComment.visible_only_to_author ? '¿Eliminar este comentario pendiente?' : '¿Eliminar este comentario?' }}</h2>
                        <p>Esta acción no se puede deshacer.</p>
                    </div>
                </header>

                <div
                    v-if="deleteOperationError"
                    class="operation-error"
                    role="alert"
                >
                    <p>{{ deleteOperationError }}</p>
                </div>

                <footer>
                    <button type="button" class="comment-action-button" :disabled="isDeletingComment(pendingDeleteComment.id)" @click="cancelDeleteComment">
                        Cancelar
                    </button>
                    <button
                        type="button"
                        class="comment-action-button danger"
                        :disabled="isDeletingComment(pendingDeleteComment.id)"
                        @click="confirmDeleteComment"
                    >
                        {{ isDeletingComment(pendingDeleteComment.id) ? 'Eliminando...' : 'Eliminar' }}
                    </button>
                </footer>
            </div>
        </div>

        <div
            v-if="pendingReportComment"
            class="comment-report-backdrop"
            role="dialog"
            aria-modal="true"
            aria-labelledby="comment-report-title"
        >
            <div class="comment-report-modal">
                <header>
                    <Flag :size="20" />

                    <div>
                        <h2 id="comment-report-title">
                            Reportar comentario
                        </h2>

                        <p>
                            Elegí el motivo que mejor describe el problema.
                        </p>
                    </div>
                </header>

                <div class="comment-report-options">
                    <label
                        v-for="option in article.comment_report_reasons"
                        :key="option.value"
                        class="comment-report-option"
                    >
                        <input
                            v-model="reportReason"
                            type="radio"
                            name="comment-report-reason"
                            :value="option.value"
                            :disabled="reportProcessing"
                        />

                        <span>{{ option.label }}</span>
                    </label>
                </div>

                <p
                    v-if="reportReasonError"
                    class="form-error"
                >
                    {{ reportReasonError }}
                </p>

                <div
                    v-if="reportOperationError"
                    class="operation-error"
                    role="alert"
                >
                    <p class="eyebrow">
                        No se pudo enviar el reporte
                    </p>

                    <p>
                        {{ reportOperationError }}
                    </p>
                </div>

                <footer>
                    <button
                        type="button"
                        class="comment-action-button comment-report-cancel-action"
                        :disabled="reportProcessing"
                        @click="cancelReport"
                    >
                        Cancelar
                    </button>

                    <button
                        type="button"
                        class="comment-report-submit"
                        :disabled="reportProcessing || !reportReason"
                        @click="submitReport"
                    >
                        {{ reportProcessing ? 'Enviando...' : 'Enviar reporte' }}
                    </button>
                </footer>
            </div>
        </div>

        <section class="content-band">
            <SectionHeader eyebrow="Relacionadas" title="Seguir leyendo" />
            <div class="featured-grid">
                <ArticleCard v-for="item in related" :key="item.id" :article="item" />
            </div>
        </section>
</template>
