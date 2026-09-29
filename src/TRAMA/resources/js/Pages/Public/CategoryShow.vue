<script setup>
/* ============================================================================
 * PAGE: Public/CategoryShow.vue
 * ============================================================================
 *
 * Sección pública del portal.
 *
 * Lista noticias publicadas de una categoría con portada visual propia.
 * ============================================================================ */

import { Head } from '@inertiajs/vue3';
import PaginatedArticleGrid from '@/Components/News/PaginatedArticleGrid.vue';
import Pagination from '@/Components/Shared/Pagination.vue';

// Props de la sección:
// category contiene nombre, descripción, color e imagen de portada; articles
// contiene las noticias publicadas de esa sección con enlaces de paginación.
defineProps({
    category: Object,
    articles: Object,
});
</script>

<template>
    <Head :title="category.name" />

        <section class="section-hero" :style="{ '--accent': category.accent_color }">
            <img :src="category.cover_image" :alt="category.name" />
            <div>
                <p class="eyebrow">Sección</p>
                <h1>{{ category.name }}</h1>
                <p>{{ category.description }}</p>
            </div>
        </section>

        <section class="content-band">
            <PaginatedArticleGrid :articles="articles" />
            <Pagination :links="articles.links" scroll-to-context-on-change />
        </section>
</template>
