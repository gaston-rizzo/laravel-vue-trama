<script setup>
/* ============================================================================
 * PAGE: Public/Search.vue
 * ============================================================================
 *
 * Búsqueda pública.
 *
 * Permite consultar noticias por término y filtrar por categoría.
 * ============================================================================ */

import { Head, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import PaginatedArticleGrid from '@/Components/News/PaginatedArticleGrid.vue';
import Pagination from '@/Components/Shared/Pagination.vue';

// Props de búsqueda enviadas por Laravel:
// filters conserva el texto y categoría elegidos, categories carga el selector
// de secciones y results contiene las noticias encontradas con paginación.
const props = defineProps({
    filters: Object,
    categories: Array,
    results: Object,
});

// Estado reactivo del formulario de búsqueda y filtro por sección.
const form = reactive({
    q: props.filters.q || '',
    category: props.filters.category || '',
});
// Habilita la búsqueda solo cuando el usuario escribió al menos un caracter real.
const canSearch = computed(() => form.q.trim().length >= 1);
const hasResults = computed(() => Boolean(props.results?.data?.length));

// Ejecuta la búsqueda manteniendo el estado visual de la página.
function search() {
    const term = form.q.trim();

    if (!canSearch.value) {
        return;
    }

    form.q = term;

    router.get(route('search'), {
        q: term,
        category: form.category || '',
    }, {
        preserveState: true,
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Buscar" />

        <section class="search-hero">
            <p class="eyebrow">Archivo TRAMA</p>
            <h1>Buscar cobertura</h1>
            <form @submit.prevent="search" class="search-form">
                <input v-model="form.q" placeholder="Buscar por tema, persona o palabra clave" />
                <select v-model="form.category">
                    <option value="">Todas las secciones</option>
                    <option v-for="category in categories" :key="category.slug" :value="category.slug">
                        {{ category.name }}
                    </option>
                </select>
                <button type="submit" :disabled="!canSearch">Buscar noticias</button>
            </form>
        </section>

        <section class="content-band">
            <PaginatedArticleGrid v-if="hasResults" :articles="results" />
            <p v-else class="empty-state">No se encontraron noticias para esa búsqueda.</p>
            <Pagination :links="results.links" />
        </section>
</template>
