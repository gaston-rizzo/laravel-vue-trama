<script setup>
/* ============================================================================
 * COMPONENT: Pagination.vue
 * ============================================================================
 *
 * Paginación compartida para pantallas que reciben el arreglo links generado
 * por el paginador tradicional de Laravel.
 *
 * En el sitio público se utiliza en:
 *
 *      - las páginas de sección, como Política, Economía o Tecnología;
 *      - los resultados del buscador;
 *      - los perfiles de periodistas que muestran sus noticias;
 *      - la pantalla Mis comentarios.
 *
 * En administración se utiliza en Publicidades, Etiquetas, Empleados y Usuarios
 * registrados, cuyas filas ya llegan paginadas desde Laravel.
 *
 * La portada no lo utiliza porque muestra una selección limitada de noticias
 * y no un listado paginado. Noticias y Moderación conservan sus paginadores
 * administrativos específicos.
 * ============================================================================ */

import { router } from '@inertiajs/vue3';
import { nextTick } from 'vue';

// Props de paginación:
// links recibe el arreglo de enlaces que Laravel genera con paginate().
const props = defineProps({
    links: {
        type: Array,
        default: () => [],
    },
    scrollToContentOnChange: {
        type: Boolean,
        default: false,
    },
    scrollToContextOnChange: {
        type: Boolean,
        default: false,
    },
});

let rememberedScroll = {
    left: 0,
    top: 0,
};

function rememberPaginationScroll() {
    if (typeof window === 'undefined') {
        return;
    }

    rememberedScroll = {
        left: window.scrollX,
        top: window.scrollY,
    };
}

function waitForNextFrame() {
    return new Promise((resolve) => {
        window.requestAnimationFrame(resolve);
    });
}

function hasVisibleRealArticle(grid) {
    const realArticles = [
        ...grid.querySelectorAll('.article-card:not(.article-card-placeholder)'),
    ];

    return realArticles.some((article) => {
        const rect = article.getBoundingClientRect();

        return rect.bottom > 0 && rect.top < window.innerHeight;
    });
}

function hasSparseArticlePage(grid) {
    const articleCount = Number(grid.dataset.articleCount || 0);
    const expectedCount = Number(grid.dataset.expectedCount || 0);

    return expectedCount > 0 && articleCount > 0 && articleCount < expectedCount;
}

function articleCountFor(grid) {
    return Number(grid.dataset.articleCount || 0);
}

function contentTopFor(grid) {
    const contentBand = grid.closest('.content-band');
    const target = contentBand || grid;

    return target.getBoundingClientRect().top + window.scrollY;
}

function pageContextTopFor(grid) {
    const contextBlocks = [
        ...document.querySelectorAll(
            '.section-hero, .search-hero, .journalist-hero'
        ),
    ];
    const gridTop = grid.getBoundingClientRect().top + window.scrollY;
    const contextBlock = contextBlocks
        .filter((block) => (
            block.getBoundingClientRect().top + window.scrollY
        ) < gridTop)
        .pop();
    const target = contextBlock || grid;

    return target.getBoundingClientRect().top + window.scrollY;
}

function scrollToContextStartIfViewportIsSparse() {
    const grid = document.querySelector('.paginated-news-grid');

    if (!grid) {
        return;
    }

    if (props.scrollToContextOnChange) {
        window.scrollTo({
            left: rememberedScroll.left,
            top: Math.max(0, pageContextTopFor(grid) - 18),
            behavior: 'auto',
        });

        return;
    }

    if (props.scrollToContentOnChange) {
        window.scrollTo({
            left: rememberedScroll.left,
            top: Math.max(0, contentTopFor(grid) - 18),
            behavior: 'auto',
        });

        return;
    }

    if (hasSparseArticlePage(grid)) {
        window.scrollTo({
            left: rememberedScroll.left,
            top: Math.max(0, pageContextTopFor(grid) - 18),
            behavior: 'auto',
        });

        return;
    }

    if (
        hasVisibleRealArticle(grid)
    ) {
        return;
    }

    window.scrollTo({
        left: rememberedScroll.left,
        top: Math.max(0, pageContextTopFor(grid) - 18),
        behavior: 'auto',
    });
}

async function restorePaginationScroll() {
    if (typeof window === 'undefined') {
        return;
    }

    await nextTick();

    const documentElement = document.documentElement;
    const previousScrollBehavior =
        documentElement.style.scrollBehavior;

    documentElement.style.scrollBehavior = 'auto';

    window.scrollTo({
        left: rememberedScroll.left,
        top: rememberedScroll.top,
        behavior: 'auto',
    });

    await waitForNextFrame();
    scrollToContextStartIfViewportIsSparse();

    documentElement.style.scrollBehavior =
        previousScrollBehavior;
}

function shouldUseBrowserNavigation(event) {
    return event.defaultPrevented
        || event.button !== 0
        || event.metaKey
        || event.ctrlKey
        || event.shiftKey
        || event.altKey;
}

function visitPaginationPage(event, url) {
    if (!url || shouldUseBrowserNavigation(event)) {
        return;
    }

    event.preventDefault();
    rememberPaginationScroll();

    router.visit(url, {
        method: 'get',
        preserveState: true,
        preserveScroll: true,
        onSuccess: restorePaginationScroll,
    });
}

// Traduce etiquetas de paginación de Laravel a textos claros en español.
function labelFor(label) {
    const value = String(label || '').replace(/&laquo;|&raquo;/g, '').trim();

    if (value === 'pagination.previous' || value.toLowerCase() === 'previous') {
        return '←';
    }

    if (value === 'pagination.next' || value.toLowerCase() === 'next') {
        return '→';
    }

    if (value === '...') {
        return '…';
    }

    return value;
}
</script>

<template>
    <nav v-if="links.length > 3" class="pagination">
        <template v-for="link in links" :key="link.label">
            <a
                v-if="link.url"
                :href="link.url"
                :class="{ active: link.active }"
                @click="visitPaginationPage($event, link.url)"
            >
                {{ labelFor(link.label) }}
            </a>
            <span v-else class="disabled">{{ labelFor(link.label) }}</span>
        </template>
    </nav>
</template>
