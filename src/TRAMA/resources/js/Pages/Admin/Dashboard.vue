<script setup>
/* ============================================================================
 * PAGE: Admin/Dashboard.vue
 * ============================================================================
 *
 * Página principal del panel interno de TRAMA.
 *
 * Muestra un resumen del estado del portal mediante indicadores, tareas
 * pendientes y actividad reciente, según los permisos del usuario autenticado.
 *
 * Los indicadores son cifras resumidas que permiten consultar rápidamente
 * información como la cantidad de noticias, comentarios pendientes, usuarios
 * registrados o clics recibidos por las publicidades.
 *
 * Los periodistas reciben información relacionada con sus propias noticias;
 * los editores ven el flujo editorial y la moderación de comentarios; los
 * administradores acceden a métricas y movimientos propios de la gestión del
 * sistema.
 * ============================================================================ */

import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { FilePlus2 } from '@lucide/vue';

// Props recibidas desde DashboardController:
// - stats: indicadores generales del panel según el rol del usuario.
// - topArticles: noticias con mayor cantidad de visualizaciones.
// - pendingReview: noticias que todavía esperan una decisión editorial.
// - activity: últimas acciones realizadas sobre las noticias.
// - recentActivity: listados recientes de publicaciones, comentarios,
//   usuarios registrados y clicks en publicidades.
// - permissions: permisos disponibles para mostrar u ocultar cada sección.
const props = defineProps({
    stats: Object,
    topArticles: Array,
    pendingReview: Array,
    activity: Array,
    recentActivity: Object,
    permissions: Object,
});

// Textos visibles para las acciones guardadas en el historial de revisiones.
const actionLabels = {
    created: 'Creada',
    updated: 'Actualizada',
    archived: 'Archivada',
    published: 'Publicada',
    scheduled: 'Programada',
    changes_requested: 'Devuelta para corrección',
    resubmitted: 'Reenviada a revisión',
    restored_as_draft: 'Restaurada como borrador',
    created_and_submitted: 'Creada y enviada a revisión',
    created_and_scheduled: 'Creada y programada',
    created_and_published: 'Creada y publicada',
    returned_to_draft: 'Devuelta a borrador',
    submitted_for_review: 'Enviada a revisión',
    corrections_saved: 'Correcciones guardadas',
    status_changed: 'Estado modificado',
    homepage_updated: 'Configuración de portada',
};

// Textos visibles para los estados internos de una noticia.
const statusLabels = {
    draft: 'En borrador',
    review: 'En revisión',
    needs_changes: 'Devuelta para corrección',
    scheduled: 'Programada',
    published: 'Publicada',
    archived: 'Archivada',
};

// Traduce los códigos internos de los roles a textos visibles.
const roleLabels = {
    admin: 'Administrador',
    editor: 'Editor',
    journalist: 'Periodista',
    reader: 'Usuario registrado',
};

// Indica si el usuario actual administra la estructura y las cuentas del sistema.
const isAdministrator = computed(() =>
    Boolean(props.permissions?.can_manage_system)
);

// Texto pequeño mostrado encima del título principal del dashboard.
const dashboardEyebrow = computed(() =>
    isAdministrator.value
        ? 'Administración'
        : 'Redacción'
);

// Título principal del dashboard según las funciones del usuario autenticado.
const dashboardTitle = computed(() =>
    isAdministrator.value
        ? 'Panel de administración'
        : 'Panel editorial'
);

// Devuelve el nombre visible de un rol sin modificar su código interno.
function roleLabel(role) {
    return roleLabels[role] || role;
}

// Decide si una noticia abre el formulario interno o su página pública.
function articleHref(article) {
    if (canEditArticle(article)) {
        return route('admin.articles.edit', article.id);
    }

    return route('articles.show', article.slug);
}

// Indica si el usuario puede modificar la noticia según permiso y estado.
function canEditArticle(article) {
    return props.permissions.can_review
        || ['draft', 'review', 'needs_changes'].includes(article.status);
}

// Acorta textos largos para que las tarjetas de actividad no rompan la grilla.
function shortText(value, limit = 72) {
    const text = String(value || '').trim();

    if (text.length <= limit) {
        return text;
    }

    return `${text.slice(0, limit).trim()}...`;
}

/**
 * Muestra la fecha y hora de la actividad con el mismo
 * formato utilizado en los demás bloques del panel.
 *
 * Ejemplo:
 * 04/08/2026 12:46
 */
