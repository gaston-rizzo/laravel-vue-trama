<script setup>
/* ============================================================================
 * PAGE: Error.vue
 * ============================================================================
 *
 * Pantalla para errores HTTP de TRAMA.
 *
 * Evita mostrar al visitante excepciones, SQL, rutas internas o stack traces y
 * conserva una salida clara hacia la portada. El 503 de base de datos sigue
 * usando su vista Blade independiente para no depender de Inertia.
 * ============================================================================ */

import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';

// Propiedades recibidas por la pantalla de error.
//
// statusCode contiene el código HTTP que determina qué mensaje y qué acción
// debe mostrar la página. Si no se recibe ninguno, se utiliza 500.
const props = defineProps({
    statusCode: {
        type: Number,
        default: 500,
    },
});

// Define el contenido visible de la página según el código HTTP recibido.
//
// Cada estado tiene un título y un mensaje propios. Si el código no coincide
// con ninguno de los contemplados, se utiliza un mensaje genérico de respaldo.
const errorContent = computed(() => ({
    403: {
        title: 'No tenés acceso a esta sección',
        copy: 'Tu cuenta no tiene permisos para ingresar al contenido solicitado.',
    },
    404: {
        title: 'Página no encontrada',
        copy: 'La página que buscás no existe o fue retirada.',
    },
    419: {
        title: 'Tu sesión venció',
        copy: 'Actualizá la página e intentá nuevamente.',
    },
    500: {
        title: 'Ocurrió un problema',
        copy: 'No pudimos procesar la solicitud en este momento.',
    },
    503: {
        title: 'TRAMA no está disponible temporalmente',
        copy: 'Estamos teniendo un inconveniente con el servicio. Intentá nuevamente en unos minutos.',
    },
}[props.statusCode] || {
    title: 'No pudimos completar la solicitud',
    copy: 'Intentá nuevamente en unos minutos.',
}));

// Recarga completamente la página actual para volver a intentar la solicitud.
//
// Se utiliza principalmente en errores recuperables, como mantenimiento temporal
// o una sesión vencida que requiere obtener nuevamente la página desde Laravel.
function reloadPage() {
    window.location.reload();
}
</script>

<template>
    <Head :title="`${statusCode} · ${errorContent.title}`" />

    <main class="trama-error-page">
        <section class="trama-error-card">
            <Link :href="route('home')" class="trama-error-brand">TRAMA</Link>
            <p class="eyebrow">Error {{ statusCode }}</p>
            <strong class="trama-error-code">{{ statusCode }}</strong>
            <h1>{{ errorContent.title }}</h1>
            <p>{{ errorContent.copy }}</p>
            <button v-if="statusCode === 503" type="button" class="trama-primary-button" @click="reloadPage">Reintentar</button>
            <button v-else-if="statusCode === 419" type="button" class="trama-primary-button" @click="reloadPage">Actualizar página</button>
            <Link v-else :href="route('home')" class="trama-primary-link">Volver al inicio</Link>
        </section>
    </main>
</template>
