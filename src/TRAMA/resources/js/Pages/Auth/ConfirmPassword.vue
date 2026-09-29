<script setup>
/* ============================================================================
 * PAGE: Auth/ConfirmPassword.vue
 * ============================================================================
 *
 * Confirmación de contraseña de TRAMA.
 *
 * Solicita la contraseña actual antes de permitir una acción que requiere una
 * verificación adicional de seguridad.
 * ============================================================================ */

import { Head, useForm } from '@inertiajs/vue3';

// Formulario Inertia que envía la contraseña actual para confirmar la identidad
// del usuario antes de continuar.
const form = useForm({
    password: '',
});

// La contraseña actual utiliza el mismo máximo que el resto del sitio.
const CURRENT_PASSWORD_MAX = 20;

// Envía la contraseña actual a Laravel y limpia el campo al finalizar.
function submit() {
    // Laravel comprueba la contraseña del usuario autenticado y devuelve el
    // error correspondiente cuando la verificación no es válida.
    form.post(route('password.confirm'), {
        onFinish: () => form.reset(),
    });
}
</script>

<template>
    <Head title="Confirmar contraseña" />

    <form class="auth-form" novalidate @submit.prevent="submit">
        <p class="eyebrow">Seguridad</p>

        <h2>Confirmá tu contraseña</h2>

        <p class="auth-copy">
            Por seguridad, ingresá tu contraseña para continuar.
        </p>

        <label>
            Contraseña

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
                :maxlength="CURRENT_PASSWORD_MAX"
                required
            />

            <span v-if="form.errors.password">
                {{ form.errors.password }}
            </span>
        </label>

        <button
            type="submit"
            :disabled="form.processing"
        >
            {{ form.processing ? 'Confirmando...' : 'Continuar' }}
        </button>
    </form>
</template>
