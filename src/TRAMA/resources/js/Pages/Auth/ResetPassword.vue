<script setup>
/* ============================================================================
 * PAGE: Auth/ResetPassword.vue
 * ============================================================================
 *
 * Restablecimiento de contraseña de TRAMA.
 *
 * Permite definir una nueva contraseña mediante el enlace recibido por email.
 * ============================================================================ */

import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';

// Props recibidas por la página de restablecimiento:
// - email: identifica la cuenta que solicitó el cambio de contraseña.
// - token: valida el enlace de restablecimiento recibido por email.
const props = defineProps({
    email: String,
    token: String,
    resetLinkError: String,
});

// Formulario Inertia que envía el token, el email y la nueva contraseña.
const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

// Bloqueo local inmediato para impedir un doble envío antes de que Inertia
// actualice el estado processing.
const isSubmitting = ref(false);

// Indica si el botón debe permanecer deshabilitado durante el envío.
const submitDisabled = computed(() =>
    Boolean(props.resetLinkError) || isSubmitting.value || form.processing
);

// Límites compartidos con Laravel para el restablecimiento de acceso.
const RESET_EMAIL_MAX = 50;
const RESET_PASSWORD_MIN = 8;
const RESET_PASSWORD_MAX = 20;
const passwordHasLetterPattern = /\p{L}/u;
const passwordHasNumberPattern = /\d/u;
const passwordPolicyMessage = 'La contraseña debe tener entre 8 y 20 caracteres e incluir al menos una letra y un número.';

function validatePassword(showIncomplete = true) {
    const password = form.password;

    if (!password || password.length < RESET_PASSWORD_MIN) {
        if (showIncomplete) {
            form.setError('password', passwordPolicyMessage);
        } else {
            form.clearErrors('password');
        }

        return false;
    }

    if (
        password.length > RESET_PASSWORD_MAX ||
        !passwordHasLetterPattern.test(password) ||
        !passwordHasNumberPattern.test(password)
    ) {
        form.setError('password', passwordPolicyMessage);
        return false;
    }

    form.clearErrors('password');
    return true;
}

// Valida la confirmación y envía la nueva contraseña a Laravel.
function submit() {
    if (submitDisabled.value) {
        return;
    }

    form.clearErrors('password', 'password_confirmation');

    if (!validatePassword()) {
        return;
    }

    if (form.password_confirmation.length > RESET_PASSWORD_MAX) {
        form.setError(
            'password_confirmation',
            'La confirmación no puede superar los 20 caracteres.'
        );
        return;
    }

    if (form.password !== form.password_confirmation) {
        form.setError(
            'password_confirmation',            
            'Las contraseñas no coinciden.'
        );

        return;
    }

    isSubmitting.value = true;

    // Laravel valida el token, el email y la nueva contraseña.
    // Si los datos son correctos, actualiza la contraseña y redirige al usuario.
    form.post(route('password.update'), {
        onFinish: () => {
            form.reset('password', 'password_confirmation');
            isSubmitting.value = false;
        },
    });
}
</script>

<template>
    <Head title="Nueva contraseña" />

    <form class="auth-form" novalidate @submit.prevent="submit">
        <p class="eyebrow">Seguridad</p>

        <h2>Crear nueva contraseña</h2>

        <p v-if="resetLinkError" class="flash-message auth-error-message">
            {{ resetLinkError }}
        </p>

        <label>
            Email

            <input
                v-model="form.email"
                type="email"
                :maxlength="RESET_EMAIL_MAX"
                required
                :disabled="Boolean(resetLinkError)"
            />

            <span v-if="form.errors.email">
                {{ form.errors.email }}
            </span>
        </label>

        <label>
            Nueva contraseña

            <input
                v-model="form.password"
                type="text"
                class="auth-password-mask"
                style="-webkit-text-security: disc;"
                autocomplete="new-password"
                autocapitalize="none"
                spellcheck="false"
                inputmode="text"
                data-1p-ignore="true"
                data-lpignore="true"
                data-form-type="other"
                :minlength="RESET_PASSWORD_MIN"
                :maxlength="RESET_PASSWORD_MAX"
                required
                :disabled="Boolean(resetLinkError)"
                @blur="validatePassword"
                @input="validatePassword(false)"
            />

            <span v-if="form.errors.password">
                {{ form.errors.password }}
            </span>
        </label>

        <label>
            Confirmar contraseña

            <input
                v-model="form.password_confirmation"
                type="text"
                class="auth-password-mask"
                style="-webkit-text-security: disc;"
                autocomplete="new-password"
                autocapitalize="none"
                spellcheck="false"
                inputmode="text"
                data-1p-ignore="true"
                data-lpignore="true"
                data-form-type="other"
                :minlength="RESET_PASSWORD_MIN"
                :maxlength="RESET_PASSWORD_MAX"
                required
                :disabled="Boolean(resetLinkError)"
            />

            <span v-if="form.errors.password_confirmation">
                {{ form.errors.password_confirmation }}
            </span>
        </label>

        <button
            type="submit"
            :disabled="submitDisabled"
        >
            {{ submitDisabled ? 'Actualizando...' : 'Actualizar contraseña' }}
        </button>
    </form>
</template>
