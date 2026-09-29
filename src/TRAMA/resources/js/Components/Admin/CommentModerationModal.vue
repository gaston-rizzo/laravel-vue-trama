<script setup>
/* ============================================================================
 * COMPONENT: CommentModerationModal.vue
 * ============================================================================
 *
 * Modal utilizado por el editor para revisar un comentario sin abandonar la
 * grilla de moderación.
 *
 * El detalle completo se solicita a Laravel cuando el editor selecciona una fila.
 * El modal se muestra recién cuando esa carga terminó correctamente o cuando debe
 * informar un error, evitando mostrar un estado intermedio de carga.
 *
 * De esta manera Index.vue continúa trabajando con filas compactas y no necesita
 * cargar de antemano cuerpos completos, comentarios padre ni reportes.
 *
 * El modal permite:
 *
 *      - leer el comentario completo;
 *      - consultar el comentario principal cuando se modera una respuesta;
 *      - revisar los reportes abiertos y sus motivos;
 *      - aprobar, rechazar o retirar el comentario según su estado;
 *      - mantener publicado un comentario reportado;
 *      - eliminar definitivamente el comentario;
 *      - derivar manualmente al administrador la cuenta pública del autor.
 *
 * Las decisiones se envían mediante Inertia para que, al completarse, Laravel
 * vuelva a calcular la fila, los conteos y la paginación de la grilla.
 * ============================================================================ */

import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { route } from 'ziggy-js';
import {
    Check,
    CircleAlert,
    ExternalLink,
    Flag,
    MessageSquare,
    MessagesSquare,
    ShieldAlert,
    Trash2,
    UserRoundCog,
    X,
} from '@lucide/vue';

const props = defineProps({
    /* Indica si el modal debe permanecer visible. */
    open: {
        type: Boolean,
        default: false,
    },

    /* Identificador del comentario seleccionado en la grilla. */
    commentId: {
        type: [Number, String],
        default: null,
    },
});

const emit = defineEmits([
    'close',
]);

/* Traducción visual de comments.status. */
const statusLabels = {
    pending: 'Pendiente',
    approved: 'Aprobado',
    rejected: 'Rechazado',
};

/*
 * Replica dentro del modal el segundo renglón que usa la grilla para distinguir
 * rechazos automáticos de rechazos tomados por una decisión editorial.
 */
const isAutomaticRejected = computed(() => (
    comment.value?.status === 'rejected'
    && comment.value?.moderation_source === 'automatic'
));

/* Traducción del origen que tomó la última decisión de moderación. */
const moderationSourceLabels = {
    automatic: 'Automático',
    editorial: 'Editorial',
};

/* Códigos operativos convertidos a etiquetas comprensibles para el editor. */
const moderationReasonLabels = {
    automatic_clean: 'Sin señales de infracción',
    language_review: 'Idioma no confirmado',
    language_mixed: 'Mezcla de idiomas',
    unsupported_language: 'Idioma no soportado',
    link_detected: 'Enlace detectado',
    threat_high: 'Amenaza de riesgo alto',
    threat_medium: 'Amenaza o intimidación para revisión',
    threat_review: 'Intimidación dudosa',
    toxicity_review: 'Toxicidad dudosa',
    toxicity_clear: 'Toxicidad alta',
    spam_review: 'Spam dudoso',
    spam_clear: 'Spam claro',
    automation_error: 'Fallo técnico del análisis automático',
    automation_enqueue_error: 'No se pudo encolar el análisis automático',
    editorial_approved: 'Aprobado por el equipo editorial',
    editorial_rejected: 'Rechazado por el equipo editorial',
    editorial_maintained: 'Mantenido por el equipo editorial',
    parent_editorial_rejected: 'Retirado al rechazarse el comentario principal',
};

/* Detalle completo recibido desde AdminCommentDetailResource. */
const comment = ref(null);
const closedAfterSuccess = ref(false);

/* Estado de la petición GET utilizada para abrir el modal. */
const loading = ref(false);

/* Mensaje visible cuando el detalle no pudo recuperarse o una acción falló. */
const errorMessage = ref('');

