<script setup>
/* ============================================================================
 * LAYOUT: PersistentInertiaLayout.vue
 * ============================================================================
 *
 * Mantiene montado el layout público durante las navegaciones Inertia.
 *
 * Inertia mantiene este layout montado mientras navega entre rutas que usan la
 * misma página Vue. Por eso el header, la navegación y el footer públicos no se
 * reinician al pasar de Portada a Política, Deportes, Cultura o una noticia.
 *
 * Las pantallas de autenticación y del panel editorial no usan ni descargan el
 * layout público. En esos casos este componente deja pasar el contenido sin
 * envolverlo.
 * ============================================================================ */

import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import PublicNewsLayout from '@/Layouts/PublicNewsLayout.vue';

// Obtiene el contexto actual de Inertia para acceder de forma reactiva a la URL
// y a las props compartidas enviadas por Laravel.
const page = usePage();

// screen identifica la pantalla lógica enviada por Laravel dentro de Trama.vue.
const currentScreen = computed(() => page.props.screen ?? '');
// Solo las pantallas públicas del portal conservan header, navegación y footer.
// AdvertiserDemo simula un sitio externo y por eso queda expresamente afuera.
const isPublicScreen = computed(() => (
    currentScreen.value.startsWith('Public/')
    && currentScreen.value !== 'Public/AdvertiserDemo'
));
</script>

<template>
    <PublicNewsLayout v-if="isPublicScreen">
        <slot />
    </PublicNewsLayout>

    <slot v-else />
</template>
