<script setup>
/* ============================================================================
 * COMPONENT: RevisionHistory.vue
 * ============================================================================
 *
 * Presenta y compara versiones históricas de una noticia.
 *
 * Permite seleccionar una revisión, identificar los campos modificados,
 * comparar la versión anterior con la versión seleccionada y restaurar una
 * versión histórica como borrador.
 * ============================================================================ */

import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { History, RotateCcw } from '@lucide/vue';
import ConfirmActionModal from '@/Components/Shared/ConfirmActionModal.vue';

// Props del historial:
// revisions contiene las versiones guardadas, article identifica la noticia
// actual y canRestore habilita o bloquea el botón para restaurar una versión.
const props = defineProps({
    revisions: {
        type: Array,
        default: () => [],
    },
    article: {
        type: Object,
        required: true,
    },
    categories: {
        type: Array,
        default: () => [],
    },
    canRestore: {
        type: Boolean,
        default: false,
    },
});

// ID de la revisión seleccionada en la lista lateral.
const selectedId = ref(props.revisions[0]?.id || null);

// Revisión cuya restauración está pendiente de confirmación.
const restoreTarget = ref(null);
// Bloquea el modal mientras se procesa la restauración.
const restoreProcessing = ref(false);

// Revisión completa que corresponde al ID seleccionado.
const selected = computed(() => {
    return props.revisions.find(
        (revision) => revision.id === selectedId.value
    ) || null;
});

/*
 * Posición de la revisión seleccionada dentro de la lista.
 *
 * Las revisiones llegan ordenadas desde la más reciente hasta la más antigua.
 */
const selectedIndex = computed(() => {
    return props.revisions.findIndex(
        (revision) => revision.id === selectedId.value
    );
});

/*
 * Revisión cronológicamente anterior a la seleccionada.
 *
 * Como la lista está ordenada de la más nueva a la más antigua,
 * la revisión anterior se encuentra en la posición siguiente.
 *
 * Ejemplo:
 *
 * 17:35 seleccionada → revisión anterior: 16:53
 * 16:53 seleccionada → revisión anterior: 16:52
 */
const previousRevision = computed(() => {
    if (selectedIndex.value === -1) {
        return null;
    }

    return props.revisions[selectedIndex.value + 1] || null;
});

/*
 * Campos técnicos que permanecen guardados en el snapshot,
 * pero no deben mostrarse como filas independientes.
 */
const hiddenHistoryFields = [
    'slug',
    'cover_alt',
    'tag_ids',
    'captured_at',
];

/*
 * Devuelve únicamente los campos que aportan información al historial.
 *
 * En la revisión de creación se ocultan valores vacíos, listas vacías
 * e indicadores desactivados que corresponden al estado predeterminado
 * de una noticia nueva.
 */
