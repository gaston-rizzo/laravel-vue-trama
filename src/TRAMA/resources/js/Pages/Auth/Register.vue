<script setup>
/* ============================================================================
 * PAGE: Auth/Register.vue
 * ============================================================================
 *
 * Registro de cuentas de TRAMA.
 *
 * Muestra el formulario público para crear una cuenta y enviar el correo de
 * verificación necesario antes de permitir el inicio de sesión.
 * ============================================================================ */

import { computed, nextTick, onMounted, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

// Props recibidas por la página de registro:
// - redirect: conserva una URL de destino opcional para regresar a la pantalla
//   desde la cual el usuario decidió crear su cuenta.
const props = defineProps({
    redirect: String,
});

// Formulario Inertia que envía los datos necesarios para crear la cuenta
// y conserva la URL de destino posterior a la autenticación.
const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    redirect: props.redirect || '',
});

const nameInput = ref(null);
const emailInput = ref(null);
const passwordInput = ref(null);
const passwordConfirmationInput = ref(null);
const autofillBlocked = ref(true);
const userInteracted = ref(false);

// Bloqueo local inmediato para impedir un doble click antes de que Inertia
// actualice el estado processing.
const isSubmitting = ref(false);

// Indica si el botón debe permanecer deshabilitado mientras se crea la cuenta.
const submitDisabled = computed(() =>
    isSubmitting.value || form.processing
);
const strictEmailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/i;

// Límites compartidos con Laravel para evitar diferencias entre la interfaz
// y la validación definitiva del registro.
const REGISTER_NAME_MAX = 40;
const REGISTER_NAME_MIN = 3;
const REGISTER_EMAIL_MAX = 50;
const REGISTER_PASSWORD_MIN = 8;
const REGISTER_PASSWORD_MAX = 20;
const hasLetterPattern = /\p{L}/u;
const hasNumberPattern = /\d/u;
const passwordPolicyMessage = 'La contraseña debe tener entre 8 y 20 caracteres e incluir al menos una letra y un número.';

function unlockAutofillBlock() {
    autofillBlocked.value = false;
    userInteracted.value = true;
}

function clearBrowserAutofill() {
    if (userInteracted.value) {
        return;
    }

    form.name = '';
    form.email = '';
    form.password = '';
    form.password_confirmation = '';

    if (nameInput.value) {
        nameInput.value.value = '';
    }

    if (emailInput.value) {
        emailInput.value.value = '';
    }

    if (passwordInput.value) {
        passwordInput.value.value = '';
    }

    if (passwordConfirmationInput.value) {
        passwordConfirmationInput.value.value = '';
    }
}

onMounted(async () => {
    await nextTick();
    clearBrowserAutofill();
    window.setTimeout(clearBrowserAutofill, 50);
    window.setTimeout(clearBrowserAutofill, 250);
});

function validateName(showIncomplete = true) {
    const name = form.name.trim();

    if (!name) {
        if (showIncomplete) {
            form.setError('name', 'Ingresá tu nombre.');
        } else {
            form.clearErrors('name');
        }

        return false;
    }

    if (name.length < REGISTER_NAME_MIN) {
        if (showIncomplete) {
            form.setError('name', 'El nombre debe tener al menos 3 caracteres.');
        } else {
            form.clearErrors('name');
        }

        return false;
    }

    if (name.length > REGISTER_NAME_MAX) {
        form.setError('name', 'El nombre no puede superar los 40 caracteres.');
        return false;
    }

    if (!hasLetterPattern.test(name)) {
        form.setError('name', 'El nombre debe incluir al menos una letra.');
        return false;
    }

    form.clearErrors('name');
    return true;
}

function validatePassword(showIncomplete = true) {
    const password = form.password;

    if (!password || password.length < REGISTER_PASSWORD_MIN) {
        if (showIncomplete) {
            form.setError('password', passwordPolicyMessage);
        } else {
            form.clearErrors('password');
        }

        return false;
    }

    if (
        password.length > REGISTER_PASSWORD_MAX ||
        !hasLetterPattern.test(password) ||
        !hasNumberPattern.test(password)
    ) {
        form.setError('password', passwordPolicyMessage);
        return false;
    }

    form.clearErrors('password');
    return true;
}

