<script setup>
/* ============================================================================
 * PAGE: Trama.vue
 * ============================================================================
 *
 * Componente raíz que recibe desde Laravel el nombre de la pantalla solicitada
 * y los datos que esa pantalla necesita. A partir de esa información selecciona
 * y carga de forma diferida el componente Vue correspondiente. Los layouts de
 * Admin/Auth también se descargan únicamente cuando hacen falta.
 *
 * Un layout es la estructura visual general que envuelve una página y contiene
 * elementos compartidos, como la cabecera, el menú lateral, la navegación, el
 * pie de página o el área principal donde se inserta el contenido.
 *
 * El layout público persistente se asigna en inertia-pages.js y envuelve solamente
 * pantallas Public/. Trama.vue envuelve Admin/ y Auth/; Error se muestra sin esos layouts.
 *
 * Flujo:
 * 
 * Los controladores de Laravel llaman a TramaBridge::render() y le entregan:
 *
 *      1. El nombre lógico de la pantalla Vue que debe mostrarse.
 *      2. Los datos que esa pantalla necesita.
 *
 * Ejemplo:
 *
 * Un controlador de Laravel llama a TramaBridge::render() y le entrega
 * la siguiente información:
 *
 *      return TramaBridge::render('Admin/Articles/Index', [
 *          'articles' => $articles,
 *          'filters' => $filters,
 *      ]);
 *
 *      'Admin/Articles/Index'
 *
 * identifica la pantalla Admin/Articles/Index.vue.
 *
 * El array contiene los datos preparados por el controlador:
 *
 *      $articles
 *          Listado de noticias.
 *
 *      $filters
 *          Filtros aplicados al listado.
 *
 * TramaBridge empaqueta esa información en una respuesta de Inertia y la envía
 * a Trama.vue utilizando las propiedades screen y screenProps.
 *
 * Trama.vue recibe:
 *
 *      screen = 'Admin/Articles/Index'
 *
 *      screenProps = {
 *          articles,
 *          filters,
 *      }
 *
 * A partir de screen, este componente selecciona la página Vue correspondiente y
 * le entrega screenProps. Admin/ usa AdminEditorialLayout y Auth/ usa AuthNewsLayout;
 * las pantallas Public/ ya llegan envueltas por PersistentInertiaLayout.
 *
 * En resumen, el controlador prepara los datos, TramaBridge los conecta con
 * Inertia y Trama.vue selecciona y muestra la interface en el navegador.
 * ============================================================================ */

import { computed, defineAsyncComponent } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Home from './Public/Home.vue';
import CategoryShow from './Public/CategoryShow.vue';

// Cada pantalla secundaria y cada layout privado se carga solamente cuando se
// necesita. Home y CategoryShow quedan estáticos porque sostienen la primera
// pintura SSR y las pestañas principales del sitio público.
const lazy = (loader) => defineAsyncComponent(loader);

const AdminEditorialLayout = lazy(() => import('@/Layouts/AdminEditorialLayout.vue'));
const AuthNewsLayout = lazy(() => import('@/Layouts/AuthNewsLayout.vue'));

const screens = Object.freeze({
    'Public/Home': Home,
    'Public/ArticleShow': lazy(() => import('./Public/ArticleShow.vue')),
    'Public/CategoryShow': CategoryShow,
    'Public/JournalistShow': lazy(() => import('./Public/JournalistShow.vue')),
    'Public/Search': lazy(() => import('./Public/Search.vue')),
    'Public/MyComments': lazy(() => import('./Public/MyComments.vue')),
    'Public/About': lazy(() => import('./Public/About.vue')),
    'Public/Contact': lazy(() => import('./Public/Contact.vue')),
    'Public/Privacy': lazy(() => import('./Public/Privacy.vue')),
    'Public/Terms': lazy(() => import('./Public/Terms.vue')),
    'Public/AdvertiserDemo': lazy(() => import('./Public/AdvertiserDemo.vue')),
    'Error': lazy(() => import('./Error.vue')),
    'Admin/Dashboard': lazy(() => import('./Admin/Dashboard.vue')),
    'Admin/Articles/Index': lazy(() => import('./Admin/Articles/Index.vue')),
    'Admin/Articles/Form': lazy(() => import('./Admin/Articles/Form.vue')),
    'Admin/Comments/Index': lazy(() => import('./Admin/Comments/Index.vue')),
    'Admin/Categories/Index': lazy(() => import('./Admin/Categories/Index.vue')),
    'Admin/Tags/Index': lazy(() => import('./Admin/Tags/Index.vue')),
    'Admin/Advertisements/Index': lazy(() => import('./Admin/Advertisements/Index.vue')),
    'Admin/Users/Index': lazy(() => import('./Admin/Users/Index.vue')),
    'Auth/Login': lazy(() => import('./Auth/Login.vue')),
    'Auth/Register': lazy(() => import('./Auth/Register.vue')),
    'Auth/ForgotPassword': lazy(() => import('./Auth/ForgotPassword.vue')),
    'Auth/ResetPassword': lazy(() => import('./Auth/ResetPassword.vue')),
    'Auth/VerifyEmail': lazy(() => import('./Auth/VerifyEmail.vue')),
    'Auth/ConfirmPassword': lazy(() => import('./Auth/ConfirmPassword.vue')),
});

