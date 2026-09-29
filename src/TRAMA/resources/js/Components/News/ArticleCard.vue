<script setup>
/* ============================================================================
 * COMPONENT: ArticleCard.vue
 * ============================================================================
 *
 * Card editorial reutilizable.
 *
 * Renderiza noticias para portada, búsqueda, categorías y relacionadas con una
 * estructura visual consistente definida por TRAMA.
 * ============================================================================ */

import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useTramaRoute } from '@/Support/useTramaRoute';

// Props de la tarjeta de noticia:
// article trae los datos visibles de la noticia, compact reduce la densidad
// visual y clickable convierte toda la tarjeta en un único enlace.
const props = defineProps({
    article: {
        type: Object,
        required: true,
    },
    compact: {
        type: Boolean,
        default: false,
    },
    clickable: {
        type: Boolean,
        default: false,
    },
});

// route() usa la configuración Ziggy de la petición actual y es segura en SSR.
const route = useTramaRoute();

// Imagen visible de la tarjeta; usa fallback cuando la noticia no trae portada.
const coverImage = computed(() => props.article.cover_image || '/images/brand/noticia-default.webp');
// URL de la noticia usada por el enlace principal y por el click sobre el cuerpo de la tarjeta.
const articleUrl = computed(() => route('articles.show', props.article.slug));

// Abre la noticia cuando el usuario hace click en una zona libre de la tarjeta.
function openArticleFromCard(event) {
    // Si el click nació en un enlace interno, por ejemplo categoría o periodista,
    // se respeta ese enlace y no se fuerza la navegación hacia la noticia.
    if (event.target.closest('a, button')) {
        return;
    }

    router.visit(articleUrl.value);
}
</script>

<template>
    <Link
        v-if="clickable"
        :href="articleUrl"
        class="article-card article-card-link"
        :class="{ compact }"
    >
        <div class="card-media">
            <img :src="coverImage" :alt="article.cover_alt || article.title" loading="lazy" />
            <span v-if="article.is_breaking" class="breaking-pill">Urgente</span>
        </div>
        <div class="card-copy">
            <span
                v-if="article.category"
                class="category-label"
                :style="{ color: article.category.accent_color }"
            >
                {{ article.category.name }}
            </span>
            <h3>
                <span>{{ article.title }}</span>
            </h3>
            <p>{{ article.excerpt }}</p>
            <footer class="card-meta">
                <span class="card-meta-author">{{ article.author?.name }}</span>
                <span class="card-meta-separator">·</span>
                <span class="card-meta-time">{{ article.reading_time }} min</span>
            </footer>
        </div>
    </Link>

    <article
        v-else
        class="article-card article-card-selectable"
        :class="{ compact }"
        role="link"
        tabindex="0"
        @click="openArticleFromCard"
        @keydown.enter.prevent="openArticleFromCard"
        @keydown.space.prevent="openArticleFromCard"
    >
        <Link :href="articleUrl" class="card-media">
            <img :src="coverImage" :alt="article.cover_alt || article.title" loading="lazy" />
            <span v-if="article.is_breaking" class="breaking-pill">Urgente</span>
        </Link>
        <div class="card-copy">
            <Link
                v-if="article.category"
                :href="route('categories.show', article.category.slug)"
                class="category-label"
                :style="{ color: article.category.accent_color }"
            >
                {{ article.category.name }}
            </Link>
            <h3>
                <Link :href="articleUrl">{{ article.title }}</Link>
            </h3>
            <p>{{ article.excerpt }}</p>
            <footer class="card-meta">
                <Link
                    v-if="article.author?.profile_url"
                    :href="article.author.profile_url"
                    class="card-meta-author"
                >
                    {{ article.author.name }}
                </Link>
                <span v-else class="card-meta-author">{{ article.author?.name }}</span>
                <span class="card-meta-separator">·</span>
                <span class="card-meta-time">{{ article.reading_time }} min</span>
            </footer>
        </div>
    </article>
</template>
