<script setup>
/* ============================================================================
 * COMPONENT: SectionHeader.vue
 * ============================================================================
 *
 * Encabezado de seccion.
 *
 * Unifica titulos, bajadas y acentos visuales de los modulos editoriales.
 * ============================================================================ */

// Props del encabezado de sección:
// eyebrow muestra el rótulo superior, title es el título principal y
// description agrega una bajada opcional al lado derecho.
defineProps({
    eyebrow: String,
    title: String,
    description: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <header class="section-head">
        <div>
            <p v-if="eyebrow" class="eyebrow">{{ eyebrow }}</p>
            <h2>{{ title }}</h2>
        </div>
        <p v-if="description">{{ description }}</p>
    </header>
</template>
