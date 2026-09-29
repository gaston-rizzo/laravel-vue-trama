<script setup>
/* ============================================================================
 * LAYOUT: AdminEditorialLayout.vue
 * ============================================================================
 *
 * Layout principal del panel interno de TRAMA.
 *
 * Organiza la navegación y las opciones disponibles según el rol del usuario
 * autenticado. También muestra la información de la cuenta, el acceso al portal
 * público y los mensajes flash del sistema.
 *
 * Los mensajes flash son avisos temporales de éxito, advertencia o error que se
 * muestran después de completar una acción, por ejemplo al crear, actualizar,
 * publicar o eliminar un registro.
 *
 * El contenido y las secciones visibles se adaptan a administradores, editores
 * y periodistas sin modificar la estructura general de la interface.
 * ============================================================================ */

import { computed, provide, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { BarChart3, FileText, FolderTree, LogOut, Megaphone, MessageSquare, Newspaper, Tags, UserRound, Users } from '@lucide/vue';

// Obtiene el objeto reactivo de la página actual de Inertia.
//
// usePage() permite acceder desde Vue a las propiedades que Laravel
// comparte con todas las páginas, por ejemplo:
// - page.props.auth
// - page.props.flash
// - page.props.errors
//
// Como el objeto es reactivo, Vue detecta cuando Inertia actualiza
// esas propiedades después de una navegación o una petición.
const page = usePage();

// Controla si el mensaje flash global debe seguir visible.
const flashVisible = ref(Boolean(page.props.flash.status));

// Comparte con las páginas internas las funciones
// para ocultar y volver a mostrar el mensaje flash.
provide('hideEditorialFlash', hideEditorialFlash);
provide('showEditorialFlash', showEditorialFlash);

// Traducción visible de los roles guardados en base.
const roleLabels = {
    admin: 'Administrador',
    editor: 'Editor',
    journalist: 'Periodista',
    reader: 'Usuario registrado',
};

// Usuario autenticado compartido por Inertia.
const currentUser = computed(() => page.props.auth.user);
// Texto del rol mostrado en la cabecera privada.
const userRoleLabel = computed(() => roleLabels[currentUser.value?.role] || currentUser.value?.role);
// Avatar compartido por Laravel; si falta, el modelo entrega la imagen por defecto.
const currentUserAvatar = computed(() => currentUser.value?.avatar_url || '/images/brand/avatar-default.webp');

// Solo el administrador puede acceder a los módulos del sistema.
const isAdmin = computed(() =>
    currentUser.value?.role === 'admin'
);

// Nombre visible del panel según el tipo de usuario autenticado.
const panelName = computed(() =>
    isAdmin.value
        ? 'Panel de administración'
        : 'Panel editorial'
);

// Nombre de la sección principal del menú según el rol.
const mainNavLabel = computed(() =>
    isAdmin.value
        ? 'Administración'
        : 'Redacción'
);

// Texto pequeño mostrado encima del título de cada pantalla.
const panelEyebrow = computed(() =>
    isAdmin.value
        ? 'Administración'
        : 'CMS editorial'
);

// Periodistas y editores pueden acceder al módulo de noticias.
const canAccessArticles = computed(() =>
    ['journalist', 'editor'].includes(currentUser.value?.role)
);

// Solo el editor puede moderar comentarios.
const canModerate = computed(() =>
    currentUser.value?.role === 'editor'
);

// Permite que las páginas internas oculten el mensaje
// cuando el usuario vuelve a modificar información.
function hideEditorialFlash() {
    flashVisible.value = false;
}

// Vuelve a mostrar el mensaje flash después
// de guardar correctamente la noticia.
function showEditorialFlash() {
    flashVisible.value = Boolean(page.props.flash.status);
}

// Marca como activo un enlace del panel comparando la URL actual con su prefijo.
function isActive(prefix) {
    return page.url === prefix || page.url.startsWith(`${prefix}/`) || page.url.startsWith(`${prefix}?`);
}

// Cuando Laravel envía un nuevo mensaje, vuelve a mostrarlo.
watch(
    () => page.props.flash.status,
    (status) => {
        flashVisible.value = Boolean(status);
    }
);
</script>

<template>
    <div class="admin-shell">
        <aside class="admin-sidebar">
            <Link 
                :href="route('admin.dashboard')" 
                class="admin-logo"
            >
                TRAMA
                <small>{{ panelName }}</small>
            </Link>
            <nav class="admin-nav" aria-label="Panel interno">
                <span class="admin-nav-label">{{ mainNavLabel }}</span>
                <Link 
                    :href="route('admin.dashboard')" 
                    :class="{ active: isActive('/admin') && page.url === '/admin' }"
                >
                    <BarChart3 :size="18" />
                    Panel
                </Link>
                <Link 
                    v-if="canAccessArticles"
                    :href="route('admin.articles.index')" 
                    :class="{ active: isActive('/admin/articles') }"
                >
                    <FileText :size="18" />
                    Noticias
                </Link>
                <Link 
                    v-if="canModerate" 
                    :href="route('admin.comments.index')" 
                    :class="{ active: isActive('/admin/comments') }"
                >
                    <MessageSquare :size="18" />
                    Comentarios
                </Link>

                <template v-if="isAdmin">
                    <span class="admin-nav-label">Contenido</span>
                    <Link 
                        :href="route('admin.categories.index')" 
                        :class="{ active: isActive('/admin/categories') }"
                    >
                        <FolderTree :size="18" />
                        Categorías
                    </Link>
                    <Link 
                        :href="route('admin.tags.index')" 
                        :class="{ active: isActive('/admin/tags') }"
                    >
                        <Tags :size="18" />
                        Etiquetas
                    </Link>
                    <Link 
                        :href="route('admin.advertisements.index')" 
                        :class="{ active: isActive('/admin/advertisements') }"
                    >
                        <Megaphone :size="18" />
                        Publicidades
                    </Link>

                    <span class="admin-nav-label">Usuarios</span>
                    <Link 
                        :href="route('admin.users.employees')" 
                        :class="{ active: isActive('/admin/users/employees') }"
                    >
                        <Users :size="18" />
                        Empleados
                    </Link>
                    <Link 
                        :href="route('admin.users.readers')" 
                        :class="{ active: isActive('/admin/users/readers') }"
                    >
                        <UserRound :size="18" />
                        Usuarios registrados
                    </Link>
                </template>

                <span class="admin-nav-label">Portal</span>
                <Link 
                    :href="route('home')"
                >
                    <Newspaper :size="18" />
                    Ver portal
                </Link>
            </nav>
        </aside>

        <section class="admin-main">
            <header class="admin-topbar">
                <div>
                    <p class="eyebrow">{{ panelEyebrow }}</p>
                    <h1>
                        <slot name="title">{{ panelName }}</slot>
                    </h1>
                </div>
                <div class="admin-topbar-actions">
                    <slot name="actions" />
                </div>
                <div class="admin-user">
                    <img
                        :src="currentUserAvatar"
                        :alt="currentUser?.name || 'Usuario TRAMA'"
                        class="editorial-user-avatar"
                    />
                    <span class="admin-user-copy">
                        <strong>{{ currentUser?.name }}</strong>
                        <small>{{ userRoleLabel }}</small>
                    </span>
                    <Link
                        :href="route('logout')"
                        method="post"
                        as="button"
                        class="admin-user-logout-button"
                        title="Cerrar sesión"
                        aria-label="Cerrar sesión"
                    >
                        <LogOut :size="16" class="admin-user-logout-icon" />
                    </Link>
                </div>
            </header>

            <div
                v-if="flashVisible && page.props.flash.status"
                class="flash-message"
            >
                {{ page.props.flash.status }}
            </div>

            <slot />
        </section>
    </div>
</template>

<style src="../../css/admin/editorial-listing.css"></style>
