<script setup>
/* ============================================================================
 * PAGE: Public/JournalistShow.vue
 * ============================================================================
 *
 * Perfil público del periodista.
 *
 * Muestra la información visible del autor y el archivo paginado de noticias
 * publicadas que escribió en TRAMA. No muestra email ni datos privados.
 * ============================================================================ */

import { computed, nextTick  } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { CalendarDays, Newspaper, Tags } from '@lucide/vue';
import PaginatedArticleGrid from '@/Components/News/PaginatedArticleGrid.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import SectionHeader from '@/Components/News/SectionHeader.vue';

// Props del perfil:
// journalist contiene foto, nombre, cargo, biografía y métricas públicas;
// filters indica qué sección está seleccionada en esta página;
// articles contiene la página actual de noticias publicadas por ese periodista.
const props = defineProps({
    journalist: Object,
    filters: Object,
    articles: Object,
});

// Imagen del autor. Si no hay avatar cargado, se usa una imagen.
const journalistAvatar = computed(() => props.journalist.avatar || '/images/brand/avatar-default.webp');

// Texto de respaldo para perfiles que todavía no tienen biografía cargada.
const journalistBio = computed(() => props.journalist.bio || 'Integra el equipo editorial de TRAMA y publica coberturas con foco en contexto, datos y análisis.');

// Indica si el autor tiene noticias visibles en la página actual.
const hasArticles = computed(() => Boolean(props.articles?.data?.length));

// Sección seleccionada en los filtros internos del perfil.
const activeSection = computed(() => props.filters?.active_section || null);

// Texto visible para el encabezado del archivo según el filtro elegido.
const archiveTitle = computed(() => {
    const selectedSection = props.journalist.sections.find((section) => section.slug === activeSection.value);

    return selectedSection
        ? `Noticias de ${props.journalist.name} en ${selectedSection.name}`
        : `Noticias de ${props.journalist.name}`;
});

// Formatea la última fecha de publicación para mostrarla en la cabecera.
function formatDate(value) {
    if (!value) {
        return 'Sin publicaciones';
    }

    return new Intl.DateTimeFormat('es-AR', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
    }).format(new Date(value));
}

// Guarda la posición de la página antes de aplicar un filtro.
let journalistScrollX = 0;
let journalistScrollY = 0;

/*
 * Guarda la posición exacta de la ventana antes de que Inertia
 * actualice las noticias mostradas por el filtro.
 */
function rememberJournalistScroll() {
    journalistScrollX = window.scrollX;
    journalistScrollY = window.scrollY;
}

/*
 * Restaura la posición después de que Vue actualiza el contenido.
 *
 * El scroll suave se desactiva temporalmente para evitar que Chrome
 * altere la posición durante el reemplazo de la grilla.
 */
async function restoreJournalistScroll() {
    await nextTick();

    const documentElement = document.documentElement;
    const previousScrollBehavior =
        documentElement.style.scrollBehavior;

    documentElement.style.scrollBehavior = 'auto';

    window.scrollTo({
        left: journalistScrollX,
        top: journalistScrollY,
        behavior: 'auto',
    });

    documentElement.style.scrollBehavior =
        previousScrollBehavior;
}

</script>

<template>
    <Head :title="journalist.name" />

    <section class="journalist-hero">
        <div class="journalist-identity">
            <img :src="journalistAvatar" :alt="journalist.name" />
            <div>
                <p class="eyebrow">Periodista</p>
                <h1>{{ journalist.name }}</h1>
                <strong v-if="journalist.job_title">{{ journalist.job_title }}</strong>
                <p>{{ journalistBio }}</p>
            </div>
        </div>

        <div class="journalist-stats" aria-label="Resumen del periodista">
            <article>
                <Newspaper :size="20" />
                <span>{{ journalist.published_articles_count }}</span>
                <small>Noticias publicadas</small>
            </article>
            <article>
                <Tags :size="20" />
                <span>{{ journalist.sections.length }}</span>
                <small>Secciones</small>
            </article>
            <article>
                <CalendarDays :size="20" />
                <span>{{ formatDate(journalist.latest_published_at) }}</span>
                <small>Última publicación</small>
            </article>
        </div>
    </section>

    <section v-if="journalist.sections.length" class="journalist-sections" aria-label="Filtrar noticias del periodista">
        <span
            v-if="!activeSection"
            class="journalist-section-chip journalist-section-filter"
            :class="{ active: !activeSection }"
            aria-current="true"
        >
            <strong>Todas</strong>
            <span aria-hidden="true">·</span>
            <small>{{ journalist.published_articles_count }}</small>
        </span>
        <Link
            v-else
            :href="filters.all_url"
            preserve-scroll
            preserve-state
            class="journalist-section-chip journalist-section-filter"
            @start="rememberJournalistScroll"
            @success="restoreJournalistScroll"
        >
            <strong>Todas</strong>
            <span aria-hidden="true">·</span>
            <small>{{ journalist.published_articles_count }}</small>
        </Link>

        <component
            v-for="section in journalist.sections"
            :is="activeSection === section.slug ? 'span' : Link"
            :key="section.slug"
            :href="activeSection === section.slug ? undefined : section.filter_url"
            preserve-scroll
            preserve-state
            class="journalist-section-chip"
            :class="{ active: activeSection === section.slug }"
            :style="{ '--accent': section.accent_color }"
            :aria-current="activeSection === section.slug ? 'true' : undefined"
            @start="rememberJournalistScroll"
            @success="restoreJournalistScroll"
        >
            <strong>{{ section.name }}</strong>
            <span aria-hidden="true">·</span>
            <small>{{ section.articles_count }}</small>
        </component>
    </section>

    <section class="content-band">
        <SectionHeader eyebrow="Archivo" :title="archiveTitle" />

        <PaginatedArticleGrid v-if="hasArticles" :articles="articles" />

        <p v-else class="empty-state">Este periodista todavía no tiene noticias publicadas.</p>

        <Pagination :links="articles.links" scroll-to-content-on-change />
    </section>
</template>