/* Acción que actualmente se está procesando mediante Inertia. */
const processingAction = ref('');

/* Segunda confirmación necesaria antes de eliminar definitivamente. */
const deleteConfirmationOpen = ref(false);

const reviewRequestOpen = ref(false);
const reviewReason = ref('');
const reviewNote = ref('');
const REVIEW_NOTE_MIN = 10;
const REVIEW_NOTE_MAX = 300;
const reviewReasonOptions = [
    { value: 'spam', label: 'Spam' },
    { value: 'abuse', label: 'Insultos o acoso' },
    { value: 'inappropriate', label: 'Contenido inapropiado' },
    { value: 'repeated', label: 'Conducta repetida' },
    { value: 'other', label: 'Otro' },
];

/*
 * Número de carga utilizado para ignorar respuestas antiguas cuando el editor
 * cambia de comentario antes de que termine una petición anterior.
 */
let detailRequestSequence = 0;

/*
 * Identifica la última mutación iniciada desde el modal. Evita que el onFinish
 * de una petición antigua limpie el estado de otra acción que pudiera haberse
 * iniciado después de volver a abrir un comentario.
 */
let actionRequestSequence = 0;

/*
 * Aprobar solamente está disponible mientras el comentario siga pendiente.
 *
 * Rejected es un estado definitivo y no vuelve a ofrecer una transición hacia
 * aprobado.
 */
const canApprove = computed(() => (
    comment.value?.status === 'pending'
));

/*
 * La acción interna reject puede aplicarse mientras el estado no sea rejected.
 */
/*
 * En la interface, un pendiente se "Rechaza" y uno aprobado se "Retira".
 * Ambos caminos conservan status=rejected en la base.
 */
const canReject = computed(() => (
    comment.value
    && comment.value.status !== 'rejected'
));

/*
 * Mantener se muestra únicamente para comentarios aprobados con reportes
 * abiertos. La acción conserva el comentario y cierra esos reportes como
 * revisados.
 */
const canMaintain = computed(() => (
    comment.value?.status === 'approved'
    && Number(comment.value?.open_reports_count || 0) > 0
));

/*
 * Indica si la autoría corresponde a un lector que admite el flujo de derivación.
 * Si ya fue derivado, Laravel trata una nueva solicitud de forma idempotente.
 */
const canReferToAdmin = computed(() => Boolean(
    comment.value?.can_refer_to_admin
));

/*
 * Evita cerrar o ejecutar otra operación mientras existe una modificación en
 * curso. La carga inicial del detalle se controla por separado.
 */
const isProcessing = computed(() => processingAction.value !== '');

/*
 * El modal solamente se vuelve visible cuando terminó la carga del detalle.
 *
 * Si la consulta falla, también se muestra para informar el error y permitir
 * reintentar. Mientras la petición está en curso no se pinta ningún modal vacío
 * ni el mensaje "Cargando comentario…".
 */
const isModalVisible = computed(() => (
    props.open
    && !closedAfterSuccess.value
    && !loading.value
    && (comment.value !== null || errorMessage.value !== '')
));

const normalizedReviewNote = computed(() => (
    String(reviewNote.value || '').replace(/\s+/g, ' ').trim()
));

const reviewNoteLength = computed(() => normalizedReviewNote.value.length);

const canSubmitReviewRequest = computed(() => (
    Boolean(reviewReason.value)
    && reviewNoteLength.value >= REVIEW_NOTE_MIN
    && reviewNoteLength.value <= REVIEW_NOTE_MAX
    && !isProcessing.value
));

function reviewNoteCounter() {
    const length = reviewNoteLength.value;

    if (length < REVIEW_NOTE_MIN) {
        const remaining = REVIEW_NOTE_MIN - length;
        const verb = remaining === 1 ? 'falta' : 'faltan';
        const unit = remaining === 1 ? 'carácter' : 'caracteres';
        return `${length}/${REVIEW_NOTE_MAX} · ${verb} ${remaining} ${unit}`;
    }

    return `${length}/${REVIEW_NOTE_MAX}`;
}

