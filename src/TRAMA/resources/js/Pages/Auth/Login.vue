<script setup>
/* ============================================================================
 * PAGE: Auth/Login.vue
 * ============================================================================
 *
 * Inicio de sesión de TRAMA.
 *
 * Muestra el formulario de acceso del sitio y envía las credenciales al backend
 * de autenticación configurado en Laravel.
 * ============================================================================ */

import { computed, nextTick, onMounted, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

// Props recibidas por la página de inicio de sesión:
// - canResetPassword: indica si está disponible la recuperación de contraseña.
// - status: contiene mensajes de sesión enviados por Laravel.
// - redirect: conserva la URL de destino después de iniciar sesión.
const props = defineProps({
    canResetPassword: Boolean,
    status: String,
    accountBlocked: String,
    redirect: String,
});

// Formulario Inertia que envía email, contraseña y URL de regreso después del login.
const form = useForm({
    email: '',
    password: '',
    remember: false,
    redirect: props.redirect || '',
});

const emailInput = ref(null);
const passwordInput = ref(null);
const autofillBlocked = ref(true);
const userInteracted = ref(false);

// Bloqueo local inmediato para impedir doble click antes de que Inertia marque processing.
const isSubmitting = ref(false);
// Estado único usado por el botón para mostrar carga y deshabilitar el envío.
const submitDisabled = computed(() => isSubmitting.value || form.processing);

// Límites iguales a los aplicados por Laravel para las credenciales.
const LOGIN_EMAIL_MAX = 50;
const LOGIN_PASSWORD_MAX = 20;
// Unifica el aviso recibido por expulsión de sesión con el rechazo de un login nuevo.
const blockedLoginMessage = computed(() => {
    if (props.accountBlocked) {
        return props.accountBlocked;
    }

    const emailError = form.errors.email || '';

    return emailError.toLowerCase().includes('bloquead') ? emailError : '';
});

// Envía credenciales a Fortify y limpia la contraseña al terminar.
function unlockAutofillBlock() {
    autofillBlocked.value = false;
    userInteracted.value = true;
}

function clearBrowserAutofill() {
    if (userInteracted.value) {
        return;
    }

    form.email = '';
    form.password = '';
    form.remember = false;

    if (emailInput.value) {
        emailInput.value.value = '';
    }

    if (passwordInput.value) {
        passwordInput.value.value = '';
    }
}

onMounted(async () => {
    await nextTick();
    clearBrowserAutofill();
    window.setTimeout(clearBrowserAutofill, 50);
    window.setTimeout(clearBrowserAutofill, 250);
});

function submit() {
    if (submitDisabled.value) {
        return;
    }

    isSubmitting.value = true;

    // Inertia envía las credenciales con un POST HTTP normal a Laravel/Fortify.
    // Si Laravel rechaza el acceso, los errores vuelven como props del formulario;
    // si lo acepta, Inertia sigue la redirección sin recargar todo el documento.
    form.post(route('login'), {
        onFinish: () => {
            form.reset('password');
            isSubmitting.value = false;
        },
    });
}
</script>

<template>
        <Head title="Ingresar" />

        <form class="auth-form" autocomplete="off" novalidate @submit.prevent="submit">
            <Link
                :href="route('home')"
                class="volver-portada"
                :class="{ 'auth-link-disabled': submitDisabled }"
                :aria-disabled="submitDisabled ? 'true' : undefined"
                :tabindex="submitDisabled ? -1 : undefined"
                @click="submitDisabled && $event.preventDefault()"
            >
                ← Volver a portada
            </Link>

            <p class="eyebrow">Cuenta TRAMA</p>
            <h2>Ingresar</h2>
            <p class="auth-copy">
                Iniciá sesión para continuar.
            </p>
            <p v-if="status" class="flash-message">{{ status }}</p>
            <div v-if="blockedLoginMessage" class="auth-blocked-message">
                <p>{{ blockedLoginMessage }}</p>
                <Link :href="route('contact')">Contactar a TRAMA</Link>
            </div>

            <label>
                Email
                <input
                    ref="emailInput"
                    v-model="form.email"
                    type="email"
                    name="trama_login_email"
                    autocomplete="off"
                    autocapitalize="none"
                    spellcheck="false"
                    :maxlength="LOGIN_EMAIL_MAX"
                    :readonly="autofillBlocked"
                    required
                    @focus="unlockAutofillBlock"
                />
                <span v-if="form.errors.email && !blockedLoginMessage">{{ form.errors.email }}</span>
            </label>

            <label>
                Contraseña
                <input
                    ref="passwordInput"
                    v-model="form.password"
                    type="text"
                    class="auth-password-mask"
                    style="-webkit-text-security: disc;"
                    name="trama_login_passphrase"
                    autocomplete="new-password"
                    autocapitalize="none"
                    spellcheck="false"
                    inputmode="text"
                    data-1p-ignore="true"
                    data-lpignore="true"
                    data-form-type="other"
                    :maxlength="LOGIN_PASSWORD_MAX"
                    :readonly="autofillBlocked"
                    required
                    @focus="unlockAutofillBlock"
                />
                <span v-if="form.errors.password">{{ form.errors.password }}</span>
            </label>

            <label class="inline-check">
                <input v-model="form.remember" type="checkbox" />
                Mantener la sesión iniciada
            </label>

            <button type="submit" :disabled="submitDisabled">
                {{ submitDisabled ? 'Ingresando...' : 'Ingresar' }}
            </button>

            <div class="auth-links">
                <Link
                    :href="route('register', form.redirect ? { redirect: form.redirect } : {})"
                    :class="{ 'auth-link-disabled': submitDisabled }"
                    :aria-disabled="submitDisabled ? 'true' : undefined"
                    :tabindex="submitDisabled ? -1 : undefined"
                    @click="submitDisabled && $event.preventDefault()"
                >
                    Crear cuenta
                </Link>
                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    :class="{ 'auth-link-disabled': submitDisabled }"
                    :aria-disabled="submitDisabled ? 'true' : undefined"
                    :tabindex="submitDisabled ? -1 : undefined"
                    @click="submitDisabled && $event.preventDefault()"
                >
                    Olvidé mi contraseña
                </Link>
            </div>
        </form>
</template>