const visibleChangedFields = computed(() => {
    /*
     * Obtiene los campos que el backend registró como modificados.
     *
     * Si la revisión seleccionada no contiene changed_fields,
     * se utiliza un array vacío para evitar errores al ejecutar filter().
     *
     * Ejemplo:
     *
     *      selected.value.changed_fields = null
     *
     * Resultado:
     *
     *      fields = []
     */
    const fields = selected.value?.changed_fields || [];

    /*
     * Recorre cada campo registrado y decide si debe mostrarse
     * dentro de la comparación visual del historial.
     */
    return fields.filter((field) => {
        /*
         * Excluye campos técnicos que deben permanecer guardados
         * dentro del snapshot, pero que no aportan información
         * útil para quien consulta el historial.
         *
         * Ejemplos:
         *
         *      cover_alt:
         *          texto alternativo interno de la portada.
         *
         *      tag_names:
         *          nombres auxiliares de las etiquetas.
         *
         *      captured_at:
         *          fecha técnica en la que se generó el snapshot.
         */
        if (hiddenHistoryFields.includes(field)) {
            return false;
        }

        /*
         * Oculta el slug provisional únicamente en la revisión de creación.
         */
         if (
            selected.value?.action === 'created'
            && field === 'slug'
        ) {
            return false;
        }

        /*
         * En las revisiones posteriores a la creación,
         * changed_fields ya contiene únicamente los campos
         * que realmente fueron modificados.
         *
         * Ejemplo:
         *
         *      action: 'updated'
         *      changed_fields: ['title', 'body']
         *
         * Ambos campos deben mostrarse aunque el nuevo valor
         * sea vacío, porque eliminar contenido también representa
         * una modificación de la noticia.
         */
        if (selected.value?.action !== 'created') {
            return true;
        }

        /*
         * Obtiene el valor almacenado para el campo actual
         * dentro del snapshot de la revisión de creación.
         *
         * Ejemplo:
         *
         *      field = 'status'
         *      value = 'draft'
         */
        const value = selected.value?.snapshot?.[field];

        /*
         * En la revisión de creación solo se muestran los
         * indicadores editoriales que fueron activados.
         *
         * Los valores false corresponden al estado predeterminado
         * de una noticia nueva y no representan una acción realizada.
         *
         * Ejemplos:
         *
         *      is_breaking: false
         *          No se muestra.
         *
         *      is_breaking: true
         *          Se muestra como "Urgente: Sí".
         */
        if (
            [
                'is_breaking',
                'is_featured',                
            ].includes(field)
        ) {
            return value === true;
        }

        /*
         * Los valores almacenados como arrays representan
         * principalmente etiquetas o relaciones.
         *
         * Solo se muestran cuando contienen al menos un elemento.
         *
         * Ejemplos:
         *
         *      tag_ids: []
         *          No se muestra.
         *
         *      tag_ids: [2, 5]
         *          Se muestra.
         */
        if (Array.isArray(value)) {
            return value.length > 0;
        }

        /*
         * El editor puede guardar un cuerpo visualmente vacío
         * utilizando etiquetas HTML sin texto visible.
         *
         * Ejemplos:
         *
         *      <p></p>
         *      <p><br></p>
         *
         * Se eliminan las etiquetas HTML antes de comprobar
         * si existe contenido real.
         */
        if (field === 'body') {
            const visibleBodyText = String(value || '')
                .replace(/<[^>]*>/g, '')
                .trim();

            /*
             * Si después de eliminar las etiquetas no queda texto,
             * la fila "Cuerpo" no debe mostrarse.
             */
            return visibleBodyText !== '';
        }

        /*
         * Para los demás campos de la revisión de creación,
         * solo se muestran valores que contengan información.
         *
         * Ejemplos:
         *
         *      title: null
         *          No se muestra.
         *
         *      subtitle: ''
         *          No se muestra.
         *
         *      status: 'draft'
         *          Se muestra.
         *
         *      slug: 'borrador-uuid'
         *          Se muestra.
         */
        return (
            value !== null
            && value !== undefined
            && value !== ''
        );
    });
});

// Textos visibles para las acciones guardadas en el historial.
const actionLabels = {
    created: 'Creación',

    created_and_submitted: 'Creada y enviada a revisión',
    created_and_scheduled: 'Creada y programada',
    created_and_published: 'Creada y publicada',

    submitted_for_review: 'Enviada a revisión',
    updated: 'Actualización',
    changes_requested: 'Devuelta para corrección',
    resubmitted: 'Reenvío a revisión',

    restored_as_draft: 'Restauración como borrador',
    archived: 'Archivo',
    published_automatically: 'Publicación programada',
};

/*
 * Textos visibles para los estados editoriales almacenados
 * internamente en inglés dentro de la base de datos.
 */
const statusLabels = {
    draft: 'Borrador',
    review: 'En revisión',
    needs_changes: 'Devuelta para corrección',
    scheduled: 'Programada',
    published: 'Publicada',
    archived: 'Archivada',
};

// Textos visibles para los campos técnicos guardados en el snapshot.
const fieldLabels = {
    title: 'Título',
    subtitle: 'Bajada',
    excerpt: 'Resumen',
    body: 'Cuerpo',
    category_id: 'Categoría',
    cover_image: 'Imagen de portada',
    status: 'Estado',
    tag_names: 'Etiquetas',
    is_breaking: 'Urgente',
    is_featured: 'Destacada',    
    tag_ids: 'Etiquetas',
    published_at: 'Fecha de publicación',
    scheduled_at: 'Programación',    
};

/*
 * Devuelve el número visible de una revisión dentro del historial
 * de la noticia actual.
 *
 * Las revisiones llegan ordenadas desde la más reciente hasta la más antigua.
 * Por eso, la primera posición representa la revisión más nueva y la última
 * corresponde a la revisión número 1.
 */
function revisionNumber(revisionId) {
    const index = props.revisions.findIndex(
        (revision) => revision.id === revisionId
    );

    if (index === -1) {
        return '—';
    }

    return props.revisions.length - index;
}

// Formatea la fecha de una revisión utilizando el horario de 24 horas.
function formatDate(value) {
    if (!value) {
        return 'Sin fecha';
    }

    return new Intl.DateTimeFormat('es-AR', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).format(new Date(value));
}

/*
 * Convierte los valores técnicos guardados dentro del snapshot
 * en textos comprensibles para quien consulta el historial.
 */
