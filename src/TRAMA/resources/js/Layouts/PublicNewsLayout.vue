<script setup>
/* ============================================================================
 * LAYOUT: PublicNewsLayout.vue
 * ============================================================================
 *
 * Layout público de TRAMA.
 *
 * Arma cabecera, navegación por secciones, acceso al panel editorial y pie
 * editorial para todas las páginas públicas del portal.
 * ============================================================================ */

import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { LogOut, MessageCircle, Search, UserRound, LayoutDashboard } from '@lucide/vue';
import AdvertisementBanner from '@/Components/News/AdvertisementBanner.vue';

// Tiempo de rotación del banner superior. La publicidad cambia desde Vue, no
// por una nueva selección de Laravel al navegar entre secciones.
const HEADER_AD_ROTATION_MS = 6500;

// Obtiene el contexto actual de Inertia para acceder de forma reactiva a la URL
// de la página y a las props compartidas enviadas por Laravel.
const page = usePage();

// Lista de banners 728x90 del header compartida por Laravel. El layout conserva
// el banner visible actual y rota sobre esta lista sin pedir otra pantalla.
const headerAdvertisements = computed(() => page.props.advertisements?.header ?? []);
// Banner que se está mostrando en este momento dentro del header.
// Arranca con el primer banner recibido para que SSR ya entregue una imagen real
// en el HTML inicial, sin esperar al montaje de Vue en el navegador.

const visibleHeaderAdvertisement = ref(headerAdvertisements.value[0] ?? null);
// Identificador del intervalo que cambia la publicidad cada algunos segundos.
const headerAdvertisementTimer = ref(null);
// Referencia al menú nativo del usuario para poder cerrarlo desde código.
const userMenu = ref(null);

// El año y la fecha de edición provienen de la fecha de referencia configurada en Laravel.
// El pie usa siempre el año editorial compartido por Laravel. No cae al año
// real del navegador porque TRAMA utiliza su propio calendario editorial.
const year = computed(() => String(page.props.site?.reference_date || '2026-07-19').slice(0, 4));

// Etiqueta visible de la fecha correspondiente a la edición actual del portal.
// Si Laravel no envía el dato, se utiliza una cadena vacía.
const editionDateLabel = computed(() => page.props.site?.edition_date_label || '');
// Ubicación mostrada junto a la fecha de edición.
// Si Laravel no envía una ubicación, se utiliza Buenos Aires por defecto.
const editionLocation = computed(() => page.props.site?.location || 'BUENOS AIRES');

// Marca si la URL actual es la portada para no convertir el logo en enlace hacia la misma página.
const isHome = computed(() => page.url === '/' || page.url.startsWith('/?'));
// Marca si la pantalla actual es "Buscar cobertura" para no ofrecer un enlace que vuelve al mismo lugar.
const isSearch = computed(() => page.url === '/buscar' || page.url.startsWith('/buscar?'));
// Slug de la sección pública actual, por ejemplo "politica" dentro de /seccion/politica.
const activeCategorySlug = computed(() => {
    const path = page.url.split('?')[0];
    const categoryPrefix = '/seccion/';

    if (!path.startsWith(categoryPrefix)) {
        return null;
    }

    return decodeURIComponent(path.slice(categoryPrefix.length));
});
// Marca si el usuario está en su grilla de comentarios para resaltar esa opción del menú.
const isMyComments = computed(() => page.url.startsWith('/mis-comentarios'));

// Devuelve un identificador estable para comparar banners aunque Laravel mande
// nuevos objetos de props durante una navegación Inertia.
function advertisementId(advertisement) {
    return advertisement?.id ?? null;
}

// Indica si una categoría del menú coincide con la sección pública que se está viendo.
function isCategoryActive(category) {
    return activeCategorySlug.value === category.slug;
}

// Mantiene el banner actual si todavía existe en la lista recibida. Solo elige
// el primero cuando no hay banner visible o la campaña dejó de estar disponible.
function keepCurrentHeaderAdvertisementIfPossible() {
    if (!headerAdvertisements.value.length) {
        visibleHeaderAdvertisement.value = null;
        return;
    }

    const currentId = advertisementId(visibleHeaderAdvertisement.value);
    const currentStillExists = headerAdvertisements.value.some((advertisement) => advertisementId(advertisement) === currentId);

    if (!currentStillExists) {
        visibleHeaderAdvertisement.value = headerAdvertisements.value[0];
    }
}