// La observación administrativa es deliberadamente de una sola línea.
// Enter se bloquea en teclado y este handler también limpia saltos que lleguen
// desde el portapapeles para que la UI coincida con la normalización de Laravel.
function handleReviewNoteInput(event) {
    const normalized = String(event?.target?.value ?? reviewNote.value)
        .replace(/[\r\n]+/g, ' ')
        .replace(/[\t ]{2,}/g, ' ')
        .slice(0, REVIEW_NOTE_MAX);

    reviewNote.value = normalized;

    if (event?.target && event.target.value !== normalized) {
        event.target.value = normalized;
    }
}

function resetReviewRequest() {
    reviewRequestOpen.value = false;
    reviewReason.value = '';
    reviewNote.value = '';
}

/*
 * Obtiene el comentario completo, su contexto y sus reportes abiertos.
 */
async function loadCommentDetail() {
    const requestSequence = ++detailRequestSequence;

    // Invalida callbacks tardíos de cualquier acción perteneciente al detalle anterior.
    actionRequestSequence += 1;
    loading.value = true;
    errorMessage.value = '';
    processingAction.value = '';
    deleteConfirmationOpen.value = false;
    resetReviewRequest();
    comment.value = null;

    try {
        const response = await window.axios.get(
            route(
                'admin.comments.show',
                props.commentId,
            ),
        );

        /*
         * Si otra carga comenzó después, esta respuesta ya no corresponde al
         * comentario que el editor está intentando revisar.
         */
        if (requestSequence !== detailRequestSequence) {
            return;
        }

        comment.value = response.data?.comment || null;

        if (!comment.value) {
            errorMessage.value = 'No pudimos cargar el comentario seleccionado.';
        }
    } catch (error) {
        if (requestSequence !== detailRequestSequence) {
            return;
        }

        errorMessage.value = error?.response?.data?.message
            || 'No pudimos cargar el comentario seleccionado. Intentá nuevamente.';
    } finally {
        if (requestSequence === detailRequestSequence) {
            loading.value = false;
        }
    }
}

/*
 * Cierra el modal solamente cuando no hay una acción editorial en curso.
 */
function closeModal() {
    if (isProcessing.value) {
        return;
    }

    detailRequestSequence += 1;
    actionRequestSequence += 1;
    closedAfterSuccess.value = false;
    deleteConfirmationOpen.value = false;
    resetReviewRequest();
    errorMessage.value = '';
    comment.value = null;

    emit('close');
}

/*
 * Cierra el modal con Escape.
 */
function handleEscape(event) {
    if (event.key !== 'Escape' || !isModalVisible.value || isProcessing.value) {
        return;
    }

    event.preventDefault();

    // La primera pulsación cancela la confirmación destructiva; el modal padre
    // solo se cierra con Escape cuando ya no existe esa confirmación anidada.
    if (deleteConfirmationOpen.value) {
        cancelDeleteConfirmation();
        return;
    }

    if (reviewRequestOpen.value) {
        cancelReviewRequest();
        return;
    }

    closeModal();
}

/*
 * Formatea una fecha ISO para mostrarla con hora local de Argentina.
 */
