<script setup>
/* ============================================================================
 * PAGE: Public/Home.vue
 * ============================================================================
 *
 * Portada principal del portal.
 *
 * Combina noticia principal, cinta urgente, últimas noticias, más leídas,
 * destacadas y secciones por categoría en una experiencia de medio real.
 * ============================================================================ */

import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, Radio } from '@lucide/vue';
import ArticleCard from '@/Components/News/ArticleCard.vue';
import SectionHeader from '@/Components/News/SectionHeader.vue';
import { useTramaRoute } from '@/Support/useTramaRoute';

// Props recibidas desde el controlador:
// - hero: noticia principal que encabeza la portada.
// - breaking: noticias mostradas en la cinta de actualidad urgente.
// - featured: noticias destacadas de la portada.
// - latest: últimas noticias publicadas.
// - mostRead: noticias con mayor cantidad de vistas.
// - categories: categorías disponibles para organizar la portada.
// - categorySections: noticias agrupadas por categoría.
const props = defineProps({
    hero: Object,
    breaking: Array,
    featured: Array,
    latest: Array,
    mostRead: Array,    
    categories: Array,
    categorySections: Array,
});

// route() queda ligada a las rutas Ziggy de esta petición, también durante SSR.
const route = useTramaRoute();

// Noticias que se muestran en la cinta móvil de la portada.
//
// Una misma noticia puede pertenecer al mismo tiempo a varias colecciones
// —por ejemplo, ser urgente y además estar entre las últimas publicadas—.
// La cinta la agrega una sola vez por ID para evitar duplicados visuales.
const tickerItems = computed(() => {
    const items = [];
    const addedArticleIds = new Set();

    /*
     * Agrega una noticia a la cinta únicamente si todavía no fue incluida.
     *
     * La condición urgente siempre tiene prioridad sobre cualquier etiqueta
     * secundaria. De esta manera, una noticia marcada con is_breaking nunca
     * puede aparecer en la cinta sin la identificación "Urgente".
     */
    function addTickerItem(item, label = null, keyPrefix = 'noticia') {
        // Descarta valores inválidos y evita agregar nuevamente una noticia
        // que ya fue incluida en esta misma vuelta de la cinta.
        if (!item || addedArticleIds.has(item.id)) {
            return;
        }

        // Registra el identificador de la noticia para impedir duplicados
        // dentro del conjunto actual de elementos de la cinta.
        addedArticleIds.add(item.id);

        // Agrega la noticia a la cinta.
        //
        // Si está marcada como urgente, esa condición tiene prioridad sobre
        // cualquier otra etiqueta y siempre se muestra como "Urgente".
        items.push({
            key: `${keyPrefix}-${item.id}`,
            label: item.is_breaking ? 'Urgente' : label,
            title: item.title,
            slug: item.slug,
});
    }

    // Busca una referencia económica para mostrar la etiqueta "Mercados".
    // Si esa noticia también es urgente, "Urgente" conserva la prioridad.
    const economyLead = [
        ...props.breaking,
        ...props.latest,
        ...props.featured,
    ].find(
        (item) =>
            item.category?.name === 'Economía'
            || item.title.toLowerCase().includes('dólar')
    );

    if (economyLead) {
        addTickerItem(
            economyLead,
            'Mercados',
            'mercados'
        );
    }

    // Incorpora las noticias urgentes sin repetir una ya agregada.
    props.breaking.forEach((item) => {
        addTickerItem(
            item,
            'Urgente',
            'urgente'
        );
    });

    // Incorpora las últimas noticias evitando repetir las urgentes anteriores.
    props.latest.slice(0, 8).forEach((item) => {
        addTickerItem(
            item,
            null,
            'ultima'
        );
    });

    // Incorpora las más leídas sin repetir noticias ya presentes en la cinta.
    props.mostRead.slice(0, 5).forEach((item) => {
        addTickerItem(
            item,
            null,
            'ranking'
        );
    });

    return items;
});

// Duplica los ítems para que la animación pueda avanzar de forma continua.
const tickerLoopItems = computed(() => [...tickerItems.value, ...tickerItems.value]);

// Devuelve la portada de una noticia o una imagen si no tiene.
function articleCover(article) {
    return article?.cover_image || '/images/brand/noticia-default.webp';
}

// Abre la noticia principal cuando el usuario hace click en una zona libre del bloque.
function openHeroArticle(event) {
    // Si el click viene de un enlace interno, por ejemplo la categoría, se respeta
    // ese destino y no se fuerza la navegación hacia la noticia.
    if (!props.hero || event.target.closest('a, button')) {
        return;
    }

    router.visit(route('articles.show', props.hero.slug));
}