// Envía los datos de registro a Laravel y limpia las contraseñas al finalizar.
function submit() {
    if (submitDisabled.value) {
        return;
    }

    form.clearErrors('name', 'email', 'password', 'password_confirmation');

    form.name = form.name.trim();
    form.email = form.email.trim();

    if (!validateName()) {
        return;
    }

    if (form.email.length > REGISTER_EMAIL_MAX) {
        form.setError('email', 'El correo no puede superar los 50 caracteres.');
        return;
    }

    if (!strictEmailPattern.test(form.email)) {
        form.setError('email', 'Ingresá un correo electrónico válido.');
        return;
    }

    if (!validatePassword()) {
        return;
    }

    if (form.password_confirmation.length > REGISTER_PASSWORD_MAX) {
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

    // Inertia envía los datos mediante una petición POST a Laravel.
    //
    // Laravel valida el formulario, crea la cuenta, envía el correo de
    // verificación y devuelve los errores o la redirección correspondiente.
    form.post(route('register'), {
        onFinish: () => {
            form.reset('password', 'password_confirmation');
            isSubmitting.value = false;
        },
    });
}
</script>

<template>
    <Head title="Crear cuenta" />

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

        <h2>Crear cuenta</h2>

        <p class="auth-copy">
            Creá tu cuenta para comentar noticias.
        </p>

        <label>
            Nombre

            <input
                ref="nameInput"
                v-model="form.name"
                name="trama_register_display"
                autocomplete="off"
                autocapitalize="words"
                spellcheck="false"
                :minlength="REGISTER_NAME_MIN"
                :maxlength="REGISTER_NAME_MAX"
                :readonly="autofillBlocked"
                required
                @focus="unlockAutofillBlock"
                @blur="validateName"
                @input="validateName(false)"
            />

            <span v-if="form.errors.name">
                {{ form.errors.name }}
            </span>
        </label>

        <label>
            Email

            <input
                ref="emailInput"
                v-model="form.email"
                type="email"
                name="trama_register_contact"
                autocomplete="off"
                autocapitalize="none"
                spellcheck="false"
                :maxlength="REGISTER_EMAIL_MAX"
                :readonly="autofillBlocked"
                required
                @focus="unlockAutofillBlock"
            />

            <span v-if="form.errors.email">
                {{ form.errors.email }}
            </span>
        </label>

        <label>
            Contraseña

                <input
                    ref="passwordInput"
                    v-model="form.password"
                    type="text"
                    class="auth-password-mask"
                    style="-webkit-text-security: disc;"
                    name="trama_register_passphrase"
                    autocomplete="new-password"
                    autocapitalize="none"
                    spellcheck="false"
                    inputmode="text"
                    data-1p-ignore="true"
                    data-lpignore="true"
                    data-form-type="other"
                    :minlength="REGISTER_PASSWORD_MIN"
                    :maxlength="REGISTER_PASSWORD_MAX"
                    :readonly="autofillBlocked"
                required
                @focus="unlockAutofillBlock"
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
                    ref="passwordConfirmationInput"
                    v-model="form.password_confirmation"
                    type="text"
                    class="auth-password-mask"
                    style="-webkit-text-security: disc;"
                    name="trama_register_passphrase_repeat"
                    autocomplete="new-password"
                    autocapitalize="none"
                    spellcheck="false"
                    inputmode="text"
                    data-1p-ignore="true"
                    data-lpignore="true"
                    data-form-type="other"
                    :minlength="REGISTER_PASSWORD_MIN"
                    :maxlength="REGISTER_PASSWORD_MAX"
                    :readonly="autofillBlocked"
                required
                @focus="unlockAutofillBlock"
            />

            <span v-if="form.errors.password_confirmation">
                {{ form.errors.password_confirmation }}
            </span>
        </label>

        <button
            type="submit"
            :disabled="submitDisabled"
        >
            {{ submitDisabled ? 'Creando cuenta...' : 'Crear cuenta' }}
        </button>

        <div class="auth-links">
            <Link
                :href="route(
                    'login',
                    form.redirect ? { redirect: form.redirect } : {}
                )"
                :class="{ 'auth-link-disabled': submitDisabled }"
                :aria-disabled="submitDisabled ? 'true' : undefined"
                :tabindex="submitDisabled ? -1 : undefined"
                @click="submitDisabled && $event.preventDefault()"
            >
                Ya tengo cuenta
            </Link>
        </div>
    </form>
</template>