function formatDateTime(value) {
    if (!value) {
        return 'Sin fecha';
    }

    return new Intl.DateTimeFormat('es-AR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).format(new Date(value));
}

/*
 * Devuelve el primer error de validación enviado por Inertia.
 */
function firstValidationError(errors) {
    if (!errors || typeof errors !== 'object') {
        return '';
    }

    const first = Object.values(errors)[0];

    if (Array.isArray(first)) {
        return first[0] || '';
    }

    return typeof first === 'string'
        ? first
        : '';
}

/*
 * Aplica aprobar, rechazar/retirar o mantener sobre el comentario abierto.
 */
function moderate(action) {
    if (
        !comment.value
        || isProcessing.value
        || !['approve', 'reject', 'maintain'].includes(action)
    ) {
        return;
    }

    const actionSequence = ++actionRequestSequence;
    processingAction.value = action;
    errorMessage.value = '';

    router.patch(
        route(
            'admin.comments.update',
            comment.value.id,
        ),
        { action },
        {
            preserveState: true,
            preserveScroll: true,

            /*
             * La grilla se actualiza con la respuesta de Laravel. Después de una
             * decisión correcta ya no es necesario conservar abierto el modal.
             */
            onSuccess: () => {
                closeModalAfterSuccess();
            },

            onError: (errors) => {
                errorMessage.value = firstValidationError(errors)
                    || 'No pudimos aplicar la decisión. Intentá nuevamente.';
            },

            onFinish: () => {
                if (
                    actionSequence === actionRequestSequence
                    && !closedAfterSuccess.value
                ) {
                    processingAction.value = '';
                }
            },
        },
    );
}

function requestUserReview() {
    if (
        !comment.value
        || !canReferToAdmin.value
        || comment.value.author?.review_pending
        || isProcessing.value
    ) {
        return;
    }

    reviewRequestOpen.value = true;
    reviewReason.value = '';
    reviewNote.value = '';
    errorMessage.value = '';
}

function cancelReviewRequest() {
    if (isProcessing.value) {
        return;
    }

    resetReviewRequest();
    errorMessage.value = '';
}

/*
 * Deriva la cuenta pública del autor al administrador sin aplicar una sanción.
 */
function referToAdmin() {
    if (
        !comment.value
        || !canReferToAdmin.value
        || comment.value.author?.review_pending
        || !canSubmitReviewRequest.value
    ) {
        return;
    }

    const actionSequence = ++actionRequestSequence;
    processingAction.value = 'refer';
    errorMessage.value = '';
    reviewNote.value = normalizedReviewNote.value;

    router.patch(
        route(
            'admin.comments.refer-to-admin',
            comment.value.id,
        ),
        {
            reason: reviewReason.value,
            note: reviewNote.value,
        },
        {
            preserveState: true,
            preserveScroll: true,

            onSuccess: () => {
                closeModalAfterSuccess();
            },

            onError: (errors) => {
                errorMessage.value = firstValidationError(errors)
                    || 'No pudimos solicitar la revisión del usuario. Intentá nuevamente.';
            },

            onFinish: () => {
                if (
                    actionSequence === actionRequestSequence
                    && !closedAfterSuccess.value
                ) {
                    processingAction.value = '';
                }
            },
        },
    );
}

/*
 * Abre la segunda confirmación antes de eliminar el comentario.
 */
function requestDeleteConfirmation() {
    if (!comment.value || isProcessing.value) {
        return;
    }

    resetReviewRequest();
    deleteConfirmationOpen.value = true;
}

/*
 * Cancela la confirmación de eliminación.
 */
function cancelDeleteConfirmation() {
    if (isProcessing.value) {
        return;
    }

    deleteConfirmationOpen.value = false;
}

/*
 * Elimina definitivamente el comentario después de la confirmación explícita.
 */
function destroyComment() {
    if (!comment.value || isProcessing.value) {
        return;
    }

    const actionSequence = ++actionRequestSequence;
    processingAction.value = 'delete';
    errorMessage.value = '';

    router.delete(
        route(
            'admin.comments.destroy',
            comment.value.id,
        ),
        {
            preserveState: true,
            preserveScroll: true,

            onSuccess: () => {
                closeModalAfterSuccess();
            },

            onError: (errors) => {
                errorMessage.value = firstValidationError(errors)
                    || 'No pudimos eliminar el comentario. Intentá nuevamente.';
            },

            onFinish: () => {
                if (
                    actionSequence === actionRequestSequence
                    && !closedAfterSuccess.value
                ) {
                    processingAction.value = '';
                }
            },
        },
    );
}

/*
 * Cierra el modal después de una operación exitosa y limpia inmediatamente
 * el estado de procesamiento de esa operación.
 */
function closeModalAfterSuccess() {
    detailRequestSequence += 1;
    actionRequestSequence += 1;
    closedAfterSuccess.value = true;

    /*
     * La acción que cerró el modal ya terminó correctamente. Limpiarla aquí es
     * obligatorio porque onFinish no debe borrar el estado de una eventual
     * operación nueva iniciada después de volver a abrir otro comentario.
     */
    processingAction.value = '';
    deleteConfirmationOpen.value = false;
    resetReviewRequest();
    errorMessage.value = '';
    comment.value = null;

    emit('close');
}

/*
 * Cada vez que se abre el modal o cambia el comentario seleccionado se solicita
 * el detalle completo a Laravel.
 */
watch(
    [
        () => props.open,
        () => props.commentId,
    ],
    ([open, commentId]) => {
        if (!open || !commentId) {
            return;
        }

        closedAfterSuccess.value = false;
        loadCommentDetail();
    },
);

/*
 * Bloquea el desplazamiento de la página de fondo mientras el modal
 * de moderación permanece abierto.
 *
 * El contenido interno del modal conserva su propio desplazamiento cuando
 * supera la altura disponible de la pantalla.
 */
watch(
    isModalVisible,
    (isVisible) => {
        document.body.style.overflow = isVisible
            ? 'hidden'
            : '';
    },
    {
        immediate: true,
    },
);

onMounted(() => {
    document.addEventListener(
        'keydown',
        handleEscape,
    );
});

onUnmounted(() => {
    document.removeEventListener(
        'keydown',
        handleEscape,
    );

    /*
     * Restaura el desplazamiento de la página aunque el componente se
     * desmonte mientras el modal todavía se encuentra abierto.
     */
    document.body.style.overflow = '';
});
</script>

<template>
    <div
        v-if="isModalVisible"
        class="admin-modal-backdrop comment-moderation-modal-backdrop"
    >
        <section
            class="comment-moderation-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="comment-moderation-modal-title"
        >
            <!-- Encabezado fijo del modal. -->
            <header class="comment-moderation-modal__header">
                <div>
                    <p class="eyebrow">
                        Moderación
                    </p>

                    <h2 id="comment-moderation-modal-title">
                        Revisar comentario
                    </h2>
                </div>

                <button
                    type="button"
                    class="comment-moderation-modal__close"
                    :disabled="isProcessing"
                    aria-label="Cerrar modal de moderación"
                    @click="closeModal"
                >
                    <X
                        :size="20"
                        aria-hidden="true"
                    />
                </button>
            </header>

            <!-- Error recuperable dentro del modal. -->
            <div
                v-if="errorMessage && !comment"
                class="comment-moderation-modal__error"
                role="alert"
            >
                <CircleAlert
                    :size="18"
                    aria-hidden="true"
                />

                <div>
                    <strong>No se pudo abrir el comentario</strong>
                    <span>{{ errorMessage }}</span>
                </div>

                <button
                    type="button"
                    @click="loadCommentDetail"
                >
                    Reintentar
                </button>
            </div>

            <template v-else-if="comment">
                <div class="comment-moderation-modal__scroll">
                    <!-- Resumen del registro seleccionado. -->
                    <section class="comment-moderation-modal__meta">
                        <div>
                            <span>Estado</span>
                            <strong
                                :class="[
                                    'status-badge',
                                    `status-${comment.status}`,
                                ]"
                            >
                                {{ statusLabels[comment.status] || comment.status }}
                            </strong>
                            <small
                                v-if="isAutomaticRejected"
                                class="comment-moderation-modal__status-origin"
                            >
                                Automático
                            </small>
                        </div>

                        <div>
                            <span>Origen</span>
                            <strong>
                                {{ moderationSourceLabels[comment.moderation_source]
                                    || comment.moderation_source
                                    || 'Histórico' }}
                            </strong>
                        </div>

                        <div>
                            <span>Tipo</span>
                            <strong class="comment-moderation-modal__type">
                                <MessagesSquare
                                    v-if="comment.type === 'reply'"
                                    :size="15"
                                    aria-hidden="true"
                                />

                                <MessageSquare
                                    v-else
                                    :size="15"
                                    aria-hidden="true"
                                />

                                {{ comment.type === 'reply' ? 'Respuesta' : 'Comentario' }}
                            </strong>
                        </div>

                        <div>
                            <span>Fecha</span>
                            <strong>{{ formatDateTime(comment.created_at) }}</strong>
                        </div>

                        <div>
                            <span>Reportes abiertos</span>
                            <strong>{{ comment.open_reports_count || 0 }}</strong>
                        </div>
                    </section>

                    <!-- Motivo resumido de la última decisión automática/editorial. -->
                    <section
                        v-if="comment.moderation_reason"
                        class="comment-moderation-modal__section"
                    >
                        <span class="comment-moderation-modal__label">
                            Motivo de moderación
                        </span>
                        <p class="comment-moderation-modal__secondary">
                            {{ moderationReasonLabels[comment.moderation_reason]
                                || comment.moderation_reason }}
                        </p>
                    </section>

                    <!-- Autor del comentario. -->
                    <section class="comment-moderation-modal__section">
                        <div class="comment-moderation-modal__section-heading">
                            <div>
                                <span>Autor</span>
                                <strong>{{ comment.author?.name || 'Cuenta eliminada' }}</strong>
                            </div>

                            <span
                                v-if="comment.author?.context_label"
                                class="comment-moderation-author-context"
                            >
                                {{ comment.author.context_label }}
                            </span>

                            <span
                                v-else-if="comment.author?.account_blocked"
                                class="comment-moderation-modal__blocked-badge"
                            >
                                <ShieldAlert
                                    :size="14"
                                    aria-hidden="true"
                                />
                                Cuenta bloqueada
                            </span>

                            <span
                                v-else-if="comment.author?.review_pending"
                                class="comment-moderation-modal__referred"
                            >
                                <ShieldAlert
                                    :size="14"
                                    aria-hidden="true"
                                />
                                Cuenta pendiente de revisión
                            </span>
                        </div>

                        <p
                            v-if="comment.author?.email"
                            class="comment-moderation-modal__secondary"
                        >
                            {{ comment.author.email }}
                        </p>

                        <div
                            v-if="comment.author?.review_pending"
                            class="comment-moderation-review-context"
                        >
                            <strong>Cuenta pendiente de revisión</strong>
                            <span v-if="comment.author?.review_reason_label">
                                Motivo: {{ comment.author.review_reason_label }}
                            </span>
                            <p v-if="comment.author?.review_note">
                                {{ comment.author.review_note }}
                            </p>
                        </div>

                        <div
                            v-if="
                                comment.author?.review_resolution === 'no_action'
                                && comment.author?.review_resolved_at
                            "
                            class="comment-moderation-review-context comment-moderation-review-context--resolved"
                        >
                            <strong>Revisión administrativa cerrada</strong>
                            <span>Administración revisó la cuenta y no aplicó un bloqueo.</span>
                            <span v-if="comment.author?.review_resolved_by?.name">
                                Resuelta por {{ comment.author.review_resolved_by.name }}.
                            </span>
                        </div>

                        <div
                            v-if="comment.author?.account_blocked"
                            class="comment-moderation-account-blocked"
                        >
                            <ShieldAlert :size="15" aria-hidden="true" />
                            <div>
                                <strong>Cuenta bloqueada</strong>
                                <span v-if="comment.author?.blocked_reason_label">
                                    {{ comment.author.blocked_reason_label }}
                                </span>
                                <span v-if="comment.author?.blocked_since">
                                    Desde {{ formatDateTime(comment.author.blocked_since) }}
                                </span>
                            </div>
                        </div>
                    </section>

                    <!-- Noticia asociada. -->
                    <section
                        v-if="comment.article"
                        class="comment-moderation-modal__section"
                    >
                        <span class="comment-moderation-modal__label">
                            Noticia
                        </span>

                        <Link
                            :href="route('articles.show', comment.article.slug)"
                            class="comment-moderation-modal__article-link"
                        >
                            <span>{{ comment.article.title }}</span>

                            <ExternalLink
                                :size="15"
                                aria-hidden="true"
                            />
                        </Link>
                    </section>

                    <!-- Contexto de una respuesta. -->
                    <section
                        v-if="comment.parent"
                        class="comment-moderation-modal__section comment-moderation-modal__parent"
                    >
                        <div class="comment-moderation-modal__section-heading">
                            <div>
                                <span>Comentario original</span>
                                <strong>{{ comment.parent.author?.name || 'Cuenta eliminada' }}</strong>
                            </div>

                            <small>{{ formatDateTime(comment.parent.created_at) }}</small>
                        </div>

                        <p class="comment-moderation-modal__body">
                            {{ comment.parent.body }}
                        </p>
                    </section>

                    <!-- Comentario completo que se está moderando. -->
                    <section class="comment-moderation-modal__section comment-moderation-modal__current">
                        <span class="comment-moderation-modal__label">
                            {{ comment.type === 'reply' ? 'Respuesta a moderar' : 'Comentario a moderar' }}
                        </span>

                        <p class="comment-moderation-modal__body">
                            {{ comment.body }}
                        </p>
                    </section>

                    <!-- Reportes que todavía requieren revisión. -->
                    <section
                        v-if="comment.reports?.length"
                        class="comment-moderation-modal__section comment-moderation-modal__reports"
                    >
                        <div class="comment-moderation-modal__reports-title">
                            <Flag
                                :size="17"
                                aria-hidden="true"
                            />

                            <strong>
                                Reportes abiertos
                            </strong>

                            <span>{{ comment.reports.length }}</span>
                        </div>

                        <article
                            v-for="report in comment.reports"
                            :key="report.id"
                            class="comment-moderation-modal__report"
                        >
                            <div>
                                <strong>{{ report.reason_label }}</strong>

                                <small v-if="report.reporter">
                                    {{ report.reporter.name }} · {{ report.reporter.email }}
                                </small>
                            </div>

                            <time :datetime="report.created_at || undefined">
                                {{ formatDateTime(report.created_at) }}
                            </time>
                        </article>
                    </section>

                    <!-- Error ocurrido durante una acción sobre el comentario. -->
                    <div
                        v-if="errorMessage"
                        class="comment-moderation-modal__action-error"
                        role="alert"
                    >
                        <CircleAlert
                            :size="17"
                            aria-hidden="true"
                        />
                        {{ errorMessage }}
                    </div>

                </div>

                <!--
                    Footer fijo: la confirmación destructiva reemplaza las acciones
                    normales para que siempre permanezca visible, incluso con cuerpos
                    o respuestas extensas dentro del área desplazable.
                -->
                <footer class="comment-moderation-modal__actions">
                    <template v-if="deleteConfirmationOpen">
                        <div class="comment-moderation-modal__delete-confirmation comment-moderation-modal__delete-confirmation--footer">
                            <div>
                                <strong>¿Eliminar este comentario?</strong>
                                <p v-if="Number(comment.replies_count || 0) > 0">
                                    También se eliminarán {{ comment.replies_count }} respuesta{{ Number(comment.replies_count) === 1 ? '' : 's' }} asociada{{ Number(comment.replies_count) === 1 ? '' : 's' }}.
                                </p>
                                <p v-else>Esta acción no se puede deshacer.</p>
                            </div>

                            <div class="comment-moderation-modal__delete-confirmation-actions">
                                <button
                                    type="button"
                                    class="comment-moderation-modal__action comment-moderation-modal__action--cancel"
                                    :disabled="isProcessing"
                                    @click="cancelDeleteConfirmation"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="button"
                                    class="comment-moderation-modal__action comment-moderation-modal__action--delete-confirm"
                                    :disabled="isProcessing"
                                    @click="destroyComment"
                                >
                                    {{ processingAction === 'delete' ? 'Eliminando...' : 'Eliminar' }}
                                </button>
                            </div>
                        </div>
                    </template>

                    <template v-else-if="reviewRequestOpen">
                        <div class="comment-moderation-review-request">
                            <div>
                                <strong>Solicitar revisión del usuario</strong>
                                <p>El administrador verá este motivo junto con el comentario usado como evidencia.</p>
                            </div>

                            <label>
                                Motivo
                                <select
                                    v-model="reviewReason"
                                    class="comment-moderation-review-request__select"
                                    :disabled="isProcessing"
                                    required
                                >
                                    <option value="">Seleccionar motivo</option>
                                    <option
                                        v-for="option in reviewReasonOptions"
                                        :key="option.value"
                                        :value="option.value"
                                    >
                                        {{ option.label }}
                                    </option>
                                </select>
                            </label>

                            <label>
                                Observación para administración
                                <textarea
                                    v-model="reviewNote"
                                    rows="3"
                                    :maxlength="REVIEW_NOTE_MAX"
                                    :disabled="isProcessing"
                                    placeholder="Explicá por qué esta cuenta requiere revisión"
                                    @keydown.enter.prevent
                                    @input="handleReviewNoteInput"
                                />
                                <small>{{ reviewNoteCounter() }}</small>
                            </label>

                            <div class="comment-moderation-review-request__actions">
                                <button
                                    type="button"
                                    class="comment-moderation-modal__action comment-moderation-modal__action--cancel"
                                    :disabled="isProcessing"
                                    @click="cancelReviewRequest"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="button"
                                    class="comment-moderation-modal__action comment-moderation-modal__action--refer comment-moderation-modal__action--review-submit"
                                    :disabled="!canSubmitReviewRequest"
                                    @click="referToAdmin"
                                >
                                    <UserRoundCog :size="16" aria-hidden="true" />
                                    <span class="modal-action-label">
                                        <span :class="{ 'is-hidden': processingAction === 'refer' }">
                                            Solicitar revisión
                                        </span>
                                        <span :class="{ 'is-hidden': processingAction !== 'refer' }">
                                            Enviando...
                                        </span>
                                    </span>
                                </button>
                            </div>
                        </div>
                    </template>

                    <template v-else>
                        <div class="comment-moderation-modal__actions-secondary">
                            <button
                                v-if="canReferToAdmin"
                                type="button"
                                class="comment-moderation-modal__action comment-moderation-modal__action--refer"
                                :disabled="isProcessing"
                                @click="requestUserReview"
                            >
                                <UserRoundCog :size="16" aria-hidden="true" />
                                Solicitar revisión
                            </button>

                            <button
                                type="button"
                                class="comment-moderation-modal__action comment-moderation-modal__action--delete"
                                :disabled="isProcessing"
                                @click="requestDeleteConfirmation"
                            >
                                <Trash2 :size="16" aria-hidden="true" />
                                Eliminar
                            </button>
                        </div>

                        <div class="comment-moderation-modal__actions-primary">
                            <button
                                type="button"
                                class="comment-moderation-modal__action comment-moderation-modal__action--cancel"
                                :disabled="isProcessing"
                                @click="closeModal"
                            >
                                Cancelar
                            </button>
                            <button
                                v-if="canMaintain"
                                type="button"
                                class="comment-moderation-modal__action comment-moderation-modal__action--maintain"
                                :disabled="isProcessing"
                                @click="moderate('maintain')"
                            >
                                <Check :size="16" aria-hidden="true" />
                                <span class="modal-action-label">
                                    <span :class="{ 'is-hidden': processingAction === 'maintain' }">
                                        Mantener
                                    </span>
                                    <span :class="{ 'is-hidden': processingAction !== 'maintain' }">
                                        ...
                                    </span>
                                </span>
                            </button>

                            <button
                                v-if="canApprove"
                                type="button"
                                class="comment-moderation-modal__action comment-moderation-modal__action--approve"
                                :disabled="isProcessing"
                                @click="moderate('approve')"
                            >
                                <Check :size="16" aria-hidden="true" />
                                <span class="modal-action-label">
                                    <span :class="{ 'is-hidden': processingAction === 'approve' }">
                                        Aprobar
                                    </span>
                                    <span :class="{ 'is-hidden': processingAction !== 'approve' }">
                                        ...
                                    </span>
                                </span>
                            </button>

                            <button
                                v-if="canReject"
                                type="button"
                                class="comment-moderation-modal__action comment-moderation-modal__action--reject"
                                :disabled="isProcessing"
                                @click="moderate('reject')"
                            >
                                <X :size="16" aria-hidden="true" />
                                <span class="modal-action-label">
                                    <span :class="{ 'is-hidden': processingAction === 'reject' }">
                                        {{ comment.status === 'approved' ? 'Retirar' : 'Rechazar' }}
                                    </span>
                                    <span :class="{ 'is-hidden': processingAction !== 'reject' }">
                                        ...
                                    </span>
                                </span>
                            </button>
                        </div>
                    </template>
                </footer>
            </template>
        </section>
    </div>
</template>
