<script setup>
/* ============================================================================
 * PAGE: Public/Contact.vue
 * ============================================================================
 *
 * Página de contacto de TRAMA.
 *
 * Permite que un visitante o usuario autenticado envíe una consulta general
 * relacionada con su cuenta, contenido editorial, publicidad u otros temas.
 *
 * El formulario:
 *
 *      - Precarga nombre y correo cuando Laravel dispone de esos datos;
 *      - Valida de forma inmediata los campos básicos antes del submit;
 *      - Mantiene la validación definitiva del lado de Laravel;
 *      - Exige entre 15 y 1200 caracteres útiles para el mensaje;
 *      - Muestra un contador dinámico equivalente al utilizado en otros
 *        formularios editoriales de TRAMA;
 *      - Evita cambios de ancho del botón mientras la consulta se envía;
 *      - Limpia errores anteriores cuando el usuario corrige un campo;
 *      - Bloquea todos los controles mientras se procesa la consulta;
 *      - Mantiene todo el formulario deshabilitado después de un envío exitoso.
 *
 * Laravel continúa siendo la fuente definitiva de validación y persistencia.
 * ============================================================================ */

import { computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { route } from 'ziggy-js';

/* ============================================================================
 * 1. LÍMITES Y VALIDACIÓN LOCAL
 * ============================================================================ */

/*
 * Cantidad mínima de caracteres útiles exigida para una consulta.
 *
 * El cálculo utiliza `trim()` para que espacios al principio o al final no
 * permitan habilitar artificialmente el botón de envío.
 */
const CONTACT_NAME_MAX = 40;
const CONTACT_EMAIL_MAX = 50;
const CONTACT_MESSAGE_MIN = 15;

/*
 * Máximo permitido para el mensaje.
 *
 * Este valor debe permanecer sincronizado con PublicContactRequest.php.
 */
const CONTACT_MESSAGE_MAX = 1200;

/*
 * Validación local del formato del correo.
 *
 * Replica el criterio principal utilizado por StrictEmail en Laravel:
 * exige usuario, arroba, dominio y una extensión de al menos dos caracteres.
 *
 * Ejemplos:
 *
 *      usuario@gmail.com       -> válido
 *      nombre@empresa.com.ar   -> válido
 *      2@test                  -> inválido
 */
const STRICT_EMAIL_PATTERN =
    /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/i;

/* ============================================================================
 * 2. PROPS ENVIADAS POR LARAVEL
 * ============================================================================ */

/*
 * Cuando el visitante ya inició sesión, Laravel puede enviar su nombre y correo
 * para evitar que tenga que escribir nuevamente información conocida.
 *
 * Para invitados ambos valores pueden llegar vacíos.
 */
const props = defineProps({
    defaultName: {
        type: String,
        default: '',
    },

    defaultEmail: {
        type: String,
        default: '',
    },
});

/* ============================================================================
 * 3. ESTADO DE INERTIA Y FORMULARIO
 * ============================================================================ */

/*
 * Props globales compartidas por Inertia.
 *
 * Se utilizan principalmente para leer el mensaje flash devuelto por Laravel
 * después de guardar correctamente una consulta.
 */
const page = usePage();

/*
 * Formulario público de Contacto.
 *
 * "account" queda seleccionado inicialmente porque es la primera opción visible
 * del combo actual.
 */
const form = useForm({
    reason: 'account',
    name: props.defaultName,
    email: props.defaultEmail,
    message: '',
});

/* ============================================================================
 * 4. ESTADOS DERIVADOS DEL FORMULARIO
 * ============================================================================ */

/*
 * Indica si Laravel devolvió una confirmación después del último envío.
 *
 * Ejemplo:
 *
 *      "Recibimos tu consulta. El equipo de TRAMA la revisará."
 */
const submitted = computed(
    () => Boolean(page.props.flash?.status)
);

/*
 * Determina si el formulario completo debe permanecer bloqueado.
 *
 * Se bloquea:
 *
 *      - Mientras Inertia está enviando la consulta;
 *      - Después de que Laravel confirmó un envío exitoso.
 *
 * De esta manera, una consulta ya enviada no puede modificarse ni enviarse
 * nuevamente desde el mismo formulario.
 */
const formLocked = computed(
    () => form.processing || submitted.value
);

/*
 * Cantidad total de caracteres actualmente visibles dentro del textarea.
 *
 * El contador muestra el valor escrito tal como lo ve el usuario.
 */
const messageLength = computed(
    () => form.message.length
);

/*
 * Cantidad de caracteres útiles del mensaje.
 *
 * Para decidir si se alcanzó el mínimo no se consideran espacios sobrantes al
 * principio ni al final.
 */
const trimmedMessageLength = computed(
    () => form.message.trim().length
);

/*
 * Cantidad de caracteres que todavía faltan para alcanzar el mínimo.
 *
 * Cuando llega a cero, el contador deja de mostrar la aclaración adicional.
 */
const missingMessageCharacters = computed(
    () => Math.max(
        0,
        CONTACT_MESSAGE_MIN - trimmedMessageLength.value
    )
);

/*
 * El nombre debe contener al menos dos caracteres útiles.
 *
 * La validación completa continúa ejecutándose también en Laravel.
 */
const nameIsValid = computed(() => {
    const length = form.name.trim().length;

    return length >= 2 && length <= CONTACT_NAME_MAX;
});

/*
 * Comprueba localmente que el correo tenga una estructura completa.
 *
 * Esto evita aceptar visualmente valores como `2@test` antes de llegar al
 * backend.
 */
const emailIsValid = computed(() => {
    const email = form.email.trim();

    return email.length <= CONTACT_EMAIL_MAX
        && STRICT_EMAIL_PATTERN.test(email);
});

/*
 * Controla si el botón "Enviar consulta" puede utilizarse.
 *
 * El envío solamente queda disponible cuando:
 *
 *      - El formulario no está bloqueado;
 *      - El nombre tiene al menos dos caracteres útiles;
 *      - El correo tiene formato válido;
 *      - El mensaje alcanzó los 15 caracteres útiles.
 *
 * Una vez enviada correctamente la consulta, `formLocked` permanece activo y
 * el botón ya no puede volver a utilizarse.
 */
const canSubmit = computed(
    () =>
        !formLocked.value
        && nameIsValid.value
        && emailIsValid.value
        && trimmedMessageLength.value >= CONTACT_MESSAGE_MIN
        && messageLength.value <= CONTACT_MESSAGE_MAX
);

/* ============================================================================
 * 5. MANEJO DE ERRORES
 * ============================================================================ */

/**
 * Limpia el error visible de un campo cuando el usuario comienza a corregirlo.
 *
 * También elimina un posible error general de operación para que un fallo viejo
 * no permanezca visible después de que el formulario fue modificado.
 *
 * @param {string} field
 * @returns {void}
 */
function clearFieldError(field) {
    form.clearErrors(field);
    form.clearErrors('operation');
}

/**
 * Mantiene como máximo una línea vacía entre párrafos.
 *
 * Un Enter se conserva, dos Enter también, y desde el tercero en adelante
 * se reduce automáticamente a dos. No aplica trim mientras el usuario escribe
 * para no impedir que termine una línea con Enter.
 */
function normalizeContactMessage(value) {
    return String(value || '')
        .replace(/\r\n?/g, '\n')
        .replace(/[ \t]*\n[ \t]*/g, '\n')
        .replace(/\n{3,}/g, '\n\n');
}

/**
 * Normaliza el textarea en cada input para que la propia interfaz nunca conserve
 * bloques enormes de líneas vacías.
 */
function handleMessageInput(event) {
    const normalized = normalizeContactMessage(
        event?.target?.value ?? form.message,
    ).slice(0, CONTACT_MESSAGE_MAX);

    form.message = normalized;
    clearFieldError('message');

    if (event?.target && event.target.value !== normalized) {
        event.target.value = normalized;
    }
}

/* ============================================================================
 * 6. ENVÍO DEL FORMULARIO
 * ============================================================================ */

/**
 * Normaliza y valida los datos básicos antes de enviarlos a Laravel.
 *
 * Esta validación local mejora la experiencia de usuario, pero no sustituye las
 * reglas de PublicContactRequest.php. Laravel vuelve a validar obligatoriamente
 * todos los datos recibidos.
 *
 * @returns {void}
 */
function submit() {
    /*
     * Impide el envío tanto mientras Inertia está procesando como después de
     * que la consulta fue enviada correctamente.
     *
     * Aunque el botón permanece deshabilitado en ambos casos, esta comprobación
     * protege también frente a llamadas manuales a la función.
     */
    if (formLocked.value) {
        return;
    }

    /*
     * El formulario puede conservar errores devueltos por una petición anterior.
     * Antes del nuevo intento se limpian para evaluar el estado actual.
     */
    form.clearErrors();

    /*
     * Elimina espacios sobrantes de los campos antes de validar y enviar.
     *
     * Laravel vuelve a aplicar su propia normalización como segunda barrera.
     */
    form.name = form.name.trim();
    form.email = form.email.trim();
    form.message = normalizeContactMessage(form.message).trim();

    /*
     * El nombre debe contener al menos dos caracteres útiles.
     */
    if (form.name.length < 2) {
        form.setError(
            'name',
            'El nombre debe tener al menos 2 caracteres.'
        );

        return;
    }

    if (form.name.length > CONTACT_NAME_MAX) {
        form.setError(
            'name',
            'El nombre no puede superar los 40 caracteres.'
        );

        return;
    }

    /*
     * Rechaza correos incompletos como `2@test` sin esperar la respuesta
     * del servidor.
     */
    if (
        form.email.length > CONTACT_EMAIL_MAX
        || !STRICT_EMAIL_PATTERN.test(form.email)
    ) {
        form.setError(
            'email',
            form.email.length > CONTACT_EMAIL_MAX
                ? 'El correo no puede superar los 50 caracteres.'
                : 'Ingresá un correo electrónico válido.'
        );

        return;
    }

    /*
     * Una consulta debe aportar un mínimo razonable de contexto.
     */
    if (form.message.length < CONTACT_MESSAGE_MIN) {
        form.setError(
            'message',
            'La consulta debe tener al menos 15 caracteres.'
        );

        return;
    }

    /*
     * Protección adicional frente a un valor que supere el máximo por una
     * manipulación externa del DOM.
     *
     * Normalmente maxlength ya impide llegar a este punto desde la interfaz.
     */
    if (form.message.length > CONTACT_MESSAGE_MAX) {
        form.setError(
            'message',
            'La consulta no puede superar los 1200 caracteres.'
        );

        return;
    }

    /*
     * Laravel valida nuevamente, guarda la consulta y devuelve el mensaje
     * flash correspondiente.
     *
     * Después de un envío exitoso no se resetean los campos. De esta manera,
     * el usuario continúa viendo exactamente la consulta que acaba de enviar
     * mientras todo el formulario permanece bloqueado.
     */
    form.post(
        route('contact.store'),
        {
            preserveScroll: true,
        }
    );
}
</script>

<template>
    <Head title="Contacto" />

    <!--
        Página de contacto dividida en dos columnas:
        presentación de TRAMA a la izquierda y formulario a la derecha.
    -->
    <section
        class="contact-page contact-layout news-container"
    >
        <!-- Información introductoria de la página. -->
        <div>
            <p class="eyebrow">
                Sobre TRAMA
            </p>

            <h1>
                Contacto
            </h1>

            <p class="contact-intro">
                Escribinos por consultas editoriales,
                publicitarias o relacionadas
                con tu cuenta.
            </p>

        </div>

        <!--
            Formulario principal de contacto.

            Inertia administra errores de validación, estado de procesamiento
            y respuesta del servidor.
        -->
        <form
            class="contact-form"
            @submit.prevent="submit"
        >
            <!-- Confirmación devuelta por Laravel después de un envío exitoso. -->
            <p
                v-if="submitted"
                class="flash-message"
            >
                {{ page.props.flash.status }}
            </p>

            <!-- Error general de la operación, independiente de un campo puntual. -->
            <p
                v-if="form.errors.operation"
                class="operation-error"
            >
                {{ form.errors.operation }}
            </p>

            <!-- Motivo principal de la consulta. -->
            <label>
                Motivo

                <select
                    v-model="form.reason"
                    :disabled="formLocked"
                    @change="clearFieldError('reason')"
                >
                    <option value="account">
                        Problemas con mi cuenta
                    </option>

                    <option value="editorial">
                        Editorial
                    </option>

                    <option value="advertising">
                        Publicidad
                    </option>

                    <option value="other">
                        Otro
                    </option>
                </select>

                <span v-if="form.errors.reason">
                    {{ form.errors.reason }}
                </span>
            </label>

            <!-- Nombre y correo comparten una fila en desktop. -->
            <div class="contact-form-grid">
                <label>
                    Nombre

                    <input
                        v-model="form.name"
                        type="text"
                        :maxlength="CONTACT_NAME_MAX"
                        autocomplete="name"
                        required
                        :disabled="formLocked"
                        @input="clearFieldError('name')"
                    />

                    <span v-if="form.errors.name">
                        {{ form.errors.name }}
                    </span>
                </label>

                <label>
                    Email

                    <input
                        v-model="form.email"
                        type="email"
                        :maxlength="CONTACT_EMAIL_MAX"
                        autocomplete="email"
                        required
                        :disabled="formLocked"
                        @input="clearFieldError('email')"
                    />

                    <span v-if="form.errors.email">
                        {{ form.errors.email }}
                    </span>
                </label>
            </div>

            <!--
                Mensaje de la consulta.

                El navegador impide superar los 1200 caracteres y Vue utiliza
                el contenido sin espacios exteriores para evaluar el mínimo.
            -->
            <label>
                Mensaje

                <textarea
                    v-model="form.message"
                    rows="7"
                    :minlength="CONTACT_MESSAGE_MIN"
                    :maxlength="CONTACT_MESSAGE_MAX"
                    required
                    :disabled="formLocked"
                    @input="handleMessageInput"
                />

                <!--
                    Contador equivalente al utilizado en formularios editoriales.

                    Antes de alcanzar el mínimo también informa cuántos caracteres
                    útiles faltan.
                -->
                <span class="contact-character-counter">
                    {{ messageLength }}/{{ CONTACT_MESSAGE_MAX }}

                    <template
                        v-if="
                            !submitted
                            && missingMessageCharacters > 0
                        "
                    >
                        ·
                        {{
                            missingMessageCharacters === 1
                                ? 'falta 1 carácter'
                                : `faltan ${missingMessageCharacters} caracteres`
                        }}
                    </template>
                </span>

                <span v-if="form.errors.message">
                    {{ form.errors.message }}
                </span>
            </label>

            <!--
                El ancho del botón se controla desde app.css.

                Durante el envío muestra "Enviando...". Una vez que Laravel
                confirma la operación, queda deshabilitado y cambia a
                "Consulta enviada".
            -->
            <button
                class="trama-primary-button contact-submit-button"
                type="submit"
                :disabled="!canSubmit"
            >
                {{
                    form.processing
                        ? 'Enviando...'
                        : submitted
                            ? 'Consulta enviada'
                            : 'Enviar consulta'
                }}
            </button>
        </form>
    </section>
</template>