function displayValue(field, value) {
    /*
     * Los valores vacíos se representan mediante un guion para evitar
     * mostrar null, undefined o espacios sin contenido.
     */
    if (
        value === null
        || value === undefined
        || value === ''
    ) {
        return '—';
    }

    /*
     * Los snapshots guardan category_id para poder restaurar
     * la relación, pero el historial debe mostrar su nombre.
     */
    if (field === 'category_id') {
        return categoryName(value);
    }

    /*
     * Traduce los códigos internos de los estados editoriales.
     *
     * Ejemplos:
     *
     *      draft          → Borrador
     *      review         → En revisión
     *      needs_changes  → Devuelta para corrección
     */
    if (field === 'status') {
        return statusLabels[value] || String(value);
    }

    /*
     * Convierte los indicadores booleanos en respuestas visibles.
     *
     * Ejemplos:
     *
     *      true   → Sí
     *      false  → No
     */
    if (typeof value === 'boolean') {
        return value ? 'Sí' : 'No';
    }

    /*
     * Presenta los elementos de una lista separados por comas.
     *
     * Ejemplo:
     *
     *      ['Política', 'Economía']
     *
     * Resultado:
     *
     *      Política, Economía
     */
    if (Array.isArray(value)) {
        return value.join(', ') || '—';
    }

    /*
     * Los demás valores se convierten a texto sin modificar
     * su contenido original.
     */
    return String(value);
}

// Obtiene solamente el nombre del archivo de una portada.
//
// Ejemplo:
// /images/articles/borrador-uuid.webp
//
// Resultado:
// borrador-uuid.webp
function imageFileName(path) {
    if (!path) {
        return '';
    }

    return String(path).split(/[\\/]/).pop() || '';
}

/* =============================================================================
 * MODAL 
 * ============================================================================= */

// Abre el modal para confirmar la restauración de la revisión seleccionada.
function openRestoreConfirmation() {
    if (
        !props.canRestore
        || !selected.value
        || restoreProcessing.value
    ) {
        return;
    }

    restoreTarget.value = selected.value;
}

// Cierra el modal mientras no haya una restauración en curso.
function closeRestoreConfirmation() {
    if (restoreProcessing.value) {
        return;
    }

    restoreTarget.value = null;
}

// Restaura la revisión seleccionada como un nuevo borrador editable.
function confirmRestoreRevision() {
    if (
        !props.canRestore
        || !restoreTarget.value
        || restoreProcessing.value
    ) {
        return;
    }

    restoreProcessing.value = true;

    router.post(
        route('admin.articles.revisions.restore', {
            article: props.article.id,
            revision: restoreTarget.value.id,
        }),
        {},
        {
            preserveScroll: true,

            // Cierra el modal cuando Laravel completa la restauración.
            onSuccess: () => {
                restoreTarget.value = null;
            },

            // Cierra la confirmación para dejar visible el mensaje general del formulario.
            onError: () => {
            restoreTarget.value = null;
            },

            // Vuelve a habilitar los controles aunque la petición falle.
            onFinish: () => {
                restoreProcessing.value = false;
            },
        }
    );
}

/* =============================================================================== */

/*
 * Obtiene el nombre visible de una categoría a partir
 * del identificador almacenado en el snapshot.
 *
 * Ejemplo:
 *
 *      categoryId: 1
 *
 * Resultado:
 *
 *      Política
 */
function categoryName(categoryId) {
    if (
        categoryId === null
        || categoryId === undefined
        || categoryId === ''
    ) {
        return '—';
    }

    const category = props.categories.find(
        (item) => String(item.id) === String(categoryId)
    );

    return category?.name || 'Categoría no disponible';
}

</script>