const HomeScreen = screens['Public/Home'];

// Props raíz de Inertia:
// screen indica qué pantalla lógica pidió Laravel y screenProps contiene los
// datos específicos que esa pantalla necesita para renderizarse.
const props = defineProps({
    screen: {
        type: String,
        default: 'Public/Home',
    },
    screenProps: {
        type: Object,
        default: () => ({}),
    },
});

// Obtiene el objeto reactivo de la página actual de Inertia.
//
// Permite acceder a las propiedades globales compartidas por Laravel,
// por ejemplo el usuario autenticado, los mensajes flash y los errores.
//
// Cuando Inertia actualiza esas propiedades durante una navegación,
// Vue detecta el cambio y actualiza automáticamente la interface.
const page = usePage();

// Componente que se va a renderizar; si Laravel manda una pantalla desconocida, se vuelve a la portada.
const ActiveScreen = computed(() => screens[props.screen] || HomeScreen);
// Indica si la pantalla pertenece al panel privado y debe usar el layout interno.
const isAdminScreen = computed(() => props.screen.startsWith('Admin/'));
// Indica si la pantalla es de autenticación y debe usar el layout de login/registro.
const isAuthScreen = computed(() => props.screen.startsWith('Auth/'));
// Título que aparece en la cabecera del panel editorial según la pantalla activa.
const adminTitle = computed(() => ({
    'Admin/Dashboard': 'Centro de mando editorial',
    'Admin/Articles/Index': 'Noticias',
    'Admin/Articles/Form': props.screenProps?.mode === 'create' ? 'Nueva noticia' : 'Editar noticia',
    'Admin/Comments/Index': 'Moderación',
    'Admin/Categories/Index': 'Categorías',
    'Admin/Tags/Index': 'Etiquetas',
    'Admin/Advertisements/Index': 'Publicidades',
    'Admin/Users/Index': props.screenProps?.type === 'readers' ? 'Usuarios registrados' : 'Empleados',
}[props.screen] || 'Panel editorial'));
// Indica cuándo la pantalla activa es el formulario de creación o edición de noticias.
const isArticleFormScreen = computed(() => props.screen === 'Admin/Articles/Form');

// La key fuerza un montaje nuevo cuando cambia la URL, incluso si se reutiliza
// la misma pantalla para creación, edición o filtros distintos.
const screenKey = computed(() => `${props.screen}:${page.url}`);
</script>

<template>
    <AdminEditorialLayout v-if="isAdminScreen">
        <template #title>{{ adminTitle }}</template>
        <template v-if="isArticleFormScreen" #actions>
            <Link :href="route('admin.articles.index')" class="admin-topbar-link">
                ← Volver a noticias
            </Link>
        </template>
        <component :is="ActiveScreen" :key="screenKey" v-bind="screenProps" />
    </AdminEditorialLayout>

    <AuthNewsLayout v-else-if="isAuthScreen">
        <component :is="ActiveScreen" :key="screenKey" v-bind="screenProps" />
    </AuthNewsLayout>

    <component v-else :is="ActiveScreen" :key="screenKey" v-bind="screenProps" />
</template>