</script>

<template>
    <Head title="Portada" />

    <section class="news-ticker">
        <div>
            <Radio :size="18" />
        </div>
        <!--
            La ventana recorta únicamente el texto móvil. El desvanecido de los
            extremos se aplica con una máscara CSS transparente, sin superponer
            halos ni degradados rojizos sobre el fondo de la cinta.
        -->
        <div class="ticker-window">
            <div class="ticker-track">
                <Link
                    v-for="(item, index) in tickerLoopItems"
                    :key="item.key + '-' + index"
                    :href="route('articles.show', item.slug)"
                    class="ticker-item"
                >
                    <span v-if="item.label">{{ item.label }}</span>
                    <strong>{{ item.title }}</strong>
                </Link>
            </div>
        </div>
    </section>

    <section class="hero-grid">
        <article
            v-if="hero"
            class="hero-story hero-story-selectable"
            role="link"
            tabindex="0"
            @click="openHeroArticle"
            @keydown.enter.prevent="openHeroArticle"
            @keydown.space.prevent="openHeroArticle"
        >
            <img
                :src="articleCover(hero)"
                :alt="hero.cover_alt || hero.title"
                loading="eager"
                fetchpriority="high"
                decoding="async"
            />
            <span
                v-if="hero.is_breaking"
                class="breaking-pill hero-breaking-pill"
            >
                Urgente
            </span>
            <div class="hero-overlay">
                <Link 
                    :href="route('categories.show', hero.category.slug)" 
                    class="category-label"
                >
                    {{ hero.category.name }}
                </Link>
                <h1>
                    <Link 
                        :href="route('articles.show', hero.slug)"
                    >
                        {{ hero.title }}
                    </Link>
                </h1>
                <p>{{ hero.excerpt }}</p>
                <Link 
                    :href="route('articles.show', hero.slug)" 
                    class="read-link"
                >
                    Leer cobertura <ArrowRight :size="18" />
                </Link>
            </div>
        </article>

        <aside class="live-brief">
            <p class="eyebrow">Briefing</p>
            <h2>Redacción en vivo</h2>
            <div 
                v-for="(item, index) in latest.slice(0, 5)" 
                :key="item.id" class="brief-item"
            >
                <span>{{ String(index + 1).padStart(2, '0') }}</span>
                <Link 
                    :href="route('articles.show', item.slug)"
                >
                    {{ item.title }}
                </Link>
            </div>
        </aside>
    </section>

    <section class="content-band">
        <SectionHeader
            eyebrow="Selección editorial"
            title="Historias que marcan agenda"            
        />
        <div class="featured-grid">
            <ArticleCard 
                v-for="article in featured" 
                :key="article.id" 
                :article="article" 
            />
        </div>
    </section>

    <section class="split-band">
        <SectionHeader
            class="latest-section-head"
            eyebrow="Últimas noticias"
            title="Pulso de la jornada"
        />

        <div class="latest-list">
            <ArticleCard
                v-for="article in latest.slice(0, 8)"
                :key="article.id"
                :article="article"
                compact
                clickable
            />
        </div>

        <aside class="ranking-panel">
            <p class="eyebrow">Más leídas</p>
            <h2>Ranking editorial</h2>
            <Link
                v-for="(article, index) in mostRead"
                :key="article.id"
                :href="route('articles.show', article.slug)"
                class="ranking-row"
            >
                <strong>{{ index + 1 }}</strong>
                <span>{{ article.title }}</span>
            </Link>
        </aside>
    </section>

    <section class="category-showcase">
        <SectionHeader eyebrow="Secciones" title="Cobertura por área" />
        <div 
            v-for="block in categorySections" 
            :key="block.category.slug" 
            class="category-block"
        >
            <div 
                class="category-block-head" 
                :style="{ '--accent': block.category.accent_color }"
            >
                <img 
                    :src="block.category.cover_image" 
                    :alt="block.category.name" 
                />
                <div>
                    <p class="eyebrow">{{ block.category.name }}</p>
                    <h2>{{ block.category.description }}</h2>
                    <Link 
                        :href="route('categories.show', block.category.slug)"
                    >
                        Ver sección
                    </Link>
                </div>
            </div>
            <div class="mini-grid">
                <ArticleCard 
                    v-for="article in block.articles" 
                    :key="article.id" 
                    :article="article" compact 
                />
            </div>
        </div>
    </section>
</template>