function formatActivityDate(value) {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const formattedDate = date.toLocaleDateString('es-AR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });

    const formattedTime = date.toLocaleTimeString('es-AR', {
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    });

    return `${formattedDate} ${formattedTime}`;
}
</script>

<template>
    <Head :title="dashboardTitle" />

    <section class="admin-hero-panel">
        <div>
            <p class="eyebrow">{{ dashboardEyebrow }}</p>
            <h2>{{ dashboardTitle }}.</h2>
            <p v-if="permissions.can_review">
                Gestioná publicaciones, comentarios y revisiones desde un mismo lugar.
            </p>

            <p v-else-if="permissions.can_access_articles">
                Creá noticias y envialas a revisión antes de que lleguen a portada.
            </p>

            <p v-else>
                Administrá usuarios, categorías, etiquetas, publicidades y configuración del portal.
            </p>
        </div>
        <Link
            v-if="permissions.can_access_articles"
            :href="route('admin.articles.create')"
            class="admin-primary-action"
        >
            <FilePlus2 :size="18" />
            Nueva noticia
        </Link>
    </section>

    <section class="stats-grid">
        <div v-for="(value, key) in stats" :key="key" class="stat-card">
            <span>{{ key }}</span>
            <strong>{{ value }}</strong>
        </div>
    </section>

    <section class="recent-activity-grid">
        <article
            v-if="permissions.can_access_articles"
            class="recent-activity-panel"
        >
            <header>
                <p class="eyebrow">Noticias</p>
                <h3>Últimas publicadas</h3>
            </header>

            <Link
                v-for="article in recentActivity.publishedArticles"
                :key="article.id"
                :href="route('articles.show', article.slug)"
                class="recent-activity-row"
            >
                <span>{{ shortText(article.title) }}</span>
                <small>
                    {{ article.category }} · {{ article.published_at }}
                </small>
            </Link>

            <p
                v-if="!recentActivity.publishedArticles.length"
                class="empty-panel"
            >
                No hay noticias publicadas recientes.
            </p>
        </article>

        <article
            v-if="permissions.can_moderate_comments"
            class="recent-activity-panel"
        >
            <header>
                <p class="eyebrow">Comentarios</p>
                <h3>Pendientes</h3>
            </header>

            <Link
                v-for="comment in recentActivity.pendingComments"
                :key="comment.id"
                :href="route('admin.comments.index', { status: 'pending' })"
                class="recent-activity-row"
            >
                <span>{{ shortText(comment.body) }}</span>

                <small>
                    {{ comment.author_name }} · {{ comment.created_at }}
                </small>
            </Link>

            <p
                v-if="!recentActivity.pendingComments.length"
                class="empty-panel"
            >
                No hay comentarios pendientes.
            </p>
        </article>

        <article
            v-if="permissions.can_access_articles"
            :class="[
                'recent-activity-panel',
                permissions.can_moderate_comments
                    ? 'pending-review-panel--editor'
                    : 'pending-review-panel--journalist',
            ]"
        >
            <header>
                <p class="eyebrow">Noticias</p>
                <h3>Pendientes de revisión</h3>
            </header>

            <Link
                v-for="article in pendingReview"
                :key="article.id"
                :href="route('admin.articles.edit', article.id)"
                class="admin-list-row"
            >
                <span>{{ article.title }}</span>

                <strong>
                    {{ permissions.can_review ? 'Revisar' : 'En espera' }}
                </strong>
            </Link>

            <p
                v-if="!pendingReview.length"
                class="empty-panel"
            >
                No hay noticias esperando revisión.
            </p>
        </article>

        <article
            v-if="permissions.can_manage_system"
            class="recent-activity-panel"
        >
            <header>
                <p class="eyebrow">Usuarios</p>
                <h3>Últimos registros</h3>
            </header>

            <Link
                v-for="user in recentActivity.registeredUsers"
                :key="user.id"
                :href="route(
                    user.role === 'reader'
                        ? 'admin.users.readers'
                        : 'admin.users.employees'
                )"
                class="recent-activity-row"
            >
                <span>{{ shortText(user.name, 54) }}</span>

                <small>
                    {{ roleLabel(user.role) }}
                    ·
                    {{ user.verified ? 'Verificado' : 'Sin verificar' }}
                </small>
            </Link>

            <p
                v-if="!recentActivity.registeredUsers.length"
                class="empty-panel"
            >
                No hay registros recientes.
            </p>
        </article>

        <article
            v-if="permissions.can_manage_system"
            class="recent-activity-panel"
        >
            <header>
                <p class="eyebrow">Publicidades</p>
                <h3>Actividad de clicks</h3>
            </header>

            <Link
                v-for="metric in recentActivity.bannerClicks"
                :key="metric.id"
                :href="route(
                    'admin.advertisements.index',
                    { brand: metric.brand }
                )"
                class="recent-activity-row"
            >
                <span>
                    {{ metric.brand }} · {{ metric.clicks }} clics
                </span>

                <small>
                    {{ metric.placement }} · {{ metric.date }}
                </small>
            </Link>

            <p
                v-if="!recentActivity.bannerClicks.length"
                class="empty-panel"
            >
                Todavía no hay clicks registrados.
            </p>
        </article>
    </section>

    <section
        v-if="permissions.can_access_articles"
        class="admin-columns"
    >
        <div class="admin-panel most-read-panel">
            <header class="admin-panel-header">
                <p class="eyebrow">Noticias</p>
                <h3>Más leídas</h3>
            </header>

            <Link
                v-for="article in topArticles"
                :key="article.id"
                :href="articleHref(article)"
                class="admin-list-row"
            >
                <span>{{ article.title }}</span>
                <strong>{{ article.views }}</strong>
            </Link>
        </div>

        <div class="admin-panel recent-history-panel">
            <header class="admin-panel-header">
                <p class="eyebrow">Historial</p>
                <h3>Actividad reciente</h3>
            </header>
        <div
            v-for="item in activity"
            :key="item.id"
            class="activity-item"
        >
            <p class="activity-item-title">
                {{ shortText(item.article?.title || 'Noticia no disponible', 82) }}
            </p>

            <div class="activity-item-meta">
                <div class="activity-item-meta-copy">
                    <span>
                        {{ item.user?.name || 'Usuario no disponible' }}
                    </span>

                    <span aria-hidden="true">·</span>

                    <strong>
                        {{ actionLabels[item.action] || item.action }}
                    </strong>
                </div>

                <time
                    v-if="item.created_at"
                    :datetime="item.created_at"
                >
                    {{ formatActivityDate(item.created_at) }}
                </time>
            </div>
        </div>

        <p
            v-if="!activity.length"
            class="empty-panel"
        >
            No hay actividad reciente.
        </p>
        </div>
    </section>
</template>