<template>
    <section class="revision-layout">
        <aside class="revision-list">
            <div class="revision-list-heading">
                <History :size="20" />

                <div>
                    <strong>Versiones registradas</strong>
                    <span>{{ revisions.length }} revisiones</span>
                </div>
            </div>

            <button
                v-for="revision in revisions"
                :key="revision.id"
                type="button"
                :class="[
                    'revision-list-item',
                    { active: revision.id === selectedId },
                ]"
                @click="selectedId = revision.id"
            >
                <strong>
                    Revisión #{{ revisionNumber(revision.id) }}
                    ·
                    {{ actionLabels[revision.action] || revision.action }}
                </strong>

                <span>
                    {{ revision.user?.name || 'Sistema' }}
                </span>

                <small>
                    {{ formatDate(revision.created_at) }}
                </small>
            </button>

            <p
                v-if="!revisions.length"
                class="empty-state"
            >
                Todavía no hay revisiones para mostrar.
            </p>
        </aside>

        <article
            v-if="selected"
            class="revision-detail"
        >
            <header class="revision-detail-heading">
                <div>
                    <p class="eyebrow">
                        Revisión #{{ revisionNumber(selected.id) }}
                    </p>

                    <h2>
                        {{ actionLabels[selected.action] || selected.action }}
                    </h2>

                    <span>
                        {{ selected.user?.name || 'Sistema' }}
                        ·
                        {{ formatDate(selected.created_at) }}
                    </span>
                </div>
                <button
                    v-if="canRestore"
                    type="button"
                    class="admin-primary-action"
                    :disabled="restoreProcessing"
                    @click="openRestoreConfirmation"
                >
                    <RotateCcw :size="17" />
                    Restaurar como borrador
                </button>
            </header>

            <div class="revision-changed-fields">
                <strong>Campos modificados</strong>

                <span
                    v-for="field in visibleChangedFields"
                    :key="field"
                >
                    {{ fieldLabels[field] || field }}
                </span>

                <small v-if="!visibleChangedFields?.length">
                    Sin diferencias calculadas para esta versión.
                </small>
            </div>

            <div class="revision-field-table">
                <!-- Cabecera de las columnas utilizadas para comparar las revisiones. -->
                <header class="revision-field-table-heading">
                    <div class="revision-field-heading-cell">
                        <span>Campo modificado</span>
                    </div>

                    <div class="revision-field-heading-cell">
                        <strong>Versión anterior</strong>
                    </div>

                    <div class="revision-field-heading-cell">
                        <strong>Versión actualizada</strong>
                    </div>
                </header>
                <div
                    v-for="field in visibleChangedFields"
                    :key="`value-${field}`"
                    :class="{ 'revision-body-row': field === 'body' }"
                >
                    <strong>
                        {{ fieldLabels[field] || field }}
                    </strong>

                    <!--
                        La portada se compara visualmente:
                        revisión anterior contra revisión seleccionada.
                    -->
                    <template v-if="field === 'cover_image'">
                        <!-- Portada de la revisión anterior. -->
                        <div class="revision-cover">
                            <img
                                v-if="previousRevision?.snapshot?.[field]"
                                :src="previousRevision.snapshot[field]"
                                :alt="imageFileName(
                                    previousRevision.snapshot[field]
                                )"
                                class="revision-cover-image"
                            >

                            <span
                                v-else
                                class="revision-cover-empty"
                            >
                                Sin imagen
                            </span>
                        </div>

                        <!-- Portada de la revisión seleccionada. -->
                        <div class="revision-cover">
                            <img
                                v-if="selected.snapshot?.[field]"
                                :src="selected.snapshot[field]"
                                :alt="imageFileName(
                                    selected.snapshot[field]
                                )"
                                class="revision-cover-image"
                            >

                            <span
                                v-else
                                class="revision-cover-empty"
                            >
                                Sin imagen
                            </span>
                        </div>
                    </template>
                    <!--
                    El cuerpo se renderiza como HTML para conservar párrafos,
                    títulos, listas y saltos de línea del editor.
                    -->
                    <template v-else-if="field === 'body'">
                        <!-- Cuerpo de la versión anterior. -->
                        <div class="revision-body-value">
                            <div
                                v-if="previousRevision?.snapshot?.[field]"
                                class="revision-body-content"
                                v-html="previousRevision.snapshot[field]"
                            />

                            <span
                                v-else
                                class="revision-body-empty"
                            >
                                Sin contenido
                            </span>
                        </div>

                        <!-- Cuerpo de la versión actualizada. -->
                        <div class="revision-body-value">
                            <div
                                v-if="selected.snapshot?.[field]"
                                class="revision-body-content"
                                v-html="selected.snapshot[field]"
                            />

                            <span
                                v-else
                                class="revision-body-empty"
                            >
                                Sin contenido
                            </span>
                        </div>
                    </template>
                    <!--
                        Los demás campos también comparan la revisión
                        anterior con la revisión seleccionada.
                    -->
                    <template v-else>
                        <span>
                            {{
                            displayValue(
                                field,
                                previousRevision?.snapshot?.[field]
                            )
                            }}
                        </span>

                        <span>
                            {{
                                displayValue(
                                    field,
                                    selected.snapshot?.[field]
                                )
                            }}
                        </span>
                    </template>
                </div>
            </div>
        </article>
    </section>
    <!-- Muestra el modal para confirmar la restauración de una revisión como borrador editable. -->
    <ConfirmActionModal
        :open="restoreTarget !== null"
        eyebrow="Restaurar versión"
        :title="restoreTarget
            ? `¿Restaurar la revisión #${revisionNumber(restoreTarget.id)}?`
            : ''"
        message="La versión seleccionada se copiará como un nuevo borrador editable."
        help="La noticia actual y las revisiones existentes permanecerán guardadas en el historial."
        confirm-label="Restaurar como borrador"
        processing-label="Restaurando..."
        :processing="restoreProcessing"
        @confirm="confirmRestoreRevision"
        @cancel="closeRestoreConfirmation"
    />
</template>
