<script setup>
/* ============================================================================
 * PAGE: Auth/VerifyEmail.vue
 * ============================================================================
 *
 * Verificación de email de TRAMA.
 *
 * Muestra el estado de verificación del email y permite solicitar un nuevo
 * enlace cuando el reenvío está habilitado.
 * ============================================================================ */

import { Head, Link, useForm } from '@inertiajs/vue3';

// Props recibidas por la página de verificación:
// - status: contiene el resultado devuelto por Laravel después de un reenvío.
// - verified: indica si el email de la cuenta ya fue verificado.
// - canResend: indica si se puede solicitar un nuevo enlace de verificación.
defineProps({
    status: String,
    verified: Boolean,
    canResend: Boolean,
});

// Formulario Inertia sin campos:
// Laravel identifica al usuario mediante la sesión iniciada.
const form = useForm({});

// Solicita a Laravel el envío de un nuevo enlace de verificación.
function submit() {
    // Inertia realiza una petición POST y Laravel usa la sesión para identificar
    // la cuenta que debe recibir el nuevo enlace.
    form.post(route('verification.send'));
}
</script>

<template>
    <Head title="Verificar email" />

    <form class="auth-form" @submit.prevent="submit">
        <p class="eyebrow">Verificación</p>

        <h2>
            {{ verified ? 'Email verificado' : 'Confirmá tu email' }}
        </h2>

        <p v-if="verified" class="auth-copy">
            Tu email fue verificado correctamente. Ya podés iniciar sesión.
        </p>

        <p v-else class="auth-copy">
            Revisá tu email y usá el enlace de verificación para continuar.
        </p>

        <p
            v-if="status === 'verification-link-sent'"
            class="flash-message"
        >
            Te enviamos un enlace de verificación. Si no ves el mail, comprobá tu casilla de correo no deseado.
        </p>

        <button
            v-if="canResend"
            type="submit"
            :disabled="form.processing"
        >
            Reenviar enlace
        </button>

        <div class="auth-links">
            <Link :href="route('login')">
                Iniciar sesión
            </Link>
        </div>
    </form>
</template>
