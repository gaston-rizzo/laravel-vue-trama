<script setup>
/* ============================================================================
 * PAGE: Auth/ForgotPassword.vue
 * ============================================================================
 *
 * Recuperación de contraseña de TRAMA.
 *
 * Muestra el formulario para solicitar un enlace de restablecimiento mediante
 * el email asociado a la cuenta.
 * ============================================================================ */

import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

// Props recibidas por la página de recuperación:
// - status: contiene el mensaje devuelto por Laravel después de solicitar
//   el enlace de restablecimiento.
const props = defineProps({
    status: String,
});

// Formulario Inertia que envía el email asociado a la cuenta.
const form = useForm({
    email: '',
});

// Bloqueo local inmediato para impedir envíos duplicados antes de que Inertia
// actualice el estado processing.
const isSubmitting = ref(false);
const submittedEmail = ref('');
const normalizedEmail = computed(() => form.email.trim().toLowerCase());
const requestAlreadySent = computed(() =>
    Boolean(props.status) &&
    submittedEmail.value !== '' &&
    normalizedEmail.value === submittedEmail.value
);
const visibleStatus = computed(() => requestAlreadySent.value ? props.status : '');
const navigationDisabled = computed(() => isSubmitting.value || form.processing);

// Indica si el botón debe permanecer deshabilitado durante el envío.
const submitDisabled = computed(() =>
    isSubmitting.value || form.processing || requestAlreadySent.value
);

// El backend aplica el mismo máximo al correo de recuperación.
const PASSWORD_EMAIL_MAX = 50;

// Solicita a Laravel el envío del enlace de restablecimiento.
function submit() {
    if (submitDisabled.value) {
        return;
    }

    isSubmitting.value = true;

    // Inertia envía el email mediante una petición POST a Laravel.
    // Laravel procesa la solicitud y devuelve el mensaje correspondiente.
    form.post(route('password.email'), {
        onSuccess: () => {
            submittedEmail.value = normalizedEmail.value;
        },
        onError: () => {
            submittedEmail.value = '';
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
}
</script>

<template>
    <Head title="Recuperar contraseña" />

    <form class="auth-form" novalidate @submit.prevent="submit">
        <p class="eyebrow">Seguridad</p>

        <h2>Recuperar contraseña</h2>

        <p class="auth-copy">
            Ingresá tu email. Si existe una cuenta asociada, vas a recibir un enlace para restablecer la contraseña.
        </p>

        <p v-if="visibleStatus" class="flash-message">
            {{ visibleStatus }}
        </p>

        <label>
            Email

            <input
                v-model="form.email"
                type="email"
                :maxlength="PASSWORD_EMAIL_MAX"
                required
            />

            <span v-if="form.errors.email">
                {{ form.errors.email }}
            </span>
        </label>

        <button
            type="submit"
            :disabled="submitDisabled"
        >
            {{ requestAlreadySent ? 'Enlace enviado' : (submitDisabled ? 'Enviando enlace...' : 'Enviar enlace') }}
        </button>

        <div class="auth-links">
            <Link
                :href="route('login')"
                :class="{ 'auth-link-disabled': navigationDisabled }"
                :aria-disabled="navigationDisabled ? 'true' : undefined"
                :tabindex="navigationDisabled ? -1 : undefined"
                @click="navigationDisabled && $event.preventDefault()"
            >
                Volver a iniciar sesión
            </Link>
        </div>
    </form>
</template>
