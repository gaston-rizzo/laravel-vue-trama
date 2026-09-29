<script setup>
/* ============================================================================
 * PAGE: Public/MyComments.vue
 * ============================================================================
 *
 * Grilla personal de comentarios.
 *
 * Permite que un usuario autenticado encuentre lo que escribió en TRAMA. Las
 * filas publicadas o pendientes abren la noticia y resaltan el comentario. Los
 * rechazos editoriales visibles abren un detalle dentro de esta misma pantalla.
 * ============================================================================ */

import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { MessageCircle, ThumbsUp } from '@lucide/vue';
import Pagination from '@/Components/Shared/Pagination.vue';

// Props de la grilla personal:
// comments contiene la página actual de comentarios del usuario, junto con los
// enlaces de paginación generados por Laravel.
const props = defineProps({
    comments: Object,
});

// Comentario seleccionado para mostrar su detalle dentro de la misma pantalla.
const selectedComment = ref(null);

// Traducción visible para cada estado interno de moderación.
const statusLabels = {
    processing: 'Revisando',
    pending: 'Pendiente',
    approved: 'Publicado',
    rejected: 'Rechazado',
};

// Traducción visible para diferenciar comentario principal de respuesta.
const typeLabels = {
    comment: 'Comentario principal',
    reply: 'Respuesta',
};

// Indica si hay filas para mostrar en la grilla.
const hasComments = computed(() => Boolean(props.comments?.data?.length));

// Devuelve el texto público correspondiente al estado interno.
function statusLabel(status) {
    return statusLabels[status] || status;
}

// Devuelve el texto público correspondiente al tipo interno de comentario.
function typeLabel(type) {
    return typeLabels[type] || 'Comentario';
}

// Recorta texto largo y agrega puntos suspensivos para que la grilla no se deforme.
function truncateText(value, maxLength) {
    const cleanValue = String(value || '').replace(/\s+/g, ' ').trim();

    if (!cleanValue) {
        return '';
    }

    return cleanValue.length > maxLength
        ? `${cleanValue.slice(0, maxLength).trim()}...`
        : cleanValue;
}

// Muestra solo un fragmento corto del comentario escrito por el usuario.
function commentExcerpt(body) {
    return truncateText(body, 40);
}

// Recorta el título de la noticia para mantener estable el ancho de columna.
function articleTitle(title) {
    return truncateText(title || 'Sin noticia', 60);
}

// Formatea fechas en español para la columna Fecha.
function formatDate(value) {
    if (!value) {
        return 'Sin fecha';
    }

    return new Intl.DateTimeFormat('es-AR', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}

// Abre o cierra el detalle del comentario seleccionado.
function toggleCommentDetail(comment) {
    selectedComment.value = selectedComment.value?.id === comment.id ? null : comment;
}
</script>

<template>
    <Head title="Mis comentarios" />

    <section class="section-hero my-comments-hero">
        <h1>Mis comentarios</h1>
        <p>Revisá tus comentarios y respuestas, y accedé directamente a la noticia donde los publicaste.</p>
    </section>

    <section class="content-band my-comments-panel">
        <div class="my-comments-grid" v-if="hasComments">
            <div class="my-comments-head" aria-hidden="true">
                <span>Fecha</span>
                <span>Comentario</span>
                <span>Noticia</span>
                <span>Estado</span>
                <span>Me gusta</span>
                <span>Respuestas</span>
            </div>

            <template v-for="comment in comments.data" :key="comment.id">
                <Link v-if="comment.article_url" :href="comment.article_url" class="my-comments-row">
                    <span>{{ formatDate(comment.created_at) }}</span>
                    <span>
                        <strong>{{ commentExcerpt(comment.body) }}</strong>
                        <small>{{ typeLabel(comment.type) }}</small>
                    </span>
                    <span>{{ articleTitle(comment.article?.title) }}</span>
                    <span>
                        <b class="status-badge" :class="`status-${comment.status}`">{{ statusLabel(comment.status) }}</b>
                    </span>
                    <span class="metric-cell">
                        <ThumbsUp :size="15" />
                        {{ comment.likes_count }}
                    </span>
                    <span class="metric-cell">
                        <MessageCircle :size="15" />
                        {{ comment.replies_count }}
                    </span>
                </Link>

                <button v-else type="button" class="my-comments-row rejected-row" @click="toggleCommentDetail(comment)">
                    <span>{{ formatDate(comment.created_at) }}</span>
                    <span>
                        <strong>{{ commentExcerpt(comment.body) }}</strong>
                        <small>{{ typeLabel(comment.type) }}</small>
                    </span>
                    <span>{{ articleTitle(comment.article?.title) }}</span>
                    <span>
                        <b class="status-badge" :class="`status-${comment.status}`">{{ statusLabel(comment.status) }}</b>
                    </span>
                    <span class="metric-cell">
                        <ThumbsUp :size="15" />
                        {{ comment.likes_count }}
                    </span>
                    <span class="metric-cell">
                        <MessageCircle :size="15" />
                        {{ comment.replies_count }}
                    </span>
                </button>

                <article
                    v-if="selectedComment?.id === comment.id"
                    class="rejected-comment-detail my-comments-inline-detail"
                >
                    <div>
                        <span class="status-badge" :class="`status-${comment.status}`">
                            {{ statusLabel(comment.status) }}
                        </span>
                        <h2>Detalle del comentario</h2>
                    </div>
                    <p>{{ comment.body }}</p>
                    <small>
                        Noticia: {{ comment.article?.title || 'Sin noticia' }} · {{ formatDate(comment.created_at) }}
                    </small>
                </article>
            </template>
        </div>

        <p v-if="!hasComments" class="empty-state">Todavía no escribiste comentarios.</p>

        <Pagination :links="comments.links" />
    </section>
</template>