// Avanza al siguiente banner activo del header. Cuando llega al final, vuelve
// al primero para crear una rotación continua.
function rotateHeaderAdvertisement() {
    if (headerAdvertisements.value.length <= 1) {
        return;
    }

    const currentId = advertisementId(visibleHeaderAdvertisement.value);
    const currentIndex = headerAdvertisements.value.findIndex((advertisement) => advertisementId(advertisement) === currentId);
    const nextIndex = currentIndex === -1 ? 0 : (currentIndex + 1) % headerAdvertisements.value.length;

    visibleHeaderAdvertisement.value = headerAdvertisements.value[nextIndex];
}

// Detiene el intervalo anterior antes de crear otro. Esto evita que queden dos
// timers rotando el mismo banner si cambia la lista de publicidades activas.
function stopHeaderAdvertisementRotation() {
    if (!headerAdvertisementTimer.value) {
        return;
    }

    window.clearInterval(headerAdvertisementTimer.value);
    headerAdvertisementTimer.value = null;
}

// Arranca la rotación del header. Si hay un solo banner, lo muestra fijo porque
// no hay otra pieza disponible para alternar.
function startHeaderAdvertisementRotation() {
    stopHeaderAdvertisementRotation();
    keepCurrentHeaderAdvertisementIfPossible();

    if (headerAdvertisements.value.length > 1) {
        headerAdvertisementTimer.value = window.setInterval(rotateHeaderAdvertisement, HEADER_AD_ROTATION_MS);
    }
}

// Cierra el menú del usuario cuando otra acción ya no necesita mantenerlo abierto.
function closeUserMenu() {
    if (userMenu.value) {
        userMenu.value.open = false;
    }
}

// Detecta clicks fuera del menú para cerrarlo sin depender del comportamiento del navegador.
function closeUserMenuFromOutside(event) {
    if (!userMenu.value || userMenu.value.contains(event.target)) {
        return;
    }

    closeUserMenu();
}

// Si Laravel informa otra lista de banners activos, Vue conserva el banner
// visible cuando puede y reinicia el intervalo con la nueva lista.
watch(
    () => headerAdvertisements.value.map((advertisement) => advertisementId(advertisement)).join('|'),
    startHeaderAdvertisementRotation
);

// Al montar el layout, inicia la rotación automática de publicidades del header
// y registra el listener que permite cerrar el menú de usuario al hacer clic fuera.
onMounted(() => {
    startHeaderAdvertisementRotation();
    document.addEventListener('click', closeUserMenuFromOutside);
});

// Antes de desmontar el layout, detiene la rotación del banner y elimina el
// listener global para evitar mantener recursos activos innecesariamente.
onBeforeUnmount(() => {
    stopHeaderAdvertisementRotation();
    document.removeEventListener('click', closeUserMenuFromOutside);
});
</script>

