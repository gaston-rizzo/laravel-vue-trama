<script setup>
/* ============================================================================
 * COMPONENT: ConfirmActionModal.vue
 * ============================================================================
 *
 * Modal reutilizable para confirmar acciones importantes dentro del CMS.
 *
 * Permite mostrar un título, una explicación y botones para cancelar o
 * confirmar. También bloquea los controles mientras la petición está siendo
 * procesada para evitar acciones repetidas.
 *
 * IMPORTANTE: No reemplaza el modal de cambios sin guardar de Articles/Form.vue.
 * Ese modal conserva su implementación propia porque necesita tres acciones:
 * seguir editando, salir sin guardar y guardar antes de abandonar la página.
 * ============================================================================ */

import { onBeforeUnmount, onMounted } from 'vue';
import { X } from '@lucide/vue';

// Props de la modal:
// - open: indica si debe mostrarse.
// - eyebrow: texto pequeño ubicado encima del título.
// - title: pregunta o título principal de la confirmación.
// - message: explicación de la acción que se va a realizar.
// - help: aclaración adicional mostrada debajo del mensaje.
// - confirmLabel: texto del botón que ejecuta la acción.
// - cancelLabel: texto del botón que cierra la modal.
// - processingLabel: texto mostrado mientras se procesa la petición.
// - processing: bloquea los controles mientras se ejecuta la acción.
// - danger: aplica el estilo visual de una acción destructiva.
const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    eyebrow: {
        type: String,
        default: 'Confirmación',
    },
    title: {
        type: String,
        required: true,
    },
    message: {
        type: String,
        default: '',
    },
    help: {
        type: String,
        default: '',
    },
    // Mensaje seguro de una operación fallida. Se muestra dentro de la modal
    // para que el usuario no tenga que buscar el error fuera del contexto activo.
    error: {
        type: String,
        default: '',
    },
    confirmLabel: {
        type: String,
        default: 'Confirmar',
    },
    cancelLabel: {
        type: String,
        default: 'Cancelar',
    },
    processingLabel: {
        type: String,
        default: 'Procesando...',
    },
    processing: {
        type: Boolean,
        default: false,
    },
    confirmButtonClass: {
        type: [String, Array, Object],
        default: '',
    },
    danger: {
        type: Boolean,
        default: false,
    },
});

// La página que utiliza la modal decide qué hacer al confirmar o cancelar.
const emit = defineEmits([
    'confirm',
    'cancel',
]);

// Cierra la modal únicamente cuando no hay una petición en curso.
function cancel() {
    if (props.processing) {
        return;
    }

    emit('cancel');
}

// Ejecuta la acción únicamente cuando no hay otra petición en curso.
function confirm() {
    if (props.processing) {
        return;
    }

    emit('confirm');
}

// Escape equivale siempre a Cancelar. Nunca confirma acciones importantes y se
// ignora mientras la petición está en curso para no ocultar una operación activa.
function handleEscape(event) {
    if (event.key !== 'Escape' || !props.open || props.processing) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();
    cancel();
}

// Al montar el modal, registra el listener global que permite cerrarlo
// mediante la tecla Escape cuando no hay una operación en proceso.
onMounted(() => document.addEventListener('keydown', handleEscape));

// Antes de desmontar el componente, elimina el listener global para evitar
// que el manejador de Escape permanezca activo innecesariamente.
onBeforeUnmount(() => document.removeEventListener('keydown', handleEscape));
</script>

<template>
    <!-- El fondo bloquea la pantalla, pero no cierra la modal al hacer click afuera. -->
    <div
        v-if="open"
        class="admin-modal-backdrop"
    >
        <section
            class="admin-modal unsaved-changes-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="confirm-action-modal-title"
            :aria-busy="processing"
        >
            <header>
                <div>
                    <p class="eyebrow">
                        {{ eyebrow }}
                    </p>

                    <h2 id="confirm-action-modal-title">
                        {{ title }}
                    </h2>
                </div>

                <button
                    type="button"
                    title="Cerrar"
                    :disabled="processing"
                    @click="cancel"
                >
                    <X />
                </button>
            </header>

            <!-- Permite reemplazar el mensaje simple por contenido personalizado. -->
            <div
                v-if="$slots.default"
                class="modal-copy"
            >
                <slot />
            </div>

            <p
                v-else-if="message"
                class="modal-copy"
            >
                {{ message }}
            </p>

            <!-- Permite agregar una aclaración simple o contenido personalizado. -->
            <div
                v-if="$slots.help"
                class="unsaved-changes-help"
            >
                <slot name="help" />
            </div>

            <p
                v-else-if="help"
                class="unsaved-changes-help"
            >
                {{ help }}
            </p>

            <p
                v-if="error"
                class="operation-error confirm-action-modal-error"
                role="alert"
            >
                {{ error }}
            </p>

            <div class="unsaved-changes-actions">
                <button
                    type="button"
                    class="secondary-admin-link"
                    :disabled="processing"
                    @click="cancel"
                >
                    {{ cancelLabel }}
                </button>

                <button
                    type="button"
                    :class="[
                        danger
                            ? 'danger-outline-button'
                            : 'admin-primary-action',
                        confirmButtonClass,
                    ]"
                    :disabled="processing"
                    @click="confirm"
                >
                    <!--
                        Mantiene simultáneamente los dos textos dentro del cálculo
                        del ancho. Solo uno queda visible, por lo que el botón conserva
                        su tamaño cuando pasa del estado normal al procesamiento.
                    -->
                    <span class="modal-action-label">
                        <span :class="{ 'is-hidden': processing }">
                            {{ confirmLabel }}
                        </span>
                        <span :class="{ 'is-hidden': !processing }">
                            {{ processingLabel }}
                        </span>
                    </span>
                </button>
            </div>
        </section>
    </div>
</template>