<template>
    <div class="site-shell">
        <header class="public-header">
            <div class="brand-row">
                <span v-if="isHome" class="brand-mark brand-mark-current" aria-current="page">
                    <img :src="'/images/brand/logo.webp'" alt="" class="brand-icon" />
                    <span>TRAMA</span>
                </span>
                <Link v-else :href="route('home')" class="brand-mark">
                    <img :src="'/images/brand/logo.webp'" alt="" class="brand-icon" />
                    <span>TRAMA</span>
                </Link>

                <AdvertisementBanner :advertisement="visibleHeaderAdvertisement" variant="header" priority />

                <nav class="header-actions" aria-label="Accesos principales">
                    <span v-if="isSearch" class="icon-action icon-action-current" aria-current="page">
                        <Search :size="18" />
                        Buscar
                    </span>
                    <Link v-else :href="route('search')" class="icon-action">
                        <Search :size="18" />
                        Buscar
                    </Link>
                    <Link
                        v-if="page.props.auth.user?.can_access_editorial"
                        :href="route('admin.dashboard')"
                        class="icon-action accent"
                    >
                        <LayoutDashboard :size="18" />
                        Redacción
                    </Link>
                    <details v-else-if="page.props.auth.user" ref="userMenu" class="user-menu">
                        <summary class="icon-action accent" :title="page.props.auth.user.name">
                            <UserRound :size="18" />
                            <span class="user-menu-name">{{ page.props.auth.user.name }}</span>
                        </summary>
                        <div class="user-menu-panel">
                            <Link :href="route('my-comments.index')" :class="{ active: isMyComments }" @click="closeUserMenu">
                                <MessageCircle :size="16" />
                                Mis comentarios
                            </Link>
                            <Link :href="route('logout')" method="post" as="button" @click="closeUserMenu">
                                <LogOut :size="16" />
                                Cerrar sesión
                            </Link>
                        </div>
                    </details>
                    <Link v-else :href="route('login')" class="icon-action accent">
                        <UserRound :size="18" />
                        Ingresar
                    </Link>
                </nav>
            </div>

            <div v-if="editionDateLabel" class="edition-date-row">
                {{ editionDateLabel }} · {{ editionLocation }}
            </div>

            <nav class="category-nav" aria-label="Secciones">
                <span v-if="isHome" class="nav-current" aria-current="page">Portada</span>
                <Link v-else :href="route('home')">Portada</Link>
                <template v-for="category in page.props.navCategories" :key="category.slug">
                    <span
                        v-if="isCategoryActive(category)"
                        class="nav-current"
                        :style="{ '--accent': category.accent_color }"
                        aria-current="page"
                    >
                        {{ category.name }}
                    </span>
                    <Link
                        v-else
                        :href="route('categories.show', category.slug)"
                        :style="{ '--accent': category.accent_color }"
                        :prefetch="['mount', 'hover', 'click']"
                        cache-for="30s"
                    >
                        {{ category.name }}
                    </Link>
                </template>
            </nav>
        </header>

        <main>
            <slot />
        </main>

        <footer class="public-footer">
            <div class="footer-brand">
                <span v-if="isHome" class="brand-mark brand-mark-current" aria-current="page">
                    <img :src="'/images/brand/logo.webp'" alt="" class="brand-icon" />
                    <span>TRAMA</span>
                </span>
                <Link v-else :href="route('home')" class="brand-mark">
                    <img :src="'/images/brand/logo.webp'" alt="" class="brand-icon" />
                    <span>TRAMA</span>
                </Link>
                <p>Noticias, análisis y contexto para entender lo que pasa.</p>
                <small>Buenos Aires · Argentina</small>
            </div>

            <nav class="footer-columns" aria-label="Pie del sitio">
                <div>
                    <strong>Secciones</strong>
                    <Link
                        v-for="category in page.props.navCategories"
                        :key="category.slug"
                        :href="route('categories.show', category.slug)"
                        :prefetch="['hover', 'click']"
                        cache-for="30s"
                    >
                        {{ category.name }}
                    </Link>
                </div>
                <div>
                    <strong>Servicios</strong>
                    <Link :href="route('search')"><Search :size="15" />Buscar noticias</Link>
                    <Link :href="page.props.auth.user ? route('my-comments.index') : route('login')"><UserRound :size="15" />Mi cuenta</Link>
                    <Link :href="page.props.auth.user ? route('my-comments.index') : route('login')"><MessageCircle :size="15" />Mis comentarios</Link>                    
                    <Link v-if="page.props.auth.user?.can_access_editorial" :href="route('admin.dashboard')"><LayoutDashboard :size="15" />Panel editorial</Link>
                </div>
                <div>
                    <strong>Sobre TRAMA</strong>
                    <Link :href="route('about')">Quiénes somos</Link>
                    <Link :href="route('contact')">Contacto</Link>
                    <Link :href="route('privacy')">Privacidad</Link>
                    <Link :href="route('terms')">Términos</Link>
                </div>
            </nav>

            <div class="footer-bottom">
                <span>Desarrollado por Gastón Rizzo · &copy; {{ year }} TRAMA</span>
            </div>
        </footer>
    </div>
</template>